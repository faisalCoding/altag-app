<?php

namespace App\Livewire\Manager;

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentPlanDay;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Dashboard extends Component
{
    // ──────── Attendance section ────────
    public string $attendancePeriod = 'today';  // today|yesterday|last_data|last_week|custom

    public string $attFrom = '';

    public string $attTo = '';

    // ──────── Quran section ────────
    public string $quranPeriod = 'today';

    public string $quranFrom = '';

    public string $quranTo = '';

    public function mount(): void
    {
        $this->attFrom = $this->attTo = now('Asia/Riyadh')->format('Y-m-d');
        $this->quranFrom = $this->quranTo = now('Asia/Riyadh')->format('Y-m-d');
    }

    // ──────── School-day check ────────

    /**
     * Returns true if $date is a school day per the academic calendar. The
     * manager's dashboard covers the whole academy, so it asks about the
     * academy-wide periods rather than any one stage's.
     */
    private function isSchoolDay(string $date): bool
    {
        return AcademicCalendarEvent::isWorkingDay($date);
    }

    // ──────── Date-range resolvers ────────

    private function resolveAttendanceDates(): array
    {
        return $this->resolveDates($this->attendancePeriod, $this->attFrom, $this->attTo, 'attendance');
    }

    private function resolveQuranDates(): array
    {
        return $this->resolveDates($this->quranPeriod, $this->quranFrom, $this->quranTo, 'quran');
    }

    /**
     * @return array{from:string, to:string, label:string}
     */
    private function resolveDates(string $period, string $customFrom, string $customTo, string $type): array
    {
        $today = now('Asia/Riyadh')->format('Y-m-d');

        return match ($period) {
            'today' => ['from' => $today, 'to' => $today, 'label' => 'اليوم'],
            'yesterday' => [
                'from' => now('Asia/Riyadh')->subDay()->format('Y-m-d'),
                'to' => now('Asia/Riyadh')->subDay()->format('Y-m-d'),
                'label' => 'أمس',
            ],
            'last_data' => $this->resolveLastDataDates($type),
            'last_week' => [
                'from' => now('Asia/Riyadh')->subDays(6)->format('Y-m-d'),
                'to' => $today,
                'label' => 'الأسبوع الماضي',
            ],
            'custom' => [
                'from' => $customFrom ?: $today,
                'to' => $customTo ?: $today,
                'label' => 'مخصص',
            ],
            default => ['from' => $today, 'to' => $today, 'label' => 'اليوم'],
        };
    }

    private function resolveLastDataDates(string $type): array
    {
        if ($type === 'attendance') {
            $lastDate = Attendance::max('date');
        } else {
            $lastDate = StudentPlanDay::where(function ($q) {
                $q->whereNotNull('hifz_achievement')
                    ->orWhereNotNull('review_achievement');
            })->max('date');
        }

        // Ensure we have just the date portion (no time component)
        $date = $lastDate ? Carbon::parse($lastDate)->format('Y-m-d') : now('Asia/Riyadh')->format('Y-m-d');

        return ['from' => $date, 'to' => $date, 'label' => 'آخر يوم بيانات'];
    }

    // ──────── Attendance data ────────

    public function getAttendanceDataProperty(): array
    {
        ['from' => $from, 'to' => $to] = $this->resolveAttendanceDates();
        $isSingleDay = $from === $to;

        // Skip school-day check when 'last_data' — data presence implies school was held
        if ($this->attendancePeriod === 'last_data') {
            $isSchoolDay = null;
        } else {
            $isSchoolDay = $isSingleDay ? $this->isSchoolDay($from) : null;
        }

        // Total students per circle, counting only students who were active on the
        // report date per their status history (registering periods are excluded).
        $referenceDate = $isSingleDay ? $from : $to;
        $circleStudentCounts = Student::whereNotNull('circle_id')
            ->with(['statusHistories' => function ($query) use ($referenceDate) {
                $query->whereDate('start_date', '<=', $referenceDate)->orderBy('start_date', 'desc')->orderByDesc('id');
            }])
            ->get(['id', 'circle_id', 'status'])
            ->filter(function ($student) {
                $history = $student->statusHistories->first();

                return ($history ? $history->status : $student->status) === 'active';
            })
            ->groupBy('circle_id')
            ->map->count()
            ->toArray();

        $totalStudents = array_sum($circleStudentCounts);

        // Attendance records in range, excluding records taken while the student
        // was still under registration. whereDate is required because attendance
        // dates are stored with a time component that breaks raw whereBetween.
        $records = DB::table('attendances')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->whereRaw(Attendance::activeStatusOnDateSql())
            ->select('attendances.status', DB::raw('count(*) as cnt'))
            ->groupBy('attendances.status')
            ->pluck('cnt', 'attendances.status')
            ->toArray();

        $present = ($records['present'] ?? 0) + ($records['late'] ?? 0);
        $absent = $records['absent'] ?? 0;
        $total = $present + $absent;

        // Per-stage breakdown
        $stageRows = $this->getAttendanceByStage($from, $to);

        return compact('from', 'to', 'isSingleDay', 'isSchoolDay', 'present', 'absent', 'total', 'totalStudents', 'stageRows');
    }

    private function getAttendanceByStage(string $from, string $to): Collection
    {
        return Stage::with('circles')->get()->map(function ($stage) use ($from, $to) {
            $circleIds = $stage->circles->pluck('id')->toArray();
            if (empty($circleIds)) {
                return null;
            }

            $rows = DB::table('attendances')
                ->join('users as students', 'attendances.student_id', '=', 'students.id')
                ->whereIn('students.circle_id', $circleIds)
                ->whereDate('attendances.date', '>=', $from)
                ->whereDate('attendances.date', '<=', $to)
                ->whereRaw(Attendance::activeStatusOnDateSql())
                ->select('attendances.status', DB::raw('count(*) as cnt'))
                ->groupBy('attendances.status')
                ->pluck('cnt', 'attendances.status')
                ->toArray();

            $present = ($rows['present'] ?? 0) + ($rows['late'] ?? 0);
            $absent = $rows['absent'] ?? 0;

            return [
                'name' => $stage->name,
                'present' => $present,
                'absent' => $absent,
                'total' => $present + $absent,
            ];
        })->filter()->values();
    }

    // ──────── Quran data ────────

    public function getQuranDataProperty(): array
    {
        ['from' => $from, 'to' => $to] = $this->resolveQuranDates();
        $isSingleDay = $from === $to;

        // Skip school-day check when 'last_data' — data presence implies school was held
        if ($this->quranPeriod === 'last_data') {
            $isSchoolDay = null;
        } else {
            $isSchoolDay = $isSingleDay ? $this->isSchoolDay($from) : null;
        }

        // whereDate is required throughout: plan-day dates carry a time component,
        // so a raw whereBetween against bare Y-m-d bounds drops the boundary day —
        // the same trap the attendance queries above already guard against.
        $gradedDays = fn () => DB::table('student_plan_days')
            ->join('student_plans', 'student_plan_days.student_plan_id', '=', 'student_plans.id')
            ->whereDate('student_plan_days.date', '>=', $from)
            ->whereDate('student_plan_days.date', '<=', $to)
            ->where('student_plans.is_approved', 1);

        $hifzSessions = $gradedDays()->whereNotNull('hifz_achievement')->count();

        $reviewSessions = $gradedDays()->whereNotNull('review_achievement')->count();

        // Counted per session, not per row, so it shares a unit with the two
        // figures it sits beside: a day graded excellent in both hifz and review
        // is two excellent sessions, exactly as it is two sessions in the totals.
        $excellentCount = $gradedDays()->where('hifz_achievement', 3)->count()
            + $gradedDays()->where('review_achievement', 3)->count();

        $stageRows = $this->getQuranByStage($from, $to);

        $hasData = $hifzSessions + $reviewSessions > 0;

        return compact('from', 'to', 'isSingleDay', 'isSchoolDay', 'hifzSessions', 'reviewSessions', 'excellentCount', 'stageRows', 'hasData');
    }

    private function getQuranByStage(string $from, string $to): Collection
    {
        return Stage::with('circles')->get()->map(function ($stage) use ($from, $to) {
            $circleIds = $stage->circles->pluck('id')->toArray();
            if (empty($circleIds)) {
                return null;
            }

            $stageDays = fn () => DB::table('student_plan_days')
                ->join('student_plans', 'student_plan_days.student_plan_id', '=', 'student_plans.id')
                ->join('users as students', 'student_plans.student_id', '=', 'students.id')
                ->whereIn('students.circle_id', $circleIds)
                ->whereDate('student_plan_days.date', '>=', $from)
                ->whereDate('student_plan_days.date', '<=', $to)
                ->where('student_plans.is_approved', 1);

            $hifz = $stageDays()->whereNotNull('hifz_achievement')->count();

            $review = $stageDays()->whereNotNull('review_achievement')->count();

            if ($hifz + $review === 0) {
                return null;
            }

            return [
                'name' => $stage->name,
                'hifz' => $hifz,
                'review' => $review,
            ];
        })->filter()->values();
    }

    // ──────── Period quick-setters ────────

    public function setAttendancePeriod(string $period): void
    {
        $this->attendancePeriod = $period;
    }

    public function setQuranPeriod(string $period): void
    {
        $this->quranPeriod = $period;
    }

    public function render()
    {
        return view('livewire.manager.dashboard', [
            'attendanceData' => $this->attendanceData,
            'quranData' => $this->quranData,
            'attDates' => $this->resolveAttendanceDates(),
            'quranDates' => $this->resolveQuranDates(),
        ])->layout('layouts.role-shell');
    }
}
