import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { AppWindow, ArrowCircleUp, CircleNotch, EyeSlash, Globe, LinkBreak, LockSimple, Path, Terminal, TrashSimple, TreeStructure } from '@phosphor-icons/react';
import { StatusDot } from '../../../components/StatusDot';
import { httpUrl, httpsUrl, sshUrl, winboxUrl } from '../../../lib/deviceLinks';
import { UPGRADE_IN_PROGRESS, type Device } from '../../../types';

/** Where a right-click opened the menu, and on which device. The device itself is looked up
 *  live by id at render, so the menu can't outlive (or misreport) the thing it acts on. */
export type NodeMenuState = { deviceId: number; x: number; y: number };

const MARGIN = 8; // keep the menu clear of the viewport edges

/** Whether a right-click on this device has anything to offer this user. */
export function nodeMenuHasItems(device: Pick<Device, 'mgmt_ip'>, isAdmin: boolean): boolean {
    return isAdmin || !!device.mgmt_ip;
}

/**
 * Right-click menu for a device card on the map (GitHub #45).
 *
 * Tools (when the device has a management IP, for every operator - the same as the inspector's
 * buttons, plus HTTP/HTTPS): open it in Winbox, SSH or a browser, trace the path to it, or (admin,
 * RouterOS) upgrade it.
 *
 * Management (admin-only - every item is a write, and non-admin operators are read-only app-wide,
 * FR-38): its place in the hierarchy, taking it off this map, or deleting it - the reversible
 * action and the destructive one clearly separated; delete goes through a confirmation that counts
 * what it takes with it.
 */
export function MapNodeMenu({
    device,
    x,
    y,
    isAdmin,
    onThisMap,
    onTrace,
    onUpgrade,
    onSetParent,
    onClearParent,
    onRemoveFromMap,
    onDelete,
    onClose,
}: {
    device: Device;
    x: number;
    y: number;
    isAdmin: boolean;
    onThisMap: boolean;
    onTrace: () => void;
    onUpgrade: () => void;
    onSetParent: () => void;
    onClearParent: () => void;
    onRemoveFromMap: () => void;
    onDelete: () => void;
    onClose: () => void;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState({ x, y });

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
    // The tools sit in a compact grid, like the inspector's action buttons.
    const tool =
        'flex items-center justify-center gap-1 rounded-lg px-1.5 py-1.5 text-[11px] text-white/75 ring-1 ring-white/10 transition-colors duration-200 ease-fluid hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent';

    const run = (action: () => void) => () => {
        onClose();
        action();
    };

    const ip = device.mgmt_ip;
    const upgrading = !!device.upgrade_status && UPGRADE_IN_PROGRESS.has(device.upgrade_status);
    const isRouterOs = device.poll_method === 'routeros';

    // A link out: closes the menu and lets the browser follow it. Web pages open in a new tab;
    // winbox:// and ssh:// hand off to the local app, so they stay in this one (as in the inspector).
    const linkOut = (href: string, label: string, icon: ReactNode, title: string, newTab = false) => (
        <a
            href={href}
            onClick={onClose}
            title={title}
            target={newTab ? '_blank' : undefined}
            rel={newTab ? 'noopener noreferrer' : undefined}
            className={tool}
        >
            {icon}
            {label}
        </a>
    );

    // Portalled to <body> so the click coordinates stay viewport-relative whatever the canvas
    // has been panned/zoomed to, and so it paints over the map furniture.
    return createPortal(
        <>
            {/* Click-away - any outside click (either button) dismisses. */}
            <div className="fixed inset-0 z-40" onClick={onClose} onContextMenu={(e) => { e.preventDefault(); onClose(); }} />
            <div
                ref={ref}
                style={{ left: pos.x, top: pos.y }}
                className="animate-rise fixed z-50 w-60 rounded-2xl bg-surface/95 p-1.5 shadow-[0_20px_60px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10 backdrop-blur-xl"
            >
                <div className="flex items-center gap-2 px-2.5 pb-1.5 pt-1">
                    <StatusDot status={device.status} />
                    <span className="min-w-0 flex-1 truncate text-xs font-semibold text-white/90">{device.name}</span>
                </div>
                <p className="truncate px-2.5 pb-1.5 text-[10px] text-white/35">
                    {ip ? `${ip} · ` : ''}
                    {device.parent_name ? `Under ${device.parent_name}` : 'No parent'}
                </p>

                {ip && (
                    <>
                        <p className="px-2.5 pb-1 pt-0.5 text-[10px] font-semibold uppercase tracking-wider text-white/35">Tools</p>
                        <div className="grid grid-cols-3 gap-1 px-1 pb-1">
                            {linkOut(winboxUrl(ip), 'Winbox', <AppWindow weight="light" className="h-3.5 w-3.5" />, `Open ${ip} in Winbox`)}
                            {linkOut(sshUrl(ip), 'SSH', <Terminal weight="light" className="h-3.5 w-3.5" />, `SSH to ${ip}`)}
                            <button type="button" onClick={run(onTrace)} title="Live hop-by-hop trace from this server to the device (MTR)" className={tool}>
                                <Path weight="light" className="h-3.5 w-3.5" />
                                Trace
                            </button>
                            {linkOut(httpUrl(ip), 'HTTP', <Globe weight="light" className="h-3.5 w-3.5" />, `Open http://${ip} in a new tab`, true)}
                            {linkOut(httpsUrl(ip), 'HTTPS', <LockSimple weight="light" className="h-3.5 w-3.5" />, `Open https://${ip} in a new tab`, true)}
                            {isAdmin && (
                                <button
                                    type="button"
                                    onClick={run(onUpgrade)}
                                    disabled={!isRouterOs || upgrading}
                                    title={!isRouterOs ? 'RouterOS devices only' : upgrading ? device.upgrade_message ?? 'Upgrade in progress' : 'Download latest RouterOS + reboot'}
                                    className={tool}
                                >
                                    {upgrading ? <CircleNotch weight="bold" className="h-3.5 w-3.5 animate-spin" /> : <ArrowCircleUp weight="light" className="h-3.5 w-3.5" />}
                                    Upgrade
                                </button>
                            )}
                        </div>
                    </>
                )}

                {isAdmin && (
                    <>
                        {ip && <div className="my-1 h-px bg-white/10" />}

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
                    </>
                )}
            </div>
        </>,
        document.body,
    );
}
