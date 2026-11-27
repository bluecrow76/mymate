import { createPortal } from 'react-dom';
import { ArrowDown, ArrowUp } from '@phosphor-icons/react';
import { mapsInTreeOrder } from '../../maps/lib/mapTree';
import {
    BUILT_IN_FILTERS,
    DEFAULT_COLUMNS,
    MIN_DURATION_OPTIONS,
    OUTAGE_COLUMNS,
    OUTAGE_RANGES,
    sameFilters,
    type OutageColumn,
    type OutageFilters,
    type OutageSettings,
    type OutageStateFilter,
    type OutageTimeFormat,
} from '../lib/outagePrefs';
import { DeviceTypeFilter } from './DeviceTypeFilter';
import type { NetworkMap, OutageRange } from '../../../types';

const selectCls = 'w-full rounded-lg bg-white/[0.04] px-2 py-1.5 text-xs text-white ring-1 ring-white/10 outline-none transition focus:ring-emerald-400/40';
const fieldLabel = 'flex min-w-0 flex-col gap-1 text-[11px] text-white/55';
const heading = 'text-[11px] font-semibold uppercase tracking-[0.12em] text-white/55';
const linkBtn = 'text-[11px] text-emerald-300/90 hover:text-emerald-200 disabled:cursor-default disabled:text-white/25';
const pill = (active: boolean, small = false) =>
    `rounded-full ${small ? 'px-2.5 py-0.5 text-[11px]' : 'px-3 py-1 text-xs'} font-medium transition-colors duration-200 ${
        active ? 'bg-white/10 text-white/90 ring-1 ring-white/15' : 'text-white/45 hover:text-white/75'
    }`;

/**
 * The Outages page's settings (the gear): what Clear resets to, and which columns the list shows,
 * in which order, with Started and Ended as relative times or dates and times. Changes apply at
 * once and are kept in this browser; Done just closes the panel.
 *
 * Rendered into <body> (a portal) with a backdrop that closes it: the page sits in an animated,
 * transformed wrapper, which would otherwise pin a fixed panel to the page instead of the screen.
 * Pinned under the gear (`anchor`) on a wide screen; a full-width sheet on a phone (no anchor).
 */
export function OutageSettingsPanel({
    settings,
    onChange,
    current,
    maps,
    anchor,
    onClose,
}: {
    settings: OutageSettings;
    onChange: (s: OutageSettings) => void;
    current: OutageFilters; // the filters on the page right now ("Use current filters")
    maps: NetworkMap[];
    anchor: { top: number; right: number } | null; // under the gear; null = a phone sheet
    onClose: () => void;
}) {
    const d = settings.clearDefaults;
    const setDefaults = (patch: Partial<OutageFilters>) => onChange({ ...settings, clearDefaults: { ...d, ...patch } });
    const mapOptions = mapsInTreeOrder(maps);
    const hasSubmaps = d.mapId !== null && maps.some((m) => m.parent_map_id === d.mapId);

    const setColumns = (columns: OutageColumn[]) => onChange({ ...settings, columns });
    const move = (i: number, by: -1 | 1) => {
        const j = i + by;
        if (j < 0 || j >= settings.columns.length) return;
        const next = [...settings.columns];
        [next[i], next[j]] = [next[j], next[i]];
        setColumns(next);
    };
    const toggle = (i: number) => setColumns(settings.columns.map((c, k) => (k === i && c.id !== 'device' ? { ...c, visible: !c.visible } : c)));
    const setFormat = (id: 'started' | 'ended', f: OutageTimeFormat) =>
        onChange({ ...settings, ...(id === 'started' ? { startedFormat: f } : { endedFormat: f }) });
    const columnsAreDefault =
        JSON.stringify(settings.columns) === JSON.stringify(DEFAULT_COLUMNS) && settings.startedFormat === 'relative' && settings.endedFormat === 'relative';

    return createPortal(
        <>
            {/* Clicking anywhere else closes the panel. */}
            <div className="fixed inset-0 z-40" aria-hidden="true" onClick={onClose} />
            <section
                aria-label="Outages page settings"
                style={anchor ? { top: anchor.top, right: anchor.right, maxHeight: `calc(100vh - ${anchor.top + 16}px)` } : undefined}
                className={`fixed z-50 overflow-y-auto rounded-2xl bg-surface-2 p-4 text-left shadow-[0_24px_60px_-16px_rgba(0,0,0,0.9)] ring-1 ring-white/10 ${
                    anchor ? 'w-[28rem]' : 'inset-x-3 top-16 max-h-[calc(100vh-5rem)]'
                }`}
            >
                <div className="mb-3 flex items-baseline justify-between gap-2">
                    <h2 className="text-sm font-bold text-white">Outages page settings</h2>
                    <span className="text-[11px] text-white/45">Saved in this browser</span>
                </div>

                {/* What Clear resets to */}
                <div className="space-y-2.5">
                    <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <h3 className={heading}>Clear resets to</h3>
                        <span className="flex gap-3">
                            <button type="button" className={linkBtn} disabled={sameFilters(current, d)} onClick={() => setDefaults(current)}>
                                Use current filters
                            </button>
                            <button type="button" className={linkBtn} disabled={sameFilters(d, BUILT_IN_FILTERS)} onClick={() => setDefaults(BUILT_IN_FILTERS)}>
                                Use My Mate&apos;s defaults
                            </button>
                        </span>
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                        <label className={fieldLabel}>
                            Time range
                            <select value={d.range} onChange={(e) => setDefaults({ range: e.target.value as OutageRange })} className={selectCls}>
                                {OUTAGE_RANGES.map((r) => (
                                    <option key={r.value} value={r.value}>
                                        {r.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className={fieldLabel}>
                            Map
                            <select
                                value={d.mapId ?? ''}
                                onChange={(e) => setDefaults({ mapId: e.target.value === '' ? null : Number(e.target.value) })}
                                className={`${selectCls} truncate`}
                            >
                                <option value="">Any map</option>
                                {mapOptions.map(({ map, depth }) => (
                                    <option key={map.id} value={map.id}>
                                        {'  '.repeat(depth)}
                                        {map.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div className={fieldLabel}>
                            Device type
                            <DeviceTypeFilter value={d.deviceTypes} onChange={(deviceTypes) => setDefaults({ deviceTypes })} className={selectCls} />
                        </div>
                        <label className={fieldLabel}>
                            Short outages
                            <select value={d.minDuration} onChange={(e) => setDefaults({ minDuration: Number(e.target.value) })} className={selectCls}>
                                <option value={0}>Show short outages</option>
                                {MIN_DURATION_OPTIONS.map((m) => (
                                    <option key={m} value={m * 60}>
                                        Hide under {m} min
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        {hasSubmaps ? (
                            <label className="flex cursor-pointer items-center gap-1.5 text-xs text-white/65">
                                <input type="checkbox" checked={d.submaps} onChange={(e) => setDefaults({ submaps: e.target.checked })} className="accent-emerald-500" />
                                Include sub-maps
                            </label>
                        ) : (
                            <span />
                        )}
                        <div className="flex items-center gap-0.5 rounded-full bg-white/5 p-0.5 ring-1 ring-white/10" role="group" aria-label="Show">
                            {(['all', 'open', 'closed'] as OutageStateFilter[]).map((f) => (
                                <button key={f} type="button" aria-pressed={d.state === f} onClick={() => setDefaults({ state: f })} className={pill(d.state === f)}>
                                    {f === 'all' ? 'All' : f === 'open' ? 'Ongoing' : 'Resolved'}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>

                <div className="my-4 h-px bg-white/[0.07]" />

                {/* Columns: shown or not, order, and the time formats */}
                <div className="space-y-1">
                    <div className="mb-1.5 flex items-baseline justify-between gap-2">
                        <h3 className={heading}>Columns</h3>
                        <button
                            type="button"
                            className={linkBtn}
                            disabled={columnsAreDefault}
                            onClick={() => onChange({ ...settings, columns: DEFAULT_COLUMNS, startedFormat: 'relative', endedFormat: 'relative' })}
                        >
                            Reset columns
                        </button>
                    </div>
                    {settings.columns.map((c, i) => {
                        const meta = OUTAGE_COLUMNS.find((m) => m.id === c.id);
                        if (!meta) return null;
                        const format = c.id === 'started' ? settings.startedFormat : c.id === 'ended' ? settings.endedFormat : null;
                        return (
                            <div key={c.id} className="flex min-h-[2rem] items-center gap-2 rounded-lg px-1 hover:bg-white/[0.03]">
                                <label className={`flex min-w-0 flex-1 items-center gap-2 text-[13px] ${c.id === 'device' ? 'text-white/45' : 'cursor-pointer text-white/85'}`}>
                                    <input
                                        type="checkbox"
                                        checked={c.visible}
                                        disabled={c.id === 'device'}
                                        onChange={() => toggle(i)}
                                        className="accent-emerald-500"
                                    />
                                    <span className="shrink-0">{meta.label}</span>
                                    {c.id === 'device' ? (
                                        <span className="truncate text-[11px]">always shown</span>
                                    ) : (
                                        meta.hint && <span className="truncate text-[11px] text-white/40">{meta.hint}</span>
                                    )}
                                </label>
                                {format !== null && (
                                    <div className="flex shrink-0 items-center gap-0.5 rounded-full bg-white/5 p-0.5 ring-1 ring-white/10" role="group" aria-label={`${meta.label} shows`}>
                                        {(['relative', 'datetime'] as OutageTimeFormat[]).map((f) => (
                                            <button
                                                key={f}
                                                type="button"
                                                aria-pressed={format === f}
                                                onClick={() => setFormat(c.id as 'started' | 'ended', f)}
                                                className={pill(format === f, true)}
                                            >
                                                {f === 'relative' ? 'Relative' : 'Date & time'}
                                            </button>
                                        ))}
                                    </div>
                                )}
                                <span className="flex shrink-0 gap-0.5">
                                    <button
                                        type="button"
                                        onClick={() => move(i, -1)}
                                        disabled={i === 0}
                                        aria-label={`Move ${meta.label} left`}
                                        title="Move left"
                                        className="grid h-7 w-7 place-items-center rounded-md text-white/50 hover:bg-white/10 hover:text-white disabled:opacity-25 disabled:hover:bg-transparent"
                                    >
                                        <ArrowUp className="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => move(i, 1)}
                                        disabled={i === settings.columns.length - 1}
                                        aria-label={`Move ${meta.label} right`}
                                        title="Move right"
                                        className="grid h-7 w-7 place-items-center rounded-md text-white/50 hover:bg-white/10 hover:text-white disabled:opacity-25 disabled:hover:bg-transparent"
                                    >
                                        <ArrowDown className="h-3.5 w-3.5" />
                                    </button>
                                </span>
                            </div>
                        );
                    })}
                    <p className="px-1 pt-1 text-[11px] text-white/45">
                        The list shows the columns in this order, top to bottom = left to right. Dates and times are in your browser&apos;s time zone; hover a time to see the other format.
                    </p>
                </div>

                <div className="mt-4 flex justify-end">
                    <button type="button" onClick={onClose} className="rounded-full bg-emerald-500 px-5 py-1.5 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400">
                        Done
                    </button>
                </div>
            </section>
        </>,
        document.body,
    );
}
