<?php

namespace App\Actions\Neighbours;

use App\Enums\PollMethod;
use App\Models\Device;
use App\Services\Neighbours\NeighbourSnapshot;
use App\Services\Neighbours\SnmpNeighbourReader;
use App\Services\Snmp\SnmpCredential;
use App\Support\EngineLog;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read a device's neighbour data (LLDP, MNDP, bridge FDB, ARP, interface MACs and addresses)
 * and record it as sightings. Runs on the interface-discovery cadence, after the interfaces
 * themselves, so ifIndexes resolve to interface rows.
 *
 * Sightings are only ever inserted or refreshed: an upsert on each table's identity columns
 * sets first_seen_at once and moves last_seen_at on every later sighting. Something that stops
 * being seen keeps its old row (history) until the daily prune drops it. So a failed or partial
 * read costs nothing - rows just aren't refreshed that cycle.
 *
 * Best-effort and never throws, like CaptureDeviceFacts: a device without LLDP, a slow walk or
 * an unreachable host must not break a discovery tick. Only SNMP-polled devices on this server
 * for now (RouterOS-API and agent devices are later stages). Secrets are never logged.
 */
class CollectNeighbourData
{
    private const CHUNK = 500;

    public function __construct(
        private SnmpNeighbourReader $reader,
        private Settings $settings,
    ) {}

    public function __invoke(Device $device): void
    {
        if (! $this->settings->getInt('neighbours.collect', 1)
            || $device->poll_method !== PollMethod::Snmp
            || $device->agent_id !== null
            || ! $device->mgmt_ip) {
            return;
        }

        $device->loadMissing('credential');
        $cred = SnmpCredential::fromCredential($device->credential);
        if (! $cred->isUsable()) {
            return;
        }

        // MNDP is MikroTik-only: skip that walk for a device known to be something else.
        $vendor = strtolower((string) $device->vendor);
        $mikrotik = $vendor === '' || str_contains($vendor, 'mikrotik') || str_contains($vendor, 'routeros');
        $maxRows = (int) config('mymate.neighbours.max_rows_per_table', 20000);

        try {
            $snap = $this->reader->read($device->mgmt_ip, $cred, $mikrotik, $maxRows);
        } catch (Throwable $e) {
            $this->finish($device, class_basename($e).': '.$e->getMessage());
            EngineLog::debug('neighbours: read failed', ['device_id' => $device->id, 'device' => $device->name, 'error' => $e->getMessage()]);

            return;
        }

        try {
            DB::transaction(fn () => $this->store($device, $snap, now()));
        } catch (Throwable $e) {
            $this->finish($device, 'Storing the neighbour data failed: '.class_basename($e));
            EngineLog::error('neighbours: store failed', ['device_id' => $device->id, 'device' => $device->name, 'exception' => $e::class, 'error' => $e->getMessage()]);

            return;
        }

        $this->finish($device, null);
        if ($snap->truncated !== []) {
            EngineLog::warning('neighbours: table capped', ['device_id' => $device->id, 'device' => $device->name, 'tables' => $snap->truncated, 'max_rows' => $maxRows]);
        }
        EngineLog::debug('neighbours: collected', [
            'device_id' => $device->id,
            'device' => $device->name,
            'neighbours' => count($snap->neighbours),
            'fdb' => count($snap->fdb),
            'arp' => count($snap->arp),
            'addresses' => count($snap->addresses),
        ]);
    }

    private function store(Device $device, NeighbourSnapshot $snap, Carbon $now): void
    {
        /** @var array<int, int> $ifaceIds ifIndex => interface id */
        $ifaceIds = $device->interfaces()->pluck('id', 'if_index')->all();
        $iface = static fn (?int $ifIndex): ?int => $ifIndex !== null ? ($ifaceIds[$ifIndex] ?? null) : null;
        $seen = ['first_seen_at' => $now, 'last_seen_at' => $now];

        // The interfaces' own MACs - written only where they changed.
        foreach ($device->interfaces()->get(['id', 'if_index', 'mac']) as $row) {
            $mac = $snap->interfaceMacs[$row->if_index] ?? null;
            if ($mac !== null && $row->mac !== $mac) {
                $row->forceFill(['mac' => $mac])->save();
            }
        }

        $this->upsert('device_addresses', array_map(fn (array $a) => [
            'device_id' => $device->id,
            'interface_id' => $iface($a['if_index']),
            'ip' => $a['ip'],
            'prefix_length' => $a['prefix_length'],
        ] + $seen, $snap->addresses), ['device_id', 'ip'], ['interface_id', 'prefix_length']);

        $this->upsert('neighbour_sightings', array_map(fn (array $n) => [
            'device_id' => $device->id,
            'interface_id' => $iface($n['local_if_index'] ?: null),
            'local_if_index' => $n['local_if_index'],
            'protocol' => $n['protocol'],
            'remote_key' => $n['remote_key'],
            'remote_chassis_id' => $n['chassis_id'],
            'remote_mac' => $n['mac'],
            'remote_ip' => $n['ip'],
            'remote_name' => $n['name'],
            'remote_port_id' => $n['port_id'],
            'remote_port_description' => $n['port_description'],
            'remote_platform' => $n['platform'],
            'remote_version' => $n['version'],
            'remote_capabilities' => $n['capabilities'] !== null ? json_encode($n['capabilities']) : null,
        ] + $seen, $snap->neighbours), ['device_id', 'protocol', 'local_if_index', 'remote_key'], [
            'interface_id', 'remote_chassis_id', 'remote_mac', 'remote_ip', 'remote_name', 'remote_port_id',
            'remote_port_description', 'remote_platform', 'remote_version', 'remote_capabilities',
        ]);

        $this->upsert('fdb_sightings', array_map(fn (array $f) => [
            'device_id' => $device->id,
            'interface_id' => $iface($f['if_index']),
            'port' => $f['port'],
            'if_index' => $f['if_index'],
            'mac' => $f['mac'],
            'vlan' => $f['vlan'],
        ] + $seen, $snap->fdb), ['device_id', 'mac', 'vlan', 'port'], ['interface_id', 'if_index']);

        $this->upsert('arp_sightings', array_map(fn (array $a) => [
            'device_id' => $device->id,
            'interface_id' => $iface($a['if_index']),
            'if_index' => $a['if_index'],
            'ip' => $a['ip'],
            'mac' => $a['mac'],
        ] + $seen, $snap->arp), ['device_id', 'ip', 'mac'], ['interface_id', 'if_index']);
    }

    /**
     * Insert new rows; for existing ones refresh $update + last_seen_at and leave first_seen_at.
     * Rows are de-duplicated on the key first - Postgres refuses an upsert that would touch the
     * same row twice in one statement.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $key
     * @param  list<string>  $update
     */
    private function upsert(string $table, array $rows, array $key, array $update): void
    {
        $unique = [];
        foreach ($rows as $row) {
            $unique[implode("\x1f", array_map(static fn (string $k) => (string) $row[$k], $key))] = $row;
        }
        foreach (array_chunk(array_values($unique), self::CHUNK) as $chunk) {
            DB::table($table)->upsert($chunk, $key, [...$update, 'last_seen_at']);
        }
    }

    private function finish(Device $device, ?string $error): void
    {
        $device->forceFill([
            'neighbours_collected_at' => now(),
            'neighbours_error' => $error !== null ? mb_substr($error, 0, 500) : null,
        ])->save();
    }
}
