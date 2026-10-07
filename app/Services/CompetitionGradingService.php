<?php

namespace App\Services;

use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\LeaderboardCriterion;
use App\Models\LeaderboardScore;
use App\Models\Student;
use App\Models\Teacher;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Criteria scores and extra points sent from the teacher's phone, applied the
 * way the web grading page applies them, gamification included.
 *
 * Every change is held to the competition the student's circle grades in —
 * the one the supervisor marked as primary — and to that competition's
 * window: a day outside it would earn nothing, so it is refused rather than
 * saved to no effect.
 */
class CompetitionGradingService
{
    /**
     * Grant or withdraw criteria, each change on its own.
     *
     * A change states the outcome wanted — granted or not — rather than a flip,
     * so a resend, or a colleague who got there first, finds the cell already
     * as wanted. With two possible values there is no third to conflict with.
     *
     * @param  array<int, array{id: string, competition_id: int, criterion_id: int, student_id: int, date: string, scored: bool}>  $changes
     * @return array<int, array<string, mixed>>
     */
    public static function applyScores(Teacher $teacher, array $changes): array
    {
        [$competitions, $students] = self::context($teacher, collect($changes)->pluck('student_id'));
        $today = TeacherSyncSnapshot::today();

        return collect($changes)
            ->map(function (array $change) use ($teacher, $competitions, $students, $today) {
                $student = $students->get($change['student_id']);

                if ($refusal = self::refusal($teacher, $student, $competitions, (int) $change['competition_id'], $change['date'], $today)) {
                    return ['id' => $change['id']] + self::rejected(...$refusal);
                }

                $competition = $competitions->get($student->circle_id);
                $criterion = $competition->criteria->firstWhere('id', (int) $change['criterion_id']);

                if (! $criterion) {
                    return ['id' => $change['id']] + self::rejected('criterion_unavailable', 'حُذف هذا البند من المسابقة.');
                }

                return ['id' => $change['id']] + self::locked(
                    "leaderboard-score:{$student->id}:{$criterion->id}:{$change['date']}",
                    fn () => self::settleScore($student, $competition, $criterion, $change['date'], (bool) $change['scored']),
                );
            })
            ->all();
    }

    /**
     * Add or remove extra points, each change on its own.
     *
     * An addition carries the phone's own id for the row, so sending it twice
     * finds the row the first send made. A removal names the row by the
     * server's id or by the phone's, and a row already gone counts as removed.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<int, array<string, mixed>>
     */
    public static function applyExtraPoints(Teacher $teacher, array $changes): array
    {
        $removals = collect($changes)->where('action', 'remove');
        $targets = DB::table('leaderboard_extra_points')
            ->where(function ($query) use ($removals) {
                $query->whereIn('id', $removals->pluck('extra_point_id')->filter())
                    ->orWhereIn('uuid', $removals->pluck('extra_point_uuid')->filter());
            })
            ->get();

        [$competitions, $students] = self::context(
            $teacher,
            collect($changes)->pluck('student_id')->filter()->merge($targets->pluck('student_id')),
        );
        $today = TeacherSyncSnapshot::today();

        return collect($changes)
            ->map(function (array $change) use ($teacher, $competitions, $students, $targets, $today) {
                $outcome = $change['action'] === 'add'
                    ? self::addExtraPoint($teacher, $change, $competitions, $students, $today)
                    : self::removeExtraPoint($teacher, $change, $competitions, $students, $targets);

                return ['id' => $change['id']] + $outcome;
            })
            ->all();
    }

    /**
     * An extra points row as the teacher app stores it.
     *
     * @return array{id: int, uuid: ?string, competition_id: int, student_id: int, date: string, points: int, notes: ?string}
     */
    public static function presentExtraPoint(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'uuid' => $row->uuid,
            'competition_id' => (int) $row->leaderboard_id,
            'student_id' => (int) $row->student_id,
            'date' => CarbonImmutable::parse($row->date)->toDateString(),
            'points' => (int) $row->points,
            'notes' => $row->notes,
        ];
    }

    /**
     * The competition each of the teacher's circles grades in, and the named
     * students the grading page would list: approved, in one of those circles,
     * and active now.
     *
     * @param  Collection<int, mixed>  $studentIds
     * @return array{0: Collection<int, Leaderboard>, 1: Collection<int, Student>}
     */
    private static function context(Teacher $teacher, Collection $studentIds): array
    {
        // Their own circles, and any they stand in for today.
        $circleIds = collect($teacher->workingCircleIds());

        $students = Student::whereIn('id', $studentIds->map(fn ($id) => (int) $id)->unique()->values())
            ->whereIn('circle_id', $circleIds)
            ->where('status', 'active')
            ->whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->get()
            ->keyBy('id');

        return [GradingCompetitions::forCircles($circleIds), $students];
    }

    /**
     * Why this student cannot be graded in this competition on this day, or
     * null when they can.
     *
     * @param  Collection<int, Leaderboard>  $competitions
     * @return array{0: string, 1: string}|null
     */
    private static function refusal(Teacher $teacher, ?Student $student, Collection $competitions, int $competitionId, string $date, string $today): ?array
    {
        if (! $student) {
            return ['student_unavailable', 'لم يعد هذا الطالب مشاركاً في حلقاتك.'];
        }

        $competition = $competitions->get($student->circle_id);

        if (! $competition || $competition->id !== $competitionId) {
            return ['competition_unavailable', 'لم تعد هذه المسابقة معتمدة للتسجيل في حلقة الطالب.'];
        }

        if ($date > $today) {
            return ['future_date', 'هذا اليوم لم يأتِ بعد، فلا يمكن الرصد فيه.'];
        }

        if (! $teacher->mayWorkOn((int) $student->circle_id, $date)) {
            return ['substitute_day_only', 'تنوب في هذه الحلقة اليوم فقط، فلا ترصد فيها إلا بتاريخ اليوم.'];
        }

        $starts = $competition->start_date?->toDateString();
        $ends = $competition->end_date?->toDateString();

        if (($starts !== null && $date < $starts) || ($ends !== null && $date > $ends)) {
            return ['outside_competition', 'هذا اليوم خارج فترة المسابقة، ولا تُحتسب نقاطه.'];
        }

        return null;
    }

    /**
     * Bring one cell to the state the teacher asked for, as the web grading
     * page's toggle does: granting earns the criterion's XP, withdrawing takes
     * it back along with anything it unlocked.
     *
     * @return array{result: string, scored: bool}
     */
    private static function settleScore(Student $student, Leaderboard $competition, LeaderboardCriterion $criterion, string $date, bool $scored): array
    {
        $score = LeaderboardScore::where('leaderboard_id', $competition->id)
            ->where('student_id', $student->id)
            ->where('leaderboard_criterion_id', $criterion->id)
            ->whereDate('date', $date)
            ->first();

        if ($scored && ! $score) {
            $score = LeaderboardScore::create([
                'leaderboard_id' => $competition->id,
                'student_id' => $student->id,
                'leaderboard_criterion_id' => $criterion->id,
                'date' => $date,
            ]);

            GamificationService::syncStudentCustomCriterionXP($score);
        }

        if (! $scored && $score) {
            $scoreId = $score->id;
            $score->delete();

            GamificationTransaction::where('reference_type', LeaderboardScore::class)
                ->where('reference_id', $scoreId)
                ->delete();

            GamificationService::recalculateStudentState($student->id, $competition->id);
            GamificationService::syncStudentBadges($student->id, $competition->id);
        }

        return ['result' => 'applied', 'scored' => $scored];
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  Collection<int, Leaderboard>  $competitions
     * @param  Collection<int, Student>  $students
     * @return array<string, mixed>
     */
    private static function addExtraPoint(Teacher $teacher, array $change, Collection $competitions, Collection $students, string $today): array
    {
        $student = $students->get($change['student_id']);

        if ($refusal = self::refusal($teacher, $student, $competitions, (int) $change['competition_id'], $change['date'], $today)) {
            return self::rejected(...$refusal);
        }

        $competition = $competitions->get($student->circle_id);

        if (empty($competition->settings['extra_points_enabled'])) {
            return self::rejected('extra_points_disabled', 'النقاط الإضافية غير مفعّلة في هذه المسابقة.');
        }

        return self::locked("leaderboard-extra-point:{$change['id']}", function () use ($change, $student, $competition) {
            $row = DB::table('leaderboard_extra_points')->where('uuid', $change['id'])->first();

            if (! $row) {
                $id = DB::table('leaderboard_extra_points')->insertGetId([
                    'uuid' => $change['id'],
                    'leaderboard_id' => $competition->id,
                    'student_id' => $student->id,
                    'date' => $change['date'],
                    'points' => (int) $change['points'],
                    'notes' => trim((string) $change['notes']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                GamificationService::syncStudentExtraPointsXP($id);
                $row = DB::table('leaderboard_extra_points')->find($id);
            }

            return ['result' => 'applied', 'extra_point' => self::presentExtraPoint($row)];
        });
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  Collection<int, Leaderboard>  $competitions
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, object>  $targets
     * @return array<string, mixed>
     */
    private static function removeExtraPoint(Teacher $teacher, array $change, Collection $competitions, Collection $students, Collection $targets): array
    {
        $id = $change['extra_point_id'] ?? null;
        $uuid = $change['extra_point_uuid'] ?? null;

        if ($id === null && $uuid === null) {
            return self::rejected('invalid', 'لم يُحدَّد ما يُحذف.');
        }

        $row = $targets->first(fn (object $target) => ($id !== null && (int) $target->id === (int) $id)
            || ($uuid !== null && $target->uuid === $uuid));

        if (! $row) {
            return ['result' => 'applied'];
        }

        // As on the web page: only points of a student the teacher grades, in
        // the competition that student's circle grades in.
        $student = $students->get($row->student_id);

        if (! $student || $competitions->get($student->circle_id)?->id !== (int) $row->leaderboard_id) {
            return self::rejected('extra_point_unavailable', 'لا يمكنك حذف هذه النقاط.');
        }

        if (! $teacher->mayWorkOn((int) $student->circle_id, CarbonImmutable::parse($row->date)->toDateString())) {
            return self::rejected('substitute_day_only', 'تنوب في هذه الحلقة اليوم فقط، فلا تحذف إلا رصد اليوم.');
        }

        DB::table('leaderboard_extra_points')->where('id', $row->id)->delete();
        GamificationService::syncStudentExtraPointsXP((int) $row->id);

        return ['result' => 'applied'];
    }

    /**
     * Run a write while holding the lock on what it writes, so two phones
     * flushing their queues at once cannot both insert the same row.
     *
     * @param  callable(): array<string, mixed>  $write
     * @return array<string, mixed>
     */
    private static function locked(string $key, callable $write): array
    {
        try {
            return Cache::lock($key, 10)->block(5, fn () => DB::transaction($write));
        } catch (LockTimeoutException) {
            return self::rejected('busy', 'يجري تعديل هذه الخانة الآن من جهاز آخر، ستُعاد المحاولة تلقائياً.');
        }
    }

    /**
     * @return array{result: string, code: string, message: string}
     */
    private static function rejected(string $code, string $message): array
    {
        return ['result' => 'rejected', 'code' => $code, 'message' => $message];
    }
}
