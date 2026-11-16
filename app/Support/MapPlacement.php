<?php

namespace App\Support;

use App\Models\DeviceMapPosition;

/**
 * Where a device added to a map by discovery lands, so a batch of new devices doesn't pile up on
 * one spot. The map's existing device cards are laid over a grid of card-sized slots (a default
 * card is ~200px wide, so a slot is 240 x 144 with room around it, both multiples of the default
 * 16px snap grid) and the device takes the first free slot along the chosen edge:
 *
 *  - right:  down the right-hand column (the rightmost card's column), from the top;
 *  - left:   down the left-hand column, from the top;
 *  - bottom: along the bottom row (the lowest card's row), from the left;
 *  - top:    along the top row, from the left.
 *
 * An empty map starts at 0,0. Positions are card top-left corners, as React Flow stores them.
 */
class MapPlacement
{
    public const EDGES = ['right', 'left', 'bottom', 'top'];

    public const DEFAULT = 'right';

    public const SLOT_W = 240;

    public const SLOT_H = 144;

    private const SNAP = 16;

    private const MAX_STEPS = 10000; // a map would need 10k cards in one line to run out

    /** @return array{x: float, y: float} */
    public function nextSlot(int $mapId, string $edge): array
    {
        $taken = DeviceMapPosition::where('map_id', $mapId)->get(['x', 'y'])
            ->map(fn ($p) => [(float) $p->x, (float) $p->y])
            ->all();

        if ($taken === []) {
            return ['x' => 0.0, 'y' => 0.0];
        }

        $xs = array_column($taken, 0);
        $ys = array_column($taken, 1);
        $edge = in_array($edge, self::EDGES, true) ? $edge : self::DEFAULT;

        // The line to walk: a fixed column (stepping down) or a fixed row (stepping right).
        [$x, $y, $dx, $dy] = match ($edge) {
            'right' => [max($xs), min($ys), 0, self::SLOT_H],
            'left' => [min($xs), min($ys), 0, self::SLOT_H],
            'bottom' => [min($xs), max($ys), self::SLOT_W, 0],
            'top' => [min($xs), min($ys), self::SLOT_W, 0],
        };
        $x = $this->snap($x);
        $y = $this->snap($y);

        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            if (! $this->overlaps($x, $y, $taken)) {
                return ['x' => $x, 'y' => $y];
            }
            $x += $dx;
            $y += $dy;
        }

        return ['x' => $x, 'y' => $y];
    }

    /** @param list<array{0: float, 1: float}> $taken */
    private function overlaps(float $x, float $y, array $taken): bool
    {
        foreach ($taken as [$tx, $ty]) {
            if (abs($tx - $x) < self::SLOT_W && abs($ty - $y) < self::SLOT_H) {
                return true;
            }
        }

        return false;
    }

    private function snap(float $v): float
    {
        return round($v / self::SNAP) * self::SNAP + 0.0;
    }
}
