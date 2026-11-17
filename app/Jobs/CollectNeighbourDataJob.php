<?php

namespace App\Jobs;

use App\Actions\Neighbours\CollectNeighbourData;
use App\Models\Device;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Read one device's neighbour data (LLDP, MNDP, FDB, ARP). On its own `neighbours` queue and
 * Horizon supervisor, never `poll`: a busy router's ARP table or a slow UniFi agent takes 10-20s,
 * which on the polling queue would delay throughput polls for everything else.
 *
 * Unique per device, so a backlog never stacks two reads of the same device; the lock expires
 * after an hour at most in case a worker dies holding it.
 */
class CollectNeighbourDataJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 3600;

    public function __construct(public int $deviceId)
    {
        $this->onQueue('neighbours');
    }

    public function uniqueId(): string
    {
        return (string) $this->deviceId;
    }

    public function handle(CollectNeighbourData $collect): void
    {
        $device = Device::find($this->deviceId);
        if ($device !== null) {
            $collect($device); // never throws
        }
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('neighbours: job failed', [
            'device_id' => $this->deviceId,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
