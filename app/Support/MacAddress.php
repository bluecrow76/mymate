<?php

namespace App\Support;

/**
 * Normalise a MAC address from whatever form a device or SNMP build hands it over in, to the
 * lower-case colon form Postgres `macaddr` prints ("8c:ed:e1:4c:38:8f"). Seen in the field:
 *
 *  - "8C ED E1 4C 38 8F"  ext-snmp Hex-STRING once its type prefix is stripped (trailing space too)
 *  - "8C:ED:E1:4C:38:8F"  RouterOS LLDP chassis/port ids, sent as 17-byte text
 *  - "0:e0:4c:68:1:2"     net-snmp's STRING form drops leading zeros
 *  - six raw bytes        an OCTET STRING when the value comes back unformatted
 *  - "8ced.e14c.388f" / "8cede14c388f" / "0x8CEDE14C388F" / dash-separated
 *
 * All-zero MACs (an unset ifPhysAddress, eg a loopback) are treated as no MAC.
 */
final class MacAddress
{
    /**
     * @param  bool  $allowBytes  read a 6-byte value as raw octets. Only for fields that are
     *                            known to hold a MAC: a 6-character text field (an LLDP chassis
     *                            id that's a hostname, say) would otherwise be misread as one.
     */
    public static function normalise(?string $raw, bool $allowBytes = true): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        // Six raw bytes. A real hex rendering is never 6 characters, so this can't swallow one.
        if (strlen($raw) === 6) {
            if (! $allowBytes) {
                return null;
            }

            return self::fromBytes($raw);
        }

        $s = strtolower(trim($raw));
        if (str_starts_with($s, '0x')) {
            $s = substr($s, 2);
        }

        // Separated groups of one or two hex digits: ':' '-' ' ' (1-2 digits each, 6 groups).
        $groups = preg_split('/[\s:\-]+/', $s) ?: [];
        if (count($groups) === 6 && self::allHex($groups, 2)) {
            return self::join(array_map(static fn (string $g): string => str_pad($g, 2, '0', STR_PAD_LEFT), $groups));
        }

        // Cisco dotted (three groups of four) or a bare run of 12 hex digits.
        $bare = str_replace('.', '', $s);
        if (preg_match('/^[0-9a-f]{12}$/', $bare) === 1) {
            return self::join(str_split($bare, 2));
        }

        return null;
    }

    /** A MAC from the six decimal octets an OID index carries ("140.237.225.76.56.143"). */
    public static function fromOidOctets(array $octets): ?string
    {
        if (count($octets) !== 6) {
            return null;
        }
        $hex = [];
        foreach ($octets as $o) {
            if (! is_numeric($o) || (int) $o < 0 || (int) $o > 255) {
                return null;
            }
            $hex[] = sprintf('%02x', (int) $o);
        }

        return self::join($hex);
    }

    private static function fromBytes(string $bytes): ?string
    {
        return self::join(str_split(bin2hex($bytes), 2));
    }

    /** @param  list<string>  $parts  six two-digit lower-case hex groups */
    private static function join(array $parts): ?string
    {
        $mac = implode(':', $parts);

        return $mac === '00:00:00:00:00:00' ? null : $mac;
    }

    /** @param  list<string>  $groups */
    private static function allHex(array $groups, int $maxLen): bool
    {
        foreach ($groups as $g) {
            if ($g === '' || strlen($g) > $maxLen || ! ctype_xdigit($g)) {
                return false;
            }
        }

        return true;
    }
}
