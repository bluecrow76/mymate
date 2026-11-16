import { useEffect, useState, type Dispatch, type FormEvent, type SetStateAction } from 'react';
import { Plus, Power, TrashSimple, MagnifyingGlass, CircleNotch, MapPin } from '@phosphor-icons/react';
import { useQueryClient } from '@tanstack/react-query';
import { useSubnets } from '../api/getSubnets';
import { useCreateSubnet } from '../api/createSubnet';
import { useUpdateSubnet } from '../api/updateSubnet';
import { useDeleteSubnet } from '../api/deleteSubnet';
import { useScanSubnet } from '../api/scanSubnet';
import { candidateKeys } from '../api/getCandidates';
import { useAgents } from '../../settings/api/agents';
import { useIsAdmin } from '../../auth/api/auth';
import { relativeTime } from '../../../lib/relativeTime';
import { useMaps } from '../../maps/api/maps';
import type { NetworkMap, Subnet, SubnetPlacement } from '../../../types';

const field =
    'w-full rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none ' +
    'transition duration-300 ease-fluid placeholder:text-white/30 focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60';

/** Where on its map a device added from a range goes - see App\Support\MapPlacement. */
const PLACEMENTS: { value: SubnetPlacement; label: string }[] = [
    { value: 'right', label: 'Down the right-hand column' },
    { value: 'left', label: 'Down the left-hand column' },
    { value: 'bottom', label: 'Along the bottom row' },
    { value: 'top', label: 'Along the top row' },
];

/** Maps parent-first, indented by depth, for the "add devices to" picker. */
function mapsInTreeOrder(maps: NetworkMap[]): { map: NetworkMap; depth: number }[] {
    const ids = new Set(maps.map((m) => m.id));
    const children = new Map<number | null, NetworkMap[]>();
    for (const m of maps) {
        const parent = m.parent_map_id !== null && ids.has(m.parent_map_id) ? m.parent_map_id : null;
        children.set(parent, [...(children.get(parent) ?? []), m]);
    }
    const out: { map: NetworkMap; depth: number }[] = [];
    const seen = new Set<number>();
    const walk = (parent: number | null, depth: number) => {
        for (const m of [...(children.get(parent) ?? [])].sort((a, b) => a.position - b.position || a.name.localeCompare(b.name))) {
            if (seen.has(m.id)) continue;
            seen.add(m.id);
            out.push({ map: m, depth });
            walk(m.id, depth + 1);
        }
    };
    walk(null, 0);
    return out;
}

/** The two "where do added devices go" pickers, shared by the add form and a range's editor. */
function PlacementFields({
    mapId,
    placement,
    onMap,
    onPlacement,
    maps,
    disabled,
}: {
    mapId: number | null;
    placement: SubnetPlacement;
    onMap: (id: number | null) => void;
    onPlacement: (p: SubnetPlacement) => void;
    maps: NetworkMap[];
    disabled?: boolean;
}) {
    const defaultMap = maps.find((m) => m.is_default);
    return (
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <select
                value={mapId ?? ''}
                disabled={disabled}
                onChange={(e) => onMap(e.target.value ? Number(e.target.value) : null)}
                title="The map a device added from this range goes on"
                aria-label="Add devices to map"
                className={field}
            >
                <option value="">Add to the default map{defaultMap ? ` (${defaultMap.name})` : ''}</option>
                {mapsInTreeOrder(maps).map(({ map, depth }) => (
                    <option key={map.id} value={map.id}>
                        {'\u00a0\u00a0'.repeat(depth)}Add to {map.name}
                    </option>
                ))}
            </select>
            <select
                value={placement}
                disabled={disabled}
                onChange={(e) => onPlacement(e.target.value as SubnetPlacement)}
                title="Where on that map: the next free spot along this edge of the existing cards"
                aria-label="Placement on the map"
                className={field}
            >
                {PLACEMENTS.map((p) => (
                    <option key={p.value} value={p.value}>
                        {p.label}
                    </option>
                ))}
            </select>
        </div>
    );
}

/**
 * Scan-range manager. `scanning` is owned by the parent (DiscoveryView) so the
 * review queue can show a banner too. We map subnet id -> its `last_scanned_at` at
 * scan-start and clear the flag once that timestamp advances (sweep finished); a
 * 90s fallback covers a stack with no scan worker running.
 */
export function SubnetManager({
    scanning,
    setScanning,
}: {
    scanning: Record<number, string | null>;
    setScanning: Dispatch<SetStateAction<Record<number, string | null>>>;
}) {
    const isAdmin = useIsAdmin();
    const qc = useQueryClient();
    const anyScanning = Object.keys(scanning).length > 0;
    const { data: subnets } = useSubnets({ refetchInterval: anyScanning ? 2500 : false });
    const create = useCreateSubnet();
    const update = useUpdateSubnet();
    const remove = useDeleteSubnet();
    const scan = useScanSubnet();
    const { data: agents } = useAgents();
    const [cidr, setCidr] = useState('');
    const [label, setLabel] = useState('');
    const [agentId, setAgentId] = useState<number | null>(null);
    const [mapId, setMapId] = useState<number | null>(null);
    const [placement, setPlacement] = useState<SubnetPlacement>('right');
    const [editing, setEditing] = useState<number | null>(null); // range whose map settings are open
    const { data: maps } = useMaps();
    const mapName = (id: number | null) => (id === null ? null : maps?.find((m) => m.id === id)?.name ?? null);
    const agentName = (id: number | null) => agents?.find((a) => a.id === id)?.name;

    // A scanning subnet whose last_scanned_at has advanced has finished its sweep.
    useEffect(() => {
        if (!subnets) return;
        const done = subnets.filter((s) => s.id in scanning && s.last_scanned_at !== scanning[s.id]);
        if (done.length === 0) return;
        setScanning((prev) => {
            const next = { ...prev };
            for (const s of done) delete next[s.id];
            return next;
        });
        qc.invalidateQueries({ queryKey: candidateKeys.all });
    }, [subnets, scanning, setScanning, qc]);

    function submit(e: FormEvent) {
        e.preventDefault();
        if (!cidr.trim()) return;
        create.mutate(
            { cidr: cidr.trim(), label: label.trim() || null, agent_id: agentId, map_id: mapId, placement },
            { onSuccess: () => { setCidr(''); setLabel(''); setAgentId(null); setMapId(null); setPlacement('right'); } },
        );
    }

    function startScan(s: Subnet) {
        setScanning((prev) => ({ ...prev, [s.id]: s.last_scanned_at }));
        scan.mutate(s.id);
        window.setTimeout(() => {
            setScanning((prev) => {
                if (!(s.id in prev)) return prev;
                const next = { ...prev };
                delete next[s.id];
                return next;
            });
        }, 90_000);
    }

    return (
        <section className="space-y-3">
            <p className="text-[10px] font-medium uppercase tracking-[0.2em] text-white/30">Scan ranges</p>

            {isAdmin && (
            <form onSubmit={submit} className="space-y-2">
                <input value={cidr} onChange={(e) => setCidr(e.target.value)} placeholder="CIDR - e.g. 10.80.111.0/24" className={field} />
                <div className="flex gap-2">
                    <input value={label} onChange={(e) => setLabel(e.target.value)} placeholder="Label (optional)" className={field} />
                    <button
                        type="submit"
                        disabled={create.isPending || !cidr.trim()}
                        title="Add subnet"
                        className="flex shrink-0 items-center gap-1.5 rounded-xl bg-emerald-500 px-4 text-sm font-semibold text-emerald-950 transition-all duration-300 ease-fluid hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                    >
                        <Plus weight="bold" className="h-4 w-4" />
                    </button>
                </div>
                {agents && agents.length > 0 && (
                    <select
                        value={agentId ?? ''}
                        onChange={(e) => setAgentId(e.target.value ? Number(e.target.value) : null)}
                        title="Where to scan this range from"
                        className={field}
                    >
                        <option value="">Scan centrally (from this server)</option>
                        {agents.map((a) => (
                            <option key={a.id} value={a.id}>
                                Scan via agent: {a.name}
                            </option>
                        ))}
                    </select>
                )}
                <PlacementFields mapId={mapId} placement={placement} onMap={setMapId} onPlacement={setPlacement} maps={maps ?? []} />
                {create.isError && <p className="px-1 text-xs text-rose-400/90">Couldn't add - enter a valid IPv4 CIDR (and not a duplicate).</p>}
            </form>
            )}

            <ul className="space-y-1">
                {subnets?.length === 0 && (
                    <li className="rounded-xl px-3 py-2 text-xs text-white/35">No ranges yet - add one to start discovering.</li>
                )}
                {subnets?.map((s) => {
                    const isScanning = s.scanning || s.id in scanning;
                    return (
                        <li key={s.id} className="rounded-xl transition-colors duration-300 ease-fluid hover:bg-white/[0.04]">
                        <div className="group flex items-center justify-between gap-2 px-2.5 py-2">
                            <span className="min-w-0">
                                <span className="block truncate font-mono text-sm text-white/85">{s.cidr}</span>
                                <span className="block truncate text-xs">
                                    {s.label ? <span className="text-white/35">{s.label} - </span> : null}
                                    {s.agent_id ? <span className="text-sky-300/70">via {agentName(s.agent_id) ?? 'agent'} - </span> : null}
                                    {mapName(s.map_id) ? <span className="text-indigo-300/70">→ {mapName(s.map_id)} - </span> : null}
                                    {isScanning ? (
                                        <span className="text-emerald-300/80">scanning...</span>
                                    ) : (
                                        <span className="text-white/35">scanned {relativeTime(s.last_scanned_at)}</span>
                                    )}
                                </span>
                            </span>
                            {isAdmin && (
                            <span className="flex shrink-0 items-center gap-0.5">
                                <button
                                    onClick={() => startScan(s)}
                                    disabled={isScanning || !s.enabled}
                                    title={s.enabled ? 'Scan now' : 'Enable to scan'}
                                    className="rounded-lg p-1.5 text-white/40 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-emerald-300 disabled:opacity-40 disabled:hover:bg-transparent"
                                >
                                    {isScanning ? (
                                        <CircleNotch weight="bold" className="h-4 w-4 animate-spin text-emerald-300" />
                                    ) : (
                                        <MagnifyingGlass weight="light" className="h-4 w-4" />
                                    )}
                                </button>
                                <button
                                    onClick={() => update.mutate({ id: s.id, enabled: !s.enabled })}
                                    title={s.enabled ? 'Enabled - click to pause' : 'Paused - click to enable'}
                                    className={
                                        'rounded-lg p-1.5 transition-colors duration-300 ease-fluid ' +
                                        (s.enabled
                                            ? 'text-emerald-400 hover:bg-emerald-400/10'
                                            : 'text-white/25 hover:bg-white/5 hover:text-white/60')
                                    }
                                >
                                    <Power weight={s.enabled ? 'fill' : 'light'} className="h-4 w-4" />
                                </button>
                                <button
                                    onClick={() => setEditing((cur) => (cur === s.id ? null : s.id))}
                                    title="Where devices added from this range go"
                                    aria-expanded={editing === s.id}
                                    className={
                                        'rounded-lg p-1.5 transition-colors duration-300 ease-fluid hover:bg-white/5 ' +
                                        (editing === s.id ? 'text-indigo-300' : 'text-white/40 hover:text-indigo-300')
                                    }
                                >
                                    <MapPin weight="light" className="h-4 w-4" />
                                </button>
                                <button
                                    onClick={() => remove.mutate(s.id)}
                                    title="Delete subnet"
                                    className="rounded-lg p-1.5 text-white/40 opacity-100 transition-all duration-300 ease-fluid hover:bg-white/5 hover:text-rose-400 lg:text-white/30 lg:opacity-0 lg:group-hover:opacity-100"
                                >
                                    <TrashSimple weight="light" className="h-4 w-4" />
                                </button>
                            </span>
                            )}
                        </div>
                        {isAdmin && editing === s.id && (
                            <div className="space-y-1.5 px-2.5 pb-2.5">
                                <p className="text-[11px] text-white/40">Devices added from this range go:</p>
                                <PlacementFields
                                    mapId={s.map_id}
                                    placement={s.placement}
                                    onMap={(id) => update.mutate({ id: s.id, map_id: id })}
                                    onPlacement={(p) => update.mutate({ id: s.id, placement: p })}
                                    maps={maps ?? []}
                                    disabled={update.isPending}
                                />
                            </div>
                        )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
