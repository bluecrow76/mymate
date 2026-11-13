/**
 * "Arrange in grid" (Tidy layout menu): lay the selected device cards out in rows and columns.
 * All the maths is in snap points, so every card's top-left corner lands on the map's snap grid:
 *  - each column is as wide as the widest selected card, rounded up to whole snap points, plus the
 *    horizontal gap; each row is as tall as the tallest card, rounded up, plus the vertical gap.
 *    Cards differ in size (face sensors make one taller), so sizing every cell for the biggest
 *    card is what keeps them from overlapping while still lining up;
 *  - the grid's top-left corner is the snap point nearest to where the user clicked.
 */

export type GridOrder = 'name' | 'ip';
/** 'rows' fills across (left to right, then the next row); 'columns' fills down. */
export type GridFill = 'rows' | 'columns';

export interface GridCard {
    id: number;
    w: number;
    h: number;
    name: string; // the name the card shows (alias when set)
    ip: string | null;
}

export interface GridOptions {
    cols: number;
    rows: number;
    gapX: number; // snap points between columns
    gapY: number; // snap points between rows
    snap: number; // snap grid size, px
    order: GridOrder;
    fill: GridFill;
}

const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

/** The near-square starting shape for `count` cards: about sqrt(count) columns. */
export function defaultShape(count: number): { cols: number; rows: number } {
    const cols = Math.max(1, Math.ceil(Math.sqrt(count)));
    return { cols, rows: Math.max(1, Math.ceil(count / cols)) };
}

/**
 * The columns x rows the grid actually uses. Filling across, the column count leads and rows
 * follow from it; filling down, the row count leads. (9 cards in "4 columns" filled down 3 rows
 * deep only needs 3 columns, so the dialog shows this rather than the raw inputs.)
 */
export function gridShape(count: number, cols: number, rows: number, fill: GridFill): { cols: number; rows: number } {
    if (count <= 0) return { cols: 0, rows: 0 };
    if (fill === 'rows') {
        const c = Math.min(count, Math.max(1, cols));
        return { cols: c, rows: Math.ceil(count / c) };
    }
    const r = Math.min(count, Math.max(1, rows));
    return { cols: Math.ceil(count / r), rows: r };
}

/** Cell size in snap points: the biggest card rounded up to whole points, plus the gap. */
export function cellSize(cards: GridCard[], o: Pick<GridOptions, 'gapX' | 'gapY' | 'snap'>): { w: number; h: number } {
    const maxW = Math.max(1, ...cards.map((c) => c.w));
    const maxH = Math.max(1, ...cards.map((c) => c.h));
    return { w: Math.ceil(maxW / o.snap) + o.gapX, h: Math.ceil(maxH / o.snap) + o.gapY };
}

/** The snap point nearest to a flow position. */
export function nearestSnapPoint(p: { x: number; y: number }, snap: number): { x: number; y: number } {
    // `+ 0` turns a -0 (rounding a small negative) into 0.
    return { x: Math.round(p.x / snap) * snap + 0, y: Math.round(p.y / snap) * snap + 0 };
}

/**
 * An IP address as numbers to compare, octet by octet (IPv4) or group by group (IPv6), so
 * 10.101.7.9 sorts before 10.101.7.11 and 10.9.0.1 before 10.101.0.1 - never as text.
 * Returns null for anything that isn't an address.
 */
export function ipKey(ip: string | null | undefined): { family: 4 | 6; parts: number[] } | null {
    if (!ip) return null;
    const s = ip.trim();
    const v4 = parseV4(s);
    if (v4) return { family: 4, parts: v4 };
    const v6 = parseV6(s);
    return v6 ? { family: 6, parts: v6 } : null;
}

function parseV4(s: string): number[] | null {
    const m = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.exec(s);
    if (!m) return null;
    const parts = m.slice(1).map(Number);
    return parts.every((n) => n <= 255) ? parts : null;
}

/** Eight 16-bit groups. Handles "::" and a trailing dotted IPv4 (::ffff:10.0.0.1); drops a %zone. */
function parseV6(s: string): number[] | null {
    let addr = s.split('%')[0].toLowerCase();
    if (!addr.includes(':')) return null;
    // A dotted IPv4 tail is the last two groups: parse it, stand "0:0" in for it, swap it back after.
    let tail: number[] | null = null;
    const lastColon = addr.lastIndexOf(':');
    if (addr.slice(lastColon + 1).includes('.')) {
        const v4 = parseV4(addr.slice(lastColon + 1));
        if (!v4) return null;
        tail = [(v4[0] << 8) | v4[1], (v4[2] << 8) | v4[3]];
        addr = `${addr.slice(0, lastColon + 1)}0:0`;
    }
    const halves = addr.split('::');
    if (halves.length > 2) return null;
    const groups = (h: string) => (h === '' ? [] : h.split(':'));
    const head = groups(halves[0]);
    const rest = halves.length === 2 ? groups(halves[1]) : [];
    const missing = 8 - head.length - rest.length;
    if (halves.length === 1 ? missing !== 0 : missing < 1) return null;
    const hex = [...head, ...Array<string>(halves.length === 2 ? missing : 0).fill('0'), ...rest];
    if (!hex.every((g) => /^[0-9a-f]{1,4}$/.test(g))) return null;
    const parts = hex.map((g) => parseInt(g, 16));
    if (tail) parts.splice(6, 2, ...tail);
    return parts;
}

/** IPv4 first, then IPv6, then no IP at all (static objects); numerically within each. */
function compareIp(a: string | null, b: string | null): number {
    const ka = ipKey(a);
    const kb = ipKey(b);
    if (!ka || !kb) return ka ? -1 : kb ? 1 : 0;
    if (ka.family !== kb.family) return ka.family - kb.family;
    for (let i = 0; i < ka.parts.length; i++) {
        if (ka.parts[i] !== kb.parts[i]) return ka.parts[i] - kb.parts[i];
    }
    return 0;
}

/** Cards in placement order. Ties fall back to the name, then the id, so a repeat run is stable. */
export function sortCards(cards: GridCard[], order: GridOrder): GridCard[] {
    const byName = (a: GridCard, b: GridCard) => collator.compare(a.name, b.name) || a.id - b.id;
    return [...cards].sort((a, b) => (order === 'ip' ? compareIp(a.ip, b.ip) || byName(a, b) : byName(a, b)));
}

/** Top-left of every card, starting the grid at `origin` (already on a snap point). */
export function gridPositions(cards: GridCard[], o: GridOptions, origin: { x: number; y: number }): Record<number, { x: number; y: number }> {
    const out: Record<number, { x: number; y: number }> = {};
    const { cols, rows } = gridShape(cards.length, o.cols, o.rows, o.fill);
    const cell = cellSize(cards, o);
    sortCards(cards, o.order).forEach((c, i) => {
        const col = o.fill === 'rows' ? i % cols : Math.floor(i / rows);
        const row = o.fill === 'rows' ? Math.floor(i / cols) : i % rows;
        out[c.id] = { x: origin.x + col * cell.w * o.snap, y: origin.y + row * cell.h * o.snap };
    });
    return out;
}
