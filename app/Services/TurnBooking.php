<?php

namespace App\Services;

use App\Models\Circle;
use App\Models\CircleTurn;
use App\Models\Student;
use App\Support\TurnBookingWindow;
use Illuminate\Support\Collection;

/**
 * Students booking their turn in their circle's tasmeeh queue, within the
 * window the supervisor set for the circle's stage.
 */
class TurnBooking
{
    /**
     * The window the student books in. A student outside any circle has no
     * queue to join.
     */
    public static function windowFor(Student $student): ?TurnBookingWindow
    {
        return $student->circle_id ? TurnBookingWindow::forStage($student->circle?->stage) : null;
    }

    /** The turn the student holds in their circle's queue on a day. */
    public static function turnOf(Student $student, string $date): ?CircleTurn
    {
        if (! $student->circle_id) {
            return null;
        }

        return CircleTurn::where('circle_id', $student->circle_id)
            ->whereDate('date', $date)
            ->where('student_id', $student->id)
            ->first();
    }

    /**
     * Book the student the next turn today: 'closed' outside the window,
     * 'busy' when every attempt lost the race, 'held' for a turn they already
     * had, and 'booked' for a new one.
     *
     * @return array{0: 'closed'|'busy'|'held'|'booked', 1: ?CircleTurn}
     */
    public static function reserve(Student $student): array
    {
        if (! self::windowFor($student)?->isOpenNow()) {
            return ['closed', null];
        }

        $turn = CircleTurn::reserveNext($student->circle_id, $student->id, self::today());

        if (! $turn) {
            return ['busy', null];
        }

        return [$turn->wasRecentlyCreated ? 'booked' : 'held', $turn];
    }

    /**
     * Give back today's turn, while the window is still open. The numbers after
     * it stay as they are: students already told theirs keep them.
     */
    public static function cancel(Student $student): bool
    {
        if (! self::windowFor($student)?->isOpenNow()) {
            return false;
        }

        return (bool) self::turnOf($student, self::today())?->delete();
    }

    /**
     * The turns booked in some circles' queues between two days, for the
     * teachers who call them.
     *
     * @param  array<int, int>  $circleIds
     * @return Collection<int, CircleTurn>
     */
    public static function turnsBetween(array $circleIds, string $from, string $to): Collection
    {
        return CircleTurn::whereIn('circle_id', $circleIds)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->orderBy('date')
            ->orderBy('circle_id')
            ->orderBy('turn_number')
            ->get();
    }

    /**
     * The window booking opens in today for any of the circles, as the
     * teacher's page announces it.
     *
     * @param  Collection<int, Circle>  $circles
     */
    public static function windowToday(Collection $circles): ?TurnBookingWindow
    {
        foreach ($circles as $circle) {
            $window = TurnBookingWindow::forStage($circle->stage);

            if ($window?->opensToday()) {
                return $window;
            }
        }

        return null;
    }

    public static function today(): string
    {
        return now(TurnBookingWindow::TIMEZONE)->toDateString();
    }
}
