import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { CaretRight, ClipboardText, Copy, CopySimple, EyeSlash, LinkBreak, PencilSimple, TrashSimple, TreeStructure } from '@phosphor-icons/react';
import { StatusDot } from '../../../components/StatusDot';
import type { Device } from '../../../types';
import { ATTRIBUTE_LABEL, attributeOf, type DeviceAttributeClip, type DeviceAttributeKind } from '../../../lib/deviceAttributeClipboard';

const COPY_ITEMS: { kind: DeviceAttributeKind; label: string; title: string }[] = [
    { kind: 'icon', label: 'Copy icon', title: 'The icon and its colour (or the automatic icon)' },
    { kind: 'location', label: 'Copy location', title: 'Where it sits on the geo map' },
    { kind: 'poll', label: 'Copy poll method', title: 'The poll method and its credential' },
];

/** Where a right-click opened the menu, and on which device. The device itself is looked up
 *  live by id at render, so the menu can't outlive (or misreport) the thing it acts on. */
export type NodeMenuState = {
    deviceId: number;
    x: number;
    y: number;
    /** Every selected device card, when the right-clicked one is part of a multi-selection (2+). */
    selectionIds?: number[];
};

const MARGIN = 8; // keep the menu clear of the viewport edges

/**
 * Right-click menu for a device card on the map (GitHub #45). The map could only ever *hide*
 * a device ("Remove from this map"); managing its place in the hierarchy or retiring it meant
 * going to the Devices page. Both live here now, with the reversible action and the destructive
 * one clearly separated - delete goes through a confirmation that counts what it takes with it.
 *
 * Admin-only: every item is a write, and non-admin operators are read-only app-wide (FR-38).
 *
 * Copy attribute: put this device's icon, location or poll method on the attribute clipboard;
 * Paste then applies it to the right-clicked device, or to every selected one.
 *
 * New device from this: opens Add device pre-filled with this device's settings, so a sibling
 * only needs its own name and management IP.
 */
export function MapNodeMenu({
    device,
    x,
    y,
    onThisMap,
    onSetParent,
    onClearParent,
    onRemoveFromMap,
    onDelete,
    onClose,
    selectionCount = 0,
    onBulkEdit,
    onCopyAttribute,
    clip = null,
    onPasteAttribute,
    onCloneDevice,
}: {
    device: Device;
    x: number;
    y: number;
    onThisMap: boolean;
    onSetParent: () => void;
    onClearParent: () => void;
    onRemoveFromMap: () => void;
    onDelete: () => void;
    onClose: () => void;
    /** How many device cards are selected along with this one; 2+ offers the bulk edit. */
    selectionCount?: number;
    onBulkEdit?: () => void;
    onCopyAttribute?: (kind: DeviceAttributeKind) => void;
    /** What's on the attribute clipboard, offered as a paste onto the selection (or this device). */
    clip?: DeviceAttributeClip | null;
    onPasteAttribute?: () => void;
    /** Open Add device pre-filled from this device. */
    onCloneDevice?: () => void;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState({ x, y });
    const [copyOpen, setCopyOpen] = useState(false);
    // The Copy attribute flyout opens to the right, or to the left when there's no room for it.
    const flyLeft = pos.x + 2 * 240 + MARGIN > window.innerWidth;

    // Flip/clamp into view once measured - a right-click near the bottom-right of the canvas
    // would otherwise open the menu half off-screen.
    useLayoutEffect(() => {
        const box = ref.current?.getBoundingClientRect();
        if (!box) return;
        setPos({
            x: Math.max(MARGIN, Math.min(x, window.innerWidth - box.width - MARGIN)),
            y: Math.max(MARGIN, Math.min(y, window.innerHeight - box.height - MARGIN)),
        });
    }, [x, y]);

    // Escape closes, regardless of focus (the click-away overlay handles the rest).
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const item =
        'flex w-full items-center gap-2.5 rounded-xl px-2.5 py-1.5 text-left text-xs transition-colors duration-200 ease-fluid hover:bg-white/10';

    const run = (action: () => void) => () => {
        onClose();
        action();
    };

    // Portalled to <body> so the click coordinates stay viewport-relative whatever the canvas
    // has been panned/zoomed to, and so it paints over the map furniture.
    return createPortal(
        <>
            {/* Click-away - any outside click (either button) dismisses. */}
            <div className="fixed inset-0 z-40" onClick={onClose} onContextMenu={(e) => { e.preventDefault(); onClose(); }} />
            <div
                ref={ref}
                style={{ left: pos.x, top: pos.y }}
                className="animate-rise fixed z-50 w-56 rounded-2xl bg-surface/95 p-1.5 shadow-[0_20px_60px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10 backdrop-blur-xl"
            >
                <div className="flex items-center gap-2 px-2.5 pb-1.5 pt-1">
                    <StatusDot status={device.status} />
                    <span className="min-w-0 flex-1 truncate text-xs font-semibold text-white/90">{device.name}</span>
                </div>
                <p className="truncate px-2.5 pb-1.5 text-[10px] text-white/35">
                    {device.parent_name ? `Under ${device.parent_name}` : 'No parent'}
                </p>

                {selectionCount >= 2 && onBulkEdit && (
                    <>
                        <button onClick={run(onBulkEdit)} title="Change the same settings on every selected device" className={`${item} text-white/75`}>
                            <PencilSimple weight="light" className="h-4 w-4 shrink-0 text-emerald-300" />
                            <span className="flex-1">Edit {selectionCount} selected devices...</span>
                        </button>
                        <div className="my-1 h-px bg-white/10" />
                    </>
                )}

                {onCloneDevice && (
                    <>
                        <button onClick={run(onCloneDevice)} title="Add a new device with this one's settings - then change its name and management IP" className={`${item} text-white/75`}>
                            <CopySimple weight="light" className="h-4 w-4 shrink-0 text-emerald-300" />
                            <span className="flex-1">New device from this...</span>
                        </button>
                        <div className="my-1 h-px bg-white/10" />
                    </>
                )}

                {(() => {
                    const pasteCount = selectionCount >= 2 ? selectionCount : 1;
                    // Pasting a device's attribute back onto itself alone does nothing.
                    const canPaste = clip && onPasteAttribute && (selectionCount >= 2 || clip.fromId !== device.id);
                    if (!onCopyAttribute && !canPaste) return null;
                    return (
                        <>
                            {onCopyAttribute && (
                                <div className="relative" onMouseEnter={() => setCopyOpen(true)} onMouseLeave={() => setCopyOpen(false)}>
                                    <button
                                        type="button"
                                        onClick={() => setCopyOpen((o) => !o)}
                                        aria-expanded={copyOpen}
                                        className={`${item} text-white/75 ${copyOpen ? 'bg-white/10' : ''}`}
                                    >
                                        <Copy weight="light" className="h-4 w-4 shrink-0 text-emerald-300" />
                                        <span className="flex-1">Copy attribute</span>
                                        <CaretRight weight="bold" className="h-3 w-3 shrink-0 text-white/40" />
                                    </button>
                                    {copyOpen && (
                                        // Padding, not margin, on the outer box so the pointer can cross the gap.
                                        <div className={`absolute top-0 z-10 ${flyLeft ? 'right-full pr-1.5' : 'left-full pl-1.5'}`}>
                                            <div className="w-48 rounded-2xl bg-surface/95 p-1.5 shadow-[0_20px_60px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10 backdrop-blur-xl">
                                                {COPY_ITEMS.map((c) => {
                                                    const none = attributeOf(device, c.kind) === null;
                                                    return (
                                                        <button
                                                            key={c.kind}
                                                            type="button"
                                                            disabled={none}
                                                            onClick={run(() => onCopyAttribute(c.kind))}
                                                            title={none ? 'This device has no location to copy' : c.title}
                                                            className={`${item} text-white/75 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent`}
                                                        >
                                                            <span className="flex-1">{c.label}</span>
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                            {canPaste && (
                                <button
                                    onClick={run(onPasteAttribute)}
                                    title={`Copied from ${clip.fromName}`}
                                    className={`${item} text-white/75`}
                                >
                                    <ClipboardText weight="light" className="h-4 w-4 shrink-0 text-emerald-300" />
                                    <span className="flex-1">
                                        Paste {ATTRIBUTE_LABEL[clip.kind]} to {selectionCount >= 2 ? `${pasteCount} selected devices` : 'this device'}
                                    </span>
                                </button>
                            )}
                            <div className="my-1 h-px bg-white/10" />
                        </>
                    );
                })()}

                <button onClick={run(onSetParent)} className={`${item} text-white/75`}>
                    <TreeStructure weight="light" className="h-4 w-4 shrink-0 text-emerald-300" />
                    <span className="flex-1">{device.parent_device_id === null ? 'Set parent...' : 'Change parent...'}</span>
                </button>
                {device.parent_device_id !== null && (
                    <button onClick={run(onClearParent)} className={`${item} text-white/75`}>
                        <LinkBreak weight="light" className="h-4 w-4 shrink-0 text-white/40" />
                        <span className="flex-1">Clear parent</span>
                    </button>
                )}

                <div className="my-1 h-px bg-white/10" />

                {onThisMap && (
                    <button
                        onClick={run(onRemoveFromMap)}
                        title="Takes it off this map only - it stays monitored, and keeps its links and history"
                        className={`${item} text-white/75`}
                    >
                        <EyeSlash weight="light" className="h-4 w-4 shrink-0 text-white/40" />
                        <span className="flex-1">Remove from this map</span>
                    </button>
                )}
                <button
                    onClick={run(onDelete)}
                    title="Deletes the device everywhere - monitoring, links and history included"
                    className={`${item} text-rose-300/90 hover:bg-rose-500/10`}
                >
                    <TrashSimple weight="light" className="h-4 w-4 shrink-0" />
                    <span className="flex-1">Delete device...</span>
                </button>
            </div>
        </>,
        document.body,
    );
}
