<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use IntlDateFormatter;

/**
 * Dates as the academy reads them: Umm al-Qura Hijri, in Arabic.
 *
 * The calendar and locale used to be spelled out wherever a date was shown —
 * eighty hand-built formatters across twenty-six files, drifting between
 * patterns. They all come through here now, so a date reads the same wherever
 * it appears and a change of wording is made once.
 *
 * Dates are stored and reasoned about in Gregorian throughout; this is a
 * reading of them, never a replacement.
 */
class HijriDate
{
    private const LOCALE = 'ar_SA@calendar=islamic-umalqura';

    private const TIMEZONE = 'Asia/Riyadh';

    /**
     * Formatters are expensive to build and are asked for the same handful of
     * patterns over and over in a table, so each pattern is built once.
     *
     * @var array<string, IntlDateFormatter>
     */
    private static array $formatters = [];

    /**
     * "١٨ صفر ١٤٤٨" — the everyday form.
     */
    public static function full(DateTimeInterface|string|int|null $date): string
    {
        return self::format($date, 'd MMMM yyyy');
    }

    /**
     * "السبت، ١٨ صفر ١٤٤٨" — when the day of the week matters.
     */
    public static function withWeekday(DateTimeInterface|string|int|null $date): string
    {
        return self::format($date, 'EEEE، d MMMM yyyy');
    }

    /**
     * "١٨ صفر" — inside a table or a chart, where the year is already known.
     */
    public static function dayMonth(DateTimeInterface|string|int|null $date): string
    {
        return self::format($date, 'd MMMM');
    }

    /**
     * "صفر ١٤٤٨" — a heading over a month.
     */
    public static function monthYear(DateTimeInterface|string|int|null $date): string
    {
        return self::format($date, 'MMMM yyyy');
    }

    /**
     * "السبت".
     */
    public static function weekday(DateTimeInterface|string|int|null $date): string
    {
        return self::format($date, 'EEEE');
    }

    /**
     * "١٨ صفر ١٤٤٨ ٠٥:٣٠ م" — when the hour is part of the record.
     */
    public static function withTime(DateTimeInterface|string|int|null $date): string
    {
        return self::format($date, 'd MMMM yyyy hh:mm a');
    }

    /**
     * The Gregorian date behind a Hijri one, for the title attribute that
     * carries it on hover.
     */
    public static function gregorian(DateTimeInterface|string|int|null $date): string
    {
        $timestamp = self::timestamp($date);

        return $timestamp === null ? '' : Carbon::createFromTimestamp($timestamp, self::TIMEZONE)->format('Y-m-d');
    }

    /**
     * The Hijri months from the one holding $from onwards, each with the
     * Gregorian day it starts on and its length, so the teacher app can draw a
     * month grid without doing any Umm al-Qura arithmetic of its own.
     *
     * Read at noon so moving across midnight in either timezone can never land
     * on a neighbouring Gregorian day, and through the calendar's own clock
     * rather than date(), which reads UTC.
     *
     * @return array<int, array{key: string, title: string, first_day: string, length: int}>
     */
    public static function months(string $from, int $count): array
    {
        $calendar = \IntlCalendar::createInstance(self::TIMEZONE, self::LOCALE);
        $calendar->setTime(Carbon::parse($from.' 12:00:00', self::TIMEZONE)->getTimestamp() * 1000);
        $calendar->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);

        $months = [];

        for ($i = 0; $i < $count; $i++) {
            $firstDay = Carbon::createFromTimestamp((int) ($calendar->getTime() / 1000), self::TIMEZONE)->toDateString();

            $months[] = [
                'key' => sprintf('%04d-%02d', $calendar->get(\IntlCalendar::FIELD_YEAR), $calendar->get(\IntlCalendar::FIELD_MONTH) + 1),
                'title' => self::monthYear($firstDay.' 12:00:00'),
                'first_day' => $firstDay,
                'length' => $calendar->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH),
            ];

            $calendar->add(\IntlCalendar::FIELD_MONTH, 1);
        }

        return $months;
    }

    /**
     * Latin digits rendered as Arabic-Indic ones.
     *
     * The dates on these pages already read ٨ ربيع الآخر ١٤٤٨; a plain 1 beside
     * them looks like a different language rather than the same sentence.
     */
    public static function arabicDigits(int|string $value): string
    {
        return strtr((string) $value, [
            '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        ]);
    }

    /**
     * Format against any ICU pattern. An empty date reads as an empty string,
     * so a view never has to guard a missing one.
     */
    public static function format(DateTimeInterface|string|int|null $date, string $pattern): string
    {
        $timestamp = self::timestamp($date);

        if ($timestamp === null) {
            return '';
        }

        self::$formatters[$pattern] ??= new IntlDateFormatter(
            self::LOCALE,
            IntlDateFormatter::FULL,
            IntlDateFormatter::NONE,
            self::TIMEZONE,
            IntlDateFormatter::TRADITIONAL,
            $pattern,
        );

        $formatted = self::$formatters[$pattern]->format($timestamp);

        return $formatted === false ? '' : $formatted;
    }

    /**
     * Anything a date can arrive as, reduced to a timestamp. Blank strings and
     * unparseable values give null rather than today, so a missing date is
     * never quietly rendered as one.
     */
    private static function timestamp(DateTimeInterface|string|int|null $date): ?int
    {
        if ($date === null || $date === '') {
            return null;
        }

        if (is_int($date)) {
            return $date;
        }

        if ($date instanceof DateTimeInterface) {
            return $date->getTimestamp();
        }

        try {
            return Carbon::parse($date)->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }
}
