<?php

namespace App\Services;

use App\Http\Resources\V1\SyncFreeRecitationResource;
use App\Http\Resources\V1\SyncTasmeehCellResource;
use App\Models\FreeRecitation;
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
            ->whereIn('circle_id', $teacher->circles()->pluck('circles.id'))
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

                if (! in_array($part, TasmeehSnapshot::PARTS[$day->plan->plan_type] ?? [], true)) {
                    return self::rejected('invalid_part', 'هذا الجزء ليس في خطة الطالب.');
                }

                // One lock per day, not per part: both parts share the day's
                // points row, which the web card writes under the same key.
                return Cache::lock(self::dayLockKey($day->id), 10)->block(5, fn () => self::settlePlan(
                    $teacher, $student, $day->id, $part, $date, $today, [$grade, $recited], [$baseGrade, $baseRecited],
                ));
            }

            $student = $students->get($change['student_id']);

            if (! $student) {
                return self::rejected('student_unavailable', 'لم يعد هذا الطالب ضمن حلقاتك.');
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

    /**
     * Compare the change with the plan day as it stands now, and write it when
     * the server still holds what the phone last saw.
     *
     * @param  array{0: ?int, 1: array{0: int, 1: int}|null}  $value
     * @param  array{0: ?int, 1: array{0: int, 1: int}|null}  $base
     * @return array<string, mixed>
     */
    private static function settlePlan(Teacher $teacher, Student $student, int $dayId, string $part, string $date, string $today, array $value, array $base): array
    {
        $day = StudentPlanDay::find($dayId);

        if (! $day) {
            return self::rejected('day_unavailable', 'حُذف هذا اليوم من خطة الطالب.');
        }

        // Reciting exactly the day's portion is recorded as no range. All three
        // values are read against the schedule as it stands now: a plan edited
        // since can make a stored range equal to the day's new portion.
        $value[1] = TasmeehSnapshot::withoutScheduled($day, $part, $value[1]);
        $base[1] = TasmeehSnapshot::withoutScheduled($day, $part, $base[1]);
        $server = [$day->{"{$part}_achievement"}, TasmeehSnapshot::recitedAyahIds($day, $part)];

        if (self::same($server, $value)) {
            return ['result' => 'applied', 'cell' => self::presentCell($day, $part)];
        }

        if (! self::same($server, $base)) {
            return ['result' => 'conflict', 'cell' => self::presentCell($day, $part)];
        }

        [$grade, $recited] = $value;

        // A grade is dated when it changes. Correcting only the range keeps the
        // day the grade was given on — and a date the web moved it to since.
        $redated = $server[0] !== $grade;

        DB::transaction(function () use ($day, $part, $grade, $recited, $redated, $teacher, $date, $today) {
            $attributes = [
                "{$part}_achievement" => $grade,
                "{$part}_recited_from_ayah_id" => $recited[0] ?? null,
                "{$part}_recited_to_ayah_id" => $recited[1] ?? null,
                "{$part}_recorded_by" => $teacher->id,
            ];

            if ($redated) {
                $attributes["{$part}_graded_at"] = $grade === null ? null : self::gradeTime($date, $today);
            }

            $day->update($attributes);

            if ($redated) {
                GamificationService::syncStudentPlanDayXP($day->fresh());
            }
        });

        if ($redated) {
            self::notifyGrade($student, $part, $grade);
        }

        return ['result' => 'applied', 'cell' => self::presentCell($day->fresh(), $part)];
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
    private static function gradeTime(string $date, string $today): CarbonInterface
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
            route('student.hifz'),
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
