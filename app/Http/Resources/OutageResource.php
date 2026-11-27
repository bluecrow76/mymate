<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $device = $this->device;

        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'device_name' => $device?->name,
            'device_alias' => $device?->alias,
            // The device's management IP as it is now (outages don't record the IP at the time).
            'device_ip' => $device?->mgmt_ip,
            // What the page's device icon (DeviceGlyph, as on the map) is drawn from: a custom icon
            // and tint if set, else the model's product photo, the vendor's mark or the type icon.
            'device_type' => $device?->device_type?->value ?? 'unknown',
            'device_vendor' => $device?->vendor,
            'device_model' => $device?->model,
            'device_icon' => $device?->icon,
            'device_icon_color' => $device?->icon_color,
            // Maps the device is placed on that this operator can open, by name - the page jumps to
            // one. Empty when it's on none (or none visible), so the page opens the device instead.
            'device_maps' => $device
                ? $device->mapPositions
                    ->map(fn ($p) => $p->map)
                    ->filter()
                    ->sortBy(fn ($m) => mb_strtolower($m->name))
                    ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])
                    ->values()
                    ->all()
                : [],
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'duration_s' => $this->duration_s,
            'ongoing' => $this->ended_at === null,
            'cause' => $this->cause,
        ];
    }
}
