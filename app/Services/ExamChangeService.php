<?php

namespace App\Services;

use App\Http\Resources\V1\SyncExamResource;
use App\Models\ExamLevel;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\Teacher;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Exams scheduled or moved on the teacher's phone, applied to the student's
 * exams.
 *
 * The phone only schedules: it sets the level and the day of a pending exam.
 * Results, the place and notes stay with the web page.
 *
 * A student waits on one exam at a time. Scheduling a second while one is
 * pending — two teachers of the circle, offline on the same morning — comes
 * back as a conflict carrying the exam already there. A moved exam carries the
 * level and day the phone last saw, and is held back as a conflict when the
 * server has since moved it elsewhere. Resending what the server already holds
 * reports as applied, so a dropped connection never schedules twice.
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
        $today = TeacherSyncSnapshot::today();
        $changes = collect($changes);
        $students = Student::whereIn('id', $changes->pluck('student_id')->unique())
            ->whereIn('circle_id', $teacher->circles()->pluck('circles.id'))
            ->whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->get()
            ->keyBy('id');

        $levels = ExamLevel::whereIn('id', $changes->pluck('level_id')->unique())->pluck('id')->flip();

        return $changes
            ->map(fn (array $change) => ['id' => $change['id']] + self::applyOne($change, $students, $levels, $today))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, int>  $levels
     * @return array<string, mixed>
     */
    private static function applyOne(array $change, Collection $students, Collection $levels, string $today): array
    {
        $student = $students->get($change['student_id']);

        if (! $student) {
            return self::rejected('student_unavailable', 'لم يعد هذا الطالب ضمن حلقاتك.');
        }

        if (! $levels->has($change['level_id'])) {
            return self::rejected('exam_level_unavailable', 'لم يعد مستوى الاختبار هذا موجوداً.');
        }

        try {
            return Cache::lock(self::studentLockKey($student->id), 10)->block(5, fn () => DB::transaction(
                fn () => $change['action'] === 'create'
                    ? self::create($student, $change, $today)
                    : self::update($student, $change, $today),
            ));
        } catch (LockTimeoutException) {
            return self::rejected('busy', 'يجري تعديل اختبار هذا الطالب الآن من جهاز آخر، ستُعاد المحاولة تلقائياً.');
        }
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private static function create(Student $student, array $change, string $today): array
    {
        $date = $change['date'];
        $level = (int) $change['level_id'];

        // The change id names the exam it created, so a create resent after a
        // dropped connection, or after the teacher edited it again, finds it.
        $existing = StudentExam::where('uuid', $change['id'])->where('student_id', $student->id)->first();

        if ($existing) {
            return self::settle($existing, $level, $date, null, $change, $today);
        }

        $pending = StudentExam::where('student_id', $student->id)->pending()->orderBy('date_time')->orderBy('id')->first();

        if ($pending) {
            return ['result' => 'conflict', 'exam' => new SyncExamResource($pending)];
        }

        if (self::isPast($date, $change['edited_on'], $today)) {
            return self::rejected('date_in_past', 'مضى هذا اليوم، فاختر يوماً قادماً للاختبار.');
        }

        $exam = StudentExam::create([
            'uuid' => $change['id'],
            'student_id' => $student->id,
            'exam_level_id' => $level,
            // The column defaults to passed, which an exam yet to be sat is not.
            'status' => 'pending',
            'date_time' => "{$date} 00:00:00",
        ]);

        // Told once the exam is safely written, as the web page tells them.
        DB::afterCommit(fn () => NotificationService::notify(
            'student',
            $student->id,
            'exam_scheduled',
            'اختبار جديد مجدول',
            'تم جدولة اختبار جديد لك، تفقّد التفاصيل',
            route('student.exams'),
        ));

        return ['result' => 'applied', 'exam' => new SyncExamResource($exam)];
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private static function update(Student $student, array $change, string $today): array
    {
        $examId = $change['exam']['id'] ?? null;
        $examUuid = $change['exam']['uuid'] ?? null;

        // An exam created on a phone is known there by its uuid until the
        // next snapshot brings its id.
        $exam = $examId === null && $examUuid === null ? null : StudentExam::query()
            ->where(fn ($q) => $examId !== null ? $q->whereKey($examId) : $q->where('uuid', $examUuid))
            ->where('student_id', $student->id)
            ->first();

        if (! $exam) {
            return self::rejected('exam_unavailable', 'حُذف هذا الاختبار، أو لم يعد لطالب في حلقاتك.');
        }

        $base = [(int) $change['base']['level_id'], $change['base']['date']];

        return self::settle($exam, (int) $change['level_id'], $change['date'], $base, $change, $today);
    }

    /**
     * Compare the change with the exam as it stands now, and move it when the
     * server still holds what the phone last saw. A null base means the phone
     * created this exam itself, so whatever it holds now is the phone's own.
     *
     * @param  array{0: int, 1: string}|null  $base
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private static function settle(StudentExam $exam, int $level, string $date, ?array $base, array $change, string $today): array
    {
        if ($exam->status !== 'pending') {
            return self::rejected('exam_closed', 'رُصدت نتيجة هذا الاختبار أو أُلغي، فلم يعد قابلاً للتعديل.') + ['exam' => new SyncExamResource($exam)];
        }

        $server = [$exam->exam_level_id, $exam->date_time->toDateString()];

        if ($server === [$level, $date]) {
            return ['result' => 'applied', 'exam' => new SyncExamResource($exam)];
        }

        if ($base !== null && $server !== $base) {
            return ['result' => 'conflict', 'exam' => new SyncExamResource($exam)];
        }

        // An overdue exam may still change level without being moved.
        if ($date !== $server[1] && self::isPast($date, $change['edited_on'], $today)) {
            return self::rejected('date_in_past', 'مضى هذا اليوم، فاختر يوماً قادماً للاختبار.');
        }

        // The day moves; the hour the web page may have set stays.
        $exam->update([
            'exam_level_id' => $level,
            'date_time' => $exam->date_time->copy()->setDateFrom($date),
        ]);

        return ['result' => 'applied', 'exam' => new SyncExamResource($exam)];
    }

    /**
     * Whether the day has gone by. Judged against the day the teacher made
     * the edit, so an exam set for today offline is not refused for reaching
     * the server tomorrow — and never against the bare `today` rule, which
     * reads UTC.
     */
    private static function isPast(string $date, string $editedOn, string $today): bool
    {
        return $date < min($editedOn, $today);
    }

    /**
     * The lock both the API and the tasmeeh page's exam editor take to write
     * a student's exams, so a phone and a browser setting the same student's
     * exam at once cannot both find nothing pending and schedule two.
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
