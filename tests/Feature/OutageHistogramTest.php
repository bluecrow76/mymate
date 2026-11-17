<?php

namespace Tests\Feature;

use App\Enums\DeviceType;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Map;
use App\Models\Outage;
use App\Models\User;
use App\Support\AuthSettings;
use App\Support\OutageWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Outages page's time range, its device-type and short-outage filters, and the histogram of
 * devices going down (stacked by map branch or device type).
 */
class OutageHistogramTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago'; // UTC-6 in November, UTC-5 before 1 Nov 2026

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 18:30:00', 'UTC')); // 12:30 in Chicago, a Tuesday
    }

    private function place(Device $device, Map $map): void
    {
        DeviceMapPosition::create(['device_id' => $device->id, 'map_id' => $map->id, 'x' => 0, 'y' => 0]);
    }

    private function down(Device $device, string $startedUtc, ?int $durationS = 600): Outage
    {
        $start = CarbonImmutable::parse($startedUtc, 'UTC');

        return Outage::factory()->for($device)->create([
            'started_at' => $start,
            'ended_at' => $durationS !== null ? $start->addSeconds($durationS) : null,
            'duration_s' => $durationS,
        ]);
    }

    private function histogram(string $query): array
    {
        return $this->getJson('/api/outages/histogram?tz='.urlencode(self::TZ).'&'.$query)->assertOk()->json('data');
    }

    /** Counts keyed by group, in key order (the order groups appear in a bar isn't part of the API). */
    private function byKey(array $counts): array
    {
        ksort($counts);

        return $counts;
    }

    /** @return list<int> */
    private function listed(string $query): array
    {
        return collect($this->getJson('/api/outages?tz='.urlencode(self::TZ).'&'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    // --- OutageWindow -----------------------------------------------------------------------

    public function test_bars_line_up_with_the_viewers_hours_midnights_and_mondays(): void
    {
        // 24 hours of hourly bars, the last one holding "now" (12:00-13:00 in Chicago).
        $day = OutageWindow::for('24h', self::TZ);
        $this->assertSame(24, $day->buckets);
        $this->assertSame('2026-11-17T19:00:00Z', $day->to->toIso8601ZuluString());
        $this->assertSame('2026-11-16T19:00:00Z', $day->from->toIso8601ZuluString());

        // A month of daily bars starts at local midnight - across the DST change, so the first
        // midnight is 05:00Z (CDT), the last 06:00Z (CST).
        $month = OutageWindow::for('1mo', self::TZ);
        $this->assertSame(30, $month->buckets);
        $this->assertSame('2026-10-19T05:00:00Z', $month->from->toIso8601ZuluString());
        $this->assertSame('2026-11-18T06:00:00Z', $month->to->toIso8601ZuluString());

        // Six months of weekly bars, each starting Monday 00:00 local.
        $half = OutageWindow::for('6mo', self::TZ);
        $this->assertSame(26, $half->buckets);
        foreach ([$half->from, $half->to, $half->bucketStart(7)] as $t) {
            $local = $t->setTimezone(self::TZ);
            $this->assertSame([1, '00:00'], [$local->dayOfWeekIso, $local->format('H:i')]);
        }
    }

    public function test_an_unknown_time_zone_falls_back_to_utc(): void
    {
        $this->assertSame('UTC', OutageWindow::for('24h', 'Mars/Olympus')->timezone);
        $this->assertSame('2026-11-17T19:00:00Z', OutageWindow::for('24h', 'Mars/Olympus')->to->toIso8601ZuluString());
    }

    // --- The list: range, bar window, type, short outages --------------------------------------

    public function test_the_range_lists_outages_that_started_in_it_and_anything_still_ongoing(): void
    {
        $this->actingAsUser();
        $d = Device::factory()->create();
        $recent = $this->down($d, '2026-11-17 18:00:00');
        $old = $this->down($d, '2026-11-17 10:00:00');
        $stillDown = $this->down($d, '2026-11-14 10:00:00', null);

        $this->assertSame([$recent->id, $stillDown->id], $this->listed('range=1h'));
        $this->assertSame([$recent->id, $old->id, $stillDown->id], $this->listed('range=24h'));
        // No range: no time limit (the nav badge's open count relies on it).
        $this->assertCount(3, $this->getJson('/api/outages')->json('data'));
    }

    public function test_a_bar_window_lists_only_outages_that_started_in_it(): void
    {
        $this->actingAsUser();
        $d = Device::factory()->create();
        $in = $this->down($d, '2026-11-17 14:20:00');
        $this->down($d, '2026-11-17 15:05:00');
        $this->down($d, '2026-11-14 10:00:00', null); // ongoing, but didn't start in the bar

        $this->assertSame([$in->id], $this->listed('range=24h&from=2026-11-17T14:00:00Z&to=2026-11-17T15:00:00Z'));
        $this->getJson('/api/outages?from=2026-11-17T15:00:00Z&to=2026-11-17T14:00:00Z')->assertUnprocessable();
    }

    public function test_device_types_filter_the_list(): void
    {
        $this->actingAsUser();
        $router = $this->down(Device::factory()->create(['device_type' => DeviceType::Router]), '2026-11-17 18:00:00');
        $ap = $this->down(Device::factory()->create(['device_type' => DeviceType::Ap]), '2026-11-17 18:00:00');
        $this->down(Device::factory()->create(['device_type' => DeviceType::Switch]), '2026-11-17 18:00:00');

        $this->assertSame([$router->id, $ap->id], $this->listed('range=24h&device_types[]=router&device_types[]=ap'));
        $this->getJson('/api/outages?device_types[]=toaster')->assertUnprocessable();
    }

    public function test_short_outages_can_be_hidden_but_ongoing_ones_always_show(): void
    {
        $this->actingAsUser();
        $d = Device::factory()->create();
        $this->down($d, '2026-11-17 18:00:00', 30);
        $long = $this->down($d, '2026-11-17 18:01:00', 400);
        $ongoing = $this->down($d, '2026-11-17 18:29:00', null);

        $this->assertCount(3, $this->listed('range=1h'));
        $this->assertSame([$long->id, $ongoing->id], $this->listed('range=1h&min_duration=60'));
        $this->assertSame([$ongoing->id], $this->listed('range=1h&min_duration=600'));
    }

    // --- Histogram ------------------------------------------------------------------------------

    public function test_bars_count_distinct_devices_that_went_down(): void
    {
        $this->actingAsUser();
        $flappy = Device::factory()->create(['device_type' => DeviceType::Ap]);
        $steady = Device::factory()->create(['device_type' => DeviceType::Router]);
        foreach (['18:05', '18:10', '18:20'] as $t) {
            $this->down($flappy, "2026-11-17 {$t}:00", 20);
        }
        $this->down($steady, '2026-11-17 18:15:00');
        $this->down($steady, '2026-11-17 06:59:00'); // 00:59 in Chicago: the bar that started at local midnight
        $this->down($steady, '2026-11-15 12:00:00'); // before the range

        $h = $this->histogram('range=24h&group_by=type');

        $this->assertSame(3600, $h['bucket_s']);
        $this->assertCount(24, $h['buckets']);
        $last = $h['buckets'][23];
        $this->assertSame(['2026-11-17T18:00:00Z', 2], [$last['start'], $last['total']]); // 3 flaps + 1 = 2 devices
        $this->assertSame(['type:ap' => 1, 'type:router' => 1], $this->byKey($last['counts']));
        $this->assertSame(['2026-11-17T06:00:00Z', 1], [$h['buckets'][11]['start'], $h['buckets'][11]['total']]);
        $this->assertSame(3, array_sum(array_column($h['buckets'], 'total')));

        // Groups carry the label and devices over the whole range, biggest first (ties by name).
        $this->assertSame([['type:ap', 'Access point', 1, 'ap'], ['type:router', 'Router', 1, 'router']],
            array_map(fn ($g) => [$g['key'], $g['label'], $g['devices'], $g['type']], $h['groups']));

        // Hiding short outages drops the flapping AP altogether.
        $this->assertSame(['type:router' => 1], $this->histogram('range=24h&group_by=type&min_duration=60')['buckets'][23]['counts']);
    }

    public function test_by_map_it_stacks_top_level_maps_or_the_chosen_maps_sub_maps(): void
    {
        $this->actingAsUser();
        $jobs = Map::factory()->create(['name' => 'Jobsites']);
        $alpha = Map::factory()->create(['name' => 'Alpha', 'parent_map_id' => $jobs->id]);
        $alphaFloor = Map::factory()->create(['name' => 'Alpha floor 2', 'parent_map_id' => $alpha->id]);
        $bravo = Map::factory()->create(['name' => 'Bravo', 'parent_map_id' => $jobs->id]);
        $office = Map::factory()->create(['name' => 'Office']);

        $onJobs = Device::factory()->create();
        $onAlphaFloor = Device::factory()->create();
        $onBravo = Device::factory()->create();
        $onBoth = Device::factory()->create(); // on Bravo and Office
        $loose = Device::factory()->create();   // on no map
        $this->place($onJobs, $jobs);
        $this->place($onAlphaFloor, $alphaFloor);
        $this->place($onBravo, $bravo);
        $this->place($onBoth, $bravo);
        $this->place($onBoth, $office);
        foreach ([$onJobs, $onAlphaFloor, $onBravo, $onBoth, $loose] as $d) {
            $this->down($d, '2026-11-17 18:10:00');
        }

        // Every map: top-level maps. The device on two branches counts once, under the one
        // whose name sorts first (Jobsites), so the segments add up to the bar.
        $all = $this->histogram('range=24h&group_by=map');
        $this->assertSame(5, $all['buckets'][23]['total']);
        $this->assertSame($this->byKey(["map:{$jobs->id}" => 4, 'none' => 1]), $this->byKey($all['buckets'][23]['counts']));
        $this->assertSame(['Jobsites', 'Not on a map'], array_column($all['groups'], 'label'));

        // Jobsites chosen: its sub-maps (Alpha gathers Alpha floor 2) and the map itself.
        $jobsView = $this->histogram("range=24h&group_by=map&map_id={$jobs->id}");
        $this->assertSame(4, $jobsView['buckets'][23]['total']);
        $this->assertSame(
            $this->byKey(["map:{$bravo->id}" => 2, "map:{$alpha->id}" => 1, "map:{$jobs->id}" => 1]),
            $this->byKey($jobsView['buckets'][23]['counts']),
        );
        $labels = array_column($jobsView['groups'], 'label', 'key');
        $this->assertSame('Jobsites (this map)', $labels["map:{$jobs->id}"]);
        $this->assertSame($bravo->id, $jobsView['groups'][0]['map_id']); // biggest first

        // Without sub-maps there's only the map itself.
        $only = $this->histogram("range=24h&group_by=map&map_id={$jobs->id}&submaps=0");
        $this->assertSame(["map:{$jobs->id}" => 1], $only['buckets'][23]['counts']);
        $this->assertSame('Jobsites', $only['groups'][0]['label']);
    }

    public function test_past_seven_groups_the_smallest_are_summed_as_other(): void
    {
        $this->actingAsUser();
        foreach (range(1, 9) as $i) {
            $map = Map::factory()->create(['name' => "Site {$i}"]);
            // Site 1 has 9 devices down, site 9 has 1 - a clear ranking.
            foreach (range(1, 10 - $i) as $_) {
                $d = Device::factory()->create();
                $this->place($d, $map);
                $this->down($d, '2026-11-17 18:10:00');
            }
        }

        $h = $this->histogram('range=24h&group_by=map');

        $this->assertSame(['Site 1', 'Site 2', 'Site 3', 'Site 4', 'Site 5', 'Site 6', 'Other'], array_column($h['groups'], 'label'));
        $this->assertSame(3 + 2 + 1, $h['groups'][6]['devices']);
        $this->assertSame(6, $h['buckets'][23]['counts']['other']);
        $this->assertSame(45, $h['buckets'][23]['total']);
    }

    public function test_the_histogram_takes_the_same_filters_as_the_list(): void
    {
        $this->actingAsUser();
        $site = Map::factory()->create();
        $router = Device::factory()->create(['device_type' => DeviceType::Router]);
        $ap = Device::factory()->create(['device_type' => DeviceType::Ap]);
        $elsewhere = Device::factory()->create(['device_type' => DeviceType::Router]);
        $this->place($router, $site);
        $this->place($ap, $site);
        foreach ([$router, $ap, $elsewhere] as $d) {
            $this->down($d, '2026-11-17 18:10:00');
        }
        $this->down($ap, '2026-11-17 18:20:00', null); // still down

        $this->assertSame(1, $this->histogram("range=24h&map_id={$site->id}&device_types[]=router")['buckets'][23]['total']);
        $this->assertSame(1, $this->histogram('range=24h&state=open')['buckets'][23]['total']);
    }

    public function test_a_restricted_operator_only_counts_what_they_can_see(): void
    {
        $mine = Map::factory()->create(['name' => 'Mine']);
        $theirs = Map::factory()->create(['name' => 'Theirs']);
        $visible = Device::factory()->create();
        $hidden = Device::factory()->create();
        $this->place($visible, $mine);
        $this->place($visible, $theirs); // "Theirs" sorts after "Mine", but they can't see it anyway
        $this->place($hidden, $theirs);
        $this->down($visible, '2026-11-17 18:10:00');
        $this->down($hidden, '2026-11-17 18:10:00');

        app(AuthSettings::class)->setRestrictedOutages(true);
        $user = User::factory()->create(['is_admin' => false, 'restricted' => true]);
        $user->maps()->attach($mine->id);
        $this->actingAs($user);

        $h = $this->histogram('range=24h&group_by=map');
        $this->assertSame(1, $h['buckets'][23]['total']);
        $this->assertSame(['Mine'], array_column($h['groups'], 'label'));

        // And not at all until an admin lets restricted operators see outages.
        app(AuthSettings::class)->setRestrictedOutages(false);
        $this->getJson('/api/outages/histogram?range=24h')->assertForbidden();
    }

    public function test_the_histogram_needs_a_known_range_and_grouping(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/outages/histogram')->assertUnprocessable();
        $this->getJson('/api/outages/histogram?range=5y')->assertUnprocessable();
        $this->getJson('/api/outages/histogram?range=24h&group_by=colour')->assertUnprocessable();
        $this->assertCount(26, $this->histogram('range=6mo')['buckets']);
    }
}
