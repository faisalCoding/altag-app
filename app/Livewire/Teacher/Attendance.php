<?php

namespace App\Livewire\Teacher;

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance as AttendanceModel;
use App\Models\Circle;
use App\Models\GamificationTransaction;
use App\Models\Setting;
use App\Models\Student;
use App\Services\GamificationService;
use App\Services\GuardianNotificationService;
use App\Support\HijriDate;
use Carbon\Carbon;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Attendance extends Component
{
    public $circles = [];

    public ?int $selectedCircle = null;

    #[Url]
    public string $date = '';

    public $students;

    /** @var array<int, string> student_id => status */
    public array $records = [];

    /** Ordered student IDs — used by Alpine for navigation */
    public array $studentOrder = [];

    public function mount(): void
    {

        $this->students = collect();
        if (empty($this->date)) {
            // Riyadh, not app.timezone: on UTC the register would open on
            // yesterday's column for the first three hours of every day.
            $this->date = now('Asia/Riyadh')->format('Y-m-d');
        }

        $teacher = auth()->guard('teacher')->user();
        $this->circles = $teacher->circles()->get();

        if ($this->circles->count() === 1) {
            $this->selectedCircle = $this->circles->first()->id;
            $this->loadStudents();
        }

    }

    public function updatedSelectedCircle(): void
    {
        $this->loadStudents();
    }

    public function updatedDate(): void
    {
        $this->loadStudents();
    }

    #[On('student-list-updated')]
    public function loadStudents(): void
    {
        if (! $this->selectedCircle) {
            $this->students = collect();
            $this->records = [];
            $this->studentOrder = [];
            $this->dispatch('studentsLoaded');

            return;
        }

        $studentsQuery = Student::where('circle_id', $this->selectedCircle)
            ->whereRoleState(fn ($q) => $q->where('is_approved', true))
            // whereDate, not a bare comparison: the column carries a time of
            // midnight, and "2026-09-21 00:00:00" <= "2026-09-21" is false as a
            // string — which hid every student on the very day they enrolled.
            ->where(function ($query) {
                $query->whereNull('joined_at')
                    ->orWhereDate('joined_at', '<=', $this->date);
            })
            ->with([
                'circle',
                'statusHistories' => function ($query) {
                    $query->where('start_date', '<=', $this->date)->orderBy('start_date', 'desc')->orderByDesc('id');
                },
            ])
            ->orderBy('name')
            ->get();

        $this->students = $studentsQuery->filter(function ($student) {
            $history = $student->statusHistories->first();
            $statusOnDate = $history ? $history->status : $student->status;

            return $statusOnDate === 'active';
        })->values();

        $existing = AttendanceModel::where('circle_id', $this->selectedCircle)
            ->whereDate('date', $this->date)
            ->pluck('status', 'student_id')
            ->toArray();

        $this->records = [];
        foreach ($this->students as $student) {
            $this->records[$student->id] = $existing[$student->id] ?? '';
        }

        $this->studentOrder = $this->students->pluck('id')->toArray();

        // Tell Alpine to reset navigation state
        $this->dispatch('studentsLoaded');
    }

    /**
     * Wizard mode: save status. Alpine handles navigation immediately without waiting.
     */
    public function markStatus(int $studentId, string $status): void
    {
        if (! in_array($status, ['present', 'absent', 'late', 'excused'])) {
            return;
        }

        $this->records[$studentId] = $status;
        $this->saveRecord($studentId, $status);
        $this->dispatch('attendance-updated');
    }

    /**
     * List mode: save status and re-render so WhatsApp links reflect new state.
     */
    public function updateStatus(int $studentId, string $status): void
    {
        if (! in_array($status, ['present', 'absent', 'late', 'excused'])) {
            return;
        }

        $this->records[$studentId] = $status;
        $this->saveRecord($studentId, $status);
        $this->dispatch('attendance-updated');
    }

    public function markAllPresent(): void
    {
        $teacher = auth()->guard('teacher')->user();

        foreach ($this->students as $student) {
            if (AttendanceModel::whereDate('date', $this->date)->where('student_id', $student->id)->exists()) {
                continue;
            }
            $this->records[$student->id] = 'present';

            $attendance = AttendanceModel::updateOrCreate(
                [
                    'student_id' => $student->id,
                    'date' => $this->date,
                ],
                [
                    'teacher_id' => $teacher->id,
                    'circle_id' => $this->selectedCircle,
                    'status' => 'present',
                ]
            );

            GamificationService::syncStudentAttendanceXP($attendance);
        }

        $this->isComplete = true;
        $this->dispatch('attendance-updated');
        Flux::toast('تم تسجيل حضور جميع الطلاب بنجاح', variant: 'success');
    }

    public function exportCsv(): ?StreamedResponse
    {
        if (! $this->selectedCircle || $this->students->isEmpty()) {
            Flux::toast(__('اختر حلقة بها طلاب أولاً'), variant: 'warning');

            return null;
        }

        $labels = ['present' => 'حاضر', 'absent' => 'غائب', 'late' => 'متأخر', 'excused' => 'مستأذن'];
        $students = $this->students;
        $records = $this->records;
        $date = $this->date;

        $circleName = collect($this->circles)->firstWhere('id', $this->selectedCircle)?->name ?? '';

        $hijri = HijriDate::full($date);

        return response()->streamDownload(function () use ($students, $records, $labels, $hijri) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders Arabic correctly
            fputcsv($out, ['التاريخ', 'اسم الطالب', 'الحالة']);
            foreach ($students as $student) {
                $status = $records[$student->id] ?? '';
                fputcsv($out, [$hijri, $student->name, $labels[$status] ?? 'غير مسجل']);
            }
            fclose($out);
        }, "attendance-{$circleName}-{$date}.csv");
    }

    public function clearDayAttendance(): void
    {
        if (! $this->selectedCircle || empty($this->date)) {
            return;
        }

        $attendanceIds = AttendanceModel::where('circle_id', $this->selectedCircle)
            ->whereDate('date', $this->date)
            ->pluck('id')
            ->toArray();

        AttendanceModel::whereIn('id', $attendanceIds)->delete();

        GamificationTransaction::where('reference_type', AttendanceModel::class)
            ->whereIn('reference_id', $attendanceIds)
            ->delete();

        foreach ($this->students as $student) {
            $this->records[$student->id] = '';

            $activeLeaderboards = GamificationService::getActiveLeaderboards($student, $this->date);
            foreach ($activeLeaderboards as $lb) {
                GamificationService::recalculateStudentState($student->id, $lb->id);
            }
        }

        $this->dispatch('attendance-cleared');
        Flux::toast('تم حذف جميع بيانات التحضير لهذا اليوم', variant: 'success');
    }

    private function saveRecord(int $studentId, string $status): void
    {
        $teacher = auth()->guard('teacher')->user();

        $existing = AttendanceModel::where('student_id', $studentId)
            ->whereDate('date', $this->date)
            ->first();

        if ($existing) {
            $existing->update([
                'teacher_id' => $teacher->id,
                'circle_id' => $this->selectedCircle,
                'status' => $status,
            ]);
        } else {
            $existing = AttendanceModel::create([
                'student_id' => $studentId,
                'date' => $this->date,
                'teacher_id' => $teacher->id,
                'circle_id' => $this->selectedCircle,
                'status' => $status,
            ]);
        }

        GamificationService::syncStudentAttendanceXP($existing);

        if (in_array($status, ['absent', 'late'], true)) {
            $student = Student::find($studentId);
            if ($student) {
                GuardianNotificationService::notifyAbsence($student, $status, Carbon::parse($this->date)->toDateString());
            }
        }
    }

    /** @var array{present: int, late: int, absent: int, excused: int}|null Memoized per render — weeklyAttendancePercentage() and the donut both need it. */
    protected ?array $weeklyBreakdownCache = null;

    /**
     * present/late/absent/excused counts for the selected circle within the
     * calendar week containing the currently selected date.
     *
     * @return array{present: int, late: int, absent: int, excused: int}
     */
    public function weeklyBreakdown(): array
    {
        if ($this->weeklyBreakdownCache !== null) {
            return $this->weeklyBreakdownCache;
        }

        $empty = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];

        if (! $this->selectedCircle) {
            return $this->weeklyBreakdownCache = $empty;
        }

        $weekStart = Carbon::parse($this->date)->startOfWeek(Carbon::SATURDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::FRIDAY);

        // Compared as full datetimes (not bare Y-m-d) since the "date" column is
        // stored with a time component — a bare upper bound string-compares as
        // "less than" any same-day timestamp and silently drops that day's rows.
        $counts = AttendanceModel::where('circle_id', $this->selectedCircle)
            ->whereBetween('date', [$weekStart->startOfDay(), $weekEnd->endOfDay()])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return $this->weeklyBreakdownCache = [
            'present' => (int) ($counts['present'] ?? 0),
            'late' => (int) ($counts['late'] ?? 0),
            'absent' => (int) ($counts['absent'] ?? 0),
            'excused' => (int) ($counts['excused'] ?? 0),
        ];
    }

    /**
     * Present ratio (present counts as full attendance, late as half) across
     * this week's recorded attendance for the selected circle.
     */
    public function weeklyAttendancePercentage(): int
    {
        $breakdown = $this->weeklyBreakdown();
        $recorded = $breakdown['present'] + $breakdown['late'] + $breakdown['absent'];

        if ($recorded === 0) {
            return 0;
        }

        return (int) round(($breakdown['present'] + $breakdown['late'] * 0.5) / $recorded * 100);
    }

    /** @var array<string, array<string, int>>|null Memoized per render — one query covers all four status sparklines. */
    protected ?array $sparklineCache = null;

    /**
     * Daily count of a given status over the last 7 days for the selected
     * circle, oldest first — feeds the mini sparkline on each stat card.
     *
     * @return array<int, int>
     */
    public function sparklineFor(string $status): array
    {
        $start = Carbon::parse($this->date)->subDays(6);

        if (! $this->selectedCircle) {
            return array_fill(0, 7, 0);
        }

        if ($this->sparklineCache === null) {
            $end = Carbon::parse($this->date);
            $rows = AttendanceModel::where('circle_id', $this->selectedCircle)
                ->whereBetween('date', [$start->copy()->startOfDay(), $end->endOfDay()])
                ->selectRaw('date, status, count(*) as total')
                ->groupBy('date', 'status')
                ->get();

            $this->sparklineCache = [];
            foreach ($rows as $row) {
                $day = Carbon::parse($row->date)->toDateString();
                $this->sparklineCache[$row->status][$day] = (int) $row->total;
            }
        }

        $counts = $this->sparklineCache[$status] ?? [];

        $series = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $start->copy()->addDays($i)->toDateString();
            $series[] = $counts[$day] ?? 0;
        }

        return $series;
    }

    /**
     * The most recent distinct attendance days for the selected circle, each
     * with its computed present ratio — feeds "آخر الجلسات".
     *
     * @return array<int, array{date: string, percentage: int, time: ?string}>
     */
    public function recentSessions(int $limit = 3): array
    {
        if (! $this->selectedCircle) {
            return [];
        }

        $dates = AttendanceModel::where('circle_id', $this->selectedCircle)
            ->select('date')
            ->distinct()
            ->orderByDesc('date')
            ->limit($limit)
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString());

        if ($dates->isEmpty()) {
            return [];
        }

        // Bound by the earliest/latest of the selected dates rather than whereIn(),
        // since the "date" column stores a time component and whereIn() would
        // string-compare bare Y-m-d values against it and match nothing.
        $allRecords = AttendanceModel::where('circle_id', $this->selectedCircle)
            ->whereBetween('date', [
                Carbon::parse($dates->last())->startOfDay(),
                Carbon::parse($dates->first())->endOfDay(),
            ])
            ->get(['date', 'status', 'created_at'])
            ->groupBy(fn (AttendanceModel $record) => Carbon::parse($record->date)->toDateString());

        return $dates->map(function (string $dateStr) use ($allRecords) {
            $records = $allRecords->get($dateStr, collect());

            $total = $records->count();
            $present = $records->whereIn('status', ['present', 'late'])->count();

            return [
                'date' => $dateStr,
                'percentage' => $total > 0 ? (int) round($present / $total * 100) : 0,
                'time' => optional($records->first())->created_at?->format('H:i'),
            ];
        })->all();
    }

    public function getWhatsAppMessage(Student $student, string $status = 'absent'): string
    {
        $hijriDate = $this->getHijriDate();

        $count = $status === 'late'
            ? $student->getLatenessInPeriodCount($this->date)
            : $student->getAbsencesInPeriodCount($this->date);

        $limitName = $status === 'late' ? 'lateness_limit' : 'absence_limit';
        $limit = (int) Setting::getVal($limitName, $status === 'late' ? 5 : 3);
        $statusText = $status === 'late' ? 'متأخر' : 'غائب';

        if ($statusText == 'متأخر') {
            $statusText = 'حضر الحلقة متأخراً';
        }
        $ordinals = [
            1 => 'الأولى',
            2 => 'الثانية',
            3 => 'الثالثة',
            4 => 'الرابعة',
            5 => 'الخامسة',
            6 => 'السادسة',
            7 => 'السابعة',
            8 => 'الثامنة',
            9 => 'التاسعة',
            10 => 'العاشرة',
        ];

        $ordinal = $ordinals[$count] ?? $count;

        $msg = "السلام عليكم ورحمة الله وبركاته\nنود إشعاركم بأن الطالب {$student->name} {$statusText} وذلك في اليوم {$hijriDate} وهذه للمرة {$ordinal}";

        // if ($count >= $limit) {
        //     $msg .= " ويكون بذلك `تجاوز حد {$limit} ايام وسيتم إحالته للإدارة`.";
        // } else {
        //     $statusText = $status === 'late' ? 'تأخر' : 'غياب';

        //     $msg .= " \n`وفي حال تم تسجيل {$limit} حالات {$statusText} على الطالب بلا عذر فسيتم إحالته للإدارة`";
        // }

        return $msg;
    }

    private function getHijriDate(): string
    {
        return HijriDate::full($this->date);
    }

    /**
     * The working times the calendar holds for this circle's stage today, so
     * the teacher sees when the circle is due rather than being asked to type
     * how long it ran.
     *
     * @return array<int, array{from: string, to: string, label: string|null}>
     */
    #[Computed]
    public function todaysSessions(): array
    {
        if (! $this->selectedCircle || ! $this->date) {
            return [];
        }

        return AcademicCalendarEvent::sessionsOn(
            $this->date,
            Circle::find($this->selectedCircle)?->stage_id,
        );
    }

    public function render()
    {
        return view('livewire.teacher.attendance');
    }
}
