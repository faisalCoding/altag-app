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
     * Each week with its number, its days, and how it reads: a label, or a
     * title over a short range for a compact chip — and whether today is in it.
     *
     * @return list<array{number: int, from: string, to: string, label: string, title: string, range: string, current: bool}>
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

            $title = 'الأسبوع '.HijriDate::arabicDigits($number);
            $range = self::range($from->toDateString(), $to);

            $weeks[] = [
                'number' => $number,
                'from' => $from->toDateString(),
                'to' => $to,
                'label' => "{$title} · {$range}",
                'title' => $title,
                'range' => $range,
                'current' => $from->toDateString() <= $today && $today <= $to,
            ];

            $from = $saturday->addDay();
        }

        return $weeks;
    }

    /**
     * "٥–١١ ربيع الآخر", or "٢٨ ربيع الأول – ٤ ربيع الآخر" across two months;
     * one day alone when the week has one.
     */
    private static function range(string $from, string $to): string
    {
        if ($from === $to) {
            return HijriDate::dayMonth($from);
        }

        if (HijriDate::format($from, 'MMMM') === HijriDate::format($to, 'MMMM')) {
            return HijriDate::format($from, 'd').'–'.HijriDate::dayMonth($to);
        }

        return HijriDate::dayMonth($from).' – '.HijriDate::dayMonth($to);
    }

    /**
     * One week of the competition by its number; null for none, or one it does not have.
     *
     * @return array{number: int, from: string, to: string, label: string, title: string, range: string, current: bool}|null
     */
    public static function find(Leaderboard $leaderboard, int|string|null $number): ?array
    {
        if ($number === null || $number === '') {
            return null;
        }

        return collect(self::of($leaderboard))->firstWhere('number', (int) $number);
    }
}
