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
 *
 * A read that failed or came back partial is tried again sooner, backing off: retry_after (10
 * min) after the first failure, then double that each time, until it reaches the interval. A
 * blip at a site is filled in within minutes; a device that never answers costs a few extra
 * reads, then settles back to the interval.
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
        $retryAfter = max(60, (int) config('mymate.neighbours.retry_after', 600));
        $ids = Device::query()
            ->where('poll_method', PollMethod::Snmp)
            ->whereNull('agent_id')
            ->whereNotNull('mgmt_ip')
            ->whereNull('discovery_error')
            ->where(function ($q) use ($interval, $retryAfter) {
                $q->whereNull('neighbours_collected_at')
                    ->orWhere('neighbours_collected_at', '<=', now()->subSeconds($interval));
                // One clause per backoff step shorter than the interval (3 at the defaults).
                for ($failures = 1, $delay = $retryAfter; $delay < $interval; $failures++, $delay *= 2) {
                    $q->orWhere(fn ($q) => $q->where('neighbours_failures', $failures)
                        ->where('neighbours_collected_at', '<=', now()->subSeconds($delay)));
                }
            })
            ->orderByRaw('neighbours_collected_at asc nulls first')
            ->pluck('id');

        $ids->each(fn (int $id) => CollectNeighbourDataJob::dispatch($id));

        return $ids->count();
    }
}
