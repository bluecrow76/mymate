import { useSyncExternalStore } from 'react';

/**
 * A one-shot "pan the map to this device" request. Pages outside the map (Outages, Devices,
 * a device's own page) set it as they switch to the map; MapCanvas frames the card once it's on
 * screen and clears the request. Kept out of the persisted shell store on purpose - a reload
 * shouldn't fly the map somewhere it was asked to go an hour ago.
 */
type FocusRequest = { deviceId: number; mapId: number; nonce: number } | null;

let request: FocusRequest = null;
let nonce = 0;
const listeners = new Set<() => void>();

function emit(): void {
    listeners.forEach((l) => l());
}

export function requestMapFocus(deviceId: number, mapId: number): void {
    request = { deviceId, mapId, nonce: ++nonce };
    emit();
}

/** Clear the request once handled. The nonce guards against clearing a newer one. */
export function clearMapFocus(handled: number): void {
    if (request?.nonce !== handled) return;
    request = null;
    emit();
}

export function useMapFocusRequest(): FocusRequest {
    return useSyncExternalStore(
        (cb) => {
            listeners.add(cb);
            return () => listeners.delete(cb);
        },
        () => request,
    );
}
