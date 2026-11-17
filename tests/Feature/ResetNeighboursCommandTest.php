<?php

namespace Tests\Feature;

use App\Actions\Neighbours\DispatchNeighbourCollection;
use App\Enums\PollMethod;
use App\Jobs\CollectNeighbourDataJob;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `mymate:neighbours:reset`: equipment set up at the office then moved to a jobsite starts over
 * with no neighbour data, current or history, and is read again on the next loop tick.
 */
class ResetNeighboursCommandTest extends TestCase
{
    use RefreshDatabase;

    private Device $router;

    private Device $other;

    private const TABLES = ['neighbour_sightings', 'fdb_sightings', 'arp_sightings', 'device_addresses'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = Device::factory()->create(['name' => 'jobsite-router', 'mgmt_ip' => '192.0.2.1', 'poll_method' => PollMethod::Snmp,
            'neighbours_collected_at' => now()->subMinutes(5), 'neighbours_error' => 'Some tables couldn\'t be read: fdb (timeout)', 'neighbours_failures' => 2]);
        $this->other = Device::factory()->create(['name' => 'office-switch', 'mgmt_ip' => '192.0.2.2', 'poll_method' => PollMethod::Snmp,
            'neighbours_collected_at' => now()->subMinutes(5)]);

        foreach ([$this->router, $this->other] as $d) {
            $current = ['device_id' => $d->id, 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subMinutes(5)];
            $old = ['device_id' => $d->id, 'first_seen_at' => now()->subDays(9), 'last_seen_at' => now()->subDays(8)];
            DB::table('neighbour_sightings')->insert([
                ['protocol' => 'lldp', 'local_if_index' => 1, 'remote_key' => 'office-core', 'remote_name' => 'OFFICE-CORE'] + $current,
                ['protocol' => 'mndp', 'local_if_index' => 1, 'remote_key' => 'office-old', 'remote_name' => 'OFFICE-OLD'] + $old,
            ]);
            DB::table('fdb_sightings')->insert([
                ['port' => 1, 'mac' => '02:00:00:00:00:0'.$d->id % 10, 'vlan' => 0] + $current,
                ['port' => 2, 'mac' => '02:00:00:00:01:0'.$d->id % 10, 'vlan' => 0] + $old,
            ]);
            DB::table('arp_sightings')->insert(['ip' => '10.0.0.'.$d->id, 'mac' => '02:00:00:00:02:00'] + $current);
            DB::table('device_addresses')->insert(['ip' => '10.1.0.'.$d->id, 'prefix_length' => 24] + $current);
        }
    }

    private function rows(Device $d, string $table): int
    {
        return DB::table($table)->where('device_id', $d->id)->count();
    }

    public function test_it_removes_everything_for_the_device_and_only_that_device(): void
    {
        $this->artisan('mymate:neighbours:reset', ['device' => ['jobsite-router']])
            ->expectsConfirmation('Delete all of this neighbour data, including history?', 'yes')
            ->expectsOutputToContain('jobsite-router: removed 2 neighbours, 2 MACs, 1 ARP entries, 1 addresses.')
            ->expectsOutputToContain('read again within a minute')
            ->assertSuccessful();

        foreach (self::TABLES as $table) {
            $this->assertSame(0, $this->rows($this->router, $table), $table);
        }
        $this->assertSame(2, $this->rows($this->other, 'neighbour_sightings'));
        $this->assertSame(2, $this->rows($this->other, 'fdb_sightings'));
        $this->assertSame(1, $this->rows($this->other, 'arp_sightings'));
        $this->assertSame(1, $this->rows($this->other, 'device_addresses'));

        $this->router->refresh();
        $this->assertNull($this->router->neighbours_collected_at);
        $this->assertNull($this->router->neighbours_error);
        $this->assertSame(0, $this->router->neighbours_failures);
        $this->assertNotNull($this->other->fresh()->neighbours_collected_at);
    }

    public function test_the_reset_device_is_due_for_a_read_straight_away(): void
    {
        Queue::fake();
        $this->artisan('mymate:neighbours:reset', ['device' => [(string) $this->router->id], '--force' => true])->assertSuccessful();

        $this->assertSame(1, app(DispatchNeighbourCollection::class)());
        Queue::assertPushed(CollectNeighbourDataJob::class, 1);
    }

    public function test_several_devices_at_once_by_id_ip_or_name(): void
    {
        $this->artisan('mymate:neighbours:reset', ['device' => [(string) $this->router->id, '192.0.2.2'], '--force' => true])
            ->expectsOutputToContain('jobsite-router: removed')
            ->expectsOutputToContain('office-switch: removed')
            ->assertSuccessful();

        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_declining_the_confirmation_changes_nothing(): void
    {
        $this->artisan('mymate:neighbours:reset', ['device' => ['jobsite-router']])
            ->expectsConfirmation('Delete all of this neighbour data, including history?', 'no')
            ->expectsOutputToContain('Nothing changed.')
            ->assertSuccessful();

        $this->assertSame(2, $this->rows($this->router, 'neighbour_sightings'));
        $this->assertNotNull($this->router->fresh()->neighbours_collected_at);
    }

    public function test_without_force_a_non_interactive_run_changes_nothing_and_fails(): void
    {
        $this->artisan('mymate:neighbours:reset', ['device' => ['jobsite-router'], '--no-interaction' => true])
            ->expectsOutputToContain('add --force')
            ->assertFailed();

        $this->assertSame(2, $this->rows($this->router, 'neighbour_sightings'));
    }

    public function test_an_unknown_device_changes_nothing(): void
    {
        $this->artisan('mymate:neighbours:reset', ['device' => ['jobsite-router', 'no-such-thing'], '--force' => true])
            ->expectsOutputToContain('No device matches "no-such-thing".')
            ->assertFailed();

        $this->assertSame(2, $this->rows($this->router, 'neighbour_sightings'));
    }

    public function test_it_says_when_the_device_wont_be_read_again(): void
    {
        $this->router->update(['discovery_error' => 'SNMP timeout']);

        $this->artisan('mymate:neighbours:reset', ['device' => ['jobsite-router'], '--force' => true])
            ->expectsOutputToContain('once its discovery stops failing (SNMP timeout)')
            ->assertSuccessful();
    }
}
