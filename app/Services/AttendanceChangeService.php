<?php

namespace App\Services;

use App\Http\Resources\V1\SyncAttendanceResource;
use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\AttendanceRevision;
use App\Models\Circle;
use App\Models\GamificationTransaction;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\HijriDate;
use App\Support\StudentStatus;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Attendance edits queued on the teacher's phone, applied to the register.
 *
 * Every change is held to the rules of the monthly sheet: a working day of the
 * circle's stage, not in the future, on or after the day the student joined,
 * while they were active, and with a reason when the day being marked is not
 * the day of the edit and the stage asks for one. A change that passes lands
 * in attendance_revisions exactly as a sheet edit would.
 *
 * The day of the edit is the phone's, not the server's: a register taken on
 * the day itself while offline is not an off-day edit just because it reached
 * the server the next morning.
 *
 * Each change carries the status the phone last saw on the server. When the
 * server has since moved to a third value — another teacher of the circle, or
 * the web sheet — the change is held back and returned as a conflict with the
 * server's record, for the teacher to settle. A change whose value the server
 * already holds reports as applied, so resending after a dropped connection
 * never writes twice.
 */
class AttendanceChangeService
{
    public const STATUSES = ['present', 'absent', 'late', 'excused'];

    /**
     * Apply a batch of changes, each on its own: one refused change never holds
     * back the rest.
     *
     * @param  array<int, array{id: string, student_id: int, date: string, status: ?string, base_status: ?string, reason?: ?string, edited_on: string}>  $changes
     * @return array<int, array<string, mixed>>
     */
    public static function apply(Teacher $teacher, array $changes): array
    {
        $today = TeacherSyncSnapshot::today();
        $circles = $teacher->circles()->with('stage')->get()->keyBy('id');

        $students = Student::whereIn('id', collect($changes)->pluck('student_id')->unique())
            ->whereIn('circle_id', $circles->keys())
            ->whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->with('statusHistories')
            ->get()
            ->keyBy('id');

        return collect($changes)
            ->map(fn (array $change) => ['id' => $change['id']] + self::applyOne(
                $teacher,
                $change,
                $students->get($change['student_id']),
                $circles,
                $today,
            ))
            ->all();
    }

    /**
     * @param  array{id: string, student_id: int, date: string, status: ?string, base_status: ?string, reason?: ?string, edited_on: string}  $change
     * @param  Collection<int, Circle>  $circles
     * @return array<string, mixed>
     */
    private static function applyOne(Teacher $teacher, array $change, ?Student $student, Collection $circles, string $today): array
    {
        if (! $student) {
            return self::rejected('student_unavailable', 'لم يعد هذا الطالب ضمن حلقاتك.');
        }

        $circle = $circles->get($student->circle_id);
        $date = $change['date'];

        if ($refusal = self::refusal($student, $circle, $date, $today)) {
            return self::rejected(...$refusal);
        }

        $editedOn = self::editedOn($change['edited_on'], $date, $today);
        $isOffDay = $date !== $editedOn;
        $reason = trim((string) ($change['reason'] ?? ''));

        if ($isOffDay && $reason === '' && ($circle->stage?->require_edit_reason ?? true)) {
            return self::rejected('reason_required', 'يجب إدخال سبب التعديل عند التحضير في غير يوم الجلسة.');
        }

        // Several phones of one circle tend to flush their queues together when
        // the connection returns, and the table has no unique index to stop two
        // of them inserting the same student's day side by side.
        try {
            return Cache::lock("attendance:{$student->id}:{$date}", 10)->block(5, fn () => self::settle(
                $teacher,
                $student,
                $circle,
                $date,
                $change['status'] ?: null,
                $change['base_status'] ?: null,
                $isOffDay ? $reason : null,
                $editedOn,
            ));
        } catch (LockTimeoutException) {
            return self::rejected('busy', 'يجري تعديل هذه الخانة الآن من جهاز آخر، ستُعاد المحاولة تلقائياً.');
        }
    }

    /**
     * Compare the change with the record as it stands now, and write it when
     * the server still holds what the phone last saw.
     *
     * @return array<string, mixed>
     */
    private static function settle(Teacher $teacher, Student $student, Circle $circle, string $date, ?string $status, ?string $baseStatus, ?string $reason, string $editedOn): array
    {
        // whereDate: the date column carries a time component, so a bare Y-m-d
        // match misses the row and a second one would be inserted beside it.
        $existing = Attendance::where('student_id', $student->id)->whereDate('date', $date)->first();
        $serverStatus = $existing?->status;

        if ($serverStatus === $status) {
            return ['result' => 'applied', 'attendance' => self::present($existing)];
        }

        if ($serverStatus !== $baseStatus) {
            return ['result' => 'conflict', 'attendance' => self::present($existing)];
        }

        $attendance = DB::transaction(function () use ($teacher, $student, $circle, $date, $status, $existing, $serverStatus, $reason, $editedOn) {
            $revision = [
                'student_id' => $student->id,
                'circle_id' => $circle->id,
                'date' => $date,
                'old_status' => $serverStatus,
                'new_status' => $status,
                'reason' => $reason,
                'is_off_day_edit' => $date !== $editedOn,
                'edited_on' => $editedOn,
                'edited_by_id' => $teacher->id,
                'edited_by_type' => $teacher->getMorphClass(),
            ];

            if ($status === null) {
                AttendanceRevision::create($revision + ['attendance_id' => null]);
                self::deleteRecord($existing, $student, $date);

                return null;
            }

            if ($existing) {
                $existing->update([
                    'teacher_id' => $teacher->id,
                    'circle_id' => $circle->id,
                    'status' => $status,
                ]);
                $attendance = $existing;
            } else {
                $attendance = Attendance::create([
                    'student_id' => $student->id,
                    'date' => $date,
                    'teacher_id' => $teacher->id,
                    'circle_id' => $circle->id,
                    'status' => $status,
                ]);
            }

            GamificationService::syncStudentAttendanceXP($attendance);
            AttendanceRevision::create($revision + ['attendance_id' => $attendance->id]);

            return $attendance;
        });

        // Guardians are told once the record is safely written, so a failing
        // notification can never roll back the attendance it reports on.
        if (in_array($status, ['absent', 'late'], true)) {
            GuardianNotificationService::notifyAbsence($student, $status, $date);
        }

        return ['result' => 'applied', 'attendance' => self::present($attendance)];
    }

    /**
     * Why a day cannot be marked for this student, worded as the sheet words
     * it, or null when it can.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function refusal(Student $student, Circle $circle, string $date, string $today): ?array
    {
        if ($date > $today) {
            return ['future_date', 'هذا اليوم لم يأتِ بعد، فلا يمكن تحضيره.'];
        }

        if (! AcademicCalendarEvent::isWorkingDay($date, $circle->stage_id)) {
            return ['not_working_day', 'هذا اليوم ليس من أيام الحلقة في التقويم.'];
        }

        $joined = $student->joined_at?->toDateString();

        if ($joined !== null && $date < $joined) {
            return ['not_enrolled', 'لم يكن الطالب قد التحق بالمجمع بعد — التحق في '.HijriDate::full($joined).'.'];
        }

        $status = self::statusOnDate($student, $date);

        if ($status !== 'active') {
            return ['inactive_on_date', 'حالة الطالب في هذا اليوم: '.StudentStatus::label($status).self::becameActiveOn($student, $date)];
        }

        return null;
    }

    /**
     * The day the teacher made the edit, as the phone reports it, kept between
     * the day being marked and today: a clock running ahead cannot date an edit
     * in the future, and one running behind cannot date it before the day it
     * marks.
     */
    private static function editedOn(string $reported, string $date, string $today): string
    {
        if ($reported > $today) {
            return $today;
        }

        return $reported < $date ? $date : $reported;
    }

    /**
     * A student's enrolment status on a date: the newest history row at or
     * before it, falling back to their current status when there is none.
     */
    private static function statusOnDate(Student $student, string $date): string
    {
        $history = $student->statusHistories
            ->filter(fn ($row) => Carbon::parse($row->start_date)->toDateString() <= $date)
            ->sortBy([['start_date', 'desc'], ['id', 'desc']])
            ->first();

        return $history->status ?? $student->status;
    }

    /**
     * " — صار مشاركاً في ..." when the student became active after this date,
     * so a corrected status reads differently from one never corrected.
     */
    private static function becameActiveOn(Student $student, string $date): string
    {
        $return = $student->statusHistories
            ->filter(fn ($row) => $row->status === 'active'
                && Carbon::parse($row->start_date)->toDateString() > $date)
            ->sortBy([['start_date', 'asc'], ['id', 'asc']])
            ->first();

        return $return
            ? ' — صار مشاركاً في '.HijriDate::full($return->start_date).'.'
            : '.';
    }

    /**
     * Clearing a cell takes the XP it earned with it, as clearing it on the
     * sheet does, so a removed record leaves no points behind.
     */
    private static function deleteRecord(Attendance $attendance, Student $student, string $date): void
    {
        $attendanceId = $attendance->id;
        $attendance->delete();

        GamificationTransaction::where('reference_type', Attendance::class)
            ->where('reference_id', $attendanceId)
            ->delete();

        foreach (GamificationService::getActiveLeaderboards($student, $date) as $leaderboard) {
            GamificationService::recalculateStudentState($student->id, $leaderboard->id);
        }
    }

    /**
     * @return array{result: string, code: string, message: string}
     */
    private static function rejected(string $code, string $message): array
    {
        return ['result' => 'rejected', 'code' => $code, 'message' => $message];
    }

    private static function present(?Attendance $attendance): ?SyncAttendanceResource
    {
        return $attendance ? new SyncAttendanceResource($attendance) : null;
    }
}
