import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { ArrowRight } from '@phosphor-icons/react';
import { displayName } from '../../../lib/deviceName';
import { formatMbps } from '../../../lib/formatRate';
import { MEDIA_META } from '../lib/mediaType';
import type { Link, NetworkInterface } from '../../../types';

/** Where the pointer is over which device link (screen coordinates). */
export type LinkHover = { linkId: number; x: number; y: number };

/** The live per-direction numbers the edge is already drawing (UtilEdge data). */
export type LinkLive = {
    abMbps?: number | null;
    baMbps?: number | null;
    effAb?: number | null;
    effBa?: number | null;
    down?: boolean;
};

type End = { name: string; ip: string | null } | undefined;

const OFFSET = 14; // gap between the pointer and the card
const MARGIN = 8; // keep the card clear of the viewport edges
const DELAY_MS = 250; // sweeping the pointer across the map shouldn't flash cards

/** Compact speed ("1G", "2.5G", "100M"); null when unknown. */
function speed(mbps: number | null | undefined): string | null {
    if (mbps == null || mbps <= 0) return null;
    return mbps >= 1000 ? `${+(mbps / 1000).toFixed(mbps % 1000 === 0 ? 0 : 1)}G` : `${mbps}M`;
}

function dbm(v: number | null): string {
    return v == null ? '-' : `${v.toFixed(1)} dBm`;
}

/**
 * Hover card for a device link on the map: the two devices and, above all, which port on each
 * end the link is bound to - with that port's state, speed, optics and OSPF cost - plus the
 * link's capacity and live load each way. Read-only and pointer-transparent, so it never gets
 * in the way of clicking the link (history) or its label (remove).
 */
export function LinkTooltip({ hover, link, a, b, live }: { hover: LinkHover; link: Link; a: End; b: End; live: LinkLive | undefined }) {
    const ref = useRef<HTMLDivElement>(null);
    const [shown, setShown] = useState(false);
    const [pos, setPos] = useState({ x: hover.x + OFFSET, y: hover.y + OFFSET });

    useEffect(() => {
        const t = window.setTimeout(() => setShown(true), DELAY_MS);
        return () => window.clearTimeout(t);
    }, []);

    // Below-right of the pointer; flip to the other side rather than run off the screen.
    useLayoutEffect(() => {
        const box = ref.current?.getBoundingClientRect();
        if (!box) return;
        let x = hover.x + OFFSET;
        let y = hover.y + OFFSET;
        if (x + box.width + MARGIN > window.innerWidth) x = hover.x - OFFSET - box.width;
        if (y + box.height + MARGIN > window.innerHeight) y = hover.y - OFFSET - box.height;
        setPos({ x: Math.max(MARGIN, x), y: Math.max(MARGIN, y) });
    }, [hover.x, hover.y, shown]);

    const media = link.media_type ? MEDIA_META[link.media_type].label : null;
    const capAb = speed(live?.effAb ?? link.eff_ab_mbps);
    const capBa = speed(live?.effBa ?? link.eff_ba_mbps);
    const capacity = capAb && capBa && capAb !== capBa ? `${capAb} / ${capBa}` : capAb ?? capBa;
    const overridden = link.bw_ab_mbps != null || link.bw_ba_mbps != null;
    const hasLoad = !live?.down && (live?.abMbps != null || live?.baMbps != null);

    return createPortal(
        <div
            ref={ref}
            role="tooltip"
            style={{ left: pos.x, top: pos.y, opacity: shown ? 1 : 0 }}
            className="pointer-events-none fixed z-50 w-[22rem] max-w-[calc(100vw-16px)] rounded-2xl bg-surface/95 p-3 text-xs shadow-[0_20px_60px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10 backdrop-blur-xl transition-opacity duration-150"
        >
            <div className="grid grid-cols-2 gap-3">
                <EndColumn label="A" end={a} iface={link.a_interface} />
                <EndColumn label="B" end={b} iface={link.b_interface} />
            </div>

            <div className="mt-2.5 space-y-1 border-t border-white/10 pt-2 text-[11px]">
                <Row k="Link">
                    {[media, capacity ? `${capacity}${overridden ? ' (set on the link)' : ''}` : 'speed unknown'].filter(Boolean).join(' · ')}
                </Row>
                {live?.down ? (
                    <Row k="Load"><span className="text-rose-300">an end is down</span></Row>
                ) : hasLoad ? (
                    <>
                        <Row k="Load">
                            <Dir from="A" to="B" mbps={live?.abMbps} cap={live?.effAb} />
                        </Row>
                        <Row k="">
                            <Dir from="B" to="A" mbps={live?.baMbps} cap={live?.effBa} />
                        </Row>
                    </>
                ) : null}
            </div>
        </div>,
        document.body,
    );
}

function EndColumn({ label, end, iface }: { label: string; end: End; iface: NetworkInterface | null }) {
    return (
        <div className="min-w-0">
            <p className="text-[9px] font-medium uppercase tracking-[0.16em] text-white/35">End {label}</p>
            <p className="truncate font-semibold text-white/90" title={end?.name}>{end?.name ?? 'Unknown device'}</p>
            {end?.ip && <p className="truncate font-mono text-[10px] text-white/40">{end.ip}</p>}
            {iface ? (
                <div className="mt-1.5 space-y-0.5 text-[11px]">
                    <p className="flex items-center gap-1.5 truncate font-mono text-emerald-200" title={iface.name}>
                        <span
                            className={`h-1.5 w-1.5 shrink-0 rounded-full ${iface.oper_status === 'up' ? 'bg-emerald-400' : iface.oper_status === 'down' ? 'bg-rose-400' : 'bg-white/25'}`}
                            title={iface.oper_status ? `Port ${iface.oper_status}` : 'Port state not reported yet'}
                        />
                        <span className="truncate">{iface.name}</span>
                    </p>
                    {iface.description && iface.description !== iface.name && (
                        <p className="truncate text-white/55" title={iface.description}>{iface.description}</p>
                    )}
                    <p className="text-white/50">
                        {[iface.oper_status ?? 'state ?', speed(iface.speed_mbps) ?? 'speed ?', iface.ospf_cost != null ? `OSPF ${iface.ospf_cost}` : null]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                    {(iface.optical_rx_dbm != null || iface.optical_tx_dbm != null) && (
                        <p className="text-white/50">Rx {dbm(iface.optical_rx_dbm)} · Tx {dbm(iface.optical_tx_dbm)}</p>
                    )}
                </div>
            ) : (
                <p className="mt-1.5 text-[11px] italic text-white/40">No port (ping only)</p>
            )}
        </div>
    );
}

function Row({ k, children }: { k: string; children: ReactNode }) {
    return (
        <div className="flex gap-2">
            <span className="w-9 shrink-0 text-white/35">{k}</span>
            <span className="min-w-0 flex-1 text-white/75">{children}</span>
        </div>
    );
}

function Dir({ from, to, mbps, cap }: { from: string; to: string; mbps: number | null | undefined; cap: number | null | undefined }) {
    const pct = mbps != null && cap ? (mbps / cap) * 100 : null;
    return (
        <span className="flex items-center gap-1 tabular-nums">
            {from}
            <ArrowRight weight="bold" className="h-2.5 w-2.5 text-white/40" />
            {to}
            <span className="ml-1 text-white/90">{formatMbps(mbps ?? null, { compact: true }) || '-'}</span>
            {pct != null && <span className="text-white/45">({pct.toFixed(pct < 10 ? 1 : 0)}%)</span>}
        </span>
    );
}

/** The device facts a link end shows, from the canvas's device list. */
export function endOf(d: { name: string; alias?: string | null; mgmt_ip?: string | null } | undefined): End {
    return d ? { name: displayName(d), ip: d.mgmt_ip ?? null } : undefined;
}
