<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The Outages page's time range: a preset (1 hour .. 6 months) turned into a window of whole
 * histogram bars, aligned to the viewer's local clock so hourly bars start on the hour and daily
 * bars at local midnight (weekly bars on a Monday). The list and the histogram share the window,
 * so the list never shows a range the chart doesn't.
 *
 * Bars are counted in "wall seconds" - seconds since 1970 as read on the viewer's clock - so the
 * database can bucket with plain arithmetic (see OutageController::histogram()).
 */
final class OutageWindow
{
    /** range key => [span seconds, bar seconds]. Months are 30 days. Roughly 24-30 bars each. */
    public const RANGES = [
        '1h' => [3600, 120],
        '2h' => [7200, 300],
        '4h' => [14400, 600],
        '6h' => [21600, 900],
        '12h' => [43200, 1800],
        '24h' => [86400, 3600],
        '1w' => [604800, 21600],
        '2w' => [1209600, 43200],
        '1mo' => [2592000, 86400],
        '2mo' => [5184000, 172800],
        '3mo' => [7776000, 259200],
        '6mo' => [15552000, 604800],
    ];

    /** Bars line up on this wall-clock instant: Monday 2001-01-01 00:00. */
    private const ORIGIN_WALL = 978307200;

    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly int $bucketSeconds,
        public readonly int $buckets,
        public readonly int $fromWall,
        public readonly string $timezone,
    ) {}

    public static function for(string $range, string $timezone, ?CarbonImmutable $now = null): self
    {
        [$span, $bucket] = self::RANGES[$range] ?? self::RANGES['24h'];
        $timezone = self::validTimezone($timezone);
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone);

        // The bar holding "now" is the last one; the window runs back whole bars from its end.
        $nowWall = $now->getTimestamp() + $now->getOffset();
        $endWall = intdiv($nowWall - self::ORIGIN_WALL, $bucket) * $bucket + self::ORIGIN_WALL + $bucket;
        $buckets = (int) ceil($span / $bucket);
        $fromWall = $endWall - $buckets * $bucket;

        return new self(self::fromWall($fromWall, $timezone), self::fromWall($endWall, $timezone), $bucket, $buckets, $fromWall, $timezone);
    }

    /** The start of bar $i, as a real instant (UTC). */
    public function bucketStart(int $i): CarbonImmutable
    {
        return self::fromWall($this->fromWall + $i * $this->bucketSeconds, $this->timezone);
    }

    public static function validTimezone(?string $timezone): string
    {
        return $timezone !== null && in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }

    /** A wall-clock time in $timezone (as seconds) -> the instant, in UTC. */
    private static function fromWall(int $wall, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($wall, 'UTC')->shiftTimezone($timezone)->setTimezone('UTC');
    }
}
