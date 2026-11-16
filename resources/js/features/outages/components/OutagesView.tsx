import { useEffect, useState } from 'react';
import { Warning } from '@phosphor-icons/react';
import { useOutages } from '../api/getOutages';
import { StatusDot } from '../../../components/StatusDot';
import { displayName } from '../../../lib/deviceName';
import { relativeTime } from '../../../lib/relativeTime';
import { useMaps } from '../../maps/api/maps';
import { mapsInTreeOrder } from '../../maps/lib/mapTree';
import { useShowOnMap } from '../../maps/lib/useShowOnMap';
import { loadOutagePrefs, saveOutagePrefs, type OutageStateFilter } from '../lib/outagePrefs';
import type { Outage } from '../../../types';

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

/** The name the rest of the app shows for the device: its alias when set, else the hostname. */
function outageDeviceName(o: Outage): string {
    return o.device_name !== null ? displayName({ name: o.device_name, alias: o.device_alias }) : `device ${o.device_id}`;
}

export function OutagesView() {
    // State, map and sub-maps are remembered in this browser, like the Devices page filters.
    const [saved] = useState(loadOutagePrefs);
    const [filter, setFilter] = useState<OutageStateFilter>(saved.state);
    const [mapId, setMapId] = useState<number | null>(saved.mapId);
    const [submaps, setSubmaps] = useState(saved.submaps);
    const { data: maps } = useMaps();
    const mapOptions = mapsInTreeOrder(maps ?? []);
    const hasSubmaps = mapId !== null && (maps ?? []).some((m) => m.parent_map_id === mapId);

    const { data: outages, isLoading } = useOutages(filter === 'all' ? undefined : filter, mapId !== null ? { mapId, submaps } : {});
    const { go, menu } = useShowOnMap();

    useEffect(() => saveOutagePrefs({ state: filter, mapId, submaps }), [filter, mapId, submaps]);

    // A remembered map that has since been deleted (or is no longer visible) would filter to
    // nothing with no option selected - drop back to every map.
    useEffect(() => {
        if (maps && mapId !== null && !maps.some((m) => m.id === mapId)) setMapId(null);
    }, [maps, mapId]);

    const mapName = mapId !== null ? maps?.find((m) => m.id === mapId)?.name : undefined;
    const emptyNote = [filter !== 'all' ? (filter === 'open' ? 'ongoing' : 'resolved') : null, mapName ? `on ${mapName}${hasSubmaps && submaps ? ' or its sub-maps' : ''}` : null]
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
                        <div className="flex items-center gap-0.5 rounded-full bg-white/5 p-0.5 ring-1 ring-white/10">
                            {(['all', 'open', 'closed'] as OutageStateFilter[]).map((f) => (
                                <button key={f} onClick={() => setFilter(f)} className={pill(filter === f)}>
                                    {f === 'all' ? 'All' : f === 'open' ? 'Ongoing' : 'Resolved'}
                                </button>
                            ))}
                        </div>
                    </div>
                </header>

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
                                    <th className="px-4 py-2.5 font-medium">Device</th>
                                    <th className="px-4 py-2.5 font-medium">IP address</th>
                                    <th className="px-4 py-2.5 font-medium">Started</th>
                                    <th className="px-4 py-2.5 font-medium">Duration</th>
                                    <th className="px-4 py-2.5 font-medium">State</th>
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
                                            <td className="px-4 py-2.5 font-medium text-white/85">{name}</td>
                                            <td className="px-4 py-2.5 font-mono text-xs tabular-nums text-white/55">{o.device_ip ?? '–'}</td>
                                            <td className="px-4 py-2.5 tabular-nums text-white/55">{relativeTime(o.started_at)}</td>
                                            <td className="px-4 py-2.5 tabular-nums text-white/70">
                                                {o.ongoing ? 'ongoing' : fmtDuration(o.duration_s)}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <span className="flex items-center gap-1.5 text-white/60">
                                                    <StatusDot status={o.ongoing ? 'down' : 'up'} />
                                                    {o.ongoing ? 'Down' : 'Recovered'}
                                                </span>
                                            </td>
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
