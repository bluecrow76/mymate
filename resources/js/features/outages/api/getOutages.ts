import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Outage } from '../../../types';

export type OutageQuery = {
    state?: 'open' | 'closed';
    mapId?: number; // only devices on this map...
    submaps?: boolean; // ...and its sub-maps (default true)
};

export const outageKeys = {
    all: ['outages'] as const,
    list: (q: OutageQuery) => ['outages', q.state ?? 'all', q.mapId ?? null, q.mapId !== undefined ? (q.submaps ?? true) : null] as const,
};

async function fetchOutages(q: OutageQuery): Promise<Outage[]> {
    const params: Record<string, string | number> = {};
    if (q.state) params.state = q.state;
    if (q.mapId !== undefined) {
        params.map_id = q.mapId;
        params.submaps = q.submaps === false ? 0 : 1;
    }
    const { data } = await apiClient.get<{ data: Outage[] }>('/outages', { params });
    return data.data;
}

/** The outage timeline, optionally filtered to open/closed and to one map. Polls so it stays
 *  fresh as devices go down/recover (outages aren't pushed over the socket). */
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
