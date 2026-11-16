<?php

namespace App\Models;

use App\Services\Discovery\Scanner;
use Database\Factories\SubnetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * An operator-authorized IP range the discovery worker sweeps for new devices
 *. Per-subnet `scan_interval_s` controls cadence; the loop dispatches a
 * ScanSubnetJob when a subnet is due. An `agent_id` delegates the scan to a remote
 * agent so a subnet inside a management network is discovered from the inside.
 */
class Subnet extends Model
{
    /** @use HasFactory<SubnetFactory> */
    use HasFactory;

    protected $fillable = ['cidr', 'label', 'enabled', 'scan_interval_s', 'last_scanned_at', 'scanning_since', 'scan_total', 'scan_swept', 'scan_found', 'agent_id', 'map_id', 'placement'];

    protected $casts = [
        'enabled' => 'boolean',
        'scan_interval_s' => 'integer',
        'last_scanned_at' => 'datetime',
        'scanning_since' => 'datetime',
    ];

    // Mirror the DB defaults on a freshly created instance.
    protected $attributes = [
        'enabled' => true,
        'scan_interval_s' => 3600,
        'placement' => 'right',
    ];

    /**
     * The subnet whose settings decide where a device added for $ip goes: the most specific subnet,
     * scanned from the same place (this server, or the same agent), that contains it. Between two
     * equally specific ones, one with a map wins. Null when no subnet contains it.
     *
     * @param  Collection<int, Subnet>|null  $subnets  all subnets, when the caller already has them
     */
    public static function bestFor(string $ip, ?int $agentId, ?Collection $subnets = null): ?self
    {
        $subnets ??= self::query()->get();

        return $subnets
            ->filter(fn (Subnet $s) => $s->agent_id === $agentId)
            ->map(fn (Subnet $s) => [$s, Scanner::prefixContaining($s->cidr, $ip)])
            ->filter(fn (array $pair) => $pair[1] !== null)
            ->sortByDesc(fn (array $pair) => $pair[1] * 2 + ($pair[0]->map_id !== null ? 1 : 0))
            ->first()[0] ?? null;
    }

    /** The map devices added from this subnet's discovery results go on; null = the default map. */
    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }

    /** The remote agent that scans this subnet, or null when scanned centrally. */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
