<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRole;
use App\Services\TeacherSyncSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class Teacher extends User
{
    use BelongsToRole;

    public const ROLE = 'teacher';

    /** @return HasMany<SubstituteAssignment, $this> */
    public function substituteAssignments(): HasMany
    {
        return $this->hasMany(SubstituteAssignment::class, 'teacher_id');
    }

    /**
     * The circles the teacher works in today: their own, and those they stand
     * in for today. What every page of the day's work — the register, the
     * tasmeeh, the pairs, the competitions, the exams — and the app read.
     * Pages that shape a circle rather than work its day (its students, their
     * plans) keep to circles() alone.
     *
     * Each circle carries `standing_in`: true for one held today only as a
     * substitute, so a page can tell them apart without asking again.
     *
     * @return Builder<Circle>
     */
    public function workingCircles(): Builder
    {
        return Circle::query()
            ->select('circles.*')
            ->selectRaw('circles.id not in (select circle_id from circle_teacher where teacher_id = ?) as standing_in', [$this->id])
            ->where(fn ($q) => $q
                ->whereIn('circles.id', $this->ownCircleIds())
                ->orWhereIn('circles.id', $this->assignedToday()))
            ->orderBy('circles.id');
    }

    /**
     * @return array<int, int>
     */
    public function workingCircleIds(): array
    {
        return Circle::query()
            ->where(fn ($q) => $q
                ->whereIn('circles.id', $this->ownCircleIds())
                ->orWhereIn('circles.id', $this->assignedToday()))
            ->pluck('circles.id')
            ->all();
    }

    /**
     * The circles the teacher holds today only as a substitute — not one of
     * their own.
     *
     * @return array<int, int>
     */
    public function substituteCircleIds(): array
    {
        return $this->assignedToday()
            ->whereNotIn('circle_id', $this->ownCircleIds())
            ->pluck('circle_id')
            ->all();
    }

    /**
     * Whether the teacher may write a circle's record of a date: any date of
     * their own circles, and only today's of one they stand in for.
     */
    public function mayWorkOn(int $circleId, ?string $date): bool
    {
        if ($this->circles()->whereKey($circleId)->exists()) {
            return true;
        }

        return $date === TeacherSyncSnapshot::today()
            && $this->assignedToday()->where('circle_id', $circleId)->exists();
    }

    /** The teacher's own circles, as a subquery. */
    private function ownCircleIds(): QueryBuilder
    {
        return DB::table('circle_teacher')->select('circle_id')->where('teacher_id', $this->id);
    }

    /**
     * The circles assigned to the teacher as a substitute for today.
     *
     * @return Builder<SubstituteAssignment>
     */
    private function assignedToday(): Builder
    {
        return SubstituteAssignment::query()
            ->select('circle_id')
            ->where('teacher_id', $this->id)
            ->whereDate('date', TeacherSyncSnapshot::today());
    }
}
