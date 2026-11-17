import { useEffect, useRef, useState } from 'react';
import { CaretDown } from '@phosphor-icons/react';
import { DEVICE_TYPE_OPTIONS } from '../lib/outagePrefs';
import type { DeviceType } from '../../../types';

/**
 * Multi-select of device types for the Outages page. None ticked means every type (the same
 * as all of them), so the button reads "Any type" then.
 */
export function DeviceTypeFilter({ value, onChange, className = '' }: { value: DeviceType[]; onChange: (v: DeviceType[]) => void; className?: string }) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const onDown = (e: PointerEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setOpen(false);
        };
        document.addEventListener('pointerdown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('pointerdown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const chosen = DEVICE_TYPE_OPTIONS.filter((o) => value.includes(o.value));
    const label =
        chosen.length === 0 || chosen.length === DEVICE_TYPE_OPTIONS.length
            ? 'Any type'
            : chosen.length <= 2
              ? chosen.map((o) => o.label).join(', ')
              : `${chosen.length} types`;

    const toggle = (t: DeviceType) => onChange(value.includes(t) ? value.filter((v) => v !== t) : [...value, t]);

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-label="Limit to device types"
                className={`flex items-center gap-1.5 ${className}`}
            >
                <span className="max-w-[10rem] truncate">{label}</span>
                <CaretDown className="h-3 w-3 shrink-0 text-white/45" />
            </button>
            {open && (
                <div role="listbox" aria-multiselectable className="absolute right-0 z-20 mt-1 w-44 rounded-lg bg-surface-2 p-1 shadow-[0_12px_32px_-12px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                    {DEVICE_TYPE_OPTIONS.map((o) => (
                        <label key={o.value} className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-xs text-white/75 hover:bg-white/[0.05]">
                            <input type="checkbox" checked={value.includes(o.value)} onChange={() => toggle(o.value)} className="accent-emerald-500" />
                            {o.label}
                        </label>
                    ))}
                    {value.length > 0 && (
                        <button type="button" onClick={() => onChange([])} className="mt-1 w-full rounded-md px-2 py-1 text-left text-[11px] text-white/45 hover:bg-white/[0.05] hover:text-white/75">
                            Clear - any type
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
