import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

// The neighbour data collected for one device (LLDP/MNDP, MACs per port, ARP) - the device
// page's Neighbours tab. `current` = refreshed by the latest read; the rest is history.

export interface NeighbourInterface {
    id: number;
    name: string;
    description: string | null;
}

export interface NeighbourDeviceRef {
    id: number;
    name: string;
}

export interface NeighbourRow {
    protocol: 'lldp' | 'mndp';
    interface: NeighbourInterface | null;
    local_if_index: number;
    remote_name: string | null;
    remote_ip: string | null;
    remote_mac: string | null;
    remote_chassis_id: string | null;
    remote_port_id: string | null;
    remote_port_description: string | null;
    remote_platform: string | null;
    remote_version: string | null;
    remote_capabilities: string[] | null;
    device: NeighbourDeviceRef | null;
    first_seen_at: string | null;
    last_seen_at: string | null;
    current: boolean;
}

export interface NeighbourMac {
    mac: string;
    ip: string | null;
    device: NeighbourDeviceRef | null;
    first_seen_at: string | null;
    last_seen_at: string | null;
    current: boolean;
}

export interface NeighbourPort {
    interface: NeighbourInterface | null;
    port: number;
    if_index: number | null;
    current_count: number;
    macs: NeighbourMac[];
}

export interface NeighbourArp {
    ip: string;
    mac: string;
    interface: NeighbourInterface | null;
    device: NeighbourDeviceRef | null;
    first_seen_at: string | null;
    last_seen_at: string | null;
    current: boolean;
}

export interface DeviceNeighbours {
    device_id: number;
    supported: boolean;
    enabled: boolean;
    interval_s: number;
    collected_at: string | null;
    error: string | null;
    history: boolean;
    truncated: boolean;
    addresses: { ip: string; prefix_length: number | null; interface: NeighbourInterface | null; current: boolean }[];
    neighbours: NeighbourRow[];
    ports: NeighbourPort[];
    arp: NeighbourArp[];
}

export function useDeviceNeighbours(id: number, history: boolean) {
    return useQuery({
        queryKey: ['device-page', id, 'neighbours', history] as const,
        queryFn: async (): Promise<DeviceNeighbours> => {
            const { data } = await apiClient.get<{ data: DeviceNeighbours }>(`/devices/${id}/neighbours`, {
                params: history ? { history: 1 } : undefined,
            });
            return data.data;
        },
        placeholderData: keepPreviousData,
        refetchInterval: 120_000,
    });
}
