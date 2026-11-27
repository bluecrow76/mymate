import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { GearSix, Warning } from '@phosphor-icons/react';
import { useOutageHistogram, useOutages } from '../api/getOutages';
import { StatusDot } from '../../../components/StatusDot';
import { DeviceGlyph } from '../../topology/nodes/DeviceGlyph';
import { displayName } from '../../../lib/deviceName';
import { relativeTime } from '../../../lib/relativeTime';
import { useMaps } from '../../maps/api/maps';
import { mapsInTreeOrder } from '../../maps/lib/mapTree';
import { useShowOnMap } from '../../maps/lib/useShowOnMap';
import {
    DEVICE_TYPE_OPTIONS,
    MIN_DURATION_OPTIONS,
    OUTAGE_RANGES,
    loadOutagePrefs,
    loadOutageSettings,
    sameFilters,
    saveOutagePrefs,
    saveOutageSettings,
    type OutageColourBy,
    type OutageColumnId,
    type OutageFilters,
    type OutageStateFilter,
    type OutageTimeFormat,
} from '../lib/outagePrefs';
import { DeviceTypeFilter } from './DeviceTypeFilter';
import { OutageHistogram } from './OutageHistogram';
import { OutageSettingsPanel } from './OutageSettingsPanel';
import type { DeviceType, NetworkMap, Outage, OutageHistogramGroup, OutageRange } from '../../../types';

function fmtDuration(s: number | null): string {
    if (s === null) return '-';
    if (s < 60) return `${s}s`;
    const m = Math.floor(s / 60);
    if (m < 60) return `${m}m ${s % 60}s`;
    const h = Math.floor(m / 60);
    return `${h}h ${m % 60}m`;
}

const pill = (active: boolean) =>
    `rounded-full px-3 py-1 text-xs font-medium transition-colors duration-200 ${
        active ? 'bg-white/10 text-white/90 ring-1 ring-white/15' : 'text-white/45 hover:text-white/75'
    }`;

const selectCls = 'rounded-lg bg-white/[0.04] px-2 py-1.5 text-xs text-white ring-1 ring-white/10 outline-none transition focus:ring-emerald-400/40';

const TIME_ZONE = Intl.DateTimeFormat().resolvedOptions().timeZone;

/** "Thu 27 Nov, 14:32:07" in the browser's time zone; the year only when it isn't this one. */
function fmtDateTime(iso: string): string {
    const d = new Date(iso);
    return d.toLocaleString([], {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        ...(d.getFullYear() !== new Date().getFullYear() ? { year: 'numeric' } : {}),
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

/** A time in the chosen format, with the other format on hover. */
function TimeText({ iso, format }: { iso: string; format: OutageTimeFormat }) {
    return format === 'datetime' ? (
        <span title={relativeTime(iso)}>{fmtDateTime(iso)}</span>
    ) : (
        <span title={`${fmtDateTime(iso)} (${TIME_ZONE})`}>{relativeTime(iso)}</span>
    );
}

/** My Mate records "unreachable"; anything else came from a Dude import (its service or status). */
function causeLabel(cause: string | null): { text: string; title?: string } {
    if (cause === null || cause === '') return { text: '–' };
    if (cause === 'unreachable') return { text: 'Unreachable', title: "Stopped answering My Mate's pings" };
    return { text: cause, title: 'Imported from The Dude' };
}

/** "last 24 hours, any map, any type, short outages shown, all outages" - for Clear's tooltip. */
function describeFilters(f: OutageFilters, maps: NetworkMap[] | undefined): string {
    const types = DEVICE_TYPE_OPTIONS.filter((o) => f.deviceTypes.includes(o.value)).map((o) => o.label.toLowerCase());
    const map = f.mapId !== null ? maps?.find((m) => m.id === f.mapId)?.name : undefined;
    return [
        (OUTAGE_RANGES.find((r) => r.value === f.range)?.label ?? f.range).toLowerCase(),
        f.mapId === null ? 'any map' : `${map ?? 'a map'}${f.submaps ? ' and its sub-maps' : ''}`,
        types.length === 0 || types.length === DEVICE_TYPE_OPTIONS.length ? 'any type' : types.join(' / '),
        f.minDuration > 0 ? `hide under ${f.minDuration / 60} min` : 'short outages shown',
        f.state === 'all' ? 'all outages' : f.state === 'open' ? 'ongoing outages' : 'resolved outages',
    ].join(', ');
}

/** The name the rest of the app shows for the device: its alias when set, else the hostname. */
function outageDeviceName(o: Outage): string {
    return o.device_name !== null ? displayName({ name: o.device_name, alias: o.device_alias }) : `device ${o.device_id}`;
}

/** "14:00 - 15:00" style label for the bar the list is narrowed to. */
function windowLabel(w: { start: string; end: string }): string {
    const s = new Date(w.start);
    const e = new Date(w.end);
    const day = (d: Date) => d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    const time = (d: Date) => d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const midnight = (d: Date) => d.getHours() === 0 && d.getMinutes() === 0;
    if (midnight(s) && midnight(e)) {
        const last = new Date(e.getTime() - 1);
        return s.toDateString() === last.toDateString() ? day(s) : `${day(s)} – ${day(last)}`;
    }
    return s.toDateString() === e.toDateString() ? `${day(s)} ${time(s)} – ${time(e)}` : `${day(s)} ${time(s)} – ${day(e)} ${time(e)}`;
}

export function OutagesView() {
    // Filters, the range and the histogram's layout are remembered in this browser, like the
    // Devices page filters. The bar the list is narrowed to isn't - it belongs to this visit.
    // The gear's settings (what Clear resets to, the columns) - also kept in this browser. A first
    // visit, with no filters saved yet, starts from the Clear defaults.
    const [settings, setSettings] = useState(loadOutageSettings);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const gearRef = useRef<HTMLButtonElement>(null);
    // Where the open panel sits: under the gear on a wide screen, kept there as the page scrolls or
    // the window resizes; null on a phone, where it's a full-width sheet.
    const [anchor, setAnchor] = useState<{ top: number; right: number } | null>(null);
    useLayoutEffect(() => {
        if (!settingsOpen) return;
        const place = () => {
            const r = gearRef.current?.getBoundingClientRect();
            setAnchor(r && window.matchMedia('(min-width: 640px)').matches ? { top: r.bottom + 8, right: Math.max(12, window.innerWidth - r.right) } : null);
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setSettingsOpen(false);
        };
        place();
        window.addEventListener('resize', place);
        window.addEventListener('scroll', place, true);
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
            window.removeEventListener('keydown', onKey);
        };
    }, [settingsOpen]);
    const [saved] = useState(() => loadOutagePrefs(settings.clearDefaults));
    const [filter, setFilter] = useState<OutageStateFilter>(saved.state);
    const [mapId, setMapId] = useState<number | null>(saved.mapId);
    const [submaps, setSubmaps] = useState(saved.submaps);
    const [deviceTypes, setDeviceTypes] = useState<DeviceType[]>(saved.deviceTypes);
    const [minDuration, setMinDuration] = useState(saved.minDuration);
    const [range, setRange] = useState<OutageRange>(saved.range);
    const [colourBy, setColourBy] = useState<OutageColourBy>(saved.colourBy);
    const [collapsed, setCollapsed] = useState(saved.histogramCollapsed);
    const [bar, setBar] = useState<{ start: string; end: string } | null>(null);
    const { data: maps } = useMaps();
    const mapOptions = mapsInTreeOrder(maps ?? []);
    const hasSubmaps = mapId !== null && (maps ?? []).some((m) => m.parent_map_id === mapId);

    const state = filter === 'all' ? undefined : filter;
    const shared = {
        ...(mapId !== null ? { mapId, submaps } : {}),
        deviceTypes,
        minDuration,
        range,
    };
    const { data: outages, isLoading } = useOutages(state, { ...shared, window: bar ? { from: bar.start, to: bar.end } : undefined });
    const histogram = useOutageHistogram({ state, ...shared }, colourBy, !collapsed);
    const { go, menu } = useShowOnMap();

    useEffect(
        () => saveOutagePrefs({ state: filter, mapId, submaps, deviceTypes, minDuration, range, colourBy, histogramCollapsed: collapsed }),
        [filter, mapId, submaps, deviceTypes, minDuration, range, colourBy, collapsed],
    );
    useEffect(() => saveOutageSettings(settings), [settings]);

    // A narrowed-to bar belongs to the chart it was picked from: any filter change redraws the
    // chart, so it lets the bar go rather than leave the list on a slice that's moved.
    const filterKey = JSON.stringify([filter, mapId, submaps, deviceTypes, minDuration, range]);
    useEffect(() => setBar(null), [filterKey]);

    const rangeLabel = OUTAGE_RANGES.find((r) => r.value === range)?.label ?? range;
    const pickGroup = (g: OutageHistogramGroup) => {
        if (g.map_id !== null) {
            setMapId(g.map_id);
            setSubmaps(true);
        } else if (g.type !== null) {
            setDeviceTypes([g.type]);
        }
    };

    // A remembered map that has since been deleted (or is no longer visible) would filter to
    // nothing with no option selected - drop back to every map.
    useEffect(() => {
        if (maps && mapId !== null && !maps.some((m) => m.id === mapId)) setMapId(null);
    }, [maps, mapId]);

    // Clear: back to the Clear defaults - the user's own (settings gear), else My Mate's (last 24
    // hours, any map, any type, short outages shown, all outages) - like the Devices page's Clear.
    // The histogram's colour and collapse are layout, not filters, so they stay. Only offered
    // while something differs from those defaults.
    const current: OutageFilters = { state: filter, mapId, submaps, deviceTypes, minDuration, range };
    const defaults = settings.clearDefaults;
    const filtersActive = !sameFilters(current, defaults) || bar !== null;
    const clearFilters = () => {
        setRange(defaults.range);
        setMapId(defaults.mapId);
        setSubmaps(defaults.submaps);
        setDeviceTypes(defaults.deviceTypes);
        setMinDuration(defaults.minDuration);
        setFilter(defaults.state);
        setBar(null);
    };

    // The list's columns, in the chosen order (Device is always among them).
    // State as "Dot only" is a narrow column: no header text on screen (still read out), the word on hover.
    const stateDotOnly = settings.stateFormat === 'dot';
    const columns: Record<OutageColumnId, { header: ReactNode; thClass?: string; cell: (o: Outage, name: string) => ReactNode }> = {
        device: {
            header: 'Device',
            cell: (o, name) => (
                <td key="device" className="px-4 py-2.5 font-medium text-white/85">
                    {/* The device's icon, drawn as on the map: custom icon, product photo, vendor mark or type. */}
                    <span className="flex items-center gap-2.5">
                        <DeviceGlyph
                            deviceId={o.device_id}
                            vendor={o.device_vendor}
                            model={o.device_model}
                            type={o.device_type}
                            icon={o.device_icon}
                            iconColor={o.device_icon_color}
                            className="h-5 w-5 shrink-0"
                        />
                        <span className="min-w-0">{name}</span>
                    </span>
                </td>
            ),
        },
        ip: { header: 'IP address', cell: (o) => <td key="ip" className="px-4 py-2.5 font-mono text-xs tabular-nums text-white/55">{o.device_ip ?? '–'}</td> },
        started: {
            header: 'Started',
            cell: (o) => (
                <td key="started" className="whitespace-nowrap px-4 py-2.5 tabular-nums text-white/55">
                    <TimeText iso={o.started_at} format={settings.startedFormat} />
                </td>
            ),
        },
        ended: {
            header: 'Ended',
            cell: (o) => (
                <td key="ended" className="whitespace-nowrap px-4 py-2.5 tabular-nums text-white/55">
                    {o.ended_at ? <TimeText iso={o.ended_at} format={settings.endedFormat} /> : <span className="text-white/30" title="Still down">–</span>}
                </td>
            ),
        },
        duration: {
            header: 'Duration',
            cell: (o) => <td key="duration" className="px-4 py-2.5 tabular-nums text-white/70">{o.ongoing ? 'ongoing' : fmtDuration(o.duration_s)}</td>,
        },
        state: stateDotOnly
            ? {
                  header: <span className="sr-only">State</span>,
                  thClass: 'w-px px-3 py-2.5',
                  cell: (o) => {
                      const word = o.ongoing ? 'Down' : 'Recovered';
                      return (
                          <td key="state" className="w-px px-3 py-2.5 text-center">
                              <span role="img" aria-label={word} title={word} className="inline-flex align-middle">
                                  <StatusDot status={o.ongoing ? 'down' : 'up'} />
                              </span>
                          </td>
                      );
                  },
              }
            : {
                  header: 'State',
                  cell: (o) => (
                      <td key="state" className="px-4 py-2.5">
                          <span className="flex items-center gap-1.5 text-white/60">
                              <StatusDot status={o.ongoing ? 'down' : 'up'} />
                              {o.ongoing ? 'Down' : 'Recovered'}
                          </span>
                      </td>
                  ),
              },
        cause: {
            header: 'Cause',
            cell: (o) => {
                const c = causeLabel(o.cause);
                return (
                    <td key="cause" className="px-4 py-2.5 text-white/55" title={c.title}>
                        {c.text}
                    </td>
                );
            },
        },
        maps: {
            header: 'Maps',
            cell: (o) => {
                const names = o.device_maps.map((m) => m.name).join(', ');
                return (
                    <td key="maps" className="max-w-[16rem] truncate px-4 py-2.5 text-white/55" title={names || undefined}>
                        {names || '–'}
                    </td>
                );
            },
        },
    };
    const shown = settings.columns.filter((c) => c.visible);

    const mapName = mapId !== null ? maps?.find((m) => m.id === mapId)?.name : undefined;
    const typeNames = DEVICE_TYPE_OPTIONS.filter((o) => deviceTypes.includes(o.value)).map((o) => o.label.toLowerCase());
    const emptyNote = [
        filter !== 'all' ? (filter === 'open' ? 'ongoing' : 'resolved') : null,
        bar ? `started ${windowLabel(bar)}` : rangeLabel.toLowerCase(),
        mapName ? `on ${mapName}${hasSubmaps && submaps ? ' or its sub-maps' : ''}` : null,
        typeNames.length > 0 && typeNames.length < DEVICE_TYPE_OPTIONS.length ? typeNames.join(' / ') : null,
        minDuration > 0 ? `${minDuration / 60} min or longer` : null,
    ]
        .filter(Boolean)
        .join(', ');

    return (
        <div className="h-full overflow-y-auto p-6 lg:p-8">
            <div className="animate-rise">
                <header className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <span className="grid h-9 w-9 place-items-center rounded-xl bg-amber-500/15 text-amber-300 ring-1 ring-amber-400/20">
                            <Warning weight="light" className="h-5 w-5" />
                        </span>
                        <div>
                            <h1 className="text-base font-bold tracking-tight text-white">Outages</h1>
                            <p className="text-xs text-white/40">Device down-events with durations, newest first</p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <select value={range} onChange={(e) => setRange(e.target.value as OutageRange)} aria-label="Time range" className={selectCls}>
                            {OUTAGE_RANGES.map((r) => (
                                <option key={r.value} value={r.value}>
                                    {r.label}
                                </option>
                            ))}
                        </select>
                        {/* Capped: a native select is as wide as its longest option, and map names can be long. */}
                        <select
                            value={mapId ?? ''}
                            onChange={(e) => setMapId(e.target.value === '' ? null : Number(e.target.value))}
                            title={mapName}
                            aria-label="Limit to the devices on a map"
                            className={`${selectCls} max-w-[13rem] truncate`}
                        >
                            <option value="">Any map</option>
                            {mapOptions.map(({ map, depth }) => (
                                <option key={map.id} value={map.id}>
                                    {'  '.repeat(depth)}
                                    {map.name} ({map.device_count})
                                </option>
                            ))}
                        </select>
                        {hasSubmaps && (
                            <label className="flex cursor-pointer items-center gap-1.5 text-xs text-white/60" title="Also show outages for devices on the maps under this one">
                                <input type="checkbox" checked={submaps} onChange={(e) => setSubmaps(e.target.checked)} className="accent-emerald-500" />
                                Include sub-maps
                            </label>
                        )}
                        <DeviceTypeFilter value={deviceTypes} onChange={setDeviceTypes} className={selectCls} />
                        <select
                            value={minDuration}
                            onChange={(e) => setMinDuration(Number(e.target.value))}
                            aria-label="Hide short outages"
                            title="Hide resolved outages shorter than this (ongoing ones always show)"
                            className={selectCls}
                        >
                            <option value={0}>Show short outages</option>
                            {MIN_DURATION_OPTIONS.map((m) => (
                                <option key={m} value={m * 60}>
                                    Hide under {m} min
                                </option>
                            ))}
                        </select>
                        <div className="flex items-center gap-0.5 rounded-full bg-white/5 p-0.5 ring-1 ring-white/10">
                            {(['all', 'open', 'closed'] as OutageStateFilter[]).map((f) => (
                                <button key={f} onClick={() => setFilter(f)} className={pill(filter === f)}>
                                    {f === 'all' ? 'All' : f === 'open' ? 'Ongoing' : 'Resolved'}
                                </button>
                            ))}
                        </div>
                        {filtersActive && (
                            // Styled as a selected option: it only shows while filters are on.
                            <button
                                onClick={clearFilters}
                                title={`Back to ${describeFilters(defaults, maps)}`}
                                className={`${pill(true)} hover:bg-white/15`}
                            >
                                Clear
                            </button>
                        )}
                        <div>
                            <button
                                ref={gearRef}
                                type="button"
                                onClick={() => setSettingsOpen((o) => !o)}
                                aria-label="Outages page settings"
                                aria-expanded={settingsOpen}
                                title="Settings: what Clear resets to, and the list's columns"
                                className={`grid h-7 w-7 place-items-center rounded-lg ring-1 transition-colors ${
                                    settingsOpen ? 'bg-emerald-500/15 text-emerald-300 ring-emerald-400/35' : 'text-white/55 ring-white/10 hover:bg-white/[0.06] hover:text-white/85'
                                }`}
                            >
                                <GearSix className="h-4 w-4" />
                            </button>
                            {settingsOpen && (
                                <OutageSettingsPanel
                                    settings={settings}
                                    onChange={setSettings}
                                    current={current}
                                    maps={maps ?? []}
                                    anchor={anchor}
                                    onClose={() => setSettingsOpen(false)}
                                />
                            )}
                        </div>
                    </div>
                </header>

                <OutageHistogram
                    data={histogram.data}
                    isLoading={histogram.isLoading}
                    rangeLabel={rangeLabel}
                    colourBy={colourBy}
                    onColourBy={setColourBy}
                    collapsed={collapsed}
                    onToggleCollapsed={() => setCollapsed((c) => !c)}
                    selectedStart={bar?.start ?? null}
                    onSelectBar={setBar}
                    onPickGroup={pickGroup}
                />

                {(bar || (outages && outages.length >= 500)) && (
                    <div className="mb-2 flex flex-wrap items-center gap-2 px-1 text-xs text-white/50">
                        {bar && (
                            <span className="flex items-center gap-1.5 rounded-full bg-white/[0.05] py-0.5 pl-2.5 pr-1 ring-1 ring-white/10">
                                Started {windowLabel(bar)}
                                <button type="button" onClick={() => setBar(null)} className="rounded-full px-1.5 text-white/45 hover:bg-white/10 hover:text-white" aria-label="Show the whole range">
                                    ×
                                </button>
                            </span>
                        )}
                        {outages && outages.length >= 500 && <span>Showing the newest 500 - narrow the range or filters to see the rest.</span>}
                    </div>
                )}

                {isLoading ? (
                    <p className="px-1 text-sm text-white/40">Loading...</p>
                ) : !outages || outages.length === 0 ? (
                    <div className="grid place-items-center rounded-2xl bg-white/[0.02] py-16 text-center ring-1 ring-white/[0.06]">
                        <p className="text-sm text-white/45">No outages recorded{emptyNote ? ` (${emptyNote})` : ''}.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-2xl ring-1 ring-white/[0.06]">
                        <table className="w-full min-w-[42rem] text-left text-sm">
                            <thead className="bg-white/[0.03] text-[11px] uppercase tracking-wide text-white/40">
                                <tr>
                                    {shown.map((c) => (
                                        <th key={c.id} title={c.id === 'state' && stateDotOnly ? 'State' : undefined} className={`${columns[c.id].thClass ?? 'px-4 py-2.5'} font-medium`}>
                                            {columns[c.id].header}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/[0.04]">
                                {outages.map((o) => {
                                    const name = outageDeviceName(o);
                                    return (
                                        <tr
                                            key={o.id}
                                            onClick={(e) => go(o.device_id, name, o.device_maps, { x: e.clientX, y: e.clientY })}
                                            title={o.device_maps.length === 0 ? 'Not on any map - opens the device' : 'Show on the map'}
                                            className="cursor-pointer transition-colors duration-200 hover:bg-white/[0.03]"
                                        >
                                            {shown.map((c) => columns[c.id].cell(o, name))}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
            {menu}
        </div>
    );
}
