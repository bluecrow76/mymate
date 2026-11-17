<?php

namespace App\Console\Commands;

use App\Actions\Neighbours\GetDeviceNeighbours;
use App\Console\Commands\Concerns\FindsDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Print the neighbour data collected for one device: when it was last read (and any error), its
 * LLDP/MNDP neighbours, how many MACs sit behind each port (or every MAC with --macs), its ARP
 * table (--arp) and its own addresses. Rows from older reads are included with --history.
 */
class ShowNeighboursCommand extends Command
{
    use FindsDevice;

    protected $signature = 'mymate:neighbours:show
        {device : Device id, management IP or name}
        {--macs : List every MAC behind each port, not just the counts}
        {--arp : Also list the device\'s ARP table}
        {--history : Include rows not seen by the latest read}';

    protected $description = 'Show the LLDP/MNDP neighbours, MAC table and ARP collected for a device';

    public function handle(GetDeviceNeighbours $get): int
    {
        $device = $this->findDevice((string) $this->argument('device'));
        if ($device === null) {
            return self::FAILURE;
        }

        $data = $get($device, (bool) $this->option('history'));

        $this->line("<options=bold>{$device->name}</> (#{$device->id}, {$device->mgmt_ip})");
        if (! $data['supported']) {
            $this->warn('Neighbour data is only collected for SNMP-polled devices on this server (not ping-only, RouterOS-API or agent devices).');
        }
        if (! $data['enabled']) {
            $this->warn('Collection is turned off (Settings > Engine).');
        }
        $this->line('Last read: '.($data['collected_at'] !== null ? self::ago($data['collected_at']) : 'never'));
        if ($data['error'] !== null) {
            $this->error($data['error']);
        }

        $this->newLine();
        $this->line('<options=bold>Neighbours (LLDP / MNDP)</>');
        $rows = array_map(fn (array $n) => [
            strtoupper($n['protocol']),
            $n['interface']['name'] ?? ($n['local_if_index'] ?: '-'),
            $n['remote_name'] ?? '-',
            $n['remote_ip'] ?? '-',
            $n['remote_mac'] ?? $n['remote_chassis_id'] ?? '-',
            trim(($n['remote_port_id'] ?? '').($n['remote_port_description'] !== null && $n['remote_port_description'] !== $n['remote_port_id'] ? " ({$n['remote_port_description']})" : '')) ?: '-',
            $n['remote_platform'] ?? '-',
            self::match($n['device']),
            self::seen($n),
        ], $data['neighbours']);
        $rows === []
            ? $this->line('  none')
            : $this->table(['Proto', 'Local port', 'Remote name', 'Remote IP', 'Remote MAC / chassis', 'Remote port', 'Platform', 'In My Mate', 'Seen'], $rows);

        $this->newLine();
        $this->line('<options=bold>MAC table by port</>'.($data['truncated'] ? ' <fg=yellow>(first 5000 MACs only)</>' : ''));
        if ($data['ports'] === []) {
            $this->line('  none');
        } elseif ($this->option('macs')) {
            $rows = [];
            foreach ($data['ports'] as $p) {
                foreach ($p['macs'] as $m) {
                    $rows[] = [self::portName($p), $m['mac'], $m['ip'] ?? '-', self::match($m['device']), self::seen($m)];
                }
            }
            $this->table(['Port', 'MAC', 'IP (from ARP)', 'In My Mate', 'Seen'], $rows);
        } else {
            $this->table(['Port', 'MACs', 'Known devices behind it'], array_map(fn (array $p) => [
                self::portName($p),
                $p['current_count'].(count($p['macs']) !== $p['current_count'] ? ' (+'.(count($p['macs']) - $p['current_count']).' older)' : ''),
                self::knownBehind($p['macs']),
            ], $data['ports']));
        }

        if ($this->option('arp')) {
            $this->newLine();
            $this->line('<options=bold>ARP</>');
            $data['arp'] === []
                ? $this->line('  none')
                : $this->table(['IP', 'MAC', 'Interface', 'In My Mate', 'Seen'], array_map(fn (array $a) => [
                    $a['ip'], $a['mac'], $a['interface']['name'] ?? '-', self::match($a['device']), self::seen($a),
                ], $data['arp']));
        } else {
            $this->line(count($data['arp']).' ARP entr'.(count($data['arp']) === 1 ? 'y' : 'ies').' (--arp to list)');
        }

        $this->line('Own addresses: '.(implode(', ', array_map(
            fn (array $a) => $a['ip'].($a['prefix_length'] !== null ? '/'.$a['prefix_length'] : '').($a['interface'] !== null ? " ({$a['interface']['name']})" : ''),
            $data['addresses'],
        )) ?: 'none'));

        return self::SUCCESS;
    }

    /** @param  array{id: int, name: string}|null  $device */
    private static function match(?array $device): string
    {
        return $device !== null ? "{$device['name']} (#{$device['id']})" : '-';
    }

    /** @param  array{current: bool, last_seen_at: ?string}  $row */
    private static function seen(array $row): string
    {
        return $row['current'] ? 'now' : 'last '.self::ago($row['last_seen_at']);
    }

    private static function ago(?string $iso): string
    {
        return $iso !== null ? Carbon::parse($iso)->diffForHumans() : '-';
    }

    /** @param  array<string, mixed>  $port */
    private static function portName(array $port): string
    {
        return $port['interface']['name'] ?? ('port '.$port['port'].($port['if_index'] !== null ? " (ifIndex {$port['if_index']})" : ''));
    }

    /** @param  list<array<string, mixed>>  $macs */
    private static function knownBehind(array $macs): string
    {
        $names = [];
        foreach ($macs as $m) {
            if ($m['current'] && $m['device'] !== null) {
                $names[$m['device']['id']] = $m['device']['name'];
            }
        }
        $more = count($names) > 6 ? ' +'.(count($names) - 6).' more' : '';

        return $names === [] ? '-' : implode(', ', array_slice($names, 0, 6)).$more;
    }
}
