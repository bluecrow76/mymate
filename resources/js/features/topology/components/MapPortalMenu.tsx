import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ArrowCounterClockwise, ArrowSquareOut } from '@phosphor-icons/react';

/** Where a right-click opened the menu, and on which inter-map portal (by link id). */
export type PortalMenuState = { linkId: number; x: number; y: number };

const MARGIN = 8; // keep the menu clear of the viewport edges

/**
 * Right-click menu for an inter-map portal (the purple card a link to another map ends on).
 * A portal nobody has moved sits next to its device and follows it; once dragged - alone or as
 * part of a group - it stays put. Reset position forgets that, so it follows its device again.
 * Admin-only, like every other position change on the map.
 */
export function MapPortalMenu({
    deviceName,
    mapName,
    pinned,
    x,
    y,
    onOpenMap,
    onReset,
    onClose,
}: {
    deviceName: string;
    mapName: string;
    pinned: boolean; // has a saved position on this map
    x: number;
    y: number;
    onOpenMap: (() => void) | null; // null when the far end is on no map
    onReset: () => void;
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

    const item = 'flex w-full items-center gap-2.5 rounded-xl px-2.5 py-1.5 text-left text-xs transition-colors duration-200 ease-fluid hover:bg-white/10 disabled:pointer-events-none disabled:opacity-40';
    const run = (action: () => void) => () => {
        onClose();
        action();
    };

    return createPortal(
        <>
            {/* Click-away - any outside click (either button) dismisses. */}
            <div className="fixed inset-0 z-40" onClick={onClose} onContextMenu={(e) => { e.preventDefault(); onClose(); }} />
            <div
                ref={ref}
                role="menu"
                style={{ left: pos.x, top: pos.y }}
                className="animate-rise fixed z-50 w-60 rounded-2xl bg-surface/95 p-1.5 shadow-[0_20px_60px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10 backdrop-blur-xl"
            >
                <p className="truncate px-2.5 pt-1 text-xs font-semibold text-white/90">{deviceName}</p>
                <p className="truncate px-2.5 pb-1.5 text-[10px] text-white/35">Link to {mapName}</p>

                {onOpenMap && (
                    <button role="menuitem" onClick={run(onOpenMap)} className={`${item} text-white/75`}>
                        <ArrowSquareOut weight="light" className="h-4 w-4 shrink-0 text-indigo-300" />
                        <span className="flex-1">Go to {mapName}</span>
                    </button>
                )}
                <button
                    role="menuitem"
                    onClick={run(onReset)}
                    disabled={!pinned}
                    title={pinned ? 'Put it back next to its device, so it moves with it again' : 'Already next to its device, and moves with it'}
                    className={`${item} text-white/75`}
                >
                    <ArrowCounterClockwise weight="light" className="h-4 w-4 shrink-0 text-emerald-300" />
                    <span className="flex-1">Reset position</span>
                </button>
                {!pinned && <p className="px-2.5 pb-1 text-[10px] text-white/35">Already follows its device.</p>}
            </div>
        </>,
        document.body,
    );
}
