import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { CaretDown, CaretRight } from '@phosphor-icons/react';
import { fmtStamp, fmtTick } from '../../device-page/lib/format';
import type { DeviceType, OutageHistogram as Histogram, OutageHistogramGroup } from '../../../types';
import type { OutageColourBy } from '../lib/outagePrefs';

/**
 * How many devices went down in each slice of the Outages page's range, as stacked bars: each
 * segment is a map branch or a device type (the server does the counting - a device counts once
 * per bar, in one group). Hand-rolled SVG like the app's other charts, on the house palette.
 *
 * Colour follows the group, not its rank: a map keeps its colour while the page is open as the
 * range and filters change (MAP_COLOURS below), and each device type always has the same one.
 * "Other" is grey with a hatch so it never reads as a real group; "Not on a map" is plain grey.
 *
 * Hover a bar for its counts; click one to narrow the list to that slice (click again to clear);
 * click a legend entry to filter the page to that map or type.
 */

// The house categorical palette (GraphChart's GRAPH_PALETTE, first 7), validated for CVD
// separation and contrast on both the dark and light surfaces.
const PALETTE = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9'];
const NEUTRAL = '#7b828c';
const TYPE_COLOURS: Record<DeviceType, string> = {
    router: PALETTE[0],
    switch: PALETTE[1],
    ap: PALETTE[2],
    server: PALETTE[3],
    internet: PALETTE[4],
    unknown: NEUTRAL,
};

// Map group key -> palette slot, kept for the life of the page so a map doesn't change colour
// when another one drops out of the top groups.
const MAP_COLOURS = new Map<string, number>();

function mapColours(keys: string[]): Map<string, string> {
    const out = new Map<string, string>();
    const used = new Set<number>();
    // Keep each group's earlier colour where no other group in this set already holds it...
    for (const k of keys) {
        const slot = MAP_COLOURS.get(k);
        if (slot !== undefined && !used.has(slot)) {
            used.add(slot);
            out.set(k, PALETTE[slot]);
        }
    }
    // ...and give the rest the first free slot (there are never more groups than slots).
    for (const k of keys) {
        if (out.has(k)) continue;
        const slot = PALETTE.findIndex((_, i) => !used.has(i));
        const s = slot === -1 ? 0 : slot;
        used.add(s);
        MAP_COLOURS.set(k, s);
        out.set(k, PALETTE[s]);
    }
    return out;
}

const HEIGHT = 150;
const M = { top: 8, right: 6, bottom: 20, left: 30 };
const GAP = 2; // surface gap between stacked segments

/** A rect with only its top corners rounded (the data end); the base sits on the axis. */
function topRounded(x: number, y: number, w: number, h: number, r: number): string {
    const rr = Math.max(0, Math.min(r, w / 2, h));
    return `M${x},${y + h}V${y + rr}Q${x},${y} ${x + rr},${y}H${x + w - rr}Q${x + w},${y} ${x + w},${y + rr}V${y + h}Z`;
}

const pill = (active: boolean) =>
    `rounded-full px-2.5 py-0.5 text-[11px] font-medium transition-colors duration-200 ${
        active ? 'bg-white/10 text-white/90 ring-1 ring-white/15' : 'text-white/45 hover:text-white/75'
    }`;

export interface OutageHistogramProps {
    data: Histogram | undefined;
    isLoading: boolean;
    rangeLabel: string;
    colourBy: OutageColourBy;
    onColourBy: (c: OutageColourBy) => void;
    collapsed: boolean;
    onToggleCollapsed: () => void;
    selectedStart: string | null; // the bar the list is narrowed to
    onSelectBar: (bar: { start: string; end: string } | null) => void;
    onPickGroup: (g: OutageHistogramGroup) => void;
}

export function OutageHistogram(p: OutageHistogramProps) {
    const { data } = p;
    const hatchId = useId().replace(/:/g, '');
    const wrapRef = useRef<HTMLDivElement>(null);
    const [width, setWidth] = useState(0);
    const [hover, setHover] = useState<number | null>(null);

    useEffect(() => {
        const el = wrapRef.current;
        if (!el) return;
        const ro = new ResizeObserver(([e]) => setWidth(Math.floor(e.contentRect.width)));
        ro.observe(el);
        return () => ro.disconnect();
    }, [p.collapsed]);

    const groups = data?.groups ?? [];
    const buckets = data?.buckets ?? [];
    const colours = useMemo(() => {
        const out = new Map<string, string>();
        const mapKeys = groups.filter((g) => g.key.startsWith('map:')).map((g) => g.key);
        const byMap = mapColours(mapKeys);
        for (const g of groups) {
            if (g.key === 'other') out.set(g.key, `url(#${hatchId})`);
            else if (g.key === 'none') out.set(g.key, NEUTRAL);
            else if (g.type) out.set(g.key, TYPE_COLOURS[g.type] ?? NEUTRAL);
            else out.set(g.key, byMap.get(g.key) ?? NEUTRAL);
        }
        return out;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [groups.map((g) => g.key).join('|'), hatchId]);

    const totalDevices = groups.reduce((n, g) => n + g.devices, 0);
    const maxTotal = buckets.reduce((m, b) => Math.max(m, b.total), 0);
    const yMax = maxTotal <= 4 ? Math.max(1, maxTotal) : niceCount(maxTotal);
    const plotW = Math.max(0, width - M.left - M.right);
    const plotH = HEIGHT - M.top - M.bottom;
    const slot = buckets.length > 0 ? plotW / buckets.length : 0;
    const barW = Math.max(1, slot - Math.min(6, Math.max(1, slot * 0.25)));
    const y = (v: number) => M.top + plotH - (v / yMax) * plotH;
    // Counts are whole devices: no half-way tick unless it's a whole number too.
    const yTicks = yMax <= 4 ? Array.from({ length: yMax + 1 }, (_, i) => i) : [0, ...(Number.isInteger(yMax / 2) ? [yMax / 2] : []), yMax];
    const spanSec = buckets.length * (data?.bucket_s ?? 0);
    const xEvery = Math.max(1, Math.ceil(buckets.length / Math.max(2, Math.floor(plotW / 90))));
    const hovered = hover !== null ? buckets[hover] : undefined;

    return (
        <section className="mb-4 rounded-2xl bg-white/[0.02] ring-1 ring-white/[0.06]">
            <div className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5">
                <button
                    type="button"
                    onClick={p.onToggleCollapsed}
                    aria-expanded={!p.collapsed}
                    className="flex items-center gap-2 text-left text-xs font-medium text-white/75 hover:text-white"
                >
                    {p.collapsed ? <CaretRight className="h-3.5 w-3.5" /> : <CaretDown className="h-3.5 w-3.5" />}
                    Devices going down
                    <span className="font-normal text-white/40">
                        {data ? `${totalDevices} device${totalDevices === 1 ? '' : 's'} · ${p.rangeLabel.toLowerCase()}` : p.rangeLabel.toLowerCase()}
                    </span>
                </button>
                {!p.collapsed && (
                    <div className="flex items-center gap-1.5 text-[11px] text-white/40">
                        Colour by
                        <div className="flex items-center gap-0.5 rounded-full bg-white/5 p-0.5 ring-1 ring-white/10">
                            <button type="button" className={pill(p.colourBy === 'map')} onClick={() => p.onColourBy('map')}>
                                Map
                            </button>
                            <button type="button" className={pill(p.colourBy === 'type')} onClick={() => p.onColourBy('type')}>
                                Type
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {!p.collapsed && (
                <div className="px-4 pb-3">
                    <div ref={wrapRef} className="relative" style={{ height: HEIGHT }} onMouseLeave={() => setHover(null)}>
                        {p.isLoading && !data ? (
                            <div className="h-full animate-pulse rounded-lg bg-white/[0.03]" />
                        ) : width > 0 ? (
                            <svg
                                width={width}
                                height={HEIGHT}
                                role="img"
                                aria-label={`${totalDevices} devices went down, ${p.rangeLabel.toLowerCase()}, by ${p.colourBy === 'map' ? 'map' : 'device type'}`}
                                className="block select-none"
                            >
                                <defs>
                                    <pattern id={hatchId} width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                                        <rect width="5" height="5" fill={NEUTRAL} fillOpacity={0.45} />
                                        <line x1="0" y1="0" x2="0" y2="5" stroke={NEUTRAL} strokeWidth="2.5" />
                                    </pattern>
                                </defs>
                                {yTicks.map((t) => (
                                    <g key={t}>
                                        <line
                                            x1={M.left}
                                            x2={width - M.right}
                                            y1={y(t)}
                                            y2={y(t)}
                                            stroke="rgb(var(--graph-axis))"
                                            strokeOpacity={t === 0 ? 0.25 : 0.07}
                                        />
                                        <text x={M.left - 6} y={y(t) + 3.5} textAnchor="end" fontSize={10} fill="rgb(var(--graph-axis))" fillOpacity={0.45}>
                                            {Number.isInteger(t) ? t : t.toFixed(1)}
                                        </text>
                                    </g>
                                ))}
                                {buckets.map((b, i) => {
                                    const x = M.left + i * slot + (slot - barW) / 2;
                                    const dim = (p.selectedStart !== null && p.selectedStart !== b.start) || (hover !== null && hover !== i && p.selectedStart === null);
                                    let acc = 0;
                                    const segs = groups
                                        .filter((g) => (b.counts[g.key] ?? 0) > 0)
                                        .map((g, j, arr) => {
                                            const v = b.counts[g.key];
                                            const y0 = y(acc);
                                            acc += v;
                                            const y1 = y(acc);
                                            const top = j === arr.length - 1;
                                            const h = y0 - y1 - (top || y0 - y1 <= GAP + 1 ? 0 : GAP);
                                            const yy = y0 - h;
                                            return (
                                                <path
                                                    key={g.key}
                                                    d={top ? topRounded(x, yy, barW, h, 3) : `M${x},${yy}h${barW}v${h}h${-barW}Z`}
                                                    fill={colours.get(g.key) ?? NEUTRAL}
                                                />
                                            );
                                        });
                                    return (
                                        <g key={b.start} opacity={dim ? 0.35 : 1}>
                                            {segs}
                                        </g>
                                    );
                                })}
                                {buckets.map((b, i) =>
                                    i % xEvery === 0 ? (
                                        <text
                                            key={`x-${b.start}`}
                                            x={M.left + i * slot + slot / 2}
                                            y={HEIGHT - 5}
                                            textAnchor="middle"
                                            fontSize={10}
                                            fill="rgb(var(--graph-axis))"
                                            fillOpacity={0.45}
                                        >
                                            {fmtTick(new Date(b.start).getTime(), spanSec)}
                                        </text>
                                    ) : null,
                                )}
                                {/* Hit targets: the full height of each slot, wider than the bar. */}
                                {buckets.map((b, i) => (
                                    <rect
                                        key={`hit-${b.start}`}
                                        x={M.left + i * slot}
                                        y={M.top}
                                        width={slot}
                                        height={plotH}
                                        fill="transparent"
                                        className={b.total > 0 || p.selectedStart === b.start ? 'cursor-pointer' : ''}
                                        onMouseEnter={() => setHover(i)}
                                        onClick={() => {
                                            if (p.selectedStart === b.start) p.onSelectBar(null);
                                            else if (b.total > 0) p.onSelectBar({ start: b.start, end: b.end });
                                        }}
                                    />
                                ))}
                                {data && maxTotal === 0 && (
                                    <text x={M.left + plotW / 2} y={M.top + plotH / 2} textAnchor="middle" fontSize={12} fill="rgb(var(--graph-axis))" fillOpacity={0.45}>
                                        No devices went down in this range
                                    </text>
                                )}
                            </svg>
                        ) : null}

                        {hovered && hover !== null && (
                            <div
                                className="pointer-events-none absolute top-0 z-10 min-w-[11rem] max-w-[16rem] rounded-lg bg-surface/95 px-2.5 py-1.5 text-[11px] shadow-[0_8px_24px_-8px_rgba(0,0,0,0.8)] ring-1 ring-white/10"
                                style={
                                    M.left + hover * slot + slot / 2 > width / 2
                                        ? { right: width - (M.left + hover * slot) + 6 }
                                        : { left: M.left + (hover + 1) * slot + 6 }
                                }
                            >
                                <div className="mb-1 font-mono text-[10px] text-white/45">
                                    {fmtStamp(new Date(hovered.start).getTime())} – {fmtEnd(hovered.start, hovered.end)}
                                </div>
                                <div className="mb-1 font-medium text-white/85">
                                    {hovered.total} device{hovered.total === 1 ? '' : 's'} went down
                                </div>
                                {groups
                                    .filter((g) => (hovered.counts[g.key] ?? 0) > 0)
                                    .map((g) => (
                                        <div key={g.key} className="flex items-center gap-1.5 text-white/65">
                                            <Swatch fill={colours.get(g.key) ?? NEUTRAL} hatchId={hatchId} other={g.key === 'other'} />
                                            <span className="min-w-0 flex-1 truncate">{g.label}</span>
                                            <span className="tabular-nums text-white/85">{hovered.counts[g.key]}</span>
                                        </div>
                                    ))}
                                {hovered.total > 0 && (
                                    <div className="mt-1 text-[10px] text-white/35">
                                        {p.selectedStart === hovered.start ? 'Click to show the whole range' : 'Click to list just these'}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    {groups.length > 0 && (
                        <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1">
                            {groups.map((g) => {
                                const pickable = g.map_id !== null || g.type !== null;
                                const body = (
                                    <>
                                        <Swatch fill={colours.get(g.key) ?? NEUTRAL} hatchId={hatchId} other={g.key === 'other'} />
                                        <span className="max-w-[14rem] truncate">{g.label}</span>
                                        <span className="tabular-nums text-white/40">{g.devices}</span>
                                    </>
                                );
                                return pickable ? (
                                    <button
                                        key={g.key}
                                        type="button"
                                        onClick={() => p.onPickGroup(g)}
                                        title={g.map_id !== null ? `Show only outages on ${g.label.replace(/ \(this map\)$/, '')}` : `Show only ${g.label.toLowerCase()} outages`}
                                        className="flex items-center gap-1.5 text-[11px] text-white/65 transition-colors hover:text-white"
                                    >
                                        {body}
                                    </button>
                                ) : (
                                    <span key={g.key} className="flex items-center gap-1.5 text-[11px] text-white/50">
                                        {body}
                                    </span>
                                );
                            })}
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}

function Swatch({ fill, hatchId, other }: { fill: string; hatchId: string; other: boolean }) {
    return (
        <svg width="10" height="10" className="shrink-0" aria-hidden>
            {/* The hatch pattern lives in the chart's SVG; repeat its look here. */}
            {other ? (
                <>
                    <rect width="10" height="10" rx="2" fill={NEUTRAL} fillOpacity={0.45} />
                    <path d="M0,10L10,0M-2,4L4,-2M6,12L12,6" stroke={NEUTRAL} strokeWidth="2" />
                </>
            ) : (
                <rect width="10" height="10" rx="2" fill={fill === `url(#${hatchId})` ? NEUTRAL : fill} />
            )}
        </svg>
    );
}

/** Round a count axis up to 5, 10, 20, 25, 50, 100, ... */
function niceCount(v: number): number {
    const p = Math.pow(10, Math.floor(Math.log10(v)));
    for (const m of [1, 2, 2.5, 5, 10]) {
        if (v <= m * p && Number.isInteger(m * p)) return m * p;
    }
    return 10 * p;
}

/** A bar's end: just the time when it's the same day as its start. */
function fmtEnd(start: string, end: string): string {
    const s = new Date(start);
    const e = new Date(end);
    return s.toDateString() === e.toDateString()
        ? e.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        : fmtStamp(e.getTime());
}
