<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceType;
use App\Http\Controllers\Controller;
use App\Http\Resources\OutageResource;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Map;
use App\Models\Outage;
use App\Support\OutageWindow;
use App\Support\Visibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Outage timeline. Newest-first, capped. Optional filters, all applied in the query before the
 * cap, so a quiet map's outages aren't crowded out by a busy one's:
 *
 *  - `device_id` (one device), `state=open|closed`
 *  - `map_id` (devices placed on that map, plus its sub-maps unless `submaps=0`)
 *  - `device_types[]` (router, switch, ap, server, internet, unknown - any of them)
 *  - `min_duration` seconds: hide resolved outages shorter than this (ongoing ones always show)
 *  - `range` (a preset in OutageWindow, read on the viewer's clock `tz`): outages that started
 *    in it, plus any still ongoing. `from` + `to` instead narrow it to one histogram bar: only
 *    outages that started in that window. With neither, there's no time limit (the nav badge).
 *
 * The histogram counts, per bar of the range, how many distinct devices went down, split by the
 * map branch they're on or by device type (see histogram()).
 *
 * A restricted operator only sees outages for devices they can see (the Device visibility
 * scope, via whereHas), like every other device read.
 */
class OutageController extends Controller
{
    private const LIMIT = 500;

    /**
     * Segments in a histogram: up to this many groups are named; past it, the biggest
     * MAX_GROUPS - 1 are named and the rest summed as "Other". Matches the page's 7-colour
     * categorical palette (a colour per group, never a cycled repeat).
     */
    private const MAX_GROUPS = 7;

    private const TYPE_LABELS = [
        'router' => 'Router', 'switch' => 'Switch', 'ap' => 'Access point',
        'server' => 'Server', 'internet' => 'Internet', 'unknown' => 'Unknown',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $v = $request->validate([
            ...$this->filterRules(),
            'device_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date', 'required_with:to'],
            'to' => ['sometimes', 'date', 'required_with:from', 'after:from'],
        ]);

        $query = $this->filtered($v)
            // The device's maps ride along so the page can jump to one it's on; the Map scope
            // drops any a restricted operator can't open.
            ->with(['device.mapPositions.map:id,name,parent_map_id'])
            ->latest('started_at');

        $deviceId = (int) ($v['device_id'] ?? 0);
        if ($deviceId > 0) {
            $query->where('device_id', $deviceId);
        }

        if (isset($v['from'], $v['to'])) {
            $query->where('started_at', '>=', CarbonImmutable::parse($v['from'])->utc())
                ->where('started_at', '<', CarbonImmutable::parse($v['to'])->utc());
        } elseif (isset($v['range'])) {
            $window = OutageWindow::for($v['range'], $v['tz'] ?? 'UTC');
            $query->where(fn (Builder $q) => $q->where('started_at', '>=', $window->from)->orWhereNull('ended_at'));
        }

        return OutageResource::collection($query->limit(self::LIMIT)->get());
    }

    /**
     * Distinct devices that went down in each bar of the range, stacked by group:
     *  - `group_by=map`: the map branch the device is on. With no map filter, its top-level map;
     *    with one, the sub-map of it the device is under (or the chosen map itself). A device on
     *    several branches counts once, under the branch whose name sorts first, so a bar's
     *    segments add up to its total.
     *  - `group_by=type`: the device type.
     * Up to MAX_GROUPS groups (ranked by devices over the whole range) are named; past that the
     * biggest MAX_GROUPS - 1 are named and the rest summed as "Other".
     */
    public function histogram(Request $request): JsonResponse
    {
        $v = $request->validate([
            ...$this->filterRules(),
            'range' => ['required', 'string', Rule::in(array_keys(OutageWindow::RANGES))],
            'group_by' => ['sometimes', 'string', Rule::in(['map', 'type'])],
        ]);
        $window = OutageWindow::for($v['range'], $v['tz'] ?? 'UTC');
        $groupBy = $v['group_by'] ?? 'map';

        // One row per (bar, device). Bars are numbered from the window start in wall-clock
        // seconds, so they follow the viewer's day boundaries (see OutageWindow).
        $rows = $this->filtered($v)
            ->where('started_at', '>=', $window->from)
            ->where('started_at', '<', $window->to)
            ->selectRaw(
                "floor((extract(epoch from ((started_at at time zone 'UTC') at time zone ?)) - ?) / ?)::int as bucket, device_id",
                [$window->timezone, $window->fromWall, $window->bucketSeconds],
            )
            ->distinct()
            ->toBase()
            ->get();

        $deviceIds = $rows->pluck('device_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $groupOf = $groupBy === 'type'
            ? $this->typeGroups($deviceIds)
            : $this->mapGroups($deviceIds, isset($v['map_id']) ? (int) $v['map_id'] : null, $request->boolean('submaps', true));

        // Rank the groups by how many devices they had over the whole range.
        $devicesPerGroup = [];
        foreach ($deviceIds as $id) {
            $key = $groupOf[$id]['key'];
            $devicesPerGroup[$key] = ($devicesPerGroup[$key] ?? 0) + 1;
        }
        arsort($devicesPerGroup);
        $named = array_slice(array_keys($devicesPerGroup), 0, count($devicesPerGroup) > self::MAX_GROUPS ? self::MAX_GROUPS - 1 : self::MAX_GROUPS);
        $named = array_flip($named);

        $groups = [];
        foreach ($deviceIds as $id) {
            $g = isset($named[$groupOf[$id]['key']]) ? $groupOf[$id] : ['key' => 'other', 'label' => 'Other', 'map_id' => null, 'type' => null];
            $groups[$g['key']] ??= $g + ['devices' => 0];
            $groups[$g['key']]['devices']++;
        }
        uasort($groups, fn ($a, $b) => [$a['key'] === 'other', -$a['devices'], $a['label']] <=> [$b['key'] === 'other', -$b['devices'], $b['label']]);

        $counts = array_fill(0, $window->buckets, []);
        foreach ($rows as $row) {
            $i = (int) $row->bucket;
            if ($i < 0 || $i >= $window->buckets) {
                continue;
            }
            $id = (int) $row->device_id;
            $key = isset($named[$groupOf[$id]['key']]) ? $groupOf[$id]['key'] : 'other';
            $counts[$i][$key] = ($counts[$i][$key] ?? 0) + 1;
        }

        return response()->json(['data' => [
            'range' => $v['range'],
            'group_by' => $groupBy,
            'from' => $window->from->toIso8601ZuluString(),
            'to' => $window->to->toIso8601ZuluString(),
            'bucket_s' => $window->bucketSeconds,
            'groups' => array_values($groups),
            'buckets' => array_map(fn (int $i) => [
                'start' => $window->bucketStart($i)->toIso8601ZuluString(),
                'end' => $window->bucketStart($i + 1)->toIso8601ZuluString(),
                'total' => array_sum($counts[$i]),
                'counts' => (object) $counts[$i],
            ], range(0, $window->buckets - 1)),
        ]]);
    }

    /** @return array<string, list<mixed>> */
    private function filterRules(): array
    {
        return [
            'state' => ['sometimes', 'nullable', 'string'],
            'map_id' => ['sometimes', 'integer'],
            'submaps' => ['sometimes', 'boolean'],
            'device_types' => ['sometimes', 'array'],
            'device_types.*' => ['string', Rule::enum(DeviceType::class)],
            'min_duration' => ['sometimes', 'integer', 'min:0', 'max:86400'],
            'range' => ['sometimes', 'string', Rule::in(array_keys(OutageWindow::RANGES))],
            'tz' => ['sometimes', 'string', 'max:64'],
        ];
    }

    /**
     * The outage query with every filter the list and the histogram share.
     *
     * @param  array<string, mixed>  $v
     * @return Builder<Outage>
     */
    private function filtered(array $v): Builder
    {
        $query = Outage::query();

        if (Visibility::restrictedUser()) {
            $query->whereHas('device');
        }

        $state = $v['state'] ?? null;
        if ($state === 'open') {
            $query->whereNull('ended_at');
        } elseif ($state === 'closed') {
            $query->whereNotNull('ended_at');
        }

        if (isset($v['map_id'])) {
            $mapIds = $this->mapAndDescendants((int) $v['map_id'], (bool) ($v['submaps'] ?? true));
            $query->whereHas('device.mapPositions', fn (Builder $p) => $p->whereIn('map_id', $mapIds));
        }

        if (! empty($v['device_types'])) {
            $query->whereHas('device', fn (Builder $d) => $d->whereIn('device_type', array_values(array_unique($v['device_types']))));
        }

        $minDuration = (int) ($v['min_duration'] ?? 0);
        if ($minDuration > 0) {
            // Ongoing outages always show - they haven't had the chance to be short yet.
            $query->where(fn (Builder $q) => $q->whereNull('ended_at')->orWhere('duration_s', '>=', $minDuration));
        }

        return $query;
    }

    /**
     * device id => its group by device type.
     *
     * @param  list<int>  $deviceIds
     * @return array<int, array{key: string, label: string, map_id: null, type: string}>
     */
    private function typeGroups(array $deviceIds): array
    {
        $out = [];
        foreach (Device::query()->whereIn('id', $deviceIds)->get(['id', 'device_type']) as $device) {
            $type = $device->device_type instanceof DeviceType ? $device->device_type->value : (string) ($device->device_type ?? 'unknown');
            $out[(int) $device->id] = ['key' => "type:{$type}", 'label' => self::TYPE_LABELS[$type] ?? ucfirst($type), 'map_id' => null, 'type' => $type];
        }
        foreach ($deviceIds as $id) {
            $out[$id] ??= ['key' => 'type:unknown', 'label' => 'Unknown', 'map_id' => null, 'type' => 'unknown'];
        }

        return $out;
    }

    /**
     * device id => its group by map branch (see histogram()). Only maps this user can see count.
     *
     * @param  list<int>  $deviceIds
     * @return array<int, array{key: string, label: string, map_id: ?int, type: null}>
     */
    private function mapGroups(array $deviceIds, ?int $selected, bool $withSubmaps): array
    {
        /** @var Collection<int, Map> $maps */
        $maps = Map::query()->get(['id', 'name', 'parent_map_id'])->keyBy('id');

        // The branch a map belongs to, memoised: the top-level map it's under, or with a map
        // chosen, the child of that map it's under (the chosen map for itself; null if outside).
        $branch = [];
        $branchOf = function (int $mapId) use (&$branch, $maps, $selected, $withSubmaps): ?int {
            if (array_key_exists($mapId, $branch)) {
                return $branch[$mapId];
            }
            $result = null;
            if ($selected === null) {
                $id = $mapId;
                for ($hops = 0; $hops < 64; $hops++) { // a parent cycle can't loop forever
                    $parent = $maps[$id]->parent_map_id ?? null;
                    if ($parent === null || ! $maps->has($parent)) {
                        break;
                    }
                    $id = (int) $parent;
                }
                $result = $id;
            } elseif ($mapId === $selected) {
                $result = $selected;
            } elseif ($withSubmaps) {
                $id = $mapId;
                for ($hops = 0; $hops < 64; $hops++) {
                    $parent = $maps[$id]->parent_map_id ?? null;
                    if ($parent === null || ! $maps->has($parent)) {
                        break;
                    }
                    if ((int) $parent === $selected) {
                        $result = $id;
                        break;
                    }
                    $id = (int) $parent;
                }
            }

            return $branch[$mapId] = $result;
        };

        $branchesByDevice = [];
        foreach (DeviceMapPosition::query()->whereIn('device_id', $deviceIds)->get(['device_id', 'map_id']) as $p) {
            $mapId = (int) $p->map_id;
            if (! $maps->has($mapId)) {
                continue; // a map this operator can't see
            }
            $b = $branchOf($mapId);
            if ($b !== null) {
                $branchesByDevice[(int) $p->device_id][$b] = true;
            }
        }

        $out = [];
        foreach ($deviceIds as $id) {
            $branches = array_keys($branchesByDevice[$id] ?? []);
            if ($branches === []) {
                $out[$id] = ['key' => 'none', 'label' => 'Not on a map', 'map_id' => null, 'type' => null];

                continue;
            }
            usort($branches, fn (int $a, int $b) => [mb_strtolower($maps[$a]->name), $a] <=> [mb_strtolower($maps[$b]->name), $b]);
            $b = $branches[0];
            $label = $maps[$b]->name.($b === $selected && $withSubmaps ? ' (this map)' : '');
            $out[$id] = ['key' => "map:{$b}", 'label' => $label, 'map_id' => $b, 'type' => null];
        }

        return $out;
    }

    /**
     * The map plus, when asked, every map below it. Read through the Map visibility scope, so a
     * restricted operator naming a map they can't see gets nothing back (an empty list), never
     * another map's outages.
     *
     * @return list<int>
     */
    private function mapAndDescendants(int $mapId, bool $withChildren): array
    {
        $parents = Map::query()->pluck('parent_map_id', 'id'); // id => parent id (visible maps only)
        if (! $parents->has($mapId)) {
            return [];
        }
        if (! $withChildren) {
            return [$mapId];
        }

        $children = [];
        foreach ($parents as $id => $parent) {
            if ($parent !== null) {
                $children[(int) $parent][] = (int) $id;
            }
        }

        $out = [];
        $queue = [$mapId];
        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($out[$id])) {
                continue; // a parent cycle can't loop forever
            }
            $out[$id] = true;
            array_push($queue, ...($children[$id] ?? []));
        }

        return array_keys($out);
    }
}
