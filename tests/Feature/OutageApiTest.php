<?php

namespace Tests\Feature;

use App\Actions\Outages\RecordOutage;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Map;
use App\Models\Outage;
use App\Models\User;
use App\Support\AuthSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_outage_is_idempotent_and_stamps_duration(): void
    {
        $device = Device::factory()->create();
        $rec = app(RecordOutage::class);

        $this->travelTo(now()->startOfMinute());
        $rec->open($device);
        $rec->open($device); // again - still one open outage
        $this->assertSame(1, Outage::where('device_id', $device->id)->whereNull('ended_at')->count());

        $this->travel(90)->seconds();
        $rec->close($device);

        $outage = Outage::where('device_id', $device->id)->firstOrFail();
        $this->assertNotNull($outage->ended_at);
        $this->assertSame(90, $outage->duration_s);
        $this->travelBack();
    }

    public function test_lists_outages_newest_first_with_device_name(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create(['name' => 'CPE-X']);
        Outage::factory()->for($device)->closed()->create();
        Outage::factory()->for($device)->create(); // open

        $this->getJson('/api/outages')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.device_name', 'CPE-X');
    }

    public function test_filters_by_state_and_device(): void
    {
        $this->actingAsUser();
        $a = Device::factory()->create();
        $b = Device::factory()->create();
        Outage::factory()->for($a)->create();           // open
        Outage::factory()->for($a)->closed()->create(); // closed
        Outage::factory()->for($b)->create();           // open, other device

        $this->getJson('/api/outages?state=open')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/outages?state=closed')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/outages?device_id={$a->id}")->assertOk()->assertJsonCount(2, 'data');
    }

    /** Place a device on a map. */
    private function place(Device $device, Map $map): void
    {
        DeviceMapPosition::create(['device_id' => $device->id, 'map_id' => $map->id, 'x' => 0, 'y' => 0]);
    }

    public function test_lists_the_device_ip_alias_and_maps_by_name(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create(['name' => 'cp2-printer', 'alias' => 'CP2 Check Printer', 'mgmt_ip' => '10.101.7.11']);
        $this->place($device, Map::factory()->create(['name' => 'Warehouse']));
        $this->place($device, Map::factory()->create(['name' => 'Accounting']));
        Outage::factory()->for($device)->create();

        $this->getJson('/api/outages')
            ->assertOk()
            ->assertJsonPath('data.0.device_ip', '10.101.7.11')
            ->assertJsonPath('data.0.device_alias', 'CP2 Check Printer')
            ->assertJsonPath('data.0.device_maps.0.name', 'Accounting')
            ->assertJsonPath('data.0.device_maps.1.name', 'Warehouse');
    }

    public function test_a_device_on_no_map_lists_no_maps(): void
    {
        $this->actingAsUser();
        Outage::factory()->for(Device::factory()->create(['mgmt_ip' => null]))->create();

        $this->getJson('/api/outages')
            ->assertOk()
            ->assertJsonPath('data.0.device_maps', [])
            ->assertJsonPath('data.0.device_ip', null);
    }

    public function test_filters_by_map_including_sub_maps_unless_told_not_to(): void
    {
        $this->actingAsUser();
        $site = Map::factory()->create();
        $floor = Map::factory()->create(['parent_map_id' => $site->id]);
        $room = Map::factory()->create(['parent_map_id' => $floor->id]); // two levels down
        $elsewhere = Map::factory()->create();

        [$onSite, $onFloor, $inRoom, $away] = Device::factory()->count(4)->create()->all();
        $this->place($onSite, $site);
        $this->place($onFloor, $floor);
        $this->place($inRoom, $room);
        $this->place($away, $elsewhere);
        foreach ([$onSite, $onFloor, $inRoom, $away] as $d) {
            Outage::factory()->for($d)->create();
        }

        $ids = fn (string $url) => collect($this->getJson($url)->assertOk()->json('data'))->pluck('device_id')->sort()->values()->all();

        $this->assertSame(collect([$onSite->id, $onFloor->id, $inRoom->id])->sort()->values()->all(), $ids("/api/outages?map_id={$site->id}"));
        $this->assertSame([$onSite->id], $ids("/api/outages?map_id={$site->id}&submaps=0"));
        $this->assertSame(collect([$onFloor->id, $inRoom->id])->sort()->values()->all(), $ids("/api/outages?map_id={$floor->id}"));
    }

    public function test_map_filter_combines_with_state_and_lists_a_device_once(): void
    {
        $this->actingAsUser();
        $site = Map::factory()->create();
        $floor = Map::factory()->create(['parent_map_id' => $site->id]);
        $device = Device::factory()->create();
        $this->place($device, $site);
        $this->place($device, $floor); // on both: its outages must not come back twice
        Outage::factory()->for($device)->create();
        Outage::factory()->for($device)->closed()->create();
        Outage::factory()->for(Device::factory()->create())->create(); // not on the map

        $this->getJson("/api/outages?map_id={$site->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/outages?map_id={$site->id}&state=open")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/outages?map_id={$site->id}&state=closed")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_unknown_map_returns_nothing_not_an_error(): void
    {
        $this->actingAsUser();
        Outage::factory()->create();

        $this->getJson('/api/outages?map_id=999999')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/outages?map_id=abc')->assertUnprocessable();
    }

    public function test_a_restricted_operator_only_sees_outages_and_maps_they_can_see(): void
    {
        $mine = Map::factory()->create(['name' => 'Mine']);
        $theirs = Map::factory()->create(['name' => 'Theirs']);
        $visible = Device::factory()->create();
        $hidden = Device::factory()->create();
        $this->place($visible, $mine);
        $this->place($visible, $theirs); // on a map they can't open too
        $this->place($hidden, $theirs);
        Outage::factory()->for($visible)->create();
        Outage::factory()->for($hidden)->create();

        app(AuthSettings::class)->setRestrictedOutages(true); // Settings > Security lets them in
        $user = User::factory()->create(['is_admin' => false, 'restricted' => true]);
        $user->maps()->attach($mine->id);
        $this->actingAs($user);

        $this->getJson('/api/outages')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device_id', $visible->id)
            ->assertJsonPath('data.0.device_maps', [['id' => $mine->id, 'name' => 'Mine']]);

        // Naming a map they can't see finds nothing, rather than that map's outages.
        $this->getJson("/api/outages?map_id={$theirs->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/outages?device_id={$hidden->id}")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_unrestricted_operator_still_sees_every_outage(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        Outage::factory()->count(3)->create();

        $this->getJson('/api/outages')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_restricted_operators_are_kept_out_of_outages_until_an_admin_lets_them_in(): void
    {
        $map = Map::factory()->create();
        $user = User::factory()->create(['is_admin' => false, 'restricted' => true]);
        $user->maps()->attach($map->id);
        $this->actingAs($user);

        $this->getJson('/api/outages')->assertForbidden();
        $this->getJson('/api/user')->assertOk()->assertJsonPath('can_view_outages', false);

        app(AuthSettings::class)->setRestrictedOutages(true);

        $this->getJson('/api/outages')->assertOk();
        $this->getJson('/api/user')->assertOk()->assertJsonPath('can_view_outages', true);
    }

    public function test_unrestricted_operators_can_always_view_outages(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('can_view_outages', true);
    }

    public function test_only_an_admin_can_change_the_outages_setting_and_it_keeps_the_passkey_flag(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->putJson('/api/settings/security', ['restricted_outages' => true])
            ->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->putJson('/api/settings/security', ['passkey_required' => false])->assertOk();
        $this->actingAs($admin)
            ->putJson('/api/settings/security', ['restricted_outages' => true])
            ->assertOk()
            ->assertJsonPath('data.restricted_outages', true)
            ->assertJsonPath('data.passkey_required', false);

        // Flipping the passkey flag afterwards leaves the outages one alone (one stored row, merged).
        $this->actingAs($admin)->putJson('/api/settings/security', ['passkey_required' => false])->assertOk();
        $this->assertTrue(app(AuthSettings::class)->restrictedOutages());

        $this->actingAs($admin)->putJson('/api/settings/security', [])->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/settings/security')->assertOk()->assertJsonPath('data.restricted_outages', true);
    }

    public function test_requires_authentication(): void
    {
        Outage::factory()->create();

        $this->getJson('/api/outages')->assertUnauthorized();
    }
}
