<?php

namespace App\Console\Commands;

use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Drop neighbour sightings (LLDP/MNDP neighbours, FDB, ARP, device addresses) that haven't been
 * seen for longer than the retention window (Settings > Engine). Rows still being seen are
 * refreshed every discovery cycle, so only history that has gone quiet is removed. Deletes in
 * batches so a big first prune doesn't hold one long lock.
 */
class PruneNeighbourSightingsCommand extends Command
{
    protected $signature = 'mymate:neighbours:prune';

    protected $description = 'Remove neighbour sightings (LLDP, MNDP, FDB, ARP, addresses) not seen within the retention window';

    private const TABLES = ['neighbour_sightings', 'fdb_sightings', 'arp_sightings', 'device_addresses'];

    public function handle(Settings $settings): int
    {
        $days = max(1, $settings->getInt('neighbours.retention_days', 30));
        $cutoff = now()->subDays($days);

        foreach (self::TABLES as $table) {
            $count = 0;
            do {
                $deleted = DB::table($table)
                    ->whereIn('id', DB::table($table)->where('last_seen_at', '<', $cutoff)->limit(5000)->select('id'))
                    ->delete();
                $count += $deleted;
            } while ($deleted > 0);
            $this->info("{$table}: removed {$count} row(s) not seen for {$days} day(s).");
        }

        return self::SUCCESS;
    }
}
