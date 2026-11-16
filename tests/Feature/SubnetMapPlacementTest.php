<?php

namespace Tests\Feature;

use App\Actions\Discovery\PromoteCandidate;
use App\Enums\DiscoveryStatus;
use App\Enums\PollMethod;
use App\Models\Agent;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\DiscoveryCandidate;
use App\Models\Map;
use App\Models\Subnet;
use App\Services\Discovery\Scanner;
use App\Support\MapPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** A subnet's "add devices to" map and placement edge, used by discovery's Add button. */
class SubnetMapPlacementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // promoting kicks discovery + poll jobs
    }

    private function candidate(string $ip, ?int $agentId = null): DiscoveryCandidate
    {
        return DiscoveryCandidate::factory()->create([
            'ip' => $ip,
            'agent_id' => $agentId,
            'detected_method' => PollMethod::Snmp,
            'status' => DiscoveryStatus::New,
        ]);
    }

    private function place(Map $map, float $x, float $y): void
    {
        DeviceMapPosition::create(['device_id' => Device::factory()->create()->id, 'map_id' => $map->id, 'x' => $x, 'y' => $y]);
    }

    /** @return list<int> */
    private function mapsOf(Device $device): array
    {
        return DeviceMapPosition::where('device_id', $device->id)->pluck('map_id')->map(fn ($id) => (int) $id)->all();
    }

    private function position(Device $device, Map $map): array
    {
        $p = DeviceMapPosition::where('device_id', $device->id)->where('map_id', $map->id)->firstOrFail();

        return [(float) $p->x, (float) $p->y];
    }

    // --- Scanner::prefixContaining ------------------------------------------------------------

    public function test_prefix_containing_matches_only_addresses_inside_the_range(): void
    {
        $this->assertSame(24, Scanner::prefixContaining('10.101.7.0/24', '10.101.7.11'));
        $this->assertSame(16, Scanner::prefixContaining('10.101.0.0/16', '10.101.7.11'));
        $this->assertNull(Scanner::prefixContaining('10.101.8.0/24', '10.101.7.11'));
        $this->assertNull(Scanner::prefixContaining('not-a-cidr', '10.101.7.11'));
        $this->assertNull(Scanner::prefixContaining('10.101.7.0/24', 'fe80::1'));
    }

    // --- MapPlacement -------------------------------------------------------------------------

    public function test_an_empty_map_starts_at_the_origin(): void
    {
        $map = Map::factory()->create();

        $this->assertSame(['x' => 0.0, 'y' => 0.0], app(MapPlacement::class)->nextSlot($map->id, 'right'));
    }

    public function test_each_edge_takes_the_first_free_slot_along_it(): void
    {
        $map = Map::factory()->create();
        // Two cards side by side on the top row, one below the left one.
        $this->place($map, 0, 0);
        $this->place($map, 240, 0);
        $this->place($map, 0, 144);
        $placement = app(MapPlacement::class);

        // Right-hand column (x=240): its top slot is taken, the one under it is free.
        $this->assertSame(['x' => 240.0, 'y' => 144.0], $placement->nextSlot($map->id, 'right'));
        // Left-hand column (x=0): two taken, the third is free.
        $this->assertSame(['x' => 0.0, 'y' => 288.0], $placement->nextSlot($map->id, 'left'));
        // Bottom row (y=144): the first slot is taken, the next one along is free.
        $this->assertSame(['x' => 240.0, 'y' => 144.0], $placement->nextSlot($map->id, 'bottom'));
        // Top row (y=0): both taken, the third is free.
        $this->assertSame(['x' => 480.0, 'y' => 0.0], $placement->nextSlot($map->id, 'top'));
    }

    public function test_slots_snap_to_the_grid_and_an_unknown_edge_falls_back_to_right(): void
    {
        $map = Map::factory()->create();
        $this->place($map, 37, 9); // off-grid card

        $slot = app(MapPlacement::class)->nextSlot($map->id, 'sideways');

        $this->assertSame(['x' => 32.0, 'y' => 160.0], $slot); // snapped to 32,16 (taken), then one slot down
    }

    // --- Promote (the Add button) -------------------------------------------------------------

    public function test_add_puts_the_device_on_its_subnets_map_only(): void
    {
        $default = Map::default(); // seeded by the migrations
        $site = Map::factory()->create();
        Subnet::factory()->create(['cidr' => '10.101.7.0/24', 'map_id' => $site->id]);

        $device = app(PromoteCandidate::class)($this->candidate('10.101.7.11'));

        $this->assertSame([$site->id], $this->mapsOf($device));
        $this->assertNotContains($default->id, $this->mapsOf($device));
    }

    public function test_the_most_specific_subnet_wins(): void
    {
        $region = Map::factory()->create();
        $building = Map::factory()->create();
        Subnet::factory()->create(['cidr' => '10.101.0.0/16', 'map_id' => $region->id]);
        Subnet::factory()->create(['cidr' => '10.101.7.0/24', 'map_id' => $building->id]);

        $inBuilding = app(PromoteCandidate::class)($this->candidate('10.101.7.11'));
        $elsewhere = app(PromoteCandidate::class)($this->candidate('10.101.9.5'));

        $this->assertSame([$building->id], $this->mapsOf($inBuilding));
        $this->assertSame([$region->id], $this->mapsOf($elsewhere));
    }

    public function test_a_subnet_without_a_map_uses_the_default_map_at_its_own_placement(): void
    {
        $default = Map::default(); // seeded by the migrations
        $this->place($default, 0, 0);
        $this->place($default, 240, 0);
        Subnet::factory()->create(['cidr' => '10.5.5.0/24', 'map_id' => null, 'placement' => 'top']);

        $device = app(PromoteCandidate::class)($this->candidate('10.5.5.5'));

        $this->assertSame([$default->id], $this->mapsOf($device));
        $this->assertSame([480.0, 0.0], $this->position($device, $default));
    }

    public function test_no_matching_subnet_means_the_default_map(): void
    {
        $default = Map::default(); // seeded by the migrations
        Subnet::factory()->create(['cidr' => '192.168.1.0/24', 'map_id' => Map::factory()->create()->id]);

        $device = app(PromoteCandidate::class)($this->candidate('10.9.9.9'));

        $this->assertSame([$default->id], $this->mapsOf($device));
    }

    public function test_an_agents_subnet_only_applies_to_hosts_that_agent_found(): void
    {
        $default = Map::default(); // seeded by the migrations
        $siteMap = Map::factory()->create();
        $agent = Agent::factory()->create();
        Subnet::factory()->create(['cidr' => '10.20.0.0/24', 'agent_id' => $agent->id, 'map_id' => $siteMap->id]);

        $viaAgent = app(PromoteCandidate::class)($this->candidate('10.20.0.5', $agent->id));
        $central = app(PromoteCandidate::class)($this->candidate('10.20.0.5'));

        $this->assertSame([$siteMap->id], $this->mapsOf($viaAgent));
        $this->assertSame([$default->id], $this->mapsOf($central));
    }

    public function test_devices_added_one_after_another_never_share_a_slot(): void
    {
        $site = Map::factory()->create();
        Subnet::factory()->create(['cidr' => '10.101.7.0/24', 'map_id' => $site->id, 'placement' => 'right']);

        $a = app(PromoteCandidate::class)($this->candidate('10.101.7.1'));
        $b = app(PromoteCandidate::class)($this->candidate('10.101.7.2'));
        $c = app(PromoteCandidate::class)($this->candidate('10.101.7.3'));

        $this->assertSame([0.0, 0.0], $this->position($a, $site));
        $this->assertSame([0.0, 144.0], $this->position($b, $site));
        $this->assertSame([0.0, 288.0], $this->position($c, $site));
    }

    public function test_an_existing_device_is_reused_and_left_where_it_is(): void
    {
        $default = Map::default(); // seeded by the migrations
        $site = Map::factory()->create();
        Subnet::factory()->create(['cidr' => '10.101.7.0/24', 'map_id' => $site->id]);
        $existing = Device::factory()->create(['mgmt_ip' => '10.101.7.11']);
        DeviceMapPosition::create(['device_id' => $existing->id, 'map_id' => $default->id, 'x' => 50, 'y' => 60]);

        $device = app(PromoteCandidate::class)($this->candidate('10.101.7.11'));

        $this->assertSame($existing->id, $device->id);
        $this->assertSame([$default->id], $this->mapsOf($device));
        $this->assertSame([50.0, 60.0], $this->position($device, $default));
    }

    // --- Subnet + candidate API ---------------------------------------------------------------

    public function test_a_subnet_stores_its_map_and_placement(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();

        $id = $this->postJson('/api/subnets', ['cidr' => '10.101.7.0/24', 'map_id' => $map->id, 'placement' => 'bottom'])
            ->assertCreated()
            ->assertJsonPath('data.map_id', $map->id)
            ->assertJsonPath('data.placement', 'bottom')
            ->json('data.id');

        $this->patchJson("/api/subnets/{$id}", ['map_id' => null, 'placement' => 'left'])
            ->assertOk()
            ->assertJsonPath('data.map_id', null)
            ->assertJsonPath('data.placement', 'left');
    }

    public function test_a_new_subnet_defaults_to_the_default_map_down_the_right(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/subnets', ['cidr' => '10.101.8.0/24'])
            ->assertCreated()
            ->assertJsonPath('data.map_id', null)
            ->assertJsonPath('data.placement', 'right');
    }

    public function test_a_bad_map_or_placement_is_rejected(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/subnets', ['cidr' => '10.101.7.0/24', 'map_id' => 999999])->assertUnprocessable();
        $this->postJson('/api/subnets', ['cidr' => '10.101.7.0/24', 'placement' => 'diagonal'])->assertUnprocessable();
    }

    public function test_deleting_the_map_puts_the_subnet_back_on_the_default_map(): void
    {
        $map = Map::factory()->create();
        $subnet = Subnet::factory()->create(['cidr' => '10.101.7.0/24', 'map_id' => $map->id]);

        $map->delete();

        $this->assertNull($subnet->fresh()->map_id);
    }

    public function test_the_review_queue_says_which_map_add_will_use(): void
    {
        $this->actingAsUser();
        $default = Map::default(); // seeded by the migrations
        $site = Map::factory()->create(['name' => 'Building 7']);
        Subnet::factory()->create(['cidr' => '10.101.7.0/24', 'map_id' => $site->id]);
        $this->candidate('10.101.7.11');
        $this->candidate('10.9.9.9');

        $rows = collect($this->getJson('/api/discovery-candidates')->assertOk()->json('data'))->keyBy('ip');

        $this->assertSame('Building 7', $rows['10.101.7.11']['target_map']['name']);
        $this->assertSame($default->name, $rows['10.9.9.9']['target_map']['name']);
    }
}
