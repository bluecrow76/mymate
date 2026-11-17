<?php

namespace App\Services\Neighbours;

use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpClientException;
use App\Services\Snmp\SnmpCredential;
use App\Support\MacAddress;

/**
 * Read what a device can see of its neighbours over SNMP: LLDP and MikroTik MNDP neighbours,
 * the bridge forwarding table, ARP, and the device's own interface MACs and IP addresses.
 *
 * Big tables are walked a column at a time, only the columns used (the full ARP table on a busy
 * router is ~3,200 values where the two columns needed are ~1,600). Small tables (LLDP, MNDP)
 * are walked whole and split by column. A table the device doesn't implement comes back empty
 * (SnmpClient treats noSuchObject / end-of-MIB as "nothing here"); a transport failure throws
 * SnmpClientException straight away, so an unreachable device costs one timeout, not ten.
 *
 * Field notes this parser is built on (tested on RouterOS 7.24/7.25 and UniFi USW, Nov 2026):
 *  - RouterOS sends LLDP chassis/port ids as 17-character text ("74:FA:29:67:D6:09"), not raw
 *    bytes; MACs elsewhere come as Hex-STRING. MacAddress::normalise() takes every form.
 *  - lldpRemSysCapEnabled is BITS - one byte such as 0x28 (bridge + router), which ext-snmp may
 *    hand over as the character "(" or as hex text "28".
 *  - UniFi switches have no LLDP-MIB and no dot1dBasePortTable (their FDB port == ifIndex);
 *    RouterOS has the table and its bridge ports are NOT ifIndexes (bport 13 = ifIndex 19).
 *  - The FDB is read from dot1dTpFdbPort, not Q-BRIDGE: on UniFi the Q-BRIDGE table walked from
 *    its column silently skips whole VLANs (125 of ~245 rows), while dot1dTpFdb was complete on
 *    every device tested. So no VLAN for now - it isn't needed to place physical links.
 *  - UniFi SNMP agents are slow (~25-50 ms per value; a 240-MAC table takes 5-12s) and the
 *    smallest one stopped answering under large GETBULK requests, so the client's default bulk
 *    size is kept. Neighbour reads run on their own queue for that reason.
 *  - RouterOS rebuilds its FDB for SNMP on demand: the first dot1dTpFdbPort walk after a pause
 *    stops early with no error (17 of ~240 rows), and walks over the next second or so can still
 *    be short while it rebuilds. So a fast FDB walk is repeated after a pause, and once more if
 *    the two disagree, and the results merged (see FDB_CONFIRM_BELOW_S).
 *    Sightings tolerate any short read that's left: rows not seen just aren't refreshed.
 *  - ARP rows of type invalid(2) are failed lookups (often with an empty MAC) - dropped.
 *  - MNDP rows over VPN tunnels repeat the same neighbour many times - de-duplicated by key.
 *  - The MNDP table can change mid-walk, leaving rows with only some columns - tolerated.
 */
class SnmpNeighbourReader
{
    // Small tables, walked whole (row keys are "1.<column>.<index>").
    private const LLDP_REM = '.1.0.8802.1.1.2.1.4.1';          // lldpRemTable

    private const LLDP_REM_MAN_ADDR = '.1.0.8802.1.1.2.1.4.2'; // lldpRemManAddrTable

    private const MNDP = '.1.3.6.1.4.1.14988.1.1.11.1';         // MIKROTIK-MIB mtxrNeighborTable

    // Single columns (row keys are the index).
    private const IF_PHYS_ADDRESS = '.1.3.6.1.2.1.2.2.1.6';     // ifPhysAddress

    private const IP_AD_IF_INDEX = '.1.3.6.1.2.1.4.20.1.2';     // ipAdEntIfIndex (index = address)

    private const IP_AD_NET_MASK = '.1.3.6.1.2.1.4.20.1.3';     // ipAdEntNetMask

    private const BASE_PORT_IF_INDEX = '.1.3.6.1.2.1.17.1.4.1.2'; // dot1dBasePortIfIndex

    private const FDB_PORT = '.1.3.6.1.2.1.17.4.3.1.2';         // dot1dTpFdbPort (index = MAC octets)

    private const ARP_PHYS_ADDRESS = '.1.3.6.1.2.1.4.22.1.2';   // ipNetToMediaPhysAddress (index = ifIndex.ip)

    private const ARP_TYPE = '.1.3.6.1.2.1.4.22.1.4';           // ipNetToMediaType

    /**
     * An FDB walk that finished faster than this is confirmed by walking it again after
     * $confirmDelayS (twice if the first two disagree by more than 5%) and merging the results.
     * Covers RouterOS's cut-short walks while it rebuilds the table, for a few seconds on a
     * router. The slow UniFi agents (5-12s per walk) don't have the problem and never pay for it.
     */
    private const FDB_CONFIRM_BELOW_S = 3.0;

    /** LLDP system capability bits, most significant bit first (IEEE 802.1AB). */
    private const LLDP_CAPABILITIES = ['other', 'repeater', 'bridge', 'wlan-ap', 'router', 'telephone', 'docsis', 'station'];

    /** @param  float  $confirmDelayS  pause before an FDB confirmation walk (0 in tests) */
    public function __construct(private SnmpClient $snmp, private float $confirmDelayS = 1.0) {}

    /**
     * @param  bool  $mikrotik  also read the MikroTik MNDP table (skipped for other vendors)
     *
     * @throws SnmpClientException when the device doesn't answer
     */
    public function read(string $host, SnmpCredential $cred, bool $mikrotik = true, int $maxRows = 20000): NeighbourSnapshot
    {
        $snap = new NeighbourSnapshot;

        $ifMacs = $this->snmp->walk($host, $cred, self::IF_PHYS_ADDRESS);
        foreach ($ifMacs as $ifIndex => $raw) {
            $mac = MacAddress::normalise($raw);
            if ($mac !== null && is_numeric($ifIndex)) {
                $snap->interfaceMacs[(int) $ifIndex] = $mac;
            }
        }
        // Known ifIndexes (ifPhysAddress has a row per interface, MAC or not) - used to accept
        // an FDB port as an ifIndex on devices without a dot1dBasePortTable.
        $ifIndexes = array_map('intval', array_filter(array_keys($ifMacs), 'is_numeric'));

        $snap->addresses = $this->addresses(
            $this->snmp->walk($host, $cred, self::IP_AD_IF_INDEX),
            $this->snmp->walk($host, $cred, self::IP_AD_NET_MASK),
        );
        $snap->neighbours = [
            ...$this->lldp($this->table($host, $cred, self::LLDP_REM), $this->table($host, $cred, self::LLDP_REM_MAN_ADDR)),
            ...($mikrotik ? $this->mndp($this->table($host, $cred, self::MNDP)) : []),
        ];

        $fdbPorts = $this->fdbPorts($host, $cred);
        $basePorts = [];
        if ($fdbPorts !== []) {
            foreach ($this->snmp->walk($host, $cred, self::BASE_PORT_IF_INDEX) as $port => $ifIndex) {
                $basePorts[(int) $port] = (int) $ifIndex;
            }
        }
        $snap->fdb = $this->cap($this->fdb($fdbPorts, $basePorts, $ifIndexes), 'fdb', $maxRows, $snap);

        $arpMacs = $this->snmp->walk($host, $cred, self::ARP_PHYS_ADDRESS);
        $arpTypes = $arpMacs !== [] ? $this->snmp->walk($host, $cred, self::ARP_TYPE) : [];
        $snap->arp = $this->cap($this->arp($arpMacs, $arpTypes), 'arp', $maxRows, $snap);

        return $snap;
    }

    /**
     * dot1dTpFdbPort, confirmed on fast agents (see FDB_CONFIRM_BELOW_S): every walk's rows are
     * merged, so a short walk can only add nothing, never remove what another walk saw.
     *
     * @return array<string, string>
     */
    private function fdbPorts(string $host, SnmpCredential $cred): array
    {
        $started = microtime(true);
        $ports = $this->snmp->walk($host, $cred, self::FDB_PORT);
        if ($ports === [] || microtime(true) - $started >= self::FDB_CONFIRM_BELOW_S) {
            return $ports;
        }

        $previous = count($ports);
        for ($pass = 0; $pass < 2; $pass++) {
            usleep((int) ($this->confirmDelayS * 1_000_000));
            $again = $this->snmp->walk($host, $cred, self::FDB_PORT);
            $ports += $again;
            if (abs(count($again) - $previous) <= max(1, (int) ($previous * 0.05))) {
                break; // two walks agree - the table has settled
            }
            $previous = count($again);
        }

        return $ports;
    }

    /**
     * Walk a table and split it by column: [column => [index => value]].
     *
     * @return array<int, array<string, string>>
     */
    private function table(string $host, SnmpCredential $cred, string $baseOid): array
    {
        $cols = [];
        foreach ($this->snmp->walk($host, $cred, $baseOid) as $suffix => $value) {
            // "1.<col>.<index...>" - the leading 1 is the table's entry node.
            $parts = explode('.', ltrim((string) $suffix, '.'), 3);
            if (count($parts) === 3 && $parts[0] === '1' && ctype_digit($parts[1])) {
                $cols[(int) $parts[1]][$parts[2]] = $value;
            }
        }

        return $cols;
    }

    /**
     * @param  array<string, string>  $ifIndexes  address => ifIndex
     * @param  array<string, string>  $masks  address => netmask
     * @return list<array{ip: string, if_index: ?int, prefix_length: ?int}>
     */
    private function addresses(array $ifIndexes, array $masks): array
    {
        $out = [];
        foreach ($ifIndexes as $ip => $ifIndex) {
            $ip = (string) $ip;
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || str_starts_with($ip, '127.') || $ip === '0.0.0.0') {
                continue;
            }
            $out[] = [
                'ip' => $ip,
                'if_index' => is_numeric($ifIndex) ? (int) $ifIndex : null,
                'prefix_length' => self::prefixLength($masks[$ip] ?? null),
            ];
        }

        return $out;
    }

    /**
     * lldpRemTable, index timeMark.localPortNum.remIndex. Columns: 4 chassis id subtype,
     * 5 chassis id, 6 port id subtype, 7 port id, 8 port description, 9 system name, 10 system
     * description, 12 enabled capabilities. lldpRemLocalPortNum is the local ifIndex on the
     * devices tested (RouterOS); the management address comes from lldpRemManAddrTable.
     *
     * @return list<array<string, mixed>>
     */
    private function lldp(array $cols, array $manAddr): array
    {
        $ips = $this->lldpManagementIps($manAddr);
        $out = [];
        foreach ($cols[5] ?? [] as $index => $chassisRaw) {
            $parts = explode('.', $index);
            if (count($parts) !== 3) {
                continue;
            }
            $localPort = (int) $parts[1];
            $remKey = "{$parts[0]}.{$parts[1]}.{$parts[2]}";

            // Chassis subtype 4 = macAddress: then the value may be raw bytes. Any other subtype is
            // text, but RouterOS sends its MAC as text too, so try that without the raw-byte form.
            $chassisMac = MacAddress::normalise($chassisRaw, (int) ($cols[4][$index] ?? 0) === 4);
            $chassisId = $chassisMac ?? self::text($chassisRaw);
            $portRaw = $cols[7][$index] ?? null;
            $portMac = (int) ($cols[6][$index] ?? 0) === 3 ? MacAddress::normalise($portRaw) : null;
            $portId = $portMac ?? self::text($portRaw);

            if ($chassisId === null && $portId === null) {
                continue;
            }

            $out[] = [
                'protocol' => 'lldp',
                'local_if_index' => $localPort,
                // Chassis and port together: the same neighbour on a different port of its own is a
                // different sighting, which is exactly the change worth keeping.
                'remote_key' => self::key(($chassisId ?? '?').' '.($portId ?? '?')),
                'chassis_id' => $chassisId,
                'mac' => $chassisMac,
                'ip' => $ips[$remKey] ?? null,
                'name' => self::text($cols[9][$index] ?? null),
                'port_id' => $portId,
                'port_description' => self::text($cols[8][$index] ?? null),
                'platform' => null,
                'version' => self::text($cols[10][$index] ?? null),
                'capabilities' => self::capabilities($cols[12][$index] ?? null),
            ];
        }

        return $this->unique($out);
    }

    /**
     * lldpRemManAddrTable index: timeMark.localPortNum.remIndex.addrSubtype.addrLen.addr...
     * Only IPv4 (subtype 1, length 4) is kept: [timeMark.localPort.remIndex => ip].
     *
     * @return array<string, string>
     */
    private function lldpManagementIps(array $cols): array
    {
        $ips = [];
        foreach ($cols as $column) {
            foreach (array_keys($column) as $index) {
                $p = explode('.', (string) $index);
                if (count($p) === 9 && $p[3] === '1' && $p[4] === '4') {
                    $ip = implode('.', array_slice($p, 5, 4));
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ips["{$p[0]}.{$p[1]}.{$p[2]}"] ??= $ip;
                    }
                }
            }
        }

        return $ips;
    }

    /**
     * MIKROTIK-MIB mtxrNeighborTable, index = row number. Columns: 2 IP, 3 MAC, 4 version,
     * 5 platform, 6 identity, 7 software id, 8 local ifIndex. No remote port.
     *
     * @return list<array<string, mixed>>
     */
    private function mndp(array $cols): array
    {
        $rows = [];
        foreach ($cols as $column) {
            foreach (array_keys($column) as $index) {
                $rows[$index] = true;
            }
        }

        $out = [];
        foreach (array_keys($rows) as $index) {
            $ip = $cols[2][$index] ?? null;
            $ip = $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
            $mac = MacAddress::normalise($cols[3][$index] ?? null);
            $identity = self::text($cols[6][$index] ?? null);
            $key = $mac ?? $identity ?? $ip;
            if ($key === null) {
                continue; // a half-read row with nothing to identify it
            }
            $out[] = [
                'protocol' => 'mndp',
                'local_if_index' => (int) ($cols[8][$index] ?? 0),
                'remote_key' => self::key($key),
                'chassis_id' => null,
                'mac' => $mac,
                'ip' => $ip,
                'name' => $identity,
                'port_id' => null,
                'port_description' => null,
                'platform' => self::text($cols[5][$index] ?? null),
                'version' => self::text($cols[4][$index] ?? null),
                'capabilities' => null,
            ];
        }

        return $this->unique($out);
    }

    /**
     * dot1dTpFdbPort, index = the MAC's six octets, value = bridge port. Port 0 means the port
     * isn't known (RouterOS reports its own MACs that way) and is skipped. The status column
     * isn't read - it costs as much again on a slow agent, and the device's own MACs it would
     * flag are already known from ifPhysAddress.
     *
     * @param  array<string, string>  $ports  mac octets => bridge port
     * @param  array<int, int>  $basePorts  bridge port => ifIndex
     * @param  list<int>  $ifIndexes
     * @return list<array{port: int, if_index: ?int, mac: string, vlan: int}>
     */
    private function fdb(array $ports, array $basePorts, array $ifIndexes): array
    {
        $known = array_flip($ifIndexes);
        $out = [];
        foreach ($ports as $index => $port) {
            if (! is_numeric($port) || (int) $port <= 0) {
                continue;
            }
            $mac = MacAddress::fromOidOctets(explode('.', (string) $index));
            if ($mac === null) {
                continue;
            }
            $port = (int) $port;
            $out[] = [
                'port' => $port,
                'if_index' => $basePorts[$port] ?? (isset($known[$port]) ? $port : null),
                'mac' => $mac,
                'vlan' => 0, // not read yet - see the class notes on Q-BRIDGE
            ];
        }

        return $out;
    }

    /**
     * ipNetToMediaTable, index ifIndex.ip. Only dynamic (3) and static (4) entries are real;
     * invalid (2) are failed lookups, often still carrying a stale MAC.
     *
     * @param  array<string, string>  $macs  ifIndex.ip => MAC
     * @param  array<string, string>  $types  ifIndex.ip => type
     * @return list<array{ip: string, mac: string, if_index: ?int}>
     */
    private function arp(array $macs, array $types): array
    {
        $out = [];
        foreach ($macs as $index => $raw) {
            $type = $types[$index] ?? null;
            if ($type !== null && ! in_array((int) $type, [3, 4], true)) {
                continue;
            }
            $p = explode('.', (string) $index, 2);
            if (count($p) !== 2 || ! filter_var($p[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }
            $mac = MacAddress::normalise($raw);
            if ($mac === null) {
                continue;
            }
            $out[$p[1].' '.$mac] = ['ip' => $p[1], 'mac' => $mac, 'if_index' => (int) $p[0]];
        }

        return array_values($out);
    }

    /**
     * @template T
     *
     * @param  list<T>  $rows
     * @return list<T>
     */
    private function cap(array $rows, string $table, int $max, NeighbourSnapshot $snap): array
    {
        if ($max > 0 && count($rows) > $max) {
            $snap->truncated[] = $table;

            return array_slice($rows, 0, $max);
        }

        return $rows;
    }

    /**
     * One row per (protocol, local port, remote key) - later rows fill in what earlier ones lacked.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function unique(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $k = "{$row['protocol']}|{$row['local_if_index']}|{$row['remote_key']}";
            $out[$k] = isset($out[$k])
                ? array_merge($row, array_filter($out[$k], static fn ($v) => $v !== null))
                : $row;
        }

        return array_values($out);
    }

    /** Decode LLDP BITS: one or two bytes, raw ("(") or as hex text ("28" / "28 00"). */
    private static function capabilities(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $hex = trim($raw);
        $bytes = preg_match('/^([0-9a-fA-F]{2}\s*)+$/', $hex) === 1 && strlen($hex) >= 2
            ? (string) hex2bin(preg_replace('/\s+/', '', $hex))
            : $raw;
        $first = ord($bytes[0] ?? "\0");
        $caps = [];
        foreach (self::LLDP_CAPABILITIES as $bit => $name) {
            if ($first & (0x80 >> $bit)) {
                $caps[] = $name;
            }
        }

        return $caps === [] ? null : $caps;
    }

    /** CIDR prefix length from a dotted netmask ("255.255.255.0" -> 24); null if not a mask. */
    private static function prefixLength(?string $mask): ?int
    {
        if ($mask === null || ! filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        $bits = decbin((int) ip2long($mask));

        return preg_match('/^1*0*$/', str_pad($bits, 32, '0', STR_PAD_LEFT)) === 1 ? substr_count($bits, '1') : null;
    }

    /** Printable text, trimmed and capped to the column width; null when empty or binary. */
    private static function text(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = trim($raw);
        if ($s === '' || ! mb_check_encoding($s, 'UTF-8') || preg_match('/[\x00-\x08\x0E-\x1F]/', $s) === 1) {
            return null;
        }

        return mb_substr($s, 0, 255);
    }

    private static function key(string $s): string
    {
        return mb_substr($s, 0, 255);
    }
}
