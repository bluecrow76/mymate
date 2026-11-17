import { useMemo, useState } from 'react';
import { CaretDown, CaretRight, MagnifyingGlass } from '@phosphor-icons/react';
import { useDeviceNeighbours, type NeighbourDeviceRef, type NeighbourPort } from '../api/neighbours';
import { openDevicePage } from '../lib/location';
import { relativeTime } from '../../../lib/relativeTime';
import { Card, Empty } from './ui';
import type { Device } from '../../../types';

const th = 'px-2 py-1.5 text-left text-[10px] font-medium uppercase tracking-[0.14em] text-white/30';
const td = 'px-2 py-1.5 align-top';
const mono = 'font-mono text-[12px] tabular-nums';

/** A matched My Mate device, as a link to its page. */
function DeviceLink({ device }: { device: NeighbourDeviceRef | null }) {
    if (!device) return <span className="text-white/25">-</span>;
    return (
        <button type="button" onClick={() => openDevicePage(device.id)} className="text-left text-emerald-300 hover:text-emerald-200 hover:underline">
            {device.name}
        </button>
    );
}

function Seen({ current, lastSeen }: { current: boolean; lastSeen: string | null }) {
    return current ? (
        <span className="text-white/45">now</span>
    ) : (
        <span className="text-amber-300/80" title="Not seen by the latest read - history">
            {relativeTime(lastSeen)}
        </span>
    );
}

const portLabel = (p: NeighbourPort) => p.interface?.name ?? `port ${p.port}`;

/**
 * What the device can see of its neighbours, as collected over SNMP: LLDP / MikroTik MNDP
 * neighbours, the MACs behind each port (with their IP from ARP and the My Mate device they
 * belong to, where known), its ARP table and its own addresses. A read-only preview of the data
 * link suggestions will be built on. Not offered to restricted operators.
 */
export function NeighboursTab({ device }: { device: Device }) {
    const [history, setHistory] = useState(false);
    const [filter, setFilter] = useState('');
    const [open, setOpen] = useState<Record<string, boolean>>({});
    const [showArp, setShowArp] = useState(false);
    const { data, isLoading, error } = useDeviceNeighbours(device.id, history);

    const q = filter.trim().toLowerCase();
    const hit = (...values: (string | null | undefined)[]) => !q || values.some((v) => v?.toLowerCase().includes(q));

    const ports = useMemo(() => {
        if (!data) return [];
        if (!q) return data.ports;
        return data.ports
            .map((p) => ({ ...p, macs: p.macs.filter((m) => hit(m.mac, m.ip, m.device?.name, portLabel(p))) }))
            .filter((p) => p.macs.length > 0);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, q]);

    if (isLoading) return <Empty>Loading neighbour data...</Empty>;
    if (error || !data) return <Empty>Couldn't load the neighbour data.</Empty>;

    const neighbours = data.neighbours.filter((n) =>
        hit(n.remote_name, n.remote_ip, n.remote_mac, n.remote_chassis_id, n.remote_port_id, n.remote_port_description, n.interface?.name, n.device?.name),
    );
    const arp = data.arp.filter((a) => hit(a.ip, a.mac, a.device?.name, a.interface?.name));
    const nothingYet = data.collected_at === null;

    return (
        <div className="space-y-3">
            <Card
                title="Collection"
                right={
                    <label className="flex cursor-pointer items-center gap-1.5 text-[11px] text-white/50">
                        <input
                            type="checkbox"
                            checked={history}
                            onChange={(e) => setHistory(e.target.checked)}
                            className="h-3.5 w-3.5 rounded border-white/20 bg-white/[0.05] text-emerald-500 focus:ring-emerald-400/60"
                        />
                        Include older rows
                    </label>
                }
            >
                <div className="space-y-1.5 text-xs text-white/60">
                    {!data.supported && <p className="text-amber-300/80">Neighbour data is only collected for SNMP-polled devices on this server (not ping-only, RouterOS API or agent devices).</p>}
                    {!data.enabled && <p className="text-amber-300/80">Collection is turned off in Settings &gt; Engine.</p>}
                    <p>
                        Last read: <span className="text-white/85">{data.collected_at ? relativeTime(data.collected_at) : 'not yet'}</span>
                        {data.supported && data.enabled && <span className="text-white/35"> · read about every {Math.round(data.interval_s / 60)} min</span>}
                    </p>
                    {data.error && <p className="text-rose-300">Last read failed: {data.error}</p>}
                    {data.addresses.length > 0 && (
                        <p>
                            Own addresses:{' '}
                            <span className={`${mono} text-white/75`}>
                                {data.addresses.map((a) => `${a.ip}${a.prefix_length !== null ? `/${a.prefix_length}` : ''}${a.interface ? ` (${a.interface.name})` : ''}`).join(', ')}
                            </span>
                        </p>
                    )}
                </div>
                {!nothingYet && (
                    <div className="relative mt-3">
                        <MagnifyingGlass className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-white/30" />
                        <input
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                            placeholder="Filter by MAC, IP, name or port"
                            className="w-full rounded-lg bg-white/[0.04] py-1.5 pl-8 pr-3 text-xs text-white placeholder:text-white/30 outline-none ring-1 ring-white/10 focus:ring-emerald-400/40"
                        />
                    </div>
                )}
            </Card>

            {nothingYet ? (
                <Empty>Nothing collected for this device yet.</Empty>
            ) : (
                <>
                    <Card title={`Neighbours (LLDP / MNDP) · ${neighbours.length}`}>
                        {neighbours.length === 0 ? (
                            <Empty>{q ? 'No neighbours match the filter.' : 'This device reports no LLDP or MNDP neighbours.'}</Empty>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[720px] text-xs text-white/75">
                                    <thead>
                                        <tr className="border-b border-white/[0.06]">
                                            <th className={th}>Local port</th>
                                            <th className={th}>Neighbour</th>
                                            <th className={th}>IP / MAC</th>
                                            <th className={th}>Their port</th>
                                            <th className={th}>Platform</th>
                                            <th className={th}>In My Mate</th>
                                            <th className={th}>Seen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {neighbours.map((n, i) => (
                                            <tr key={i} className="border-b border-white/[0.04] last:border-0">
                                                <td className={td}>
                                                    <span className="mr-1.5 rounded bg-white/[0.06] px-1 py-px text-[9px] font-semibold uppercase text-white/50">{n.protocol}</span>
                                                    {n.interface?.name ?? (n.local_if_index || '-')}
                                                </td>
                                                <td className={td}>{n.remote_name ?? '-'}</td>
                                                <td className={`${td} ${mono}`}>
                                                    <div>{n.remote_ip ?? '-'}</div>
                                                    <div className="text-white/40">{n.remote_mac ?? n.remote_chassis_id ?? ''}</div>
                                                </td>
                                                <td className={td}>
                                                    {n.remote_port_id ?? <span className="text-white/25" title="MNDP doesn't report the remote port">-</span>}
                                                    {n.remote_port_description && n.remote_port_description !== n.remote_port_id && (
                                                        <div className="text-white/40">{n.remote_port_description}</div>
                                                    )}
                                                </td>
                                                <td className={td}>
                                                    {n.remote_platform ?? (n.remote_capabilities?.join(', ') || '-')}
                                                    {n.remote_version && <div className="truncate text-white/40" title={n.remote_version}>{n.remote_version.slice(0, 40)}</div>}
                                                </td>
                                                <td className={td}>
                                                    <DeviceLink device={n.device} />
                                                </td>
                                                <td className={td}>
                                                    <Seen current={n.current} lastSeen={n.last_seen_at} />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>

                    <Card title={`MAC table by port · ${ports.length} port${ports.length === 1 ? '' : 's'}`}>
                        {data.truncated && <p className="mb-2 text-[11px] text-amber-300/80">Showing the first 5,000 MACs only.</p>}
                        {ports.length === 0 ? (
                            <Empty>{q ? 'No MACs match the filter.' : 'No MAC table entries collected (this device may not expose its bridge table over SNMP).'}</Empty>
                        ) : (
                            <div className="divide-y divide-white/[0.04]">
                                {ports.map((p) => {
                                    const key = `${p.interface?.id ?? 'p'}-${p.port}`;
                                    const expanded = open[key] ?? !!q;
                                    const known = [...new Map(p.macs.filter((m) => m.device && m.current).map((m) => [m.device!.id, m.device!])).values()];
                                    return (
                                        <div key={key} className="py-1">
                                            <button
                                                type="button"
                                                onClick={() => setOpen((o) => ({ ...o, [key]: !expanded }))}
                                                className="flex w-full items-center gap-2 rounded px-1 py-1 text-left text-xs hover:bg-white/[0.03]"
                                            >
                                                {expanded ? <CaretDown className="h-3 w-3 text-white/40" /> : <CaretRight className="h-3 w-3 text-white/40" />}
                                                <span className="w-28 shrink-0 truncate font-medium text-white/85">{portLabel(p)}</span>
                                                <span className="w-20 shrink-0 tabular-nums text-white/60">
                                                    {p.current_count} MAC{p.current_count === 1 ? '' : 's'}
                                                    {p.macs.length !== p.current_count && <span className="text-amber-300/70"> +{p.macs.length - p.current_count}</span>}
                                                </span>
                                                <span className="min-w-0 flex-1 truncate text-white/45">
                                                    {p.interface?.description && <span className="mr-2 text-white/35">{p.interface.description}</span>}
                                                    {known.length > 0 && <span className="text-emerald-300/80">{known.slice(0, 5).map((d) => d.name).join(', ')}{known.length > 5 ? ` +${known.length - 5}` : ''}</span>}
                                                </span>
                                            </button>
                                            {expanded && (
                                                <div className="overflow-x-auto pl-6">
                                                    <table className="w-full min-w-[560px] text-xs text-white/75">
                                                        <thead>
                                                            <tr className="border-b border-white/[0.06]">
                                                                <th className={th}>MAC</th>
                                                                <th className={th}>IP (from ARP)</th>
                                                                <th className={th}>In My Mate</th>
                                                                <th className={th}>Seen</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {p.macs.map((m) => (
                                                                <tr key={m.mac} className="border-b border-white/[0.04] last:border-0">
                                                                    <td className={`${td} ${mono}`}>{m.mac}</td>
                                                                    <td className={`${td} ${mono}`}>{m.ip ?? <span className="text-white/25">-</span>}</td>
                                                                    <td className={td}>
                                                                        <DeviceLink device={m.device} />
                                                                    </td>
                                                                    <td className={td}>
                                                                        <Seen current={m.current} lastSeen={m.last_seen_at} />
                                                                    </td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </Card>

                    <Card
                        title={`ARP · ${arp.length}`}
                        right={
                            data.arp.length > 0 && (
                                <button type="button" onClick={() => setShowArp((s) => !s)} className="text-[11px] text-white/50 hover:text-white/80">
                                    {showArp || q ? 'Hide' : 'Show'}
                                </button>
                            )
                        }
                    >
                        {data.arp.length === 0 ? (
                            <Empty>No ARP entries collected.</Empty>
                        ) : (showArp || q) && (
                            arp.length === 0 ? (
                                <Empty>No ARP entries match the filter.</Empty>
                            ) : (
                                <div className="max-h-[480px] overflow-auto">
                                    <table className="w-full min-w-[560px] text-xs text-white/75">
                                        <thead className="sticky top-0 bg-surface">
                                            <tr className="border-b border-white/[0.06]">
                                                <th className={th}>IP</th>
                                                <th className={th}>MAC</th>
                                                <th className={th}>Interface</th>
                                                <th className={th}>In My Mate</th>
                                                <th className={th}>Seen</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {arp.map((a) => (
                                                <tr key={`${a.ip}-${a.mac}`} className="border-b border-white/[0.04] last:border-0">
                                                    <td className={`${td} ${mono}`}>{a.ip}</td>
                                                    <td className={`${td} ${mono}`}>{a.mac}</td>
                                                    <td className={td}>{a.interface?.name ?? '-'}</td>
                                                    <td className={td}>
                                                        <DeviceLink device={a.device} />
                                                    </td>
                                                    <td className={td}>
                                                        <Seen current={a.current} lastSeen={a.last_seen_at} />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )
                        )}
                    </Card>
                </>
            )}
        </div>
    );
}
