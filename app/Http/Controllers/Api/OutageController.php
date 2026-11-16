<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OutageResource;
use App\Models\Map;
use App\Models\Outage;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Outage timeline. Newest-first, capped. Optional filters:
 * `?device_id=` (one device), `?state=open|closed`, and `?map_id=` (devices placed on that map,
 * plus its sub-maps unless `?submaps=0`). Every filter runs in the query, before the cap, so a
 * quiet map's outages aren't crowded out by a busy one's.
 *
 * A restricted operator only sees outages for devices they can see (the Device visibility
 * scope, via whereHas), like every other device read.
 */
class OutageController extends Controller
{
    private const LIMIT = 500;

    public function index(Request $request): AnonymousResourceCollection
    {
        $v = $request->validate([
            'device_id' => ['sometimes', 'integer'],
            'state' => ['sometimes', 'nullable', 'string'],
            'map_id' => ['sometimes', 'integer'],
            'submaps' => ['sometimes', 'boolean'],
        ]);

        $query = Outage::query()
            // The device's maps ride along so the page can jump to one it's on; the Map scope
            // drops any a restricted operator can't open.
            ->with(['device.mapPositions.map:id,name,parent_map_id'])
            ->latest('started_at');

        if (Visibility::restrictedUser()) {
            $query->whereHas('device');
        }

        $deviceId = (int) ($v['device_id'] ?? 0);
        if ($deviceId > 0) {
            $query->where('device_id', $deviceId);
        }

        $state = $v['state'] ?? null;
        if ($state === 'open') {
            $query->whereNull('ended_at');
        } elseif ($state === 'closed') {
            $query->whereNotNull('ended_at');
        }

        if (isset($v['map_id'])) {
            $mapIds = $this->mapAndDescendants((int) $v['map_id'], $request->boolean('submaps', true));
            $query->whereHas('device.mapPositions', fn (Builder $p) => $p->whereIn('map_id', $mapIds));
        }

        return OutageResource::collection($query->limit(self::LIMIT)->get());
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
