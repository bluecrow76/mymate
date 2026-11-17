<?php

namespace Tests\Feature;

use App\Actions\Neighbours\CollectNeighbourData;
use App\Actions\Neighbours\DispatchNeighbourCollection;
use App\Enums\PollMethod;
use App\Jobs\CollectNeighbourDataJob;
use App\Models\Agent;
use App\Models\Credential;
use App\Models\Device;
use App\Models\NetworkInterface;
use App\Models\User;
use App\Services\Neighbours\SnmpNeighbourReader;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpCredential;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSnmpClient;
use Tests\TestCase;

/**
 * Neighbour data collection (stage 1 of link suggestions). The fixtures are synthetic but copy
 * the formats seen on real RouterOS 7.24/7.25 and UniFi USW gear: text LLDP chassis ids, BITS
 * capabilities as a raw character, bridge ports that aren't ifIndexes, invalid ARP rows with
 * empty MACs, MNDP rows repeated over VPN tunnels and a row cut short mid-walk.
 */
class CollectNeighbourDataTest extends TestCase
{
    use RefreshDatabase;

    private const IF_PHYS = '.1.3.6.1.2.1.2.2.1.6';

    private const IP_AD_IF_INDEX = '.1.3.6.1.2.1.4.20.1.2';

    private const IP_AD_NET_MASK = '.1.3.6.1.2.1.4.20.1.3';

    private const LLDP_REM = '.1.0.8802.1.1.2.1.4.1';

    private const LLDP_MAN = '.1.0.8802.1.1.2.1.4.2';

    private const MNDP = '.1.3.6.1.4.1.14988.1.1.11.1';

    private const BASE_PORT_IF_INDEX = '.1.3.6.1.2.1.17.1.4.1.2';

    private const FDB_PORT = '.1.3.6.1.2.1.17.4.3.1.2';

    private const ARP_MAC = '.1.3.6.1.2.1.4.22.1.2';

    private const ARP_TYPE = '.1.3.6.1.2.1.4.22.1.4';

    private FakeSnmpClient $snmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snmp = new FakeSnmpClient;
        $this->app->instance(SnmpClient::class, $this->snmp);
        // No real pause between FDB confirmation walks in tests.
        $this->app->bind(SnmpNeighbourReader::class, fn ($app) => new SnmpNeighbourReader($app->make(SnmpClient::class), 0.0));
    }

    /** A MikroTik router: two SFP+ uplinks, a VLAN interface, MNDP + LLDP neighbours. */
    private function router(): Device
    {
        $device = Device::factory()->create([
            'poll_method' => PollMethod::Snmp,
            'credential_id' => Credential::factory()->create(['type' => 'snmp', 'snmp_community' => 'public'])->id,
            'vendor' => 'MikroTik',
            'mgmt_ip' => '192.0.2.1',
        ]);
        foreach ([18 => 'sfp-sfpplus2', 19 => 'sfp-sfpplus1', 27 => 'vlan-lan'] as $ifIndex => $name) {
            NetworkInterface::factory()->create(['device_id' => $device->id, 'if_index' => $ifIndex, 'name' => $name]);
        }

        $this->snmp->walks = [
            self::IF_PHYS => [1 => '00 00 00 00 00 00 ', 18 => '04 F4 1C 39 E8 B5 ', 19 => '04 F4 1C 39 E8 B4 ', 27 => '04 F4 1C 39 E8 B4 '],
            self::IP_AD_IF_INDEX => ['192.0.2.1' => '27', '198.51.100.1' => '19', '127.0.0.1' => '1'],
            self::IP_AD_NET_MASK => ['192.0.2.1' => '255.255.255.0', '198.51.100.1' => '255.255.255.252', '127.0.0.1' => '255.0.0.0'],
            self::LLDP_REM => [
                // local ifIndex 19 <-> sw-south "Port 25"; capabilities as the raw BITS byte 0x28 = "("
                '1.4.0.19.1' => '4', '1.5.0.19.1' => '8C:ED:E1:4C:38:8F', '1.6.0.19.1' => '7', '1.7.0.19.1' => 'Port 25',
                '1.8.0.19.1' => 'Port 25 - to core', '1.9.0.19.1' => 'sw-south', '1.12.0.19.1' => '(',
                // local ifIndex 18 <-> sw-office "Port 49"; capabilities as hex text; '+' arrives as '_'
                '1.4.0.18.2' => '4', '1.5.0.18.2' => '74:FA:29:1C:0C:1C', '1.6.0.18.2' => '7', '1.7.0.18.2' => 'Port 49',
                '1.8.0.18.2' => 'SFP_ 1', '1.9.0.18.2' => 'sw-office', '1.12.0.18.2' => '28',
            ],
            self::LLDP_MAN => [
                '1.3.0.19.1.1.4.192.0.2.71' => '3',
                '1.3.0.18.2.1.4.192.0.2.76' => '3',
            ],
            self::MNDP => [
                '1.2.1' => '192.0.2.71', '1.3.1' => '8C ED E1 4C 38 90 ', '1.5.1' => 'UBNT', '1.6.1' => 'sw-south', '1.8.1' => '19',
                // the same VPN neighbour twice (no MAC over a tunnel)
                '1.2.2' => '10.255.0.1', '1.4.2' => '7.25.1', '1.5.2' => 'MikroTik', '1.6.2' => 'remote-vpn', '1.8.2' => '30',
                '1.2.3' => '10.255.0.1', '1.4.3' => '7.25.1', '1.5.3' => 'MikroTik', '1.6.3' => 'remote-vpn', '1.8.3' => '30',
                // a row cut short mid-walk: nothing to identify it by
                '1.8.4' => '30',
            ],
            // bridge ports are not ifIndexes on RouterOS: 13 -> 19, 14 -> 18
            self::BASE_PORT_IF_INDEX => [13 => '19', 14 => '18'],
            self::FDB_PORT => [
                // sw-south's bridge MAC behind bridge port 13 (= ifIndex 19)
                '140.237.225.76.56.143' => '13',
                // a host behind bridge port 14 (= ifIndex 18)
                '0.224.76.1.2.3' => '14',
                // the router's own MAC, reported on port 0: skipped
                '4.244.28.57.232.180' => '0',
            ],
            self::ARP_MAC => [
                '27.192.0.2.71' => '8C ED E1 4C 38 8F ',
                '19.198.51.100.2' => '00 E0 4C 01 02 03 ',
                // failed lookups: one with an empty MAC, one still carrying a stale MAC
                '27.192.0.2.99' => '',
                '27.192.0.2.98' => '02 00 00 00 00 98 ',
            ],
            self::ARP_TYPE => ['27.192.0.2.71' => '3', '19.198.51.100.2' => '4', '27.192.0.2.99' => '2', '27.192.0.2.98' => '2'],
        ];

        return $device;
    }

    private function ifaceId(Device $device, int $ifIndex): int
    {
        return (int) NetworkInterface::where('device_id', $device->id)->where('if_index', $ifIndex)->value('id');
    }

    public function test_a_router_read_is_stored_as_sightings(): void
    {
        $device = $this->router();

        app(CollectNeighbourData::class)($device);

        $device->refresh();
        $this->assertNotNull($device->neighbours_collected_at);
        $this->assertNull($device->neighbours_error);

        // Interface MACs, all-zero skipped.
        $this->assertSame('04:f4:1c:39:e8:b4', NetworkInterface::find($this->ifaceId($device, 19))->mac);
        $this->assertSame('04:f4:1c:39:e8:b5', NetworkInterface::find($this->ifaceId($device, 18))->mac);

        // Every owned address (not loopback), with its interface and prefix length.
        $addresses = DB::table('device_addresses')->where('device_id', $device->id)->orderBy('ip')->get();
        $this->assertSame(['192.0.2.1', '198.51.100.1'], $addresses->pluck('ip')->all());
        $this->assertSame([24, 30], $addresses->pluck('prefix_length')->map(fn ($v) => (int) $v)->all());
        $this->assertSame($this->ifaceId($device, 27), (int) $addresses[0]->interface_id);

        // LLDP: remote port, management IP from the man-addr table, capabilities from both BITS forms.
        $lldp = DB::table('neighbour_sightings')->where('device_id', $device->id)->where('protocol', 'lldp')->orderBy('local_if_index')->get();
        $this->assertCount(2, $lldp);
        $south = $lldp->firstWhere('local_if_index', 19);
        $this->assertSame($this->ifaceId($device, 19), (int) $south->interface_id);
        $this->assertSame('8c:ed:e1:4c:38:8f', $south->remote_mac);
        $this->assertSame('192.0.2.71', $south->remote_ip);
        $this->assertSame('Port 25', $south->remote_port_id);
        $this->assertSame('Port 25 - to core', $south->remote_port_description);
        $this->assertSame('sw-south', $south->remote_name);
        $this->assertSame(['bridge', 'router'], json_decode($south->remote_capabilities, true));
        $office = $lldp->firstWhere('local_if_index', 18);
        $this->assertSame('Port 49', $office->remote_port_id);
        $this->assertSame('192.0.2.76', $office->remote_ip);
        $this->assertSame(['bridge', 'router'], json_decode($office->remote_capabilities, true));

        // MNDP: the VPN repeat collapses to one row, the half-read row is skipped.
        $mndp = DB::table('neighbour_sightings')->where('device_id', $device->id)->where('protocol', 'mndp')->orderBy('local_if_index')->get();
        $this->assertCount(2, $mndp);
        $this->assertSame('8c:ed:e1:4c:38:90', $mndp[0]->remote_mac);
        $this->assertSame('UBNT', $mndp[0]->remote_platform);
        $this->assertNull($mndp[0]->remote_port_id);
        $this->assertSame('remote-vpn', $mndp[1]->remote_name);
        $this->assertNull($mndp[1]->interface_id); // ifIndex 30 isn't a known interface

        // FDB: bridge ports translated to ifIndexes; the port-0 entry skipped.
        $fdb = DB::table('fdb_sightings')->where('device_id', $device->id)->orderBy('port')->get();
        $this->assertCount(2, $fdb);
        $this->assertSame(['8c:ed:e1:4c:38:8f', 13, 19], [$fdb[0]->mac, (int) $fdb[0]->port, (int) $fdb[0]->if_index]);
        $this->assertSame($this->ifaceId($device, 19), (int) $fdb[0]->interface_id);
        $this->assertSame(['00:e0:4c:01:02:03', 14, 18], [$fdb[1]->mac, (int) $fdb[1]->port, (int) $fdb[1]->if_index]);

        // ARP: dynamic and static kept, both invalid failed lookups dropped (even the stale MAC).
        $arp = DB::table('arp_sightings')->where('device_id', $device->id)->orderBy('ip')->get();
        $this->assertSame(['192.0.2.71', '198.51.100.2'], $arp->pluck('ip')->all());
        $this->assertSame('8c:ed:e1:4c:38:8f', $arp[0]->mac);
        $this->assertSame($this->ifaceId($device, 27), (int) $arp[0]->interface_id);
    }

    public function test_a_second_read_refreshes_last_seen_and_a_change_adds_a_row_beside_the_old_one(): void
    {
        $device = $this->router();
        app(CollectNeighbourData::class)($device);
        $first = DB::table('neighbour_sightings')->where('protocol', 'lldp')->where('local_if_index', 19)->first();

        $this->travel(10)->minutes();
        // sw-south was re-patched to its Port 26, and a MAC moved to another bridge port.
        $this->snmp->walks[self::LLDP_REM]['1.7.0.19.1'] = 'Port 26';
        $this->snmp->walks[self::FDB_PORT]['0.224.76.1.2.3'] = '13';
        app(CollectNeighbourData::class)($device);

        // The office neighbour is unchanged: same row, first seen kept, last seen moved on.
        $office = DB::table('neighbour_sightings')->where('protocol', 'lldp')->where('local_if_index', 18)->get();
        $this->assertCount(1, $office);
        $this->assertTrue(strtotime($office[0]->last_seen_at) > strtotime($office[0]->first_seen_at));

        // The re-patched neighbour: the Port 25 row stays as history, a Port 26 row is new.
        $south = DB::table('neighbour_sightings')->where('protocol', 'lldp')->where('local_if_index', 19)->orderBy('id')->get();
        $this->assertSame(['Port 25', 'Port 26'], $south->pluck('remote_port_id')->all());
        $this->assertSame($first->last_seen_at, $south[0]->last_seen_at);
        $this->assertSame($south[1]->first_seen_at, $south[1]->last_seen_at);

        // The MAC that moved: its old port's row is kept, the new port is a new row.
        $moved = DB::table('fdb_sightings')->where('mac', '00:e0:4c:01:02:03')->orderBy('id')->get();
        $this->assertSame([14, 13], $moved->pluck('port')->map(fn ($p) => (int) $p)->all());
    }

    public function test_a_unifi_switch_without_a_base_port_table_uses_its_ports_as_ifindexes_and_skips_mndp(): void
    {
        $device = Device::factory()->create([
            'poll_method' => PollMethod::Snmp,
            'credential_id' => Credential::factory()->create(['type' => 'snmp', 'snmp_community' => 'public'])->id,
            'vendor' => 'Ubiquiti',
        ]);
        NetworkInterface::factory()->create(['device_id' => $device->id, 'if_index' => 25, 'name' => '0/25']);

        $this->snmp->walks = [
            // every port reports the bridge MAC; no dot1dBasePortTable
            self::IF_PHYS => [1 => '8C ED E1 4C 38 8F ', 25 => '8C ED E1 4C 38 8F '],
            self::FDB_PORT => [
                '4.244.28.57.232.180' => '25',
                // a port number that isn't an interface: kept, but with no ifIndex
                '0.1.2.3.4.5' => '30',
            ],
            // would be read if this were a MikroTik
            self::MNDP => ['1.2.1' => '192.0.2.9', '1.6.1' => 'should-not-appear'],
        ];

        app(CollectNeighbourData::class)($device);

        $fdb = DB::table('fdb_sightings')->where('device_id', $device->id)->orderBy('port')->get();
        $this->assertCount(2, $fdb);
        $this->assertSame([25, 25, 0], [(int) $fdb[0]->port, (int) $fdb[0]->if_index, (int) $fdb[0]->vlan]);
        $this->assertSame($this->ifaceId($device, 25), (int) $fdb[0]->interface_id);
        $this->assertNull($fdb[1]->if_index);
        $this->assertSame(0, DB::table('neighbour_sightings')->count());
    }

    public function test_a_cut_short_fdb_walk_is_completed_by_a_second_pass(): void
    {
        // RouterOS's FDB walks stop early for a moment after a pause, while it rebuilds the table.
        $device = $this->router();
        $full = $this->snmp->walks;
        $snmp = new class($full) extends FakeSnmpClient
        {
            private int $fdbWalks = 0;

            public function __construct(array $walks)
            {
                $this->walks = $walks;
            }

            public function walk(string $host, SnmpCredential $cred, string $baseOid): array
            {
                // The first two walks stop early (the table is being rebuilt), the third is whole.
                if ($baseOid === '.1.3.6.1.2.1.17.4.3.1.2' && $this->fdbWalks++ < 2) {
                    return array_slice($this->walks[$baseOid], 0, 1 + $this->fdbWalks % 2, true);
                }

                return parent::walk($host, $cred, $baseOid);
            }
        };
        $this->app->instance(SnmpClient::class, $snmp);

        app(CollectNeighbourData::class)($device);

        $this->assertSame(2, DB::table('fdb_sightings')->where('device_id', $device->id)->count());
    }

    public function test_nothing_is_collected_when_turned_off_or_not_an_snmp_device_on_this_server(): void
    {
        $device = $this->router();

        app(Settings::class)->set('neighbours.collect', 0);
        app(CollectNeighbourData::class)($device);
        $this->assertNull($device->fresh()->neighbours_collected_at);

        app(Settings::class)->set('neighbours.collect', 1);
        $device->forceFill(['poll_method' => PollMethod::None])->save();
        app(CollectNeighbourData::class)($device);
        $device->forceFill(['poll_method' => PollMethod::Snmp, 'agent_id' => Agent::factory()->create()->id])->save();
        app(CollectNeighbourData::class)($device);

        $this->assertNull($device->fresh()->neighbours_collected_at);
        $this->assertSame(0, DB::table('fdb_sightings')->count());
    }

    public function test_an_unreachable_device_records_the_error_without_throwing(): void
    {
        $device = $this->router();
        $this->snmp->throwOnWalk = true;

        app(CollectNeighbourData::class)($device);

        $device->refresh();
        $this->assertNotNull($device->neighbours_collected_at);
        $this->assertStringContainsString('no response', (string) $device->neighbours_error);
        $this->assertStringNotContainsString('public', (string) $device->neighbours_error); // never the community
        $this->assertSame(0, DB::table('neighbour_sightings')->count());
    }

    public function test_due_snmp_devices_are_queued_on_their_own_queue(): void
    {
        Queue::fake();
        $snmp = fn (array $attrs = []) => Device::factory()->create(['poll_method' => PollMethod::Snmp] + $attrs);

        $never = $snmp();
        $stale = $snmp(['neighbours_collected_at' => now()->subHours(2)]);
        $snmp(['neighbours_collected_at' => now()->subMinutes(5)]);                 // read recently
        $snmp(['discovery_error' => 'SnmpClientException: no response']);           // not answering
        $snmp(['agent_id' => Agent::factory()->create()->id]);                      // behind an agent
        Device::factory()->create(['poll_method' => PollMethod::None]);             // ping only
        Device::factory()->create(['poll_method' => PollMethod::RouterOs]);         // API (later stage)

        $this->assertSame(2, app(DispatchNeighbourCollection::class)());

        Queue::assertPushedOn('neighbours', CollectNeighbourDataJob::class);
        Queue::assertPushed(CollectNeighbourDataJob::class, 2);
        $queued = [];
        Queue::assertPushed(CollectNeighbourDataJob::class, function (CollectNeighbourDataJob $job) use (&$queued) {
            $queued[] = $job->deviceId;

            return true;
        });
        $this->assertEqualsCanonicalizing([$never->id, $stale->id], $queued);

        // Turning collection off queues nothing.
        app(Settings::class)->set('neighbours.collect', 0);
        $this->assertSame(0, app(DispatchNeighbourCollection::class)());
    }

    public function test_the_job_reads_the_device(): void
    {
        $device = $this->router();

        (new CollectNeighbourDataJob($device->id))->handle(app(CollectNeighbourData::class));

        $this->assertNotNull($device->fresh()->neighbours_collected_at);
        $this->assertSame(2, DB::table('fdb_sightings')->where('device_id', $device->id)->count());
        $this->assertSame((string) $device->id, (new CollectNeighbourDataJob($device->id))->uniqueId());
    }

    public function test_the_prune_drops_only_sightings_older_than_the_retention_window(): void
    {
        $device = $this->router();
        app(CollectNeighbourData::class)($device);
        $fresh = DB::table('fdb_sightings')->count();

        DB::table('fdb_sightings')->insert([
            'device_id' => $device->id, 'port' => 9, 'mac' => '02:00:00:00:00:09', 'vlan' => 0,
            'first_seen_at' => now()->subDays(60), 'last_seen_at' => now()->subDays(45),
        ]);
        app(Settings::class)->set('neighbours.retention_days', 30);

        $this->artisan('mymate:neighbours:prune')->assertSuccessful();

        $this->assertSame($fresh, DB::table('fdb_sightings')->count());
        $this->assertSame(0, DB::table('fdb_sightings')->where('mac', '02:00:00:00:00:09')->count());
        $this->assertGreaterThan(0, DB::table('neighbour_sightings')->count());
    }

    public function test_the_engine_settings_offer_collection_as_a_checkbox_and_validate_it(): void
    {
        $admin = User::factory()->admin()->create();

        $settings = $this->actingAs($admin)->getJson('/api/settings')->assertOk()->json('data');
        $collect = collect($settings)->firstWhere('key', 'neighbours.collect');
        $this->assertSame(['value' => 1, 'type' => 'bool'], ['value' => $collect['value'], 'type' => $collect['type']]);
        $this->assertSame('int', collect($settings)->firstWhere('key', 'neighbours.retention_days')['type']);

        $this->actingAs($admin)->putJson('/api/settings', ['settings' => [['key' => 'neighbours.collect', 'value' => 2]]])
            ->assertUnprocessable();
        $this->actingAs($admin)->putJson('/api/settings', ['settings' => [['key' => 'neighbours.collect', 'value' => 0]]])
            ->assertOk();
        $this->assertSame(0, app(Settings::class)->getInt('neighbours.collect', 1));
    }
}
