<?php

namespace Tests\Unit;

use App\Support\MacAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MacAddressTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function forms(): array
    {
        return [
            // ext-snmp Hex-STRING after its type prefix is stripped, trailing space and all
            'hex string with spaces' => ['8C ED E1 4C 38 8F ', '8c:ed:e1:4c:38:8f'],
            // RouterOS LLDP chassis ids arrive as 17 characters of text
            'routeros text' => ['74:FA:29:1C:0C:1C', '74:fa:29:1c:0c:1c'],
            'net-snmp short groups' => ['0:e0:4c:68:1:2', '00:e0:4c:68:01:02'],
            'dashes' => ['8C-ED-E1-4C-38-8F', '8c:ed:e1:4c:38:8f'],
            'cisco dotted' => ['8ced.e14c.388f', '8c:ed:e1:4c:38:8f'],
            'bare hex' => ['8CEDE14C388F', '8c:ed:e1:4c:38:8f'],
            '0x prefix' => ['0x8CEDE14C388F', '8c:ed:e1:4c:38:8f'],
            'raw bytes' => ["\x8c\xed\xe1\x4c\x38\x8f", '8c:ed:e1:4c:38:8f'],
            'all zero is no mac' => ['00 00 00 00 00 00 ', null],
            'empty' => ['', null],
            'null' => [null, null],
            'not a mac' => ['Port 25', null],
            'too few groups' => ['8C:ED:E1:4C:38', null],
        ];
    }

    #[DataProvider('forms')]
    public function test_normalises_every_form_devices_send(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, MacAddress::normalise($raw));
    }

    public function test_six_character_text_is_not_read_as_bytes_when_bytes_are_not_allowed(): void
    {
        // An LLDP chassis id of subtype "local" can be a short hostname.
        $this->assertSame('73:77:69:74:63:68', MacAddress::normalise('switch'));
        $this->assertNull(MacAddress::normalise('switch', allowBytes: false));
    }

    public function test_reads_the_mac_from_oid_index_octets(): void
    {
        $this->assertSame('04:f4:1c:39:e8:b4', MacAddress::fromOidOctets(['4', '244', '28', '57', '232', '180']));
        $this->assertNull(MacAddress::fromOidOctets(['4', '244', '28']));
        $this->assertNull(MacAddress::fromOidOctets(['4', '244', '28', '57', '232', '999']));
        $this->assertNull(MacAddress::fromOidOctets(['0', '0', '0', '0', '0', '0']));
    }
}
