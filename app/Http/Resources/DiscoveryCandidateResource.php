<?php

namespace App\Http\Resources;

use App\Models\Credential;
use App\Models\DiscoveryCandidate;
use App\Models\Map;
use App\Models\Subnet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin DiscoveryCandidate */
class DiscoveryCandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ip' => $this->ip,
            // Which agent's sweep found it (null = central). Promoting it makes a device polled by
            // that agent, and the same IP can appear once per agent (GitHub #49).
            'agent' => $this->agent_id === null ? null : ['id' => $this->agent_id, 'name' => $this->agent?->name],
            'status' => $this->status->value,
            'sysname' => $this->sysname,
            'detected_method' => $this->detected_method?->value,
            'matched_credential_id' => $this->matched_credential_id,
            // Every credential that authenticated (SNMP + RouterOS + SSH), for tags in the queue.
            'matched_credentials' => $this->matchedCredentials(),
            // scan | cmr. A CMR candidate paired with the controller; mac/board/os come from it.
            'source' => $this->source,
            'mac' => $this->mac,
            'board' => $this->board,
            'os' => $this->os,
            // Where Add will put it: its subnet's map, else the default map (null when there are no maps).
            'target_map' => $this->targetMap($request),
            'first_seen' => $this->first_seen,
            'last_seen' => $this->last_seen,
        ];
    }

    /**
     * Resolve the matched credential ids to {id, name, type} for display. Falls back to the
     * single poll/ssh columns for candidates found before the full list was recorded.
     *
     * @return list<array{id:int, name:string, type:string}>
     */
    private function matchedCredentials(): array
    {
        $ids = $this->matched_credential_ids ?: array_values(array_filter([
            $this->matched_credential_id,
            $this->matched_ssh_credential_id,
        ]));

        $map = self::credentialMap();

        return collect($ids)
            ->map(fn ($id): ?Credential => $map->get((int) $id))
            ->filter()
            ->map(fn (Credential $c): array => ['id' => $c->id, 'name' => $c->name, 'type' => $c->type])
            ->values()
            ->all();
    }

    /** @return array{id:int, name:string}|null */
    private function targetMap(Request $request): ?array
    {
        if ($this->isCmr()) {
            return null; // CMR pairing keeps its own placement
        }
        // Subnets and the default map, loaded once per request (kept on the request, not a static,
        // so a long-lived worker never serves a stale copy).
        $subnets = $request->attributes->get('mm.subnets') ?? tap(Subnet::with('map:id,name')->get(), fn ($s) => $request->attributes->set('mm.subnets', $s));
        if (! $request->attributes->has('mm.default_map')) {
            $request->attributes->set('mm.default_map', Map::default());
        }

        $map = Subnet::bestFor($this->ip, $this->agent_id, $subnets)?->map ?? $request->attributes->get('mm.default_map');

        return $map ? ['id' => $map->id, 'name' => $map->name] : null;
    }

    /** Credentials keyed by id, loaded once per request (the pool is small). */
    private static function credentialMap(): Collection
    {
        static $map = null;

        return $map ??= Credential::query()->get(['id', 'name', 'type'])->keyBy('id');
    }
}
