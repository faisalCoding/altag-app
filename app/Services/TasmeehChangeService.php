<?php

namespace App\Services;

use App\Http\Resources\V1\SyncFreeRecitationResource;
use App\Http\Resources\V1\SyncTasmeehAttemptResource;
use App\Http\Resources\V1\SyncTasmeehCellResource;
use App\Models\FreeRecitation;
use App\Models\PlanDayAttempt;
use App\Models\Student;
use App\Models\StudentPlanDay;
use App\Models\Teacher;
use App\Support\AyahIndex;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tasmeeh grades queued on the teacher's phone, applied to the plan days and
 * free recitations they were given on.
 *
 * A cell is one part (hifz or review) of a plan day, or of a student's free
 * recitation on a day. Its value is the grade — 0 for «لم يسمع», null when not
 * graded — and the range actually recited when it was not the portion the day
 * set. The plan itself never changes.
 *
 * Each change carries the value the phone last saw on the server. When the
 * server has since moved to a third value — another teacher, or the web card —
 * the change is held back and returned as a conflict with the server's cell,
 * for the teacher to settle. A change whose value the server already holds
 * reports as applied, so resending after a dropped connection never writes
 * twice. Only the grade and the range are compared: when or by whom a cell was
 * graded is not something two teachers disagree about.
 */
class TasmeehChangeService
{
    public const GRADES = [0, 1, 2, 3];

    /**
     * Apply a batch of changes, each on its own: one refused change never holds
     * back the rest.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<int, array<string, mixed>>
     */
    public static function apply(Teacher $teacher, array $changes): array
    {
        $today = TeacherSyncSnapshot::today();
        $changes = collect($changes);

        $days = StudentPlanDay::whereIn('id', $changes->where('kind', 'plan')->pluck('day_id')->filter()->unique())
            ->with('plan:id,student_id,plan_type')
            ->get()
            ->keyBy('id');

        $studentIds = $changes->where('kind', 'free')->pluck('student_id')
            ->merge($days->map(fn (StudentPlanDay $day) => $day->plan?->student_id))
            ->filter()
            ->unique();

        $students = Student::whereIn('id', $studentIds)
            // Their own circles, and any they stand in for today.
            ->whereIn('circle_id', $teacher->workingCircleIds())
            ->whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->get()
            ->keyBy('id');

        return $changes
            ->map(fn (array $change) => ['id' => $change['id']] + self::applyOne($teacher, $change, $days, $students, $today))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  Collection<int, StudentPlanDay>  $days
     * @param  Collection<int, Student>  $students
     * @return array<string, mixed>
     */
    private static function applyOne(Teacher $teacher, array $change, Collection $days, Collection $students, string $today): array
    {
        // Never `?:` here: it would read «لم يسمع» (0) as not graded.
        $grade = self::grade($change['grade'] ?? null);
        $baseGrade = self::grade($change['base']['grade'] ?? null);

        // A phone clock running ahead cannot date a grade in the future.
        $date = min($change['date'], $today);
        $part = $change['part'];

        $recited = self::ayahIds($change['recited'] ?? null);
        $baseRecited = self::ayahIds($change['base']['recited'] ?? null);

        if ($recited === false || $baseRecited === false) {
            return self::rejected('invalid_range', 'نطاق الآيات غير صحيح.');
        }

        try {
            if ($change['kind'] === 'plan') {
                $day = $days->get($change['day_id']);

                if (! $day || ! $day->plan) {
                    return self::rejected('day_unavailable', 'حُذف هذا اليوم من خطة الطالب.');
                }

                $student = $students->get($day->plan->student_id);

                if (! $student) {
                    return self::rejected('student_unavailable', 'لم يعد هذا الطالب ضمن حلقاتك.');
                }

                if (! $teacher->mayWorkOn((int) $student->circle_id, $date)) {
                    return self::substituteDayOnly();
                }

                if (! in_array($part, TasmeehSnapshot::PARTS[$day->plan->plan_type] ?? [], true)) {
                    return self::rejected('invalid_part', 'هذا الجزء ليس في خطة الطالب.');
                }

                // One lock per day, not per part: both parts share the day's
                // points row, which the web card writes under the same key.
                return Cache::lock(self::dayLockKey($day->id), 10)->block(5, fn () => self::settlePlan(
                    $teacher, $student, $day->id, $part, $date, $today, [$grade, $recited], [$baseGrade, $baseRecited],
                    (bool) ($change['session'] ?? false), (bool) ($change['redate'] ?? false),
                ));
            }

            $student = $students->get($change['student_id']);

            if (! $student) {
                return self::rejected('student_unavailable', 'لم يعد هذا الطالب ضمن حلقاتك.');
            }

            if (! $teacher->mayWorkOn((int) $student->circle_id, $date)) {
                return self::substituteDayOnly();
            }

            if ($grade !== null && $grade >= 1 && $recited === null) {
                return self::rejected('range_required', 'حدّد ما سمّعه الطالب قبل التقييم.');
            }

            return Cache::lock("tasmeeh:free:{$student->id}:{$part}:{$date}", 10)->block(5, fn () => self::settleFree(
                $teacher, $student, $change['id'], $part, $date, $today, [$grade, $recited], [$baseGrade, $baseRecited],
            ));
        } catch (LockTimeoutException) {
            return self::rejected('busy', 'يجري تعديل هذه الخانة الآن من جهاز آخر، ستُعاد المحاولة تلقائياً.');
        }
    }

    /** A circle stood in for is graded for today alone. */
    private static function substituteDayOnly(): array
    {
        return self::rejected('substitute_day_only', 'تنوب في هذه الحلقة اليوم فقط، فلا تقيّم فيها إلا بتاريخ اليوم.');
    }

    /**
     * Compare the change with what the server holds, and write it when the
     * server still holds what the phone last saw.
     *
     * A plan day keeps every session its parts were recited in. An app that
     * holds them all ($session) grades the session of the change's date, so a
     * «لم يسمع» given on Sunday stays when the same portion is graded on Monday.
     *
     * An app from before sessions holds only the day's latest grade, and its
     * change is read the way it meant it: compared with that grade, a new or
     * changed grade — or one it asks to record for the day ($redate) — goes to
     * the session of the change's date, and correcting only the range edits
     * the session the grade was given in.
     *
     * @param  array{0: ?int, 1: array{0: int, 1: int}|null}  $value
     * @param  array{0: ?int, 1: array{0: int, 1: int}|null}  $base
     * @return array<string, mixed>
     */
    private static function settlePlan(Teacher $teacher, Student $student, int $dayId, string $part, string $date, string $today, array $value, array $base, bool $session, bool $redate = false): array
    {
        $day = StudentPlanDay::with('plan.student')->find($dayId);

        if (! $day) {
            return self::rejected('day_unavailable', 'حُذف هذا اليوم من خطة الطالب.');
        }

        // Reciting exactly the day's portion is recorded as no range. All
        // values are read against the schedule as it stands now: a plan edited
        // since can make a stored range equal to the day's new portion.
        $value[1] = TasmeehSnapshot::withoutScheduled($day, $part, $value[1]);
        $base[1] = TasmeehSnapshot::withoutScheduled($day, $part, $base[1]);

        PlanDayAttempts::adopt($day, $part);
        $attempt = PlanDayAttempts::on($day, $part, $date);

        if ($session) {
            $server = self::attemptValue($day, $part, $attempt);
        } else {
            $server = [$day->{"{$part}_achievement"}, TasmeehSnapshot::recitedAyahIds($day, $part)];
            $latest = PlanDayAttempts::latest($day, $part);

            // The app sees one grade per day and dates nothing itself: a grade
            // it carries from another session is recorded for this one only
            // when it asks, or when the grade itself changes.
            $recordForDay = $redate && $value[0] !== null && $latest?->recited_on !== $date;

            if (self::same($server, $value) && ! $recordForDay) {
                return self::applied($day, $part, $attempt);
            }

            // Correcting only the range, or clearing the grade, is about the
            // session the app was shown.
            if (! $recordForDay && $latest && ($server[0] === $value[0] || $value === [null, null])) {
                $attempt = $latest;
            }
        }

        if (self::same($server, $value) && $session) {
            return self::applied($day, $part, $attempt);
        }

        if (! self::same($server, $value) && ! self::same($server, $base)) {
            return ['result' => 'conflict'] + self::present($day, $part, $attempt);
        }

        [$grade, $recited] = $value;

        $attempt = PlanDayAttempts::record($day, $part, $attempt?->recited_on ?? $date, $today, $grade, $recited, $teacher->id, $attempt);

        return self::applied($day->fresh(), $part, $attempt);
    }

    /**
     * What a session holds, read like a cell: the grade and the range recited
     * when it was not the day's portion.
     *
     * @return array{0: ?int, 1: array{0: int, 1: int}|null}
     */
    private static function attemptValue(StudentPlanDay $day, string $part, ?PlanDayAttempt $attempt): array
    {
        return $attempt
            ? [$attempt->grade, TasmeehSnapshot::withoutScheduled($day, $part, $attempt->recitedAyahIds())]
            : [null, null];
    }

    /**
     * @return array<string, mixed>
     */
    private static function applied(StudentPlanDay $day, string $part, ?PlanDayAttempt $attempt): array
    {
        return ['result' => 'applied'] + self::present($day, $part, $attempt);
    }

    /**
     * The plan day's summary, which apps from before sessions read, and the
     * session the change was about.
     *
     * @return array{cell: ?SyncTasmeehCellResource, attempt: ?SyncTasmeehAttemptResource}
     */
    private static function present(StudentPlanDay $day, string $part, ?PlanDayAttempt $attempt): array
    {
        $attempt = $attempt?->exists ? $attempt->fresh() : null;

        return [
            'cell' => self::presentCell($day, $part),
            'attempt' => $attempt ? new SyncTasmeehAttemptResource($attempt->setRelation('day', $day)->load('recorder:id,name')) : null,
        ];
    }

    /**
     * Compare the change with the student's free recitation of that part and
     * day, and write it when the server still holds what the phone last saw.
     *
     * @param  array{0: ?int, 1: array{0: int, 1: int}|null}  $value
     * @param  array{0: ?int, 1: array{0: int, 1: int}|null}  $base
     * @return array<string, mixed>
     */
    private static function settleFree(Teacher $teacher, Student $student, string $changeId, string $part, string $date, string $today, array $value, array $base): array
    {
        // whereDate: the column is cast to a date stored with a time part.
        $row = FreeRecitation::where('student_id', $student->id)
            ->where('type', $part)
            ->whereDate('recited_on', $date)
            ->first();

        $server = $row ? [$row->achievement, self::storedRange($row->from_ayah_id, $row->to_ayah_id)] : [null, null];

        if (self::same($server, $value)) {
            return ['result' => 'applied', 'cell' => self::presentFree($row)];
        }

        if (! self::same($server, $base)) {
            return ['result' => 'conflict', 'cell' => self::presentFree($row)];
        }

        [$grade, $recited] = $value;
        $gradeChanged = $server[0] !== $grade;
        $previouslyGradedAt = $row?->graded_at;

        $row = DB::transaction(function () use ($row, $student, $part, $date, $today, $grade, $recited, $gradeChanged, $teacher, $changeId, $previouslyGradedAt) {
            // An emptied cell leaves no row behind, and takes its points along.
            if ($grade === null && $recited === null) {
                if ($row) {
                    // Saved ungraded first: the streak and badges recomputed by
                    // the sync read the table, not this model.
                    $row->update(['achievement' => null, 'graded_at' => null]);
                    GamificationService::syncFreeRecitationXP($row, $previouslyGradedAt);
                    $row->delete();
                }

                return null;
            }

            $row ??= new FreeRecitation([
                'uuid' => $changeId,
                'student_id' => $student->id,
                'type' => $part,
                'recited_on' => $date,
            ]);

            $row->fill([
                'achievement' => $grade,
                'from_ayah_id' => $recited[0] ?? null,
                'to_ayah_id' => $recited[1] ?? null,
                'recorded_by' => $teacher->id,
            ]);

            if ($gradeChanged) {
                $row->graded_at = $grade === null ? null : self::gradeTime($date, $today);
            }

            $row->save();

            if ($gradeChanged) {
                GamificationService::syncFreeRecitationXP($row, $previouslyGradedAt);
            }

            return $row;
        });

        if ($gradeChanged) {
            self::notifyGrade($student, $part, $grade);
        }

        return ['result' => 'applied', 'cell' => self::presentFree($row?->fresh())];
    }

    /**
     * When a grade counts as given: now when the teacher grades today, else
     * midday of the day they chose, as the web card dates it.
     */
    public static function gradeTime(string $date, string $today): CarbonInterface
    {
        return $date === $today
            ? now()
            : CarbonImmutable::parse("{$date} 12:00:00", TeacherSyncSnapshot::TIMEZONE)->utc();
    }

    /**
     * The student hears about a grade, once it is safely written. «لم يسمع» and
     * a cleared grade are not news.
     */
    private static function notifyGrade(Student $student, string $part, ?int $grade): void
    {
        if ($grade === null || $grade < 1) {
            return;
        }

        $label = $part === 'hifz' ? 'الحفظ' : 'المراجعة';

        NotificationService::notify(
            'student',
            $student->id,
            'grading',
            'تقييم جديد',
            "قام معلمك بتقييم {$label} الخاص بك",
            route('student.dashboard'),
        );
    }

    private static function grade(mixed $raw): ?int
    {
        return $raw === null ? null : (int) $raw;
    }

    /**
     * A range the phone sent as surah and verse numbers, as ayah ids; null for
     * no range, and false when either end names no ayah.
     *
     * @param  array{from: array{surah: int, verse: int}, to: array{surah: int, verse: int}}|null  $range
     * @return array{0: int, 1: int}|false|null
     */
    private static function ayahIds(?array $range): array|false|null
    {
        if ($range === null) {
            return null;
        }

        $from = AyahIndex::id((int) $range['from']['surah'], (int) $range['from']['verse']);
        $to = AyahIndex::id((int) $range['to']['surah'], (int) $range['to']['verse']);

        return $from === null || $to === null ? false : [$from, $to];
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function storedRange(int|string|null $fromId, int|string|null $toId): ?array
    {
        return $fromId === null || $toId === null ? null : [(int) $fromId, (int) $toId];
    }

    /**
     * The lock both the API and the web card take to write a plan day.
     */
    public static function dayLockKey(int $dayId): string
    {
        return "tasmeeh:day:{$dayId}";
    }

    /**
     * @param  array{0: ?int, 1: array{0: int, 1: int}|null}  $a
     * @param  array{0: ?int, 1: array{0: int, 1: int}|null}  $b
     */
    private static function same(array $a, array $b): bool
    {
        return $a[0] === $b[0] && $a[1] === $b[1];
    }

    private static function presentCell(StudentPlanDay $day, string $part): ?SyncTasmeehCellResource
    {
        $day->loadMissing("{$part}Recorder:id,name");

        return TasmeehSnapshot::holdsRecord($day, $part) ? new SyncTasmeehCellResource($day, $part) : null;
    }

    private static function presentFree(?FreeRecitation $row): ?SyncFreeRecitationResource
    {
        return $row ? new SyncFreeRecitationResource($row->loadMissing('recorder:id,name')) : null;
    }

    /**
     * @return array{result: string, code: string, message: string}
     */
    private static function rejected(string $code, string $message): array
    {
        return ['result' => 'rejected', 'code' => $code, 'message' => $message];
    }
}
