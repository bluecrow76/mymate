import { useState, type ReactNode } from 'react';
import { GridFour } from '@phosphor-icons/react';
import { ModalShell } from '../../../components/Dialog';
import { defaultShape, gridShape, type GridFill, type GridOrder } from '../lib/gridArrange';
import { clampGap, GRID_GAP_MAX, loadGridPrefs, saveGridPrefs, type GridArrangePrefs } from '../lib/gridArrangePrefs';

export interface GridArrangeChoice extends GridArrangePrefs {
    cols: number;
    rows: number;
}

/**
 * "Arrange in grid" settings for the selected device cards. Arrange closes the dialog and the
 * map then asks where the grid's top-left corner goes (see MapCanvas). Gaps are whole snap
 * points, so the arranged cards stay on the snap grid.
 */
export function GridArrangeDialog({
    count,
    snap,
    onArrange,
    onClose,
}: {
    count: number; // selected device cards
    snap: number; // snap grid size, px
    onArrange: (choice: GridArrangeChoice) => void;
    onClose: () => void;
}) {
    const [prefs, setPrefs] = useState<GridArrangePrefs>(loadGridPrefs);
    const start = defaultShape(count);
    // Text, so a field can be cleared while typing; the other count follows a valid entry.
    const [cols, setCols] = useState(String(start.cols));
    const [rows, setRows] = useState(String(start.rows));
    const [gapX, setGapX] = useState(String(prefs.gapX));
    const [gapY, setGapY] = useState(String(prefs.gapY));

    const asCount = (s: string): number | null => {
        const n = Number(s);
        return Number.isInteger(n) && n >= 1 && n <= count ? n : null;
    };
    const asGap = (s: string): number | null => (s.trim() === '' ? null : clampGap(Number(s)));

    const colsN = asCount(cols);
    const rowsN = asCount(rows);
    const gapXN = asGap(gapX);
    const gapYN = asGap(gapY);
    const valid = colsN !== null && rowsN !== null && gapXN !== null && gapYN !== null;
    const shape = valid ? gridShape(count, colsN, rowsN, prefs.fill) : null;

    function editCols(v: string) {
        setCols(v);
        const n = asCount(v);
        if (n !== null) setRows(String(Math.ceil(count / n)));
    }
    function editRows(v: string) {
        setRows(v);
        const n = asCount(v);
        if (n !== null) setCols(String(Math.ceil(count / n)));
    }

    function arrange() {
        if (!valid) return;
        const next = { ...prefs, gapX: gapXN, gapY: gapYN };
        saveGridPrefs(next);
        onArrange({ ...next, cols: colsN, rows: rowsN });
    }

    return (
        <ModalShell icon={<GridFour weight="light" className="h-5 w-5" />} title="Arrange in grid" onClose={onClose}>
            <form
                className="space-y-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    arrange();
                }}
            >
                <p className="text-xs text-white/50">
                    {count} selected device{count === 1 ? '' : 's'}. After Arrange, click the map where the grid's top-left corner should go.
                </p>

                <Field label="Size">
                    <div className="grid grid-cols-2 gap-2">
                        <NumberInput label="Columns" value={cols} onChange={editCols} min={1} max={count} invalid={colsN === null} autoFocus />
                        <NumberInput label="Rows" value={rows} onChange={editRows} min={1} max={count} invalid={rowsN === null} />
                    </div>
                </Field>

                <Field label={`Gap between cards (snap points of ${snap}px)`}>
                    <div className="grid grid-cols-2 gap-2">
                        <NumberInput label="Horizontal" value={gapX} onChange={setGapX} min={0} max={GRID_GAP_MAX} invalid={gapXN === null} />
                        <NumberInput label="Vertical" value={gapY} onChange={setGapY} min={0} max={GRID_GAP_MAX} invalid={gapYN === null} />
                    </div>
                </Field>

                <Field label="Sort by">
                    <Segmented<GridOrder>
                        value={prefs.order}
                        onChange={(order) => setPrefs((p) => ({ ...p, order }))}
                        options={[
                            { value: 'name', label: 'Name' },
                            { value: 'ip', label: 'IP address' },
                        ]}
                    />
                </Field>

                <Field label="Fill">
                    <Segmented<GridFill>
                        value={prefs.fill}
                        onChange={(fill) => setPrefs((p) => ({ ...p, fill }))}
                        options={[
                            { value: 'rows', label: 'Across, row by row' },
                            { value: 'columns', label: 'Down, column by column' },
                        ]}
                    />
                </Field>

                <p className="min-h-[1rem] text-xs text-white/50" aria-live="polite">
                    {shape
                        ? `Uses ${shape.cols} column${shape.cols === 1 ? '' : 's'} × ${shape.rows} row${shape.rows === 1 ? '' : 's'}.`
                        : `Columns and rows are 1 to ${count}; gaps are 0 to ${GRID_GAP_MAX}.`}
                </p>

                <div className="flex items-center justify-end gap-2 pt-1">
                    <button type="button" onClick={onClose} className="rounded-full px-4 py-2 text-sm font-medium text-white/60 transition-colors duration-300 ease-fluid hover:text-white/90">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={!valid}
                        className="rounded-full bg-emerald-500 px-5 py-2 text-sm font-semibold text-emerald-950 shadow-[0_8px_24px_-8px_rgba(16,185,129,0.6)] transition-all duration-300 ease-fluid hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                    >
                        Arrange
                    </button>
                </div>
            </form>
        </ModalShell>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="space-y-1.5">
            <p className="text-[10px] font-medium uppercase tracking-[0.16em] text-white/35">{label}</p>
            {children}
        </div>
    );
}

function NumberInput({
    label, value, onChange, min, max, invalid, autoFocus,
}: { label: string; value: string; onChange: (v: string) => void; min: number; max: number; invalid: boolean; autoFocus?: boolean }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs text-white/60">{label}</span>
            <input
                type="number"
                inputMode="numeric"
                step={1}
                min={min}
                max={max}
                value={value}
                autoFocus={autoFocus}
                onChange={(e) => onChange(e.target.value)}
                aria-invalid={invalid}
                className={`w-full rounded-xl bg-white/[0.04] px-3 py-2 text-sm tabular-nums text-white ring-1 outline-none transition-colors duration-200 ease-fluid focus:ring-emerald-400/50 ${invalid ? 'ring-rose-400/60' : 'ring-white/10'}`}
            />
        </label>
    );
}

function Segmented<T extends string>({ value, onChange, options }: { value: T; onChange: (v: T) => void; options: { value: T; label: string }[] }) {
    return (
        <div role="radiogroup" className="flex gap-1 rounded-xl bg-white/[0.03] p-1 ring-1 ring-white/10">
            {options.map((o) => (
                <button
                    key={o.value}
                    type="button"
                    role="radio"
                    aria-checked={value === o.value}
                    onClick={() => onChange(o.value)}
                    className={`flex-1 rounded-lg px-2 py-1.5 text-xs transition-colors ${value === o.value ? 'bg-white/10 text-emerald-300 ring-1 ring-white/15' : 'text-white/65 hover:bg-white/5 hover:text-white/90'}`}
                >
                    {o.label}
                </button>
            ))}
        </div>
    );
}
