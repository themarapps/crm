<?php
declare(strict_types=1);

final class Time
{
    /** @return array{string, string} */
    public static function riyadhDayUtcBounds(): array
    {
        $riyadh = new DateTimeZone('Asia/Riyadh');
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable('today', $riyadh);
        $end = $start->modify('+1 day')->modify('-1 second');
        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }
}
