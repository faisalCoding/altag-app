<?php

namespace App\Services;

use App\Http\Resources\V1\SyncExamResource;
use App\Models\ExamLevel;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\Teacher;
use App\Support\NextExamBadge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Students put forward for the association's exam from the teacher's phone.
 *
 * The phone no longer sets an exam's day: the manager seats ready students in
 * dated batches. It puts a student forward ('nominate') for a level, ready
 * now or by a day the teacher expects; changes a nomination ('revise') —
 * confirming readiness is a revision to ready now; and takes one back
 * ('withdraw'). A seated exam is the manager's: changing it is refused.
 * Requests from apps that still schedule a day ('create', 'update') are
 * refused with a word to update the app.
 *
 * A student has one open exam. Putting forward one who has an open exam
 * already — two teachers of the circle, offline on the same morning — comes
 * back as a conflict carrying that exam. A revision carries the nomination as
 * the phone last saw it, and is held back as a conflict when the server has
 * since changed it. Resending what the server already holds reports as
 * applied, so a dropped connection never puts a student forward twice.
 */
class ExamChangeService
{
    /**
     * Apply a batch of changes, each on its own: one refused change never holds
     * back the rest.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<int, array<string, mixed>>
     */
    public static function apply(Teacher $teacher, array $changes): array
    {
        $changes = collect($changes);
        $students = Student::whereIn('id', $changes->pluck('student_id')->unique())
            ->whereIn('circle_id', $teacher->circles()->pluck('circles.id'))
            ->whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->get()
            ->keyBy('id');

        $levels = ExamLevel::whereIn('id', $changes->pluck('level_id')->filter()->unique())->pluck('id')->flip();

        return $changes
            ->map(fn (array $change) => ['id' => $change['id']] + self::applyOne($teacher, $change, $students, $levels))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, int>  $levels
     * @return array<string, mixed>
     */
    private static function applyOne(Teacher $teacher, array $change, Collection $students, Collection $levels): array
    {
        if (in_array($change['action'], ['create', 'update'], true)) {
            return self::rejected('update_required', 'حدّث التطبيق: صار موعد الاختبار تحدده الإدارة في دفعات، ويرشّح المعلم طلابه للاختبار.');
        }

        $student = $students->get($change['student_id']);

        if (! $student) {
            return self::rejected('student_unavailable', 'لم يعد هذا الطالب ضمن حلقاتك.');
        }

        if ($change['action'] !== 'withdraw' && ! $levels->has($change['level_id'] ?? 0)) {
            return self::rejected('exam_level_unavailable', 'لم يعد مستوى الاختبار هذا موجوداً.');
        }

        return match ($change['action']) {
            'nominate' => self::nominate($teacher, $student, $change),
            'revise' => self::revise($student, $change),
            default => self::withdraw($student, $change),
        };
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private static function nominate(Teacher $teacher, Student $student, array $change): array
    {
        // The change id names the nomination it made, so a resend, or a
        // nomination edited again before its reply came, finds it.
        $existing = StudentExam::where('uuid', $change['id'])->where('student_id', $student->id)->first();

        if ($existing) {
            return self::settle($existing, $change, null);
        }

        $open = NextExamBadge::nextExams([$student->id])->get($student->id);

        if ($open) {
            return ['result' => 'conflict', 'exam' => new SyncExamResource($open)];
        }

        $exam = ExamReadiness::nominate($student, (int) $change['level_id'], self::expectedOn($change), $teacher, $change['id']);

        if (is_string($exam)) {
            $open = NextExamBadge::nextExams([$student->id])->get($student->id);

            return $open ? ['result' => 'conflict', 'exam' => new SyncExamResource($open)] : self::rejected('busy', $exam);
        }

        return ['result' => 'applied', 'exam' => new SyncExamResource($exam)];
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private static function revise(Student $student, array $change): array
    {
        $exam = self::target($student, $change);

        if (! $exam) {
            return self::rejected('exam_unavailable', 'أُلغي هذا الترشيح، أو لم يعد لطالب في حلقاتك.');
        }

        $base = isset($change['base']) ? [(int) $change['base']['level_id'], self::normalisedExpected($change['base']['expected_on'] ?? null)] : null;

        return self::settle($exam, $change, $base);
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private static function withdraw(Student $student, array $change): array
    {
        $exam = self::target($student, $change);

        // Gone already: what the teacher asked for holds.
        if (! $exam) {
            return ['result' => 'applied', 'exam' => null];
        }

        if ($exam->status !== StudentExam::NOMINATED) {
            return self::closed($exam);
        }

        ExamReadiness::withdraw($exam);

        return ['result' => 'applied', 'exam' => null];
    }

    /**
     * Compare the change with the nomination as it stands now, and revise it
     * when the server still holds what the phone last saw. A null base means
     * the phone made this nomination itself, so whatever it holds now is the
     * phone's own.
     *
     * @param  array<string, mixed>  $change
     * @param  array{0: int, 1: ?string}|null  $base
     * @return array<string, mixed>
     */
    private static function settle(StudentExam $exam, array $change, ?array $base): array
    {
        if ($exam->status !== StudentExam::NOMINATED) {
            return self::closed($exam);
        }

        $wanted = [(int) $change['level_id'], self::expectedOn($change)];
        $server = self::valueOf($exam);

        if ($server === $wanted) {
            return ['result' => 'applied', 'exam' => new SyncExamResource($exam)];
        }

        if ($base !== null && $server !== $base) {
            return ['result' => 'conflict', 'exam' => new SyncExamResource($exam)];
        }

        $revised = ExamReadiness::revise($exam, $wanted[0], $wanted[1]);

        return is_string($revised)
            ? self::closed($exam->fresh() ?? $exam)
            : ['result' => 'applied', 'exam' => new SyncExamResource($revised)];
    }

    /**
     * A nomination as the phone holds it: the level, and the day the student
     * is expected ready — null once they are.
     *
     * @return array{0: int, 1: ?string}
     */
    private static function valueOf(StudentExam $exam): array
    {
        return [(int) $exam->exam_level_id, $exam->ready_at === null ? $exam->expected_ready_on?->toDateString() : null];
    }

    /**
     * The exam a change names, by the server's id or, made on a phone and not
     * synced since, by its uuid.
     *
     * @param  array<string, mixed>  $change
     */
    private static function target(Student $student, array $change): ?StudentExam
    {
        $examId = $change['exam']['id'] ?? null;
        $examUuid = $change['exam']['uuid'] ?? null;

        if ($examId === null && $examUuid === null) {
            return null;
        }

        return StudentExam::query()
            ->where(fn ($q) => $examId !== null ? $q->whereKey($examId) : $q->where('uuid', $examUuid))
            ->where('student_id', $student->id)
            ->first();
    }

    /**
     * The day a change expects the student ready, or null for ready now. A
     * day already come stays expected: the teacher still confirms it.
     *
     * @param  array<string, mixed>  $change
     */
    private static function expectedOn(array $change): ?string
    {
        return self::normalisedExpected($change['expected_on'] ?? null);
    }

    private static function normalisedExpected(mixed $day): ?string
    {
        if (! is_string($day) || $day === '') {
            return null;
        }

        return CarbonImmutable::parse($day)->toDateString();
    }

    /**
     * A seated exam is the manager's, a finished one is closed.
     *
     * @return array<string, mixed>
     */
    private static function closed(StudentExam $exam): array
    {
        return $exam->status === StudentExam::SCHEDULED
            ? self::rejected('exam_scheduled', 'حدّدت الإدارة موعد هذا الاختبار في دفعة، فلم يعد الترشيح قابلاً للتعديل.') + ['exam' => new SyncExamResource($exam)]
            : self::rejected('exam_closed', 'رُصدت نتيجة هذا الاختبار أو أُلغي، فلم يعد قابلاً للتعديل.') + ['exam' => new SyncExamResource($exam)];
    }

    /**
     * The lock both the API and the site's exam pages take to write a
     * student's exams, so a phone and a browser putting the same student
     * forward at once cannot both find nothing open.
     */
    public static function studentLockKey(int $studentId): string
    {
        return "student-exam:{$studentId}";
    }

    /**
     * @return array{result: string, code: string, message: string}
     */
    private static function rejected(string $code, string $message): array
    {
        return ['result' => 'rejected', 'code' => $code, 'message' => $message];
    }
}
