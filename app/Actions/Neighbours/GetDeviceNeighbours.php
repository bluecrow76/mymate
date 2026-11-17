<?php

namespace App\Actions\Neighbours;

use App\Enums\PollMethod;
use App\Models\Device;
use App\Models\NetworkInterface;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What's been collected about one device's neighbours, ready to show: its LLDP/MNDP neighbours,
 * the MACs seen behind each of its ports, its ARP table and its own addresses. Shared by the
 * device page's Neighbours tab and `mymate:neighbours:show`.
 *
 * Each row is flagged `current` when it was refreshed by the latest read; older rows are
 * history (a neighbour that moved or went quiet) and only included when asked for.
 *
 * Remote ends are matched to My Mate devices where it's easy and unambiguous: an IP that is a
 * device's management IP or one of its collected addresses, else a MAC that is one of a device's
 * interface MACs. MACs behind a port get their IP from any device's ARP table (routers see the
 * most). This is a read-only preview; stage 2 does the real link matching.
 */
class GetDeviceNeighbours
{
    /** A read takes seconds; rows last seen within this of the read's end belong to that read. */
    private const CURRENT_WINDOW_S = 900;

    /** Cap on MAC rows returned (a core switch can hold thousands). */
    private const MAX_FDB_ROWS = 5000;

    public function __construct(private Settings $settings) {}

    /** @return array<string, mixed> */
    public function __invoke(Device $device, bool $history = false): array
    {
        $collectedAt = $device->neighbours_collected_at;
        $since = $collectedAt?->copy()->subSeconds(self::CURRENT_WINDOW_S);
        $isCurrent = static fn (?string $lastSeen): bool => $since !== null && $lastSeen !== null && Carbon::parse($lastSeen)->gte($since);
        $scope = static function ($q) use ($history, $since) {
            if (! $history && $since !== null) {
                $q->where('last_seen_at', '>=', $since);
            }
        };

        $ifaces = NetworkInterface::where('device_id', $device->id)->get(['id', 'if_index', 'name', 'description'])->keyBy('id');
        $iface = static fn ($id) => $id !== null && isset($ifaces[$id])
            ? ['id' => (int) $id, 'name' => $ifaces[$id]->name, 'description' => $ifaces[$id]->description]
            : null;

        $neighbours = DB::table('neighbour_sightings')->where('device_id', $device->id)->where($scope)
            ->orderBy('local_if_index')->orderBy('protocol')->orderByDesc('last_seen_at')->get();
        $fdb = DB::table('fdb_sightings')->where('device_id', $device->id)->where($scope)
            ->orderBy('port')->orderBy('mac')->limit(self::MAX_FDB_ROWS + 1)->get();
        $truncated = $fdb->count() > self::MAX_FDB_ROWS;
        $fdb = $fdb->take(self::MAX_FDB_ROWS);
        $arp = DB::table('arp_sightings')->where('device_id', $device->id)->where($scope)
            ->select(['*', DB::raw('host(ip) as ip_text')])->orderBy('ip')->get();
        $addresses = DB::table('device_addresses')->where('device_id', $device->id)->where($scope)
            ->select(['*', DB::raw('host(ip) as ip_text')])->orderBy('ip')->get();

        // MAC -> IP for the MACs behind its ports, from every device's ARP (newest wins).
        $macToIp = $this->macToIp($fdb->pluck('mac')->unique()->values()->all());

        // Everything to match against devices, in two batched lookups.
        $ips = array_values(array_unique(array_filter([
            ...$neighbours->pluck('remote_ip')->all(),
            ...$arp->pluck('ip_text')->all(),
            ...array_values($macToIp),
        ])));
        $macs = array_values(array_unique(array_filter([
            ...$neighbours->pluck('remote_mac')->all(),
            ...$fdb->pluck('mac')->all(),
            ...$arp->pluck('mac')->all(),
        ])));
        $byIp = $this->devicesByIp($ips, $device->id);
        $byMac = $this->devicesByMac($macs, $device->id);
        $match = static fn (?string $ip, ?string $mac) => ($ip !== null ? ($byIp[$ip] ?? null) : null) ?? ($mac !== null ? ($byMac[$mac] ?? null) : null);

        $ports = [];
        foreach ($fdb as $row) {
            $key = $row->interface_id !== null ? 'i'.$row->interface_id : 'p'.$row->port;
            $ports[$key] ??= [
                'interface' => $iface($row->interface_id),
                'port' => (int) $row->port,
                'if_index' => $row->if_index !== null ? (int) $row->if_index : null,
                'current_count' => 0,
                'macs' => [],
            ];
            $ip = $macToIp[$row->mac] ?? null;
            $current = $isCurrent($row->last_seen_at);
            $ports[$key]['current_count'] += $current ? 1 : 0;
            $ports[$key]['macs'][] = [
                'mac' => $row->mac,
                'ip' => $ip,
                'device' => $match($ip, $row->mac),
                'first_seen_at' => self::iso($row->first_seen_at),
                'last_seen_at' => self::iso($row->last_seen_at),
                'current' => $current,
            ];
        }
        // Busiest ports (uplinks) first.
        usort($ports, static fn ($a, $b) => [$b['current_count'], $a['port']] <=> [$a['current_count'], $b['port']]);

        return [
            'device_id' => $device->id,
            'supported' => $device->poll_method === PollMethod::Snmp && $device->agent_id === null,
            'enabled' => (bool) $this->settings->getInt('neighbours.collect', 1),
            'interval_s' => max(600, $this->settings->getInt('neighbours.interval', 3600)),
            'collected_at' => $collectedAt?->toIso8601String(),
            'error' => $device->neighbours_error,
            'history' => $history,
            'truncated' => $truncated,
            'addresses' => $addresses->map(fn ($a) => [
                'ip' => $a->ip_text,
                'prefix_length' => $a->prefix_length !== null ? (int) $a->prefix_length : null,
                'interface' => $iface($a->interface_id),
                'current' => $isCurrent($a->last_seen_at),
            ])->values()->all(),
            'neighbours' => $neighbours->map(fn ($n) => [
                'protocol' => $n->protocol,
                'interface' => $iface($n->interface_id),
                'local_if_index' => (int) $n->local_if_index,
                'remote_name' => $n->remote_name,
                'remote_ip' => $n->remote_ip,
                'remote_mac' => $n->remote_mac,
                'remote_chassis_id' => $n->remote_chassis_id,
                'remote_port_id' => $n->remote_port_id,
                'remote_port_description' => $n->remote_port_description,
                'remote_platform' => $n->remote_platform,
                'remote_version' => $n->remote_version,
                'remote_capabilities' => $n->remote_capabilities !== null ? json_decode($n->remote_capabilities, true) : null,
                'device' => $match($n->remote_ip, $n->remote_mac),
                'first_seen_at' => self::iso($n->first_seen_at),
                'last_seen_at' => self::iso($n->last_seen_at),
                'current' => $isCurrent($n->last_seen_at),
            ])->values()->all(),
            'ports' => array_values($ports),
            'arp' => $arp->map(fn ($a) => [
                'ip' => $a->ip_text,
                'mac' => $a->mac,
                'interface' => $iface($a->interface_id),
                'device' => $match($a->ip_text, $a->mac),
                'first_seen_at' => self::iso($a->first_seen_at),
                'last_seen_at' => self::iso($a->last_seen_at),
                'current' => $isCurrent($a->last_seen_at),
            ])->values()->all(),
        ];
    }

    /**
     * @param  list<string>  $macs
     * @return array<string, string> mac => ip
     */
    private function macToIp(array $macs): array
    {
        if ($macs === []) {
            return [];
        }
        $out = [];
        $rows = DB::table('arp_sightings')
            ->whereRaw('mac = any(?::macaddr[])', [self::pgArray($macs)])
            ->orderByDesc('last_seen_at')
            ->get(['mac', DB::raw('host(ip) as ip_text')]);
        foreach ($rows as $r) {
            $out[$r->mac] ??= $r->ip_text;
        }

        return $out;
    }

    /**
     * Devices by management IP, then by any collected address. Excludes the device itself.
     *
     * @param  list<string>  $ips
     * @return array<string, array{id: int, name: string}>
     */
    private function devicesByIp(array $ips, int $self): array
    {
        if ($ips === []) {
            return [];
        }
        $out = [];
        foreach (Device::whereRaw('mgmt_ip = any(?::text[])', [self::pgArray($ips)])->where('id', '!=', $self)->get(['id', 'name', 'mgmt_ip']) as $d) {
            $out[$d->mgmt_ip] ??= ['id' => $d->id, 'name' => $d->name];
        }
        $rows = DB::table('device_addresses')->join('devices', 'devices.id', '=', 'device_addresses.device_id')
            ->whereRaw('device_addresses.ip = any(?::inet[])', [self::pgArray($ips)])
            ->where('devices.id', '!=', $self)
            ->get(['devices.id', 'devices.name', DB::raw('host(device_addresses.ip) as ip_text')]);
        foreach ($rows as $r) {
            $out[$r->ip_text] ??= ['id' => (int) $r->id, 'name' => $r->name];
        }

        return $out;
    }

    /**
     * Devices by interface MAC. A MAC shared by several devices (a virtual MAC, say) is ambiguous
     * and left unmatched. Excludes the device itself.
     *
     * @param  list<string>  $macs
     * @return array<string, array{id: int, name: string}>
     */
    private function devicesByMac(array $macs, int $self): array
    {
        if ($macs === []) {
            return [];
        }
        $found = [];
        $rows = DB::table('interfaces')->join('devices', 'devices.id', '=', 'interfaces.device_id')
            ->whereRaw('interfaces.mac = any(?::macaddr[])', [self::pgArray($macs)])
            ->where('devices.id', '!=', $self)
            ->distinct()->get(['interfaces.mac', 'devices.id', 'devices.name']);
        foreach ($rows as $r) {
            $found[$r->mac][(int) $r->id] = ['id' => (int) $r->id, 'name' => $r->name];
        }

        return array_map(static fn (array $devices) => reset($devices), array_filter($found, static fn (array $d) => count($d) === 1));
    }

    /** @param  list<string>  $values  already-validated IPs or MACs (no quotes or commas) */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', $values).'}';
    }

    private static function iso(?string $ts): ?string
    {
        return $ts !== null ? Carbon::parse($ts)->toIso8601String() : null;
    }
}
