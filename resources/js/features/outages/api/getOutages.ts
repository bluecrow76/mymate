import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { DeviceType, Outage, OutageHistogram, OutageRange } from '../../../types';

export type OutageQuery = {
    state?: 'open' | 'closed';
    mapId?: number; // only devices on this map...
    submaps?: boolean; // ...and its sub-maps (default true)
    deviceTypes?: DeviceType[]; // any of these types (empty/absent = every type)
    minDuration?: number; // seconds: hide resolved outages shorter than this
    range?: OutageRange; // started in this range (on the viewer's clock), plus any still ongoing
    window?: { from: string; to: string }; // or: started in exactly this window (one histogram bar)
};

/** The viewer's IANA time zone, so the server lines bars up with local hours and midnights. */
const viewerTz = (): string => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
};

function filterParams(q: OutageQuery): Record<string, string | number | string[]> {
    const params: Record<string, string | number | string[]> = {};
    if (q.state) params.state = q.state;
    if (q.mapId !== undefined) {
        params.map_id = q.mapId;
        params.submaps = q.submaps === false ? 0 : 1;
    }
    if (q.deviceTypes && q.deviceTypes.length > 0) params.device_types = [...q.deviceTypes].sort(); // axios sends device_types[]=..
    if (q.minDuration) params.min_duration = q.minDuration;
    if (q.range) {
        params.range = q.range;
        params.tz = viewerTz();
    }
    return params;
}

export const outageKeys = {
    all: ['outages'] as const,
    list: (q: OutageQuery) => ['outages', 'list', filterParams(q), q.window ?? null] as const,
    histogram: (q: OutageQuery, groupBy: 'map' | 'type') => ['outages', 'histogram', filterParams(q), groupBy] as const,
};

async function fetchOutages(q: OutageQuery): Promise<Outage[]> {
    const params = filterParams(q);
    if (q.window) {
        params.from = q.window.from;
        params.to = q.window.to;
    }
    const { data } = await apiClient.get<{ data: Outage[] }>('/outages', { params });
    return data.data;
}

/** The outage timeline, optionally filtered. Polls so it stays fresh as devices go down/recover
 *  (outages aren't pushed over the socket). */
export function useOutages(state?: 'open' | 'closed', filter: Omit<OutageQuery, 'state'> = {}, enabled = true) {
    const q: OutageQuery = { state, ...filter };
    return useQuery({
        queryKey: outageKeys.list(q),
        queryFn: () => fetchOutages(q),
        refetchInterval: 15000,
        enabled, // off for an operator the Outages page isn't open to (it would only 403)
        placeholderData: keepPreviousData, // switching a filter keeps the old rows up until the new ones land
    });
}

/** Distinct devices that went down per bar of the range, stacked by map branch or device type. */
export function useOutageHistogram(q: OutageQuery & { range: OutageRange }, groupBy: 'map' | 'type', enabled = true) {
    return useQuery({
        queryKey: outageKeys.histogram(q, groupBy),
        queryFn: async () => {
            const { data } = await apiClient.get<{ data: OutageHistogram }>('/outages/histogram', {
                params: { ...filterParams(q), group_by: groupBy },
            });
            return data.data;
        },
        refetchInterval: 30000,
        enabled,
        placeholderData: keepPreviousData,
    });
}
