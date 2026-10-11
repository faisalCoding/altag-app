<?php

namespace App\Services;

use App\Models\ExamBatch;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\User;
use App\Support\HijriDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The way to the association's exam: a teacher puts a student forward, the
 * manager seats the ready ones in a dated batch, and the result closes it.
 *
 * A teacher never sets the day. They mark the student ready now, or name the
 * day they expect them to be — and that day is not readiness: the teacher
 * confirms it, reminded once when it comes. Only confirmed students can be
 * seated. The student and their guardian hear of the exam when it is seated,
 * not before.
 *
 * A student has at most one open exam, put forward or seated. Writes for a
 * student take the lock the teacher app's exam changes take, so a phone and
 * a page cannot both put the same student forward.
 */
final class ExamReadiness
{
    /**
     * Put a student forward for a level: ready now when no day is expected,
     * or a day to come they are expected to be.
     */
    public static function nominate(Student $student, int $levelId, ?string $expectedOn, ?User $teacher, ?string $uuid = null): StudentExam|string
    {
        return self::forStudent($student->id, function () use ($student, $levelId, $expectedOn, $teacher, $uuid) {
            if (StudentExam::where('student_id', $student->id)->open()->exists()) {
                return 'للطالب اختبار لم يُغلق بعد: رشّحه لاختبار جديد بعد رصد نتيجة اختباره الحالي.';
            }

            $expected = self::expectedDay($expectedOn);

            return StudentExam::create([
                'uuid' => $uuid,
                'student_id' => $student->id,
                'exam_level_id' => $levelId,
                'status' => StudentExam::NOMINATED,
                'expected_ready_on' => $expected,
                'ready_at' => $expected === null ? now() : null,
                'nominated_by' => $teacher?->id,
            ]);
        });
    }

    /**
     * Change a nomination's level or readiness, until the student is seated.
     * Moving the expected day asks for a fresh reminder when it comes.
     */
    public static function revise(StudentExam $exam, int $levelId, ?string $expectedOn): StudentExam|string
    {
        return self::forStudent($exam->student_id, function () use ($exam, $levelId, $expectedOn) {
            $exam->refresh();

            if ($exam->status !== StudentExam::NOMINATED) {
                return 'جُدول اختبار الطالب في دفعة، فلم يعد الترشيح قابلاً للتعديل.';
            }

            $expected = self::expectedDay($expectedOn);

            $exam->update([
                'exam_level_id' => $levelId,
                'expected_ready_on' => $expected,
                'ready_at' => $expected === null ? ($exam->ready_at ?? now()) : null,
                'ready_reminded_at' => $expected !== null && $exam->expected_ready_on?->isSameDay($expected) ? $exam->ready_reminded_at : null,
            ]);

            return $exam;
        });
    }

    /** The teacher confirms a student expected to be ready is ready. */
    public static function confirmReady(StudentExam $exam): void
    {
        if ($exam->status === StudentExam::NOMINATED && $exam->ready_at === null) {
            $exam->update(['ready_at' => now()]);
        }
    }

    /** Take a nomination back; a seated exam is the manager's to change. */
    public static function withdraw(StudentExam $exam): bool
    {
        if ($exam->status !== StudentExam::NOMINATED) {
            return false;
        }

        return (bool) $exam->delete();
    }

    /**
     * Open a batch at a day and place, and seat the ready students in it.
     *
     * @param  array<int, int>  $examIds
     */
    public static function createBatch(string $dateTime, ?string $location, ?string $notes, array $examIds, ?User $creator): ExamBatch
    {
        $batch = ExamBatch::create([
            'date_time' => CarbonImmutable::parse($dateTime),
            'location' => filled($location) ? trim($location) : null,
            'notes' => filled($notes) ? trim($notes) : null,
            'created_by' => $creator?->id,
        ]);

        self::seat($batch, $examIds);

        return $batch;
    }

    /**
     * Seat students put forward in a batch: each exam takes the batch's day
     * and place, and the student and guardian are told. The manager may seat
     * a student only expected to be ready too — the batch is their call —
     * and the teacher's confirmation is then no longer waited on. Students
     * seated already, or whose exam has closed, are left out.
     *
     * @param  array<int, int>  $examIds
     * @return int how many were seated
     */
    public static function seat(ExamBatch $batch, array $examIds): int
    {
        $exams = StudentExam::nominated()->whereKey($examIds)->with('examLevel', 'student')->get();

        DB::transaction(fn () => $exams->each(fn (StudentExam $exam) => $exam->update([
            'status' => StudentExam::SCHEDULED,
            'exam_batch_id' => $batch->id,
            'date_time' => $batch->date_time,
            'location' => $batch->location,
        ])));

        $exams->each(fn (StudentExam $exam) => self::tell(
            $exam,
            'exam_scheduled',
            'اختبار جديد مجدول',
            'موعد اختبار '.($exam->examLevel?->name ?? 'الجمعية').': '.self::when($batch).'.',
        ));

        return $exams->count();
    }

    /**
     * Move a batch: its seated students take the new day and place, and are
     * told when the day changes.
     */
    public static function updateBatch(ExamBatch $batch, string $dateTime, ?string $location, ?string $notes): void
    {
        $moved = ! CarbonImmutable::parse($dateTime)->equalTo($batch->date_time);

        $batch->update([
            'date_time' => CarbonImmutable::parse($dateTime),
            'location' => filled($location) ? trim($location) : null,
            'notes' => filled($notes) ? trim($notes) : null,
        ]);

        $seated = $batch->exams()->pending()->with('examLevel', 'student')->get();
        $seated->each(fn (StudentExam $exam) => $exam->update(['date_time' => $batch->date_time, 'location' => $batch->location]));

        if ($moved) {
            $seated->each(fn (StudentExam $exam) => self::tell(
                $exam,
                'exam_rescheduled',
                'تغيّر موعد اختبارك',
                'الموعد الجديد لاختبار '.($exam->examLevel?->name ?? 'الجمعية').': '.self::when($batch).'.',
            ));
        }
    }

    /**
     * Take a student out of a batch: back among those put forward, with no
     * day — ready, or expected by their day if seated before the teacher
     * confirmed them.
     */
    public static function unseat(StudentExam $exam): void
    {
        if ($exam->status !== StudentExam::SCHEDULED || $exam->exam_batch_id === null) {
            return;
        }

        $exam->update([
            'status' => StudentExam::NOMINATED,
            'exam_batch_id' => null,
            'date_time' => null,
            'location' => null,
            // Back where they stood: ready, or still expected by their day.
            'ready_at' => $exam->ready_at ?? ($exam->expected_ready_on === null ? now() : null),
        ]);

        self::tell($exam, 'exam_unscheduled', 'تأجيل اختبار', 'أُلغي موعد اختبارك، وسيُحدَّد لك موعد جديد.');
    }

    /**
     * Record how a seated exam ended. A student absent from a batch is put
     * forward again, ready, for the same level.
     */
    public static function recordResult(StudentExam $exam, string $status, mixed $score): void
    {
        $wasSeated = $exam->status === StudentExam::SCHEDULED;

        $exam->update([
            'status' => $status,
            'score_percentage' => $score === '' ? null : $score,
        ]);

        if ($wasSeated && in_array($status, ['passed', 'failed'], true)) {
            NotificationService::notify('student', $exam->student_id, 'exam_result', 'نتيجة اختبار', 'تم رصد نتيجة اختبارك، تفقّد التفاصيل', route('student.exams'));
        }

        if ($wasSeated && $status === 'absent' && $exam->exam_batch_id !== null
            && ! StudentExam::where('student_id', $exam->student_id)->open()->exists()) {
            StudentExam::create([
                'student_id' => $exam->student_id,
                'exam_level_id' => $exam->exam_level_id,
                'status' => StudentExam::NOMINATED,
                'ready_at' => now(),
                'nominated_by' => $exam->nominated_by,
            ]);
        }
    }

    /**
     * Remind each teacher once when the day they expected a student to be
     * ready comes, to confirm them or name another day.
     *
     * @return int how many reminders went out
     */
    public static function remindDue(): int
    {
        $due = StudentExam::nominated()
            ->whereNull('ready_at')
            ->whereNull('ready_reminded_at')
            ->whereDate('expected_ready_on', '<=', TeacherSyncSnapshot::today())
            ->with('student:id,name,circle_id', 'student.circle.teachers:id', 'examLevel:id,name')
            ->get();

        foreach ($due as $exam) {
            $teacherIds = $exam->nominated_by !== null
                ? [$exam->nominated_by]
                : ($exam->student?->circle?->teachers->modelKeys() ?? []);

            foreach ($teacherIds as $teacherId) {
                NotificationService::notify(
                    'teacher',
                    $teacherId,
                    'exam_readiness_due',
                    'تأكيد جاهزية للاختبار',
                    'حان اليوم المتوقع لجاهزية '.($exam->student?->name ?? 'الطالب').' لاختبار '.($exam->examLevel?->name ?? 'الجمعية').': أكّد جاهزيته أو حدّد يوماً آخر.',
                    route('teacher.student-exams'),
                );
            }

            $exam->update(['ready_reminded_at' => now()]);
        }

        return $due->count();
    }

    /**
     * "الأحد ٢٠ جمادى الأولى ١٤٤٨ الساعة 4:30 م في قاعة الجمعية". The hour is
     * read as stored: exams keep the academy's clock time as entered.
     */
    public static function when(ExamBatch $batch): string
    {
        $time = $batch->date_time;

        return HijriDate::withWeekday($time->toDateString())
            .' الساعة '.$time->format('g:i').' '.($time->format('A') === 'AM' ? 'ص' : 'م')
            .($batch->location ? ' في '.$batch->location : '');
    }

    /**
     * The day a teacher expects the student ready, or null for ready now. A
     * day already come stays a day expected — due, waiting on the teacher to
     * confirm it — as when a phone that was offline sends it late: readiness
     * is only ever the teacher's word.
     */
    private static function expectedDay(?string $expectedOn): ?CarbonImmutable
    {
        return filled($expectedOn) ? CarbonImmutable::parse($expectedOn)->startOfDay() : null;
    }

    /** Tell the student, and their guardian, of a change to their exam. */
    private static function tell(StudentExam $exam, string $type, string $title, string $body): void
    {
        NotificationService::notify('student', $exam->student_id, $type, $title, $body, route('student.exams'));

        $guardianId = $exam->student?->guardian_id;

        if ($guardianId) {
            GuardianNotificationService::record($guardianId, $type, $title, ($exam->student->name ? $exam->student->name.': ' : '').$body, [], $exam->student_id);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $write
     * @return T|string
     */
    private static function forStudent(int $studentId, callable $write): mixed
    {
        try {
            return Cache::lock(ExamChangeService::studentLockKey($studentId), 10)->block(5, fn () => DB::transaction($write));
        } catch (LockTimeoutException) {
            return 'يُعدَّل اختبار هذا الطالب الآن من جهاز آخر، أعد المحاولة بعد لحظات.';
        }
    }
}
