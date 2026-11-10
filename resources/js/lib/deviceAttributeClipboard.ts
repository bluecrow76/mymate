import { useSyncExternalStore } from 'react';
import type { Device, PollMethod } from '../types';

/**
 * One device attribute copied from the map's right-click menu ("Copy attribute"), waiting to be
 * pasted onto other devices. Same pattern as lib/mapClipboard (localStorage, synced across tabs),
 * but kept apart from it, so copying an attribute doesn't throw away copied notes and the other
 * way round.
 *
 * Each kind holds exactly the fields a paste sends to PATCH /devices/bulk:
 * - icon: the glyph override and its colour (both null = automatic icon)
 * - location: the coordinates the source shows at on the geo map (its own pin, or the site /
 *   parent position it inherits). Pasted, they become each target's own pin.
 * - poll: the poll method and its credential; the bulk endpoint needs the two together
 */
export type DeviceAttribute =
    | { kind: 'icon'; icon: string | null; icon_color: string | null }
    | { kind: 'location'; latitude: number; longitude: number }
    | { kind: 'poll'; poll_method: PollMethod; credential_id: number | null };

export type DeviceAttributeClip = DeviceAttribute & { fromId: number; fromName: string };

export type DeviceAttributeKind = DeviceAttribute['kind'];

const STORAGE_KEY = 'mymate.device-attribute-clipboard.v1';
const POLL_METHODS: readonly string[] = ['none', 'snmp', 'routeros'];

const listeners = new Set<() => void>();
let current: DeviceAttributeClip | null = null;

/** The attribute as it's taken from a device, or null when the device has nothing to copy. */
export function attributeOf(device: Device, kind: DeviceAttributeKind): DeviceAttribute | null {
    switch (kind) {
        case 'icon':
            return { kind, icon: device.icon, icon_color: device.icon_color };
        case 'location':
            return device.geo_latitude !== null && device.geo_longitude !== null
                ? { kind, latitude: device.geo_latitude, longitude: device.geo_longitude }
                : null;
        case 'poll':
            return { kind, poll_method: device.poll_method, credential_id: device.poll_method === 'none' ? null : device.credential_id };
    }
}

/** What the menu calls each attribute ("Paste icon to ..."). */
export const ATTRIBUTE_LABEL: Record<DeviceAttributeKind, string> = {
    icon: 'icon',
    location: 'location',
    poll: 'poll method',
};

/** The changes a paste sends to the bulk edit. */
export function attributeChanges(attr: DeviceAttribute) {
    switch (attr.kind) {
        case 'icon':
            return { icon: attr.icon, icon_color: attr.icon_color };
        case 'location':
            return { latitude: attr.latitude, longitude: attr.longitude };
        case 'poll':
            return { poll_method: attr.poll_method, credential_id: attr.credential_id };
    }
}

export function copyDeviceAttribute(device: Device, kind: DeviceAttributeKind): boolean {
    const attr = attributeOf(device, kind);
    if (attr === null) return false;
    current = { ...attr, fromId: device.id, fromName: device.name };
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(current));
    } catch {
        // Storage off or full (private window): the copy still works in this tab.
    }
    emit();
    return true;
}

export function getDeviceAttributeClip(): DeviceAttributeClip | null {
    return current;
}

export function useDeviceAttributeClip(): DeviceAttributeClip | null {
    return useSyncExternalStore(subscribe, getDeviceAttributeClip);
}

function emit(): void {
    listeners.forEach((l) => l());
}

function subscribe(cb: () => void): () => void {
    listeners.add(cb);
    return () => listeners.delete(cb);
}

const str = (v: unknown): string | null => (typeof v === 'string' ? v : null);
const int = (v: unknown): number | null => (typeof v === 'number' && Number.isInteger(v) ? v : null);
const coord = (v: unknown, max: number): number | null => (typeof v === 'number' && Number.isFinite(v) && Math.abs(v) <= max ? v : null);

/** Storage is outside our control (old shape, another version, hand edits): rebuild it field by field. */
function parse(raw: string | null): DeviceAttributeClip | null {
    if (raw === null) return null;
    try {
        const v = JSON.parse(raw) as Record<string, unknown> | null;
        if (typeof v !== 'object' || v === null) return null;
        const fromId = int(v.fromId);
        const fromName = str(v.fromName);
        if (fromId === null || fromName === null) return null;
        const from = { fromId, fromName };
        switch (v.kind) {
            case 'icon':
                return { kind: 'icon', icon: str(v.icon), icon_color: str(v.icon_color), ...from };
            case 'location': {
                const latitude = coord(v.latitude, 90);
                const longitude = coord(v.longitude, 180);
                return latitude !== null && longitude !== null ? { kind: 'location', latitude, longitude, ...from } : null;
            }
            case 'poll': {
                const method = str(v.poll_method);
                if (method === null || !POLL_METHODS.includes(method)) return null;
                return { kind: 'poll', poll_method: method as PollMethod, credential_id: int(v.credential_id), ...from };
            }
            default:
                return null;
        }
    } catch {
        return null;
    }
}

// Read here, once the helpers above exist (see lib/mapClipboard for why the order matters).
try {
    current = parse(window.localStorage.getItem(STORAGE_KEY));
} catch {
    current = null;
}

// A copy made in another tab shows up here too (the event only fires in the *other* tabs).
if (typeof window !== 'undefined') {
    window.addEventListener('storage', (e) => {
        if (e.key !== STORAGE_KEY) return;
        current = parse(e.newValue);
        emit();
    });
}
