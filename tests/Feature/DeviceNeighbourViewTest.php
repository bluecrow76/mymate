<?php

namespace Tests\Feature;

use App\Enums\PollMethod;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Map;
use App\Models\NetworkInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Viewing collected neighbour data: the device page's Neighbours tab (GET
 * /api/devices/{device}/neighbours) and `mymate:neighbours:show`.
 */
class DeviceNeighbourViewTest extends TestCase
{
    use RefreshDatabase;

    private Device $router;

    private Device $switch;

    private Device $ap;

    private Device $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = Device::factory()->create(['name' => 'core-router', 'mgmt_ip' => '192.0.2.1', 'poll_method' => PollMethod::Snmp, 'neighbours_collected_at' => now()]);
        $this->switch = Device::factory()->create(['name' => 'sw-south', 'mgmt_ip' => '192.0.2.71', 'poll_method' => PollMethod::None]);
        $this->ap = Device::factory()->create(['name' => 'ap-lobby', 'mgmt_ip' => '192.0.2.21', 'poll_method' => PollMethod::None]);
        // Known only by an interface MAC (no IP in any ARP table).
        $this->host = Device::factory()->create(['name' => 'nas', 'mgmt_ip' => '192.0.2.50', 'poll_method' => PollMethod::Snmp]);
        NetworkInterface::factory()->create(['device_id' => $this->host->id, 'if_index' => 1, 'name' => 'eth0', 'mac' => '02:00:00:00:00:bb']);

        $uplink = NetworkInterface::factory()->create(['device_id' => $this->router->id, 'if_index' => 19, 'name' => 'sfp-sfpplus1', 'description' => 'to south']);
        $now = now();
        $seen = ['first_seen_at' => $now->copy()->subDay(), 'last_seen_at' => $now->copy()->subSeconds(30)];
        $old = ['first_seen_at' => $now->copy()->subDays(3), 'last_seen_at' => $now->copy()->subDays(2)];

        DB::table('neighbour_sightings')->insert([
            ['device_id' => $this->router->id, 'interface_id' => $uplink->id, 'local_if_index' => 19, 'protocol' => 'lldp', 'remote_key' => 'k1',
                'remote_mac' => '8c:ed:e1:4c:38:8f', 'remote_ip' => '192.0.2.71', 'remote_name' => 'SW-SOUTH-HD24', 'remote_port_id' => 'Port 25',
                'remote_port_description' => 'Port 25 - to core', 'remote_capabilities' => '["bridge","router"]'] + $seen,
            // the same neighbour as it was before being re-patched: history
            ['device_id' => $this->router->id, 'interface_id' => $uplink->id, 'local_if_index' => 19, 'protocol' => 'lldp', 'remote_key' => 'k0',
                'remote_mac' => '8c:ed:e1:4c:38:8f', 'remote_ip' => '192.0.2.71', 'remote_name' => 'SW-SOUTH-HD24', 'remote_port_id' => 'Port 24',
                'remote_port_description' => null, 'remote_capabilities' => null] + $old,
        ]);
        DB::table('fdb_sightings')->insert([
            ['device_id' => $this->router->id, 'interface_id' => $uplink->id, 'port' => 13, 'if_index' => 19, 'mac' => '02:00:00:00:00:aa', 'vlan' => 0] + $seen,
            ['device_id' => $this->router->id, 'interface_id' => $uplink->id, 'port' => 13, 'if_index' => 19, 'mac' => '02:00:00:00:00:bb', 'vlan' => 0] + $seen,
            ['device_id' => $this->router->id, 'interface_id' => $uplink->id, 'port' => 13, 'if_index' => 19, 'mac' => '02:00:00:00:00:cc', 'vlan' => 0] + $seen,
            ['device_id' => $this->router->id, 'interface_id' => null, 'port' => 30, 'if_index' => null, 'mac' => '02:00:00:00:00:dd', 'vlan' => 0] + $seen,
        ]);
        DB::table('arp_sightings')->insert([
            // the AP's MAC -> IP comes from another device's ARP table
            ['device_id' => $this->switch->id, 'interface_id' => null, 'if_index' => 2, 'ip' => '192.0.2.21', 'mac' => '02:00:00:00:00:aa'] + $seen,
            ['device_id' => $this->router->id, 'interface_id' => $uplink->id, 'if_index' => 19, 'ip' => '192.0.2.71', 'mac' => '8c:ed:e1:4c:38:8f'] + $seen,
        ]);
        DB::table('device_addresses')->insert([
            ['device_id' => $this->router->id, 'interface_id' => $uplink->id, 'ip' => '198.51.100.1', 'prefix_length' => 30] + $seen,
        ]);
    }

    public function test_the_neighbours_endpoint_shows_current_rows_matched_to_devices(): void
    {
        $data = $this->actingAs(User::factory()->create())
            ->getJson("/api/devices/{$this->router->id}/neighbours")->assertOk()->json('data');

        $this->assertTrue($data['supported']);
        $this->assertTrue($data['enabled']);
        $this->assertNull($data['error']);

        // Only the current LLDP row; matched to the switch by its management IP.
        $this->assertCount(1, $data['neighbours']);
        $n = $data['neighbours'][0];
        $this->assertSame(['sfp-sfpplus1', 'Port 25', ['bridge', 'router']], [$n['interface']['name'], $n['remote_port_id'], $n['remote_capabilities']]);
        $this->assertSame(['id' => $this->switch->id, 'name' => 'sw-south'], $n['device']);
        $this->assertTrue($n['current']);

        // Ports, busiest first; MACs get their IP from any device's ARP and a device by IP or MAC.
        $this->assertSame([3, 1], array_column($data['ports'], 'current_count'));
        $uplink = $data['ports'][0];
        $this->assertSame('sfp-sfpplus1', $uplink['interface']['name']);
        $byMac = collect($uplink['macs'])->keyBy('mac');
        $this->assertSame(['192.0.2.21', 'ap-lobby'], [$byMac['02:00:00:00:00:aa']['ip'], $byMac['02:00:00:00:00:aa']['device']['name']]);
        $this->assertSame([null, 'nas'], [$byMac['02:00:00:00:00:bb']['ip'], $byMac['02:00:00:00:00:bb']['device']['name']]);
        $this->assertNull($byMac['02:00:00:00:00:cc']['device']);
        $this->assertNull($data['ports'][1]['interface']); // a bridge port with no known interface
        $this->assertSame(30, $data['ports'][1]['port']);

        $this->assertSame([['192.0.2.71', 'sw-south']], array_map(fn ($a) => [$a['ip'], $a['device']['name']], $data['arp']));
        $this->assertSame('198.51.100.1', $data['addresses'][0]['ip']);
    }

    public function test_history_adds_rows_the_latest_read_no_longer_saw(): void
    {
        $data = $this->actingAs(User::factory()->create())
            ->getJson("/api/devices/{$this->router->id}/neighbours?history=1")->assertOk()->json('data');

        $this->assertTrue($data['history']);
        $this->assertSame(['Port 25' => true, 'Port 24' => false], collect($data['neighbours'])->mapWithKeys(fn ($n) => [$n['remote_port_id'] => $n['current']])->all());
    }

    public function test_restricted_operators_cannot_read_neighbour_data_even_for_a_device_they_can_see(): void
    {
        $map = Map::factory()->create();
        DeviceMapPosition::create(['device_id' => $this->router->id, 'map_id' => $map->id, 'x' => 0, 'y' => 0]);
        $user = User::factory()->create(['is_admin' => false, 'restricted' => true]);
        $user->maps()->attach($map->id);

        // They can open the device itself...
        $this->actingAs($user)->getJson("/api/devices/{$this->router->id}")->assertOk();
        // ...but not what it sees of its neighbours, which names devices beyond their maps.
        $this->actingAs($user)->getJson("/api/devices/{$this->router->id}/neighbours")->assertForbidden();
    }

    public function test_a_device_never_read_says_so(): void
    {
        $data = $this->actingAs(User::factory()->create())
            ->getJson("/api/devices/{$this->ap->id}/neighbours")->assertOk()->json('data');

        $this->assertFalse($data['supported']); // ping-only
        $this->assertNull($data['collected_at']);
        $this->assertSame([], $data['neighbours']);
    }

    public function test_the_command_prints_a_device_found_by_name_ip_or_id(): void
    {
        // By name: current rows only, MAC counts per port, ARP summarised.
        $this->assertSame(0, Artisan::call('mymate:neighbours:show', ['device' => 'core-router']));
        $out = Artisan::output();
        foreach (['SW-SOUTH-HD24', 'Port 25 (Port 25 - to core)', 'sw-south (#'.$this->switch->id.')', 'ap-lobby, nas',
            '1 ARP entry (--arp to list)', 'Own addresses: 198.51.100.1/30 (sfp-sfpplus1)'] as $expected) {
            $this->assertStringContainsString($expected, $out);
        }
        $this->assertStringNotContainsString('Port 24', $out);

        // By IP, with history, every MAC and the ARP table.
        $this->assertSame(0, Artisan::call('mymate:neighbours:show', ['device' => '192.0.2.1', '--history' => true, '--macs' => true, '--arp' => true]));
        $out = Artisan::output();
        foreach (['Port 24', 'last 2 days ago', '02:00:00:00:00:cc', '192.0.2.21', '192.0.2.71'] as $expected) {
            $this->assertStringContainsString($expected, $out);
        }

        // By id, for a device that's never read (ping only).
        $this->assertSame(0, Artisan::call('mymate:neighbours:show', ['device' => (string) $this->ap->id]));
        $out = Artisan::output();
        $this->assertStringContainsString('only collected for SNMP-polled devices', $out);
        $this->assertStringContainsString('Last read: never', $out);
    }

    public function test_the_command_explains_an_unknown_or_ambiguous_device(): void
    {
        $this->artisan('mymate:neighbours:show', ['device' => 'no-such-thing'])
            ->expectsOutputToContain('No device matches')
            ->assertFailed();

        // "s" is in several names: list them instead of guessing.
        $this->artisan('mymate:neighbours:show', ['device' => 's'])
            ->expectsOutputToContain('matches several devices')
            ->assertFailed();
    }
}
