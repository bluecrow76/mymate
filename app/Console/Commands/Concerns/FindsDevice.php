<?php

namespace App\Console\Commands\Concerns;

use App\Models\Device;

/**
 * Resolve a device named on the command line: an id, a management IP, an exact name (any case),
 * else a unique partial name. Several matches print a table to pick an id from.
 */
trait FindsDevice
{
    private function findDevice(string $needle): ?Device
    {
        $device = ctype_digit($needle) ? Device::find((int) $needle) : null;
        $device ??= Device::where('mgmt_ip', $needle)->first();
        if ($device !== null) {
            return $device;
        }

        $matches = Device::whereRaw('lower(name) = ?', [mb_strtolower($needle)])->get();
        if ($matches->isEmpty()) {
            $matches = Device::where('name', 'ilike', '%'.addcslashes($needle, '%_\\').'%')->orderBy('name')->limit(20)->get();
        }
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->isEmpty()) {
            $this->error("No device matches \"{$needle}\".");
        } else {
            $this->error("\"{$needle}\" matches several devices - use the id:");
            $this->table(['Id', 'Name', 'IP'], $matches->map(fn (Device $d) => [$d->id, $d->name, $d->mgmt_ip])->all());
        }

        return null;
    }
}
