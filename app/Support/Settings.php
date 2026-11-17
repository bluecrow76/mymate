<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Engine settings: a thin repository that reads operator overrides
 * from the `settings` table, falling back to `config/mymate.php`. The loop + history
 * maintenance read through this each cycle, so a change applies without a restart.
 *
 * Only the keys in SCHEMA are operator-editable - each is genuinely read live below.
 */
class Settings
{
    /**
     * Editable tunables: config-key => [min, max, label, type?]. Default = config("mymate.$key").
     * `type` is 'int' (the default) or 'bool' - a 0/1 value the Settings page shows as a checkbox.
     */
    public const SCHEMA = [
        'ping.interval' => ['min' => 1, 'max' => 3600, 'label' => 'Up/down ping interval (seconds)'],
        'poll.interval' => ['min' => 1, 'max' => 3600, 'label' => 'Throughput poll interval (seconds)'],
        'poll.discover_interval' => ['min' => 60, 'max' => 86400, 'label' => 'Interface re-discovery interval (seconds)'],
        'discovery.check_interval' => ['min' => 5, 'max' => 3600, 'label' => 'Discovery scan-check interval (seconds)'],
        'history.retention_days' => ['min' => 1, 'max' => 365, 'label' => 'Recent-history retention (days)'],
        // Long-term rollups (GitHub #28). 5m is the source the hourly tier is built from, so it
        // has to outlive a day or two of the rollup job being down.
        'history.rollup_5m_days' => ['min' => 2, 'max' => 365, 'label' => '5-minute rollup retention (days)'],
        'history.rollup_1h_days' => ['min' => 1, 'max' => 3650, 'label' => 'Hourly rollup retention (days)'],
        // Neighbour data (LLDP/MNDP/FDB/ARP sightings), read on its own cadence and queue.
        'neighbours.collect' => ['min' => 0, 'max' => 1, 'type' => 'bool', 'label' => 'Collect neighbour data (LLDP, MNDP, MAC and ARP tables) from SNMP devices'],
        'neighbours.interval' => ['min' => 600, 'max' => 86400, 'label' => 'Neighbour data read interval (seconds)'],
        'neighbours.retention_days' => ['min' => 1, 'max' => 365, 'label' => 'Neighbour history retention (days)'],
    ];

    /** DB override if present, else the config/mymate.php default. */
    public function get(string $key, mixed $default = null): mixed
    {
        $row = Setting::where('key', $key)->first();

        return $row !== null ? $row->value : config("mymate.$key", $default);
    }

    public function getInt(string $key, int $default): int
    {
        return (int) $this->get($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Effective value (override or default) + meta for every editable key - the UI form.
     *
     * @return list<array{key:string, value:int, label:string, min:int, max:int, type:string}>
     */
    public function effective(): array
    {
        $out = [];
        foreach (self::SCHEMA as $key => $meta) {
            $out[] = [
                'key' => $key,
                'value' => (int) $this->get($key, 0),
                'label' => $meta['label'],
                'min' => $meta['min'],
                'max' => $meta['max'],
                'type' => $meta['type'] ?? 'int',
            ];
        }

        return $out;
    }
}
