<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * The days absences and late arrivals are counted over against their limits:
 * the last `calculation_period_days` days up to a given day, that day
 * included — and never before the day the manager set counting to start
 * from, so a new term can begin with a clean slate.
 *
 * Every count of the limits reads it — the roll call's warnings, the
 * guardian's messages, the over-the-limit list, the student's and the
 * guardian's pages — so they agree on what has been counted. They used to
 * build the window each their own way, one of them a day longer than the rest.
 */
final class DisciplineWindow
{
    public const START_SETTING = 'discipline_count_from';

    /**
     * @return array{from: string, to: string}
     */
    public static function for(Carbon|string|null $day = null): array
    {
        $to = $day === null
            ? now('Asia/Riyadh')->format('Y-m-d')
            : Carbon::parse($day)->format('Y-m-d');
        $from = Carbon::parse($to)->subDays(self::days() - 1)->format('Y-m-d');
        $start = self::countsFrom();

        return ['from' => $start !== null && $start > $from ? $start : $from, 'to' => $to];
    }

    /** How many days back the window reaches, the last one included. */
    public static function days(): int
    {
        return max(1, (int) Setting::getVal('calculation_period_days', 30));
    }

    /** The day counting starts from, or null to count the whole window. */
    public static function countsFrom(): ?string
    {
        $start = (string) Setting::getVal(self::START_SETTING, '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : null;
    }
}
