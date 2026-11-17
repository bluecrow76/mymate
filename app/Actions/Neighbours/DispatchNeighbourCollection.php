<?php

namespace App\Actions\Neighbours;

use App\Enums\PollMethod;
use App\Jobs\CollectNeighbourDataJob;
use App\Models\Device;
use App\Support\Settings;

/**
 * Queue a neighbour-data read for every device that's due: SNMP-polled from this server, last
 * read longer ago than the interval (Settings > Engine), and not failing discovery (a device
 * that isn't answering would only sit through the timeouts). Called by the loop every minute.
 *
 * Due-ness comes from each device's neighbours_collected_at rather than a loop timer, so a loop
 * restart never resets the clock and the work spreads out over the interval on its own. The
 * job is unique per device, so a device still queued from a previous check isn't queued again.
 */
class DispatchNeighbourCollection
{
    public function __construct(private Settings $settings) {}

    public function __invoke(): int
    {
        if (! $this->settings->getInt('neighbours.collect', 1)) {
            return 0;
        }

        $interval = max(600, $this->settings->getInt('neighbours.interval', 3600));
        $ids = Device::query()
            ->where('poll_method', PollMethod::Snmp)
            ->whereNull('agent_id')
            ->whereNotNull('mgmt_ip')
            ->whereNull('discovery_error')
            ->where(fn ($q) => $q->whereNull('neighbours_collected_at')
                ->orWhere('neighbours_collected_at', '<=', now()->subSeconds($interval)))
            ->orderByRaw('neighbours_collected_at asc nulls first')
            ->pluck('id');

        $ids->each(fn (int $id) => CollectNeighbourDataJob::dispatch($id));

        return $ids->count();
    }
}
