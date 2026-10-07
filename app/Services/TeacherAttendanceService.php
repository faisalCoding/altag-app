<?php

namespace App\Services;

use App\Jobs\SendGuardianWhatsappJob;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use App\Models\TeacherAttendanceRevision;
use App\Support\HijriDate;
use App\Support\TeacherAttendanceSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every write to a teacher's day goes through here, so none of them escapes
 * the trail: the first mark, a change of status, a reason or an arrival time
 * added afterwards, and a day cleared. Each lands in
 * teacher_attendance_revisions with who made it and in which capacity.
 *
 * The rules of who may mark whom, and on which days, belong to the roll call;
 * this only writes — and, when the manager has turned it on, tells a teacher
 * marked absent or late.
 */
class TeacherAttendanceService
{
    /**
     * Set a teacher's status on a day, creating the day when it has none. A
     * status the day already holds writes nothing.
     *
     * Leaving "late" drops the arrival time, and coming back from an absence
     * drops the substitute — each describes a day that no longer stands. The
     * reason stays: it may still explain the day.
     */
    public static function mark(Teacher $teacher, string $date, string $status, ?int $stageId, ?int $editorId, string $role): TeacherAttendance
    {
        return DB::transaction(function () use ($teacher, $date, $status, $stageId, $editorId, $role) {
            // whereDate: the column is cast to a date and stored with a midnight
            // time, so a plain equality against "Y-m-d" finds nothing.
            $record = TeacherAttendance::whereDate('date', $date)->where('teacher_id', $teacher->id)->first();

            if ($record?->status === $status) {
                return $record;
            }

            $before = self::stateOf($record);

            if ($record) {
                // The stage it was filed under stays: a correction is not a move.
                $record->update([
                    'status' => $status,
                    'arrived_at' => $status === 'late' ? $record->arrived_at : null,
                    'substitute_teacher_id' => in_array($status, TeacherAttendance::AWAY, true) ? $record->substitute_teacher_id : null,
                    'recorded_by_id' => $editorId,
                ]);
            } else {
                $record = TeacherAttendance::create([
                    'teacher_id' => $teacher->id,
                    'stage_id' => $stageId,
                    'date' => $date,
                    'status' => $status,
                    'recorded_by_id' => $editorId,
                ]);
            }

            self::log($record, $before, $editorId, $role);

            if (in_array($status, ['absent', 'late'], true)) {
                self::notify($teacher, $record, $editorId, $role);
            }

            return $record;
        });
    }

    /**
     * Set the reason, and for a late teacher when they arrived, for an absent
     * one who covered. Blank text clears the reason; an arrival time or a
     * substitute on a status they do not belong to is ignored.
     */
    public static function annotate(TeacherAttendance $record, ?string $notes, ?string $arrivedAt, ?int $substituteId, ?int $editorId, string $role): void
    {
        $notes = trim((string) $notes) ?: null;
        $arrivedAt = $record->status === 'late' && $arrivedAt ? $arrivedAt.':00' : null;
        $substituteId = in_array($record->status, TeacherAttendance::AWAY, true) ? $substituteId : null;

        if ($notes === $record->notes && $arrivedAt === $record->arrived_at && $substituteId === $record->substitute_teacher_id) {
            return;
        }

        DB::transaction(function () use ($record, $notes, $arrivedAt, $substituteId, $editorId, $role) {
            $before = self::stateOf($record);

            $record->update([
                'notes' => $notes,
                'arrived_at' => $arrivedAt,
                'substitute_teacher_id' => $substituteId,
                'recorded_by_id' => $editorId,
            ]);

            self::log($record, $before, $editorId, $role);
        });
    }

    /**
     * Clear days, leaving a line for each in the trail.
     *
     * @param  Collection<int, TeacherAttendance>  $records
     */
    public static function clear(Collection $records, ?int $editorId, string $role): void
    {
        DB::transaction(function () use ($records, $editorId, $role) {
            foreach ($records as $record) {
                TeacherAttendanceRevision::create([
                    'teacher_attendance_id' => null,
                    'teacher_id' => $record->teacher_id,
                    'stage_id' => $record->stage_id,
                    'date' => $record->date->format('Y-m-d'),
                    'old_status' => $record->status,
                    'old_notes' => $record->notes,
                    'old_arrived_at' => $record->arrived_at,
                    'old_substitute_id' => $record->substitute_teacher_id,
                    'edited_by_id' => $editorId,
                    'edited_by_role' => $role,
                ]);

                $record->delete();
            }
        });
    }

    /**
     * Tell the teacher, over WhatsApp, that they were marked absent or late —
     * from the account of whoever marked them, once the write has landed.
     * Nothing goes out unless the manager has turned it on, and nothing to a
     * teacher with no number.
     */
    private static function notify(Teacher $teacher, TeacherAttendance $record, ?int $editorId, string $role): void
    {
        if (! TeacherAttendanceSettings::notifiesTeachers() || ! $teacher->phone || ! $editorId) {
            return;
        }

        $what = $record->status === 'late' ? 'تأخرك' : 'غيابك';
        $day = HijriDate::withWeekday($record->date);

        SendGuardianWhatsappJob::dispatch(
            $teacher->phone,
            "السلام عليكم ورحمة الله وبركاته\nالأستاذ {$teacher->name}، سُجِّل {$what} يوم {$day}.\nإن كان لك عذر فأبلغ مشرفك ليُثبته.",
            "{$role}_{$editorId}",
        )->afterCommit();
    }

    /**
     * @return array{status: ?string, notes: ?string, arrived_at: ?string, substitute: ?int}
     */
    private static function stateOf(?TeacherAttendance $record): array
    {
        return [
            'status' => $record?->status,
            'notes' => $record?->notes,
            'arrived_at' => $record?->arrived_at,
            'substitute' => $record?->substitute_teacher_id,
        ];
    }

    /**
     * @param  array{status: ?string, notes: ?string, arrived_at: ?string, substitute: ?int}  $before
     */
    private static function log(TeacherAttendance $record, array $before, ?int $editorId, string $role): void
    {
        TeacherAttendanceRevision::create([
            'teacher_attendance_id' => $record->id,
            'teacher_id' => $record->teacher_id,
            'stage_id' => $record->stage_id,
            'date' => $record->date->format('Y-m-d'),
            'old_status' => $before['status'],
            'new_status' => $record->status,
            'old_notes' => $before['notes'],
            'new_notes' => $record->notes,
            'old_arrived_at' => $before['arrived_at'],
            'new_arrived_at' => $record->arrived_at,
            'old_substitute_id' => $before['substitute'],
            'new_substitute_id' => $record->substitute_teacher_id,
            'edited_by_id' => $editorId,
            'edited_by_role' => $role,
        ]);
    }
}
