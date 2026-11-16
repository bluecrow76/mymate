import { useCallback, useState, type ReactNode } from 'react';
import { useActiveMapId } from '../../../lib/shellStore';
import { openDevicePage, showOnMap } from '../../device-page/lib/location';
import { DeviceMapChoiceMenu, type MapChoice } from '../components/DeviceMapChoiceMenu';

/**
 * "Show this device on the map" for pages outside the map (Outages, Devices):
 *  - on no map (or none this operator can open): its device page instead of an empty map;
 *  - already on the map that's open: stay there;
 *  - on exactly one map: that one;
 *  - on several: a small menu at the click to pick which.
 * The map then frames the device's card (see mapFocus). Render `menu` somewhere in the page.
 */
export function useShowOnMap(): {
    go: (deviceId: number, deviceName: string, maps: MapChoice[], at: { x: number; y: number }) => void;
    menu: ReactNode;
} {
    const activeMapId = useActiveMapId();
    const [choice, setChoice] = useState<{ deviceId: number; deviceName: string; maps: MapChoice[]; x: number; y: number } | null>(null);

    const go = useCallback(
        (deviceId: number, deviceName: string, maps: MapChoice[], at: { x: number; y: number }) => {
            if (maps.length === 0) openDevicePage(deviceId);
            else if (activeMapId !== null && maps.some((m) => m.id === activeMapId)) showOnMap(deviceId, activeMapId);
            else if (maps.length === 1) showOnMap(deviceId, maps[0].id);
            else setChoice({ deviceId, deviceName, maps, ...at });
        },
        [activeMapId],
    );

    const menu = choice ? (
        <DeviceMapChoiceMenu
            deviceName={choice.deviceName}
            maps={choice.maps}
            x={choice.x}
            y={choice.y}
            onPick={(mapId) => {
                setChoice(null);
                showOnMap(choice.deviceId, mapId);
            }}
            onClose={() => setChoice(null)}
        />
    ) : null;

    return { go, menu };
}
