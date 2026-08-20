<?php

namespace App\Livewire\Teacher;

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance as AttendanceModel;
use App\Models\AttendanceRevision;
use App\Models\Circle;
use App\Models\GamificationTransaction;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\GamificationService;
use App\Services\GuardianNotificationService;
use App\Support\HijriDate;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The whole month on one screen: students down the side, working days across
 * the top, every status reachable in a click.
 *
 * Edits are staged in the browser and land in one call, so a teacher can sweep
 * across a week and save once. Marking a day on any day but itself is allowed —
 * the academy backfills — but never silently: the save is refused until a
 * reason is given, and every change lands in attendance_revisions with who made
 * it and when.
 */
class AttendanceSheet extends Component
{
    public const STATUSES = ['present', 'absent', 'late', 'excused'];

    public ?int $circleId = null;

    /** The Hijri month on screen, as the Unix timestamp of its first day. */
    public ?int $monthTimestamp = null;

    public function mount(?int $circleId = null): void
    {
        $this->circleId = $circleId;
        $this->monthTimestamp ??= $this->startOfHijriMonth(now());
    }

    public function updatedCircleId(): void
    {
        unset($this->students, $this->grid, $this->revisions);
    }

    /**
     * The day the teacher is actually sitting at, which every edit is measured
     * against. Marking any other day is an off-day edit.
     */
    public function today(): string
    {
        return now()->toDateString();
    }

    public function previousMonth(): void
    {
        $this->shiftMonth(-1);
    }

    public function nextMonth(): void
    {
        $this->shiftMonth(1);
    }

    public function goToCurrentMonth(): void
    {
        $this->monthTimestamp = $this->startOfHijriMonth(now());
        unset($this->grid, $this->revisions);
    }

    private function shiftMonth(int $by): void
    {
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->setTime(($this->monthTimestamp ?? $this->startOfHijriMonth(now())) * 1000);
        $cal->add(\IntlCalendar::FIELD_MONTH, $by);
        $this->monthTimestamp = (int) ($cal->getTime() / 1000);

        unset($this->grid, $this->revisions);
    }

    private function startOfHijriMonth(CarbonInterface $date): int
    {
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->setTime($date->getTimestamp() * 1000);
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);

        return (int) ($cal->getTime() / 1000);
    }

    /**
     * The Gregorian bounds of the Hijri month on screen.
     *
     * @return array{0: string, 1: string}
     */
    private function monthBounds(): array
    {
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->setTime(($this->monthTimestamp ?? $this->startOfHijriMonth(now())) * 1000);
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $start = date('Y-m-d', (int) ($cal->getTime() / 1000));

        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH));
        $end = date('Y-m-d', (int) ($cal->getTime() / 1000));

        return [$start, $end];
    }

    public function monthLabel(): string
    {
        return HijriDate::monthYear($this->monthTimestamp ?? $this->startOfHijriMonth(now()));
    }

    /**
     * The columns: every working day of the month for this circle's stage, each
     * carrying the labels the header shows and whether it is today.
     *
     * @return array<int, array{date: string, day: string, weekday: string, is_today: bool, is_future: bool}>
     */
    #[Computed]
    public function days(): array
    {
        if (! $this->circleId) {
            return [];
        }

        [$start, $end] = $this->monthBounds();
        $stageId = Circle::find($this->circleId)?->stage_id;
        $today = $this->today();

        return collect(AcademicCalendarEvent::workingDaysBetween($start, $end, $stageId))
            ->map(fn (string $date) => [
                'date' => $date,
                'day' => HijriDate::format($date, 'd'),
                'weekday' => HijriDate::format($date, 'EEE'),
                'is_today' => $date === $today,
                'is_future' => $date > $today,
            ])
            ->values()
            ->all();
    }

    /**
     * The rows: students of the circle who were active on at least one day of
     * the month. A student who joined mid-month still gets a row — the days
     * before they joined are simply not theirs to mark.
     *
     * @return Collection<int, Student>
     */
    #[Computed]
    public function students()
    {
        if (! $this->circleId) {
            return collect();
        }

        [, $end] = $this->monthBounds();

        return Student::where('circle_id', $this->circleId)
            ->whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->where(function ($query) use ($end) {
                $query->whereNull('joined_at')->orWhere('joined_at', '<=', $end);
            })
            ->with(['statusHistories' => fn ($q) => $q->orderBy('start_date')->orderBy('id')])
            ->orderBy('name')
            ->get();
    }

    /**
     * Which cells a teacher may write in: a student counts on a day only while
     * active on it, matching the rule the rest of the app reads attendance by.
     *
     * @return array<int, array<string, bool>> student_id => date => editable
     */
    #[Computed]
    public function editable(): array
    {
        $map = [];

        foreach ($this->students as $student) {
            $joined = $student->joined_at ? Carbon::parse($student->joined_at)->toDateString() : null;

            foreach ($this->days as $day) {
                $date = $day['date'];

                if ($joined !== null && $date < $joined) {
                    $map[$student->id][$date] = false;

                    continue;
                }

                $map[$student->id][$date] = $this->statusOnDate($student, $date) === 'active';
            }
        }

        return $map;
    }

    /**
     * A student's enrolment status on a date, from their history, falling back
     * to their current status when the history says nothing yet.
     */
    private function statusOnDate(Student $student, string $date): string
    {
        $history = $student->statusHistories
            ->filter(fn ($row) => Carbon::parse($row->start_date)->toDateString() <= $date)
            ->last();

        return $history->status ?? $student->status;
    }

    /**
     * The saved sheet: every recorded status in the month, by student and day.
     *
     * @return array<int, array<string, string>>
     */
    #[Computed]
    public function grid(): array
    {
        if (! $this->circleId) {
            return [];
        }

        [$start, $end] = $this->monthBounds();

        $records = AttendanceModel::where('circle_id', $this->circleId)
            ->whereBetween('date', [Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay()])
            ->get(['student_id', 'date', 'status']);

        $grid = [];
        foreach ($records as $record) {
            $grid[$record->student_id][Carbon::parse($record->date)->toDateString()] = $record->status;
        }

        return $grid;
    }

    /**
     * The edit trail for the month, newest first — what the history panel reads.
     *
     * @return Collection<int, AttendanceRevision>
     */
    #[Computed]
    public function revisions()
    {
        if (! $this->circleId) {
            return collect();
        }

        [$start, $end] = $this->monthBounds();

        // Bounded as full datetimes: the date column stores a time component, and
        // a bare Y-m-d upper bound string-compares below that day's own rows.
        return AttendanceRevision::where('circle_id', $this->circleId)
            ->whereBetween('date', [Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay()])
            ->with('student:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /**
     * Save a batch of staged cells.
     *
     * Returns false when the save was refused, which is the browser's signal to
     * keep the staged edits on screen rather than clearing them — a missing
     * reason is the only refusal a teacher can act on.
     *
     * @param  array<int, array{student_id: int|string, date: string, status: string}>  $changes
     */
    public function saveChanges(array $changes, string $reason = ''): bool
    {
        $teacher = auth()->guard('teacher')->user();

        if (! $teacher || ! $this->circleId || ! $this->teacherOwnsCircle($teacher)) {
            Flux::toast('لا تملك صلاحية التعديل على هذه الحلقة', variant: 'danger');

            return false;
        }

        $today = $this->today();
        $reason = trim($reason);
        $allowedDates = collect($this->days)->pluck('date')->flip();
        $editable = $this->editable;

        $valid = [];
        foreach ($changes as $change) {
            $studentId = (int) ($change['student_id'] ?? 0);
            $date = (string) ($change['date'] ?? '');
            $status = (string) ($change['status'] ?? '');

            if (! $allowedDates->has($date) || $date > $today) {
                continue;
            }

            if ($status !== '' && ! in_array($status, self::STATUSES, true)) {
                continue;
            }

            if (! ($editable[$studentId][$date] ?? false)) {
                continue;
            }

            $valid[$studentId.'|'.$date] = ['student_id' => $studentId, 'date' => $date, 'status' => $status];
        }

        if ($valid === []) {
            Flux::toast('لا توجد تعديلات صالحة للحفظ', variant: 'warning');

            return false;
        }

        $offDay = collect($valid)->filter(fn (array $change) => $change['date'] !== $today);

        if ($offDay->isNotEmpty() && $reason === '') {
            $this->addError('reason', 'يجب إدخال سبب التعديل عند التحضير في غير يوم الجلسة.');

            return false;
        }

        $this->resetErrorBag('reason');

        $saved = 0;
        $notifyLater = [];

        DB::transaction(function () use ($valid, $teacher, $today, $reason, &$saved, &$notifyLater) {
            foreach ($valid as $change) {
                $existing = AttendanceModel::where('student_id', $change['student_id'])
                    ->whereDate('date', $change['date'])
                    ->first();

                $oldStatus = $existing?->status;

                if ($oldStatus === ($change['status'] ?: null)) {
                    continue;
                }

                $isOffDay = $change['date'] !== $today;

                if ($change['status'] === '') {
                    if (! $existing) {
                        continue;
                    }

                    $this->recordRevision($change, null, $oldStatus, $isOffDay, $reason, $teacher, $today, null);
                    $this->deleteRecord($existing, $change['student_id'], $change['date']);
                    $saved++;

                    continue;
                }

                // Written through the row already found by whereDate(), not
                // updateOrCreate(): the date column carries a time component, so
                // matching on a bare Y-m-d misses it and inserts a duplicate.
                if ($existing) {
                    $existing->update([
                        'teacher_id' => $teacher->id,
                        'circle_id' => $this->circleId,
                        'status' => $change['status'],
                    ]);
                    $attendance = $existing;
                } else {
                    $attendance = AttendanceModel::create([
                        'student_id' => $change['student_id'],
                        'date' => $change['date'],
                        'teacher_id' => $teacher->id,
                        'circle_id' => $this->circleId,
                        'status' => $change['status'],
                    ]);
                }

                GamificationService::syncStudentAttendanceXP($attendance);

                $this->recordRevision($change, $attendance->id, $oldStatus, $isOffDay, $reason, $teacher, $today, $change['status']);
                $saved++;

                if (in_array($change['status'], ['absent', 'late'], true)) {
                    $notifyLater[] = $change;
                }
            }
        });

        // Guardians are told after the sheet is safely written, so a failing
        // notification can never roll back the attendance it is reporting on.
        foreach ($notifyLater as $change) {
            $student = Student::find($change['student_id']);
            if ($student) {
                GuardianNotificationService::notifyAbsence($student, $change['status'], $change['date']);
            }
        }

        unset($this->grid, $this->revisions);

        Flux::toast(
            $saved > 0 ? "تم حفظ {$saved} تعديلاً بنجاح" : 'لا توجد تغييرات جديدة',
            variant: $saved > 0 ? 'success' : 'warning'
        );

        return true;
    }

    /**
     * @param  array{student_id: int, date: string, status: string}  $change
     */
    private function recordRevision(array $change, ?int $attendanceId, ?string $oldStatus, bool $isOffDay, string $reason, Teacher $teacher, string $today, ?string $newStatus): void
    {
        AttendanceRevision::create([
            'attendance_id' => $attendanceId,
            'student_id' => $change['student_id'],
            'circle_id' => $this->circleId,
            'date' => $change['date'],
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'reason' => $isOffDay ? $reason : null,
            'is_off_day_edit' => $isOffDay,
            'edited_on' => $today,
            'edited_by_id' => $teacher->id,
            'edited_by_type' => $teacher->getMorphClass(),
        ]);
    }

    /**
     * Clearing a cell takes the XP it earned with it, the same way wiping a
     * day's attendance does, so a removed record leaves no points behind.
     */
    private function deleteRecord(AttendanceModel $attendance, int $studentId, string $date): void
    {
        $attendanceId = $attendance->id;
        $attendance->delete();

        GamificationTransaction::where('reference_type', AttendanceModel::class)
            ->where('reference_id', $attendanceId)
            ->delete();

        $student = Student::find($studentId);
        if (! $student) {
            return;
        }

        foreach (GamificationService::getActiveLeaderboards($student, $date) as $leaderboard) {
            GamificationService::recalculateStudentState($studentId, $leaderboard->id);
        }
    }

    private function teacherOwnsCircle(Teacher $teacher): bool
    {
        return $teacher->circles()->whereKey($this->circleId)->exists();
    }

    /**
     * Per-student totals down the row: how each student's month reads.
     *
     * @return array<int, array{present: int, absent: int, late: int, excused: int, marked: int, percentage: int}>
     */
    #[Computed]
    public function studentTotals(): array
    {
        $totals = [];

        foreach ($this->students as $student) {
            $row = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'marked' => 0, 'percentage' => 0];

            foreach ($this->days as $day) {
                $status = $this->grid[$student->id][$day['date']] ?? '';
                if ($status === '') {
                    continue;
                }
                $row[$status]++;
                $row['marked']++;
            }

            $counted = $row['present'] + $row['late'] + $row['absent'];
            $row['percentage'] = $counted > 0
                ? (int) round(($row['present'] + $row['late'] * 0.5) / $counted * 100)
                : 0;

            $totals[$student->id] = $row;
        }

        return $totals;
    }

    /**
     * Per-day totals across the top: how far each column got.
     *
     * @return array<string, array{marked: int, total: int}>
     */
    #[Computed]
    public function dayTotals(): array
    {
        $totals = [];
        $editable = $this->editable;

        foreach ($this->days as $day) {
            $date = $day['date'];
            $marked = 0;
            $total = 0;

            foreach ($this->students as $student) {
                if (! ($editable[$student->id][$date] ?? false)) {
                    continue;
                }
                $total++;
                if (($this->grid[$student->id][$date] ?? '') !== '') {
                    $marked++;
                }
            }

            $totals[$date] = ['marked' => $marked, 'total' => $total];
        }

        return $totals;
    }

    /**
     * The month as a CSV, laid out exactly as the sheet reads on screen.
     */
    public function exportCsv(): ?StreamedResponse
    {
        if (! $this->circleId || $this->students->isEmpty()) {
            Flux::toast('اختر حلقة بها طلاب أولاً', variant: 'warning');

            return null;
        }

        $labels = AttendanceRevision::statusLabels();
        $days = $this->days;
        $students = $this->students;
        $grid = $this->grid;
        $editable = $this->editable;
        $circleName = Circle::find($this->circleId)?->name ?? '';
        $month = $this->monthLabel();

        return response()->streamDownload(function () use ($days, $students, $grid, $editable, $labels) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders Arabic correctly.

            fputcsv($out, array_merge(['الطالب'], array_map(fn (array $day) => $day['day'].' '.$day['weekday'], $days), ['نسبة الحضور']));

            foreach ($students as $student) {
                $row = [$student->name];
                $present = 0;
                $counted = 0;

                foreach ($days as $day) {
                    if (! ($editable[$student->id][$day['date']] ?? false)) {
                        $row[] = '—';

                        continue;
                    }

                    $status = $grid[$student->id][$day['date']] ?? '';
                    $row[] = $status === '' ? '' : ($labels[$status] ?? $status);

                    if (in_array($status, ['present', 'late', 'absent'], true)) {
                        $counted++;
                        $present += $status === 'present' ? 1 : ($status === 'late' ? 0.5 : 0);
                    }
                }

                $row[] = $counted > 0 ? round($present / $counted * 100).'%' : '';
                fputcsv($out, $row);
            }

            fclose($out);
        }, "attendance-sheet-{$circleName}-{$month}.csv");
    }

    public function render()
    {
        return view('livewire.teacher.attendance-sheet');
    }
}
