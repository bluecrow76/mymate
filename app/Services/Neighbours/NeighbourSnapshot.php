<?php

namespace App\Services\Neighbours;

/**
 * Everything one read of a device turned up, already parsed and normalised (MACs lower-case
 * colon form, IPs validated). Plain arrays so the reader stays transport-agnostic and the agent
 * path can build the same thing later.
 */
final class NeighbourSnapshot
{
    /**
     * @param  array<int, string>  $interfaceMacs  ifIndex => mac
     * @param  list<array{ip: string, if_index: ?int, prefix_length: ?int}>  $addresses
     * @param  list<array{protocol: string, local_if_index: int, remote_key: string, chassis_id: ?string, mac: ?string, ip: ?string, name: ?string, port_id: ?string, port_description: ?string, platform: ?string, version: ?string, capabilities: ?list<string>}>  $neighbours
     * @param  list<array{port: int, if_index: ?int, mac: string, vlan: int}>  $fdb
     * @param  list<array{ip: string, mac: string, if_index: ?int}>  $arp
     * @param  list<string>  $truncated  tables that hit the per-table row cap
     */
    public function __construct(
        public array $interfaceMacs = [],
        public array $addresses = [],
        public array $neighbours = [],
        public array $fdb = [],
        public array $arp = [],
        public array $truncated = [],
    ) {}
}
