import type { GridFill, GridOrder } from './gridArrange';

/**
 * The "Arrange in grid" dialog's last-used gaps, sort and fill direction - a per-browser
 * convenience, like the last Tidy layout. Kept under its own key (not the shell store) and read
 * defensively: storage can be blocked or hand-edited, and the dialog must work without it.
 * Columns and rows aren't kept - they follow the number of cards selected each time.
 */
export interface GridArrangePrefs {
    gapX: number;
    gapY: number;
    order: GridOrder;
    fill: GridFill;
}

const KEY = 'mymate.gridArrange.v1';
export const GRID_GAP_MAX = 20; // snap points
export const GRID_DEFAULTS: GridArrangePrefs = { gapX: 1, gapY: 1, order: 'name', fill: 'rows' };

export function clampGap(n: unknown): number | null {
    return typeof n === 'number' && Number.isInteger(n) && n >= 0 && n <= GRID_GAP_MAX ? n : null;
}

export function loadGridPrefs(): GridArrangePrefs {
    try {
        const raw = JSON.parse(window.localStorage.getItem(KEY) ?? '{}') as Partial<Record<keyof GridArrangePrefs, unknown>>;
        return {
            gapX: clampGap(raw.gapX) ?? GRID_DEFAULTS.gapX,
            gapY: clampGap(raw.gapY) ?? GRID_DEFAULTS.gapY,
            order: raw.order === 'ip' || raw.order === 'name' ? raw.order : GRID_DEFAULTS.order,
            fill: raw.fill === 'rows' || raw.fill === 'columns' ? raw.fill : GRID_DEFAULTS.fill,
        };
    } catch {
        return GRID_DEFAULTS;
    }
}

export function saveGridPrefs(p: GridArrangePrefs): void {
    try {
        window.localStorage.setItem(KEY, JSON.stringify(p));
    } catch {
        /* storage disabled or full - the dialog just starts from the defaults next time */
    }
}
