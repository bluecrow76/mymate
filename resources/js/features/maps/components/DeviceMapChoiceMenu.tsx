import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { MapTrifold } from '@phosphor-icons/react';

export type MapChoice = { id: number; name: string };

const MARGIN = 8; // keep the menu clear of the viewport edges

/** Which of a device's maps to open, when it's on more than one. Opens at the click. */
export function DeviceMapChoiceMenu({
    deviceName,
    maps,
    x,
    y,
    onPick,
    onClose,
}: {
    deviceName: string;
    maps: MapChoice[];
    x: number;
    y: number;
    onPick: (mapId: number) => void;
    onClose: () => void;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState({ x, y });

    useLayoutEffect(() => {
        const box = ref.current?.getBoundingClientRect();
        if (!box) return;
        setPos({
            x: Math.max(MARGIN, Math.min(x, window.innerWidth - box.width - MARGIN)),
            y: Math.max(MARGIN, Math.min(y, window.innerHeight - box.height - MARGIN)),
        });
    }, [x, y]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    return createPortal(
        <>
            {/* Click-away - any outside click (either button) dismisses. */}
            <div className="fixed inset-0 z-40" onClick={onClose} onContextMenu={(e) => { e.preventDefault(); onClose(); }} />
            <div
                ref={ref}
                role="menu"
                style={{ left: pos.x, top: pos.y }}
                className="animate-rise fixed z-50 w-64 rounded-2xl bg-surface/95 p-1.5 shadow-[0_20px_60px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10 backdrop-blur-xl"
            >
                <p className="truncate px-2.5 pt-1 text-xs font-semibold text-white/90">{deviceName}</p>
                <p className="px-2.5 pb-1.5 text-[10px] text-white/35">On {maps.length} maps. Open which?</p>
                <div className="max-h-72 overflow-y-auto">
                    {maps.map((m) => (
                        <button
                            key={m.id}
                            role="menuitem"
                            onClick={() => onPick(m.id)}
                            className="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-1.5 text-left text-xs text-white/75 transition-colors duration-200 ease-fluid hover:bg-white/10"
                        >
                            <MapTrifold weight="light" className="h-4 w-4 shrink-0 text-indigo-300" />
                            <span className="flex-1 truncate" title={m.name}>{m.name}</span>
                        </button>
                    ))}
                </div>
            </div>
        </>,
        document.body,
    );
}
