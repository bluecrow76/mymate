/**
 * The Outages page's last-used filters - a per-browser convenience, like the Devices page's.
 * Read defensively: storage can be blocked or hand-edited, and the page must work without it.
 */
export type OutageStateFilter = 'all' | 'open' | 'closed';

export interface OutagePrefs {
    state: OutageStateFilter;
    mapId: number | null; // null = every map
    submaps: boolean; // a chosen map includes the maps under it
}

const KEY = 'mymate.outages.v1';
export const OUTAGE_DEFAULTS: OutagePrefs = { state: 'all', mapId: null, submaps: true };

export function loadOutagePrefs(): OutagePrefs {
    try {
        const raw = JSON.parse(window.localStorage.getItem(KEY) ?? '{}') as Partial<Record<keyof OutagePrefs, unknown>>;
        return {
            state: raw.state === 'open' || raw.state === 'closed' || raw.state === 'all' ? raw.state : OUTAGE_DEFAULTS.state,
            mapId: typeof raw.mapId === 'number' && Number.isInteger(raw.mapId) && raw.mapId > 0 ? raw.mapId : null,
            submaps: typeof raw.submaps === 'boolean' ? raw.submaps : OUTAGE_DEFAULTS.submaps,
        };
    } catch {
        return OUTAGE_DEFAULTS;
    }
}

export function saveOutagePrefs(p: OutagePrefs): void {
    try {
        window.localStorage.setItem(KEY, JSON.stringify(p));
    } catch {
        /* storage disabled or full - the page just starts from the defaults next time */
    }
}
