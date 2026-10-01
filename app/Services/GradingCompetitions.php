<?php

namespace App\Services;

use App\Models\Leaderboard;
use Illuminate\Support\Collection;

/**
 * Which competition each circle records criteria scores in.
 *
 * The rule the teacher's web shell applies, read per circle: the supervisor
 * competition marked as primary for grading that the circle takes part in,
 * otherwise the teacher's own competition marked for grading for that circle.
 * Read per circle rather than once for the teacher, so a teacher whose circles
 * sit under two supervisors grades each in its own competition.
 */
class GradingCompetitions
{
    /**
     * @param  iterable<int>  $circleIds
     * @return Collection<int, Leaderboard> keyed by circle id; circles with none are left out
     */
    public static function forCircles(iterable $circleIds): Collection
    {
        $circleIds = collect($circleIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($circleIds->isEmpty()) {
            return collect();
        }

        $competitions = Leaderboard::where('is_active_for_grading', true)
            ->where(function ($query) use ($circleIds) {
                $query->whereIn('circle_id', $circleIds)
                    ->orWhereHas('circles', fn ($circles) => $circles->whereIn('circles.id', $circleIds));
            })
            ->with(['circles:id', 'criteria' => fn ($criteria) => $criteria->orderBy('id')])
            ->orderBy('id')
            ->get();

        return $circleIds
            ->mapWithKeys(function (int $circleId) use ($competitions) {
                $covering = $competitions->filter(
                    fn (Leaderboard $competition) => $competition->circle_id === $circleId
                        || $competition->circles->contains('id', $circleId)
                );

                return [$circleId => $covering->first(fn (Leaderboard $competition) => $competition->isSupervisorCompetition())
                    ?? $covering->first()];
            })
            ->filter();
    }
}
