import type { NetworkMap } from '../../../types';

/** Maps parent-first with their nesting depth, siblings in their saved order, for map filters (Devices, Outages). */
export function mapsInTreeOrder(maps: NetworkMap[]): { map: NetworkMap; depth: number }[] {
    const ids = new Set(maps.map((m) => m.id));
    const children = new Map<number | null, NetworkMap[]>();
    for (const m of maps) {
        // A parent the viewer can't see is treated as the root so the map still shows up.
        const parent = m.parent_map_id !== null && ids.has(m.parent_map_id) ? m.parent_map_id : null;
        children.set(parent, [...(children.get(parent) ?? []), m]);
    }
    const out: { map: NetworkMap; depth: number }[] = [];
    const seen = new Set<number>();
    const walk = (parent: number | null, depth: number) => {
        const kids = [...(children.get(parent) ?? [])].sort((a, b) => a.position - b.position || a.name.localeCompare(b.name));
        for (const m of kids) {
            if (seen.has(m.id)) continue;
            seen.add(m.id);
            out.push({ map: m, depth });
            walk(m.id, depth + 1);
        }
    };
    walk(null, 0);
    return out;
}
