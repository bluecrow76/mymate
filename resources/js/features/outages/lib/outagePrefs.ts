import type { DeviceType, OutageRange } from '../../../types';

/**
 * The Outages page's last-used filters - a per-browser convenience, like the Devices page's.
 * Read defensively: storage can be blocked or hand-edited, and the page must work without it.
 * New fields fall back to their defaults, so prefs saved by an older version still load.
 */
export type OutageStateFilter = 'all' | 'open' | 'closed';
export type OutageColourBy = 'map' | 'type';

export interface OutagePrefs {
    state: OutageStateFilter;
    mapId: number | null; // null = every map
    submaps: boolean; // a chosen map includes the maps under it
    deviceTypes: DeviceType[]; // empty = every type
    minDuration: number; // seconds; 0 = show short outages too
    range: OutageRange;
    colourBy: OutageColourBy; // what the histogram's stacked segments are
    histogramCollapsed: boolean;
}

export const OUTAGE_RANGES: { value: OutageRange; label: string }[] = [
    { value: '1h', label: 'Last hour' },
    { value: '2h', label: 'Last 2 hours' },
    { value: '4h', label: 'Last 4 hours' },
    { value: '6h', label: 'Last 6 hours' },
    { value: '12h', label: 'Last 12 hours' },
    { value: '24h', label: 'Last 24 hours' },
    { value: '1w', label: 'Last week' },
    { value: '2w', label: 'Last 2 weeks' },
    { value: '1mo', label: 'Last month' },
    { value: '2mo', label: 'Last 2 months' },
    { value: '3mo', label: 'Last 3 months' },
    { value: '6mo', label: 'Last 6 months' },
];

export const DEVICE_TYPE_OPTIONS: { value: DeviceType; label: string }[] = [
    { value: 'router', label: 'Router' },
    { value: 'switch', label: 'Switch' },
    { value: 'ap', label: 'Access point' },
    { value: 'server', label: 'Server' },
    { value: 'internet', label: 'Internet' },
    { value: 'unknown', label: 'Unknown' },
];

/** "Hide short outages": resolved outages shorter than this many minutes. */
export const MIN_DURATION_OPTIONS = [1, 5, 10, 15, 30];

const KEY = 'mymate.outages.v1';
export const OUTAGE_DEFAULTS: OutagePrefs = {
    state: 'all',
    mapId: null,
    submaps: true,
    deviceTypes: [],
    minDuration: 0,
    range: '24h',
    colourBy: 'map',
    histogramCollapsed: false,
};

export function loadOutagePrefs(): OutagePrefs {
    try {
        const raw = JSON.parse(window.localStorage.getItem(KEY) ?? '{}') as Partial<Record<keyof OutagePrefs, unknown>>;
        const types = Array.isArray(raw.deviceTypes)
            ? DEVICE_TYPE_OPTIONS.map((o) => o.value).filter((t) => (raw.deviceTypes as unknown[]).includes(t))
            : OUTAGE_DEFAULTS.deviceTypes;
        return {
            state: raw.state === 'open' || raw.state === 'closed' || raw.state === 'all' ? raw.state : OUTAGE_DEFAULTS.state,
            mapId: typeof raw.mapId === 'number' && Number.isInteger(raw.mapId) && raw.mapId > 0 ? raw.mapId : null,
            submaps: typeof raw.submaps === 'boolean' ? raw.submaps : OUTAGE_DEFAULTS.submaps,
            deviceTypes: types,
            minDuration: MIN_DURATION_OPTIONS.some((m) => m * 60 === raw.minDuration) ? (raw.minDuration as number) : 0,
            range: OUTAGE_RANGES.some((r) => r.value === raw.range) ? (raw.range as OutageRange) : OUTAGE_DEFAULTS.range,
            colourBy: raw.colourBy === 'type' ? 'type' : 'map',
            histogramCollapsed: raw.histogramCollapsed === true,
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
