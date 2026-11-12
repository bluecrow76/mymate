import { useMemo, useState } from 'react';
import { MagnifyingGlass, MapTrifold, X } from '@phosphor-icons/react';
import { useAddDevicesToMap, useMaps } from '../../maps/api/maps';
import { useIsAdmin } from '../../auth/api/auth';
import { pushToast } from '../../../lib/toast';

/**
 * Node menu > Add to another map: put the right-clicked device - or every selected one - on a
 * second map as well. They stay on this map too (a device can be on any number of maps). Each
 * lands where it sits here, so a group keeps its arrangement; one already on the target map is
 * left where it is there. Admin-only, like every write on the map.
 */
export function AddToMapDialog({
    currentMapId,
    devices,
    title,
    onClose,
}: {
    currentMapId: number;
    /** The devices to add, with their position on the current map. */
    devices: { deviceId: number; x: number; y: number }[];
    /** Who's being added, for the header ("core-sw1" or "3 devices"). */
    title: string;
    onClose: () => void;
}) {
    const isAdmin = useIsAdmin();
    const { data: maps } = useMaps();
    const add = useAddDevicesToMap();
    const [q, setQ] = useState('');

    // Full path per map ("Site A / Building 2"), so two child maps with the same name are told apart.
    const paths = useMemo(() => {
        const byId = new Map((maps ?? []).map((m) => [m.id, m]));
        const path = (id: number): string => {
            const parts: string[] = [];
            const seen = new Set<number>();
            for (let m = byId.get(id); m && !seen.has(m.id); m = m.parent_map_id !== null ? byId.get(m.parent_map_id) : undefined) {
                seen.add(m.id);
                parts.unshift(m.name);
            }
            return parts.join(' / ');
        };
        return (maps ?? [])
            .filter((m) => m.id !== currentMapId)
            .map((m) => ({ map: m, path: path(m.id) }))
            .sort((a, b) => a.path.localeCompare(b.path));
    }, [maps, currentMapId]);

    if (!isAdmin) return null;

    const needle = q.trim().toLowerCase();
    const candidates = paths.filter((p) => needle === '' || p.path.toLowerCase().includes(needle));

    function pick(mapId: number, name: string) {
        add.mutate(
            { mapId, devices },
            {
                onSuccess: ({ added, already, failed }) => {
                    const what = (n: number) => (n === 1 ? '1 device' : `${n} devices`);
                    if (failed > 0) {
                        pushToast({ title: `Couldn't add ${what(failed)} to ${name}`, detail: added > 0 ? `${what(added)} added.` : undefined, tone: 'down' });
                    } else if (added === 0) {
                        pushToast({ title: devices.length === 1 ? `Already on ${name}` : `All already on ${name}`, tone: 'info' });
                    } else {
                        pushToast({ title: `Added ${what(added)} to ${name}`, detail: already > 0 ? `${what(already)} already there - left where ${already === 1 ? 'it was' : 'they were'}.` : undefined, tone: 'up' });
                    }
                    onClose();
                },
                onError: () => pushToast({ title: `Couldn't add to ${name}`, tone: 'down' }),
            },
        );
    }

    return (
        <div className="fixed inset-0 z-50 grid place-items-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
            <div className="animate-rise relative w-full max-w-md rounded-[1.5rem] bg-white/[0.05] p-1 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                <div className="rounded-[calc(1.5rem-0.25rem)] bg-surface p-6 ring-1 ring-white/10">
                    <header className="mb-5 flex items-start justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-3">
                            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-indigo-500/15 text-indigo-300 ring-1 ring-indigo-400/20">
                                <MapTrifold weight="light" className="h-5 w-5" />
                            </span>
                            <div className="min-w-0">
                                <h2 className="text-base font-bold tracking-tight text-white">Add to another map</h2>
                                <p className="truncate text-xs text-white/40">{title} - stays on this map too</p>
                            </div>
                        </div>
                        <button onClick={onClose} className="rounded-lg p-1 text-white/40 transition-colors hover:bg-white/5 hover:text-white/80">
                            <X weight="bold" className="h-4 w-4" />
                        </button>
                    </header>

                    <div className="mb-2 flex items-center gap-2 rounded-xl bg-white/[0.03] px-3 py-2 ring-1 ring-white/10">
                        <MagnifyingGlass weight="bold" className="h-3.5 w-3.5 shrink-0 text-white/40" />
                        <input
                            autoFocus
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Search maps"
                            className="w-full bg-transparent text-sm text-white outline-none placeholder:text-white/30"
                        />
                    </div>

                    <ul className="max-h-72 space-y-1 overflow-auto">
                        {candidates.length === 0 ? (
                            <li className="px-3 py-6 text-center text-xs text-white/35">{paths.length === 0 ? 'There are no other maps yet.' : 'No maps match.'}</li>
                        ) : (
                            candidates.map(({ map, path }) => (
                                <li key={map.id}>
                                    <button
                                        type="button"
                                        disabled={add.isPending}
                                        onClick={() => pick(map.id, map.name)}
                                        className="flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left ring-1 ring-white/10 transition-colors hover:bg-white/5 disabled:opacity-50"
                                    >
                                        <MapTrifold weight="duotone" className="h-4 w-4 shrink-0 text-indigo-300" />
                                        <span className="min-w-0 flex-1 truncate text-sm text-white/90" title={path}>{path}</span>
                                        <span className="shrink-0 text-[11px] text-white/40">{map.device_count} devices</span>
                                    </button>
                                </li>
                            ))
                        )}
                    </ul>
                    {add.isPending && <p className="mt-2 px-1 text-xs text-white/40">Adding...</p>}
                </div>
            </div>
        </div>
    );
}
