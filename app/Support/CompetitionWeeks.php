<?php

namespace App\Support;

use App\Models\Leaderboard;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The weeks of a competition's days, for its standings week by week. A week
 * runs Sunday to Saturday, as the academy's does: the first from the day the
 * competition starts, the last up to its end — or today, while it runs.
 */
final class CompetitionWeeks
{
    /**
     * @return list<array{number: int, from: string, to: string, label: string}>
     */
    public static function of(Leaderboard $leaderboard, ?string $today = null): array
    {
        $today ??= now('Asia/Riyadh')->toDateString();
        $start = $leaderboard->start_date->toDateString();
        $end = min($leaderboard->end_date?->toDateString() ?? $today, $today);
        $weeks = [];

        for ($from = CarbonImmutable::parse($start), $number = 1; $from->toDateString() <= $end; $number++) {
            $saturday = $from->endOfWeek(CarbonInterface::SATURDAY)->startOfDay();
            $to = min($saturday->toDateString(), $end);

            $weeks[] = [
                'number' => $number,
                'from' => $from->toDateString(),
                'to' => $to,
                'label' => 'الأسبوع '.HijriDate::arabicDigits($number).' · '.HijriDate::dayMonth($from).' – '.HijriDate::dayMonth($to),
            ];

            $from = $saturday->addDay();
        }

        return $weeks;
    }

    /**
     * One week of the competition by its number; null for none, or one it does not have.
     *
     * @return array{number: int, from: string, to: string, label: string}|null
     */
    public static function find(Leaderboard $leaderboard, int|string|null $number): ?array
    {
        if ($number === null || $number === '') {
            return null;
        }

        return collect(self::of($leaderboard))->firstWhere('number', (int) $number);
    }
}
