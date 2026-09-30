<?php

namespace App\Models;

use Illuminate\Support\Str;

/**
 * The knobs the manager turns, kept in the existing key/value settings table
 * rather than a table of their own — they are a handful of scalars, and that is
 * where this application already puts such things.
 *
 * The two link tokens live here too. Regenerating one is a write of a new
 * random string, which is exactly what "the link can be refreshed" means.
 */
class BusBookingSettings
{
    public const WEEKDAYS = 'bus.weekdays';

    public const PENALTY_TEXT = 'bus.penalty_text';

    public const LOCK_DAYS = 'bus.lock_days';

    public const OFFICER_PHONE = 'bus.officer_phone';

    public const SAME_WEEK_ONLY = 'bus.same_week_only';

    public const FEE_DEADLINE_WEEKDAY = 'bus.fee_deadline_weekday';

    public const SUPERVISOR_TOKEN = 'bus.supervisor_token';

    public const OFFICER_TOKEN = 'bus.officer_token';

    /**
     * Weekdays a trip may fall on, as 1=Sunday … 7=Saturday — the same numbering
     * the attendance periods already use. Empty means every day.
     *
     * @return array<int, int>
     */
    public static function weekdays(): array
    {
        $raw = Setting::getVal(self::WEEKDAYS);

        return array_values(array_map('intval', json_decode((string) $raw, true) ?: []));
    }

    /**
     * @param  array<int, int|string>  $weekdays
     */
    public static function setWeekdays(array $weekdays): void
    {
        Setting::setVal(self::WEEKDAYS, json_encode(array_values(array_unique(array_map('intval', $weekdays)))));
    }

    public static function penaltyText(): string
    {
        return (string) Setting::getVal(self::PENALTY_TEXT, '');
    }

    /**
     * How many days before the trip a supervisor can no longer call it off.
     * One means "up to the day before".
     */
    public static function lockDays(): int
    {
        return max(0, (int) Setting::getVal(self::LOCK_DAYS, 1));
    }

    /**
     * Whether booking is confined to the week in progress.
     *
     * The academy's week turns over on Saturday, so switching this on means a
     * supervisor can only book the days after that Saturday up to the next one
     * — and the week after opens when Saturday comes, not before.
     */
    public static function sameWeekOnly(): bool
    {
        return (bool) Setting::getVal(self::SAME_WEEK_ONLY, false);
    }

    /**
     * The weekday a prepaying stage's fee is due, as 1=Sunday … 7=Saturday.
     *
     * Wednesday by default: late enough in the week that a stage has had time to
     * gather the money, early enough that a bus it lets go can still be taken by
     * somebody else before the weekend.
     */
    public static function feeDeadlineWeekday(): int
    {
        $day = (int) Setting::getVal(self::FEE_DEADLINE_WEEKDAY, 4);

        return $day >= 1 && $day <= 7 ? $day : 4;
    }

    public static function officerPhone(): string
    {
        return (string) Setting::getVal(self::OFFICER_PHONE, '');
    }

    /**
     * The link tokens, minted on first use so neither page is ever unreachable
     * for want of a setting nobody knew to create.
     */
    public static function supervisorToken(): string
    {
        return self::token(self::SUPERVISOR_TOKEN);
    }

    public static function officerToken(): string
    {
        return self::token(self::OFFICER_TOKEN);
    }

    public static function regenerate(string $key): string
    {
        $token = Str::random(32);
        Setting::setVal($key, $token);

        return $token;
    }

    private static function token(string $key): string
    {
        $token = (string) Setting::getVal($key, '');

        return $token !== '' ? $token : self::regenerate($key);
    }
}
