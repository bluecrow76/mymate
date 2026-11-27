import type { DeviceType, OutageRange } from '../../../types';

/**
 * The Outages page's last-used filters, and its settings (the gear: what Clear resets to and the
 * list's columns) - per-browser conveniences, like the Devices page's.
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

/** The filter part of the prefs: what Clear resets and the settings panel can set defaults for. */
export type OutageFilters = Pick<OutagePrefs, 'state' | 'mapId' | 'submaps' | 'deviceTypes' | 'minDuration' | 'range'>;

/** My Mate's own defaults for the filters ("Use My Mate's defaults" in the settings panel). */
export const BUILT_IN_FILTERS: OutageFilters = {
    state: OUTAGE_DEFAULTS.state,
    mapId: OUTAGE_DEFAULTS.mapId,
    submaps: OUTAGE_DEFAULTS.submaps,
    deviceTypes: OUTAGE_DEFAULTS.deviceTypes,
    minDuration: OUTAGE_DEFAULTS.minDuration,
    range: OUTAGE_DEFAULTS.range,
};

/** The filter fields of a stored object, each checked, anything missing or odd taken from `fallback`. */
function readFilters(raw: Partial<Record<string, unknown>>, fallback: OutageFilters): OutageFilters {
    const types = Array.isArray(raw.deviceTypes)
        ? DEVICE_TYPE_OPTIONS.map((o) => o.value).filter((t) => (raw.deviceTypes as unknown[]).includes(t))
        : fallback.deviceTypes;
    return {
        state: raw.state === 'open' || raw.state === 'closed' || raw.state === 'all' ? raw.state : fallback.state,
        mapId: typeof raw.mapId === 'number' && Number.isInteger(raw.mapId) && raw.mapId > 0 ? raw.mapId : raw.mapId === null ? null : fallback.mapId,
        submaps: typeof raw.submaps === 'boolean' ? raw.submaps : fallback.submaps,
        deviceTypes: types,
        minDuration: raw.minDuration === 0 || MIN_DURATION_OPTIONS.some((m) => m * 60 === raw.minDuration) ? (raw.minDuration as number) : fallback.minDuration,
        range: OUTAGE_RANGES.some((r) => r.value === raw.range) ? (raw.range as OutageRange) : fallback.range,
    };
}

/** True when two sets of filters mean the same thing (sub-maps only matter with a map chosen). */
export function sameFilters(a: OutageFilters, b: OutageFilters): boolean {
    const types = (t: DeviceType[]) => [...t].sort().join(',');
    return (
        a.state === b.state &&
        a.mapId === b.mapId &&
        (a.mapId === null || a.submaps === b.submaps) &&
        types(a.deviceTypes) === types(b.deviceTypes) &&
        a.minDuration === b.minDuration &&
        a.range === b.range
    );
}

/**
 * The last-used filters and histogram layout. With nothing saved yet the page starts from `defaults`
 * - the user's own Clear defaults when they've set some.
 */
export function loadOutagePrefs(defaults: OutageFilters = BUILT_IN_FILTERS): OutagePrefs {
    try {
        const raw = JSON.parse(window.localStorage.getItem(KEY) ?? '{}') as Partial<Record<string, unknown>>;
        return {
            ...readFilters(raw, defaults),
            colourBy: raw.colourBy === 'type' ? 'type' : 'map',
            histogramCollapsed: raw.histogramCollapsed === true,
        };
    } catch {
        return { ...OUTAGE_DEFAULTS, ...defaults };
    }
}

export function saveOutagePrefs(p: OutagePrefs): void {
    try {
        window.localStorage.setItem(KEY, JSON.stringify(p));
    } catch {
        /* storage disabled or full - the page just starts from the defaults next time */
    }
}

// --- Page settings (the gear): what Clear resets to, and the list's columns ---------------------

/** A column of the outage list. Device can move but is always shown. */
export type OutageColumnId = 'device' | 'ip' | 'started' | 'ended' | 'duration' | 'state' | 'cause' | 'maps';
export type OutageTimeFormat = 'relative' | 'datetime';
/** The State column: the dot and its word, or just the dot (a narrow column; the word on hover). */
export type OutageStateFormat = 'dot-text' | 'dot';

export const OUTAGE_COLUMNS: { id: OutageColumnId; label: string; hint?: string; timed?: boolean }[] = [
    { id: 'device', label: 'Device' },
    { id: 'ip', label: 'IP address' },
    { id: 'started', label: 'Started', timed: true },
    { id: 'ended', label: 'Ended', timed: true, hint: 'blank while ongoing' },
    { id: 'duration', label: 'Duration' },
    { id: 'state', label: 'State' },
    { id: 'cause', label: 'Cause', hint: 'unreachable, or the service from a Dude import' },
    { id: 'maps', label: 'Maps', hint: 'the maps the device is on' },
];

export interface OutageColumn {
    id: OutageColumnId;
    visible: boolean;
}

export interface OutageSettings {
    clearDefaults: OutageFilters; // what Clear resets to (and where a first visit starts)
    columns: OutageColumn[]; // in display order
    startedFormat: OutageTimeFormat;
    endedFormat: OutageTimeFormat;
    stateFormat: OutageStateFormat;
}

/** Today's columns as before; the new ones start hidden, so nothing changes until asked for. */
export const DEFAULT_COLUMNS: OutageColumn[] = [
    { id: 'device', visible: true },
    { id: 'ip', visible: true },
    { id: 'started', visible: true },
    { id: 'ended', visible: false },
    { id: 'duration', visible: true },
    { id: 'state', visible: true },
    { id: 'cause', visible: false },
    { id: 'maps', visible: false },
];

export const DEFAULT_OUTAGE_SETTINGS: OutageSettings = {
    clearDefaults: BUILT_IN_FILTERS,
    columns: DEFAULT_COLUMNS,
    startedFormat: 'relative',
    endedFormat: 'relative',
    stateFormat: 'dot-text',
};

const SETTINGS_KEY = 'mymate.outages.settings.v1';

/**
 * Columns in the saved order, known ones only; a column the saved list lacks (added in a later
 * version) joins at its default position and visibility, and Device is always visible.
 */
function readColumns(raw: unknown): OutageColumn[] {
    const known = new Set<OutageColumnId>(DEFAULT_COLUMNS.map((c) => c.id));
    const out: OutageColumn[] = [];
    if (Array.isArray(raw)) {
        for (const c of raw as Array<Partial<OutageColumn>>) {
            if (c && typeof c.id === 'string' && known.has(c.id as OutageColumnId) && !out.some((o) => o.id === c.id)) {
                out.push({ id: c.id as OutageColumnId, visible: c.id === 'device' ? true : c.visible === true });
            }
        }
    }
    DEFAULT_COLUMNS.forEach((d, i) => {
        if (!out.some((o) => o.id === d.id)) out.splice(Math.min(i, out.length), 0, { ...d });
    });
    return out;
}

const readFormat = (v: unknown): OutageTimeFormat => (v === 'datetime' ? 'datetime' : 'relative');
const readStateFormat = (v: unknown): OutageStateFormat => (v === 'dot' ? 'dot' : 'dot-text');

export function loadOutageSettings(): OutageSettings {
    try {
        const raw = JSON.parse(window.localStorage.getItem(SETTINGS_KEY) ?? '{}') as Partial<Record<string, unknown>>;
        const defaults = raw.clearDefaults && typeof raw.clearDefaults === 'object' ? (raw.clearDefaults as Partial<Record<string, unknown>>) : {};
        return {
            clearDefaults: readFilters(defaults, BUILT_IN_FILTERS),
            columns: readColumns(raw.columns),
            startedFormat: readFormat(raw.startedFormat),
            endedFormat: readFormat(raw.endedFormat),
            stateFormat: readStateFormat(raw.stateFormat),
        };
    } catch {
        return DEFAULT_OUTAGE_SETTINGS;
    }
}

export function saveOutageSettings(s: OutageSettings): void {
    try {
        window.localStorage.setItem(SETTINGS_KEY, JSON.stringify(s));
    } catch {
        /* storage disabled or full - the settings last for this visit only */
    }
}
