<?php

namespace App\Support;

use App\Models\Stage;
use Carbon\CarbonImmutable;

/**
 * When students of a stage may book their turn in the tasmeeh queue: the days
 * of the week and the hours between, in the academy's time. The supervisor
 * sets one window for all their stages.
 */
final readonly class TurnBookingWindow
{
    public const TIMEZONE = 'Asia/Riyadh';

    /** Sunday to Thursday, Carbon's day numbers. */
    public const DEFAULT_DAYS = [0, 1, 2, 3, 4];

    /**
     * @param  array<int, int>  $days  Carbon's day numbers, 0 for Sunday.
     */
    public function __construct(
        public array $days,
        public string $startsAt,
        public string $endsAt,
    ) {}

    /**
     * The stage's window, or null while booking is off or not fully set.
     */
    public static function forStage(?Stage $stage): ?self
    {
        if (! $stage?->turn_booking_enabled || ! $stage->turn_booking_starts_at || ! $stage->turn_booking_ends_at) {
            return null;
        }

        $days = array_map('intval', $stage->turn_booking_days ?? []);

        return $days === [] ? null : new self($days, $stage->turn_booking_starts_at, $stage->turn_booking_ends_at);
    }

    /** Whether booking opens at some hour today. */
    public function opensToday(): bool
    {
        return in_array(self::now()->dayOfWeek, $this->days, true);
    }

    /** Whether a turn can be booked or given back right now. */
    public function isOpenNow(): bool
    {
        $time = self::now()->format('H:i');

        return $this->opensToday() && $time >= $this->startsAt && $time < $this->endsAt;
    }

    /** The hours as the pages show them: "4:00 م - 6:00 م". */
    public function hours(): string
    {
        return self::time($this->startsAt).' - '.self::time($this->endsAt);
    }

    private static function time(string $time): string
    {
        $at = CarbonImmutable::createFromFormat('H:i', $time, self::TIMEZONE);

        return $at->format('g:i').' '.($at->hour < 12 ? 'ص' : 'م');
    }

    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE);
    }
}
