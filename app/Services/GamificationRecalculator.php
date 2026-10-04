<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\FreeRecitation;
use App\Models\Leaderboard;
use App\Models\Student;
use App\Models\StudentHadithAchievement;
use App\Models\StudentOdeAchievement;
use App\Models\StudentPlanDay;
use Illuminate\Support\Carbon;

/**
 * Replays a competition's gradings through the gamification rules.
 *
 * A gamification competition scores from its transactions, and a transaction is
 * only written when a teacher grades — against whatever criteria were enabled at
 * that moment. Turning a criterion on afterwards therefore leaves every earlier
 * grading unscored, because nothing ever revisits it. This walks the gradings
 * inside the competition's window and puts each one back through the same sync
 * the grading itself uses, so the scoring rules live in one place only.
 *
 * It runs in bounded steps rather than in one pass. A real competition here has
 * over three thousand gradings and each sync costs tens of milliseconds, which
 * is minutes of work — far past any request timeout. Stepping also means no
 * queue worker has to be running for the button to work, and the supervisor can
 * watch it advance.
 *
 * Safe to run repeatedly: each sync looks up the transaction for its grading and
 * updates, creates or removes it, so nothing is ever counted twice.
 */
class GamificationRecalculator
{
    /** Gradings handled per step. Kept low enough to stay well inside a request. */
    public const BATCH = 120;

    /** The stages, in order; each is drained before the next begins. */
    private const STAGES = ['quran', 'ode', 'hadith', 'free', 'attendance', 'students'];

    /**
     * A fresh cursor: the state a caller keeps between steps.
     *
     * @return array<string, mixed>
     */
    public static function start(): array
    {
        return [
            'stage' => self::STAGES[0],
            'after' => 0,
            'done' => false,
            'counts' => ['quran' => 0, 'ode' => 0, 'hadith' => 0, 'free' => 0, 'attendance' => 0, 'students' => 0],
        ];
    }

    /**
     * Advance one bounded step and hand back the cursor to pass in next time.
     *
     * @param  array<string, mixed>  $cursor
     * @return array<string, mixed>
     */
    public static function step(Leaderboard $competition, array $cursor): array
    {
        $studentIds = self::studentIds($competition);

        if ($studentIds === []) {
            return ['stage' => 'students', 'after' => 0, 'done' => true, 'counts' => $cursor['counts']];
        }

        $from = $competition->start_date?->startOfDay();
        $to = ($competition->end_date ?? now())->endOfDay();

        [$handled, $lastId] = match ($cursor['stage']) {
            'quran' => self::replayQuran($studentIds, $from, $to, $cursor['after']),
            'ode' => self::replayOdes($studentIds, $from, $to, $cursor['after']),
            'hadith' => self::replayHadiths($studentIds, $from, $to, $cursor['after']),
            'free' => self::replayFreeRecitations($studentIds, $from, $to, $cursor['after']),
            'attendance' => self::replayAttendance($studentIds, $from, $to, $cursor['after']),
            default => self::settleStudents($competition, $studentIds, $cursor['after']),
        };

        $counts = $cursor['counts'];
        // A cursor saved before a stage existed starts that stage from nothing.
        $counts[$cursor['stage']] = ($counts[$cursor['stage']] ?? 0) + $handled;

        // A step that reached the end of its stage moves on to the next one.
        if ($lastId === null) {
            $next = array_search($cursor['stage'], self::STAGES, true) + 1;

            return [
                'stage' => self::STAGES[$next] ?? 'students',
                'after' => 0,
                'done' => ! isset(self::STAGES[$next]),
                'counts' => $counts,
            ];
        }

        return ['stage' => $cursor['stage'], 'after' => $lastId, 'done' => false, 'counts' => $counts];
    }

    /**
     * Run the whole thing in one pass. Convenient for tests and the console;
     * too slow for a web request on real data.
     *
     * @return array<string, int>
     */
    public static function forCompetition(Leaderboard $competition): array
    {
        $cursor = self::start();

        while (! $cursor['done']) {
            $cursor = self::step($competition, $cursor);
        }

        return $cursor['counts'];
    }

    /**
     * Every student the competition covers: its own circle, or all the circles
     * of a supervisor-run competition.
     *
     * @return array<int, int>
     */
    private static function studentIds(Leaderboard $competition): array
    {
        $circleIds = $competition->isSupervisorCompetition()
            ? $competition->circles()->pluck('circles.id')->all()
            : array_filter([$competition->circle_id]);

        if ($circleIds === []) {
            return [];
        }

        return Student::whereIn('circle_id', $circleIds)->pluck('id')->all();
    }

    /**
     * @param  array<int, int>  $studentIds
     * @return array{0: int, 1: int|null} how many were synced, and the id to resume after
     */
    private static function replayQuran(array $studentIds, $from, $to, int $after): array
    {
        $days = StudentPlanDay::whereHas('plan', fn ($q) => $q->whereIn('student_id', $studentIds))
            ->where(fn ($q) => $q->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement'))
            ->where('id', '>', $after)
            ->with('plan.student')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        $synced = 0;

        foreach ($days as $day) {
            if (self::gradedWithin($day->gamificationDate(), $from, $to)) {
                GamificationService::syncStudentPlanDayXP($day);
                $synced++;
            }
        }

        return [$synced, $days->count() < self::BATCH ? null : $days->last()->id];
    }

    /**
     * @param  array<int, int>  $studentIds
     * @return array{0: int, 1: int|null}
     */
    private static function replayOdes(array $studentIds, $from, $to, int $after): array
    {
        $achievements = StudentOdeAchievement::whereHas('plan', fn ($q) => $q->whereIn('student_id', $studentIds))
            ->where(fn ($q) => $q->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement'))
            ->where('id', '>', $after)
            ->with('plan.student', 'pathDay')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        $synced = 0;

        foreach ($achievements as $achievement) {
            $date = $achievement->hifz_graded_at ?? $achievement->review_graded_at ?? $achievement->pathDay?->date;

            if (self::gradedWithin($date, $from, $to)) {
                GamificationService::syncStudentOdeAchievementXP($achievement);
                $synced++;
            }
        }

        return [$synced, $achievements->count() < self::BATCH ? null : $achievements->last()->id];
    }

    /**
     * @param  array<int, int>  $studentIds
     * @return array{0: int, 1: int|null}
     */
    private static function replayHadiths(array $studentIds, $from, $to, int $after): array
    {
        $achievements = StudentHadithAchievement::whereHas('plan', fn ($q) => $q->whereIn('student_id', $studentIds))
            ->where(fn ($q) => $q->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement'))
            ->where('id', '>', $after)
            ->with('plan.student', 'pathDay')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        $synced = 0;

        foreach ($achievements as $achievement) {
            $date = $achievement->hifz_graded_at ?? $achievement->review_graded_at ?? $achievement->pathDay?->date;

            if (self::gradedWithin($date, $from, $to)) {
                GamificationService::syncStudentHadithAchievementXP($achievement);
                $synced++;
            }
        }

        return [$synced, $achievements->count() < self::BATCH ? null : $achievements->last()->id];
    }

    /**
     * Recitations by students with no plan, scored like plan days.
     *
     * @param  array<int, int>  $studentIds
     * @return array{0: int, 1: int|null}
     */
    private static function replayFreeRecitations(array $studentIds, $from, $to, int $after): array
    {
        $recitations = FreeRecitation::whereIn('student_id', $studentIds)
            ->whereNotNull('achievement')
            ->where('id', '>', $after)
            ->with('student')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        $synced = 0;

        foreach ($recitations as $recitation) {
            if (self::gradedWithin($recitation->graded_at ?? $recitation->recited_on, $from, $to)) {
                GamificationService::syncFreeRecitationXP($recitation);
                $synced++;
            }
        }

        return [$synced, $recitations->count() < self::BATCH ? null : $recitations->last()->id];
    }

    /**
     * @param  array<int, int>  $studentIds
     * @return array{0: int, 1: int|null}
     */
    private static function replayAttendance(array $studentIds, $from, $to, int $after): array
    {
        $records = Attendance::whereIn('student_id', $studentIds)
            ->whereDate('date', '>=', $from->format('Y-m-d'))
            ->whereDate('date', '<=', $to->format('Y-m-d'))
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($records as $record) {
            GamificationService::syncStudentAttendanceXP($record);
        }

        return [$records->count(), $records->count() < self::BATCH ? null : $records->last()->id];
    }

    /**
     * Levels, coin balances and streaks all derive from the transactions above,
     * so they only settle once those exist — hence the last stage.
     *
     * @param  array<int, int>  $studentIds
     * @return array{0: int, 1: int|null}
     */
    private static function settleStudents(Leaderboard $competition, array $studentIds, int $after): array
    {
        $batch = collect($studentIds)->filter(fn ($id) => $id > $after)->sort()->take(self::BATCH)->values();

        if ($batch->isEmpty()) {
            return [0, null];
        }

        foreach (Student::whereIn('id', $batch)->get() as $student) {
            GamificationService::recalculateStudentState($student->id, $competition->id);
            GamificationService::recalculateStudentStreak($student, $competition);
        }

        return [$batch->count(), $batch->count() < self::BATCH ? null : $batch->last()];
    }

    private static function gradedWithin($date, $from, $to): bool
    {
        if (! $date) {
            return false;
        }

        $date = $date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date);

        return (! $from || $date->greaterThanOrEqualTo($from)) && $date->lessThanOrEqualTo($to);
    }
}
