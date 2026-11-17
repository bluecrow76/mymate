<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsDevice;
use App\Enums\PollMethod;
use App\Models\Device;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wipe everything collected about one or more devices' neighbours: their LLDP/MNDP neighbours,
 * MAC table, ARP table and own addresses, current and history alike, and the record of when they
 * were last read. For equipment that has moved - a router set up at the office and then shipped
 * to a jobsite would otherwise keep showing the office's neighbours as history for the whole
 * retention window.
 *
 * With the last-read time cleared the device is due straight away, so the loop reads it again
 * within a minute and it starts over with what it sees where it is now. Other devices' rows that
 * saw this one (eg the office switch's MAC table) are left alone: they're that device's history.
 */
class ResetNeighboursCommand extends Command
{
    use FindsDevice;

    protected $signature = 'mymate:neighbours:reset
        {device* : Device id, management IP or name (several allowed)}
        {--force : Don\'t ask for confirmation}';

    protected $description = 'Clear all collected neighbour data (LLDP, MNDP, MAC table, ARP, addresses) for a device so it starts fresh';

    /** table => what it's called in the output */
    private const TABLES = [
        'neighbour_sightings' => 'neighbours',
        'fdb_sightings' => 'MACs',
        'arp_sightings' => 'ARP entries',
        'device_addresses' => 'addresses',
    ];

    public function handle(Settings $settings): int
    {
        $devices = [];
        foreach ((array) $this->argument('device') as $needle) {
            $device = $this->findDevice((string) $needle);
            if ($device === null) {
                return self::FAILURE;
            }
            $devices[$device->id] = $device;
        }

        $rows = [];
        foreach ($devices as $device) {
            $rows[] = [$device->id, $device->name, $device->mgmt_ip ?? '-', ...array_map(
                fn (string $table) => DB::table($table)->where('device_id', $device->id)->count(),
                array_keys(self::TABLES),
            )];
        }
        $this->table(['Id', 'Device', 'IP', ...array_values(self::TABLES)], $rows);

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Nothing changed: add --force to reset without being asked.');

                return self::FAILURE;
            }
            if (! $this->confirm('Delete all of this neighbour data, including history?', false)) {
                $this->line('Nothing changed.');

                return self::SUCCESS;
            }
        }

        $collecting = (bool) $settings->getInt('neighbours.collect', 1);
        foreach ($devices as $device) {
            $removed = DB::transaction(function () use ($device) {
                $removed = [];
                foreach (array_keys(self::TABLES) as $table) {
                    $removed[] = DB::table($table)->where('device_id', $device->id)->delete();
                }
                $device->forceFill(['neighbours_collected_at' => null, 'neighbours_error' => null, 'neighbours_failures' => 0])->save();

                return $removed;
            });

            $this->info("{$device->name}: removed ".implode(', ', array_map(
                fn (int $n, string $what) => "{$n} {$what}",
                $removed,
                array_values(self::TABLES),
            )).'.');
            $this->line('  '.self::next($device, $collecting));
        }

        return self::SUCCESS;
    }

    /** When the device will be read again, or why it won't be. */
    private static function next(Device $device, bool $collecting): string
    {
        return match (true) {
            $device->poll_method !== PollMethod::Snmp || $device->agent_id !== null || $device->mgmt_ip === null => 'Neighbour data is only collected for SNMP-polled devices on this server, so nothing will be read for it.',
            ! $collecting => 'Collection is turned off (Settings > Engine), so it won\'t be read again until that is turned on.',
            $device->discovery_error !== null => 'It will be read again once its discovery stops failing ('.$device->discovery_error.').',
            default => 'It will be read again within a minute or so (the loop picks it up).',
        };
    }
}
