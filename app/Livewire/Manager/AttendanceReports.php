<?php

namespace App\Livewire\Manager;

use App\Models\Attendance;
use App\Models\Circle;
use App\Support\HijriDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf;

class AttendanceReports extends Component
{
    public $fromDate;

    public $toDate;

    // PDF Print properties
    public $printFrom;

    public $printTo;

    public $showPrintModal = false;

    public function mount()
    {
        $this->fromDate = Carbon::now()->subDays(6)->toDateString();
        $this->toDate = Carbon::now()->toDateString();
    }

    /**
     * The day of the month on its own — the month and the year are already
     * spelled out in the row above, which spans every day that shares them.
     *
     * All three of these used to return 'MMM yyyy', so the day row printed the
     * month twice and the reader had nothing to count the days by.
     */
    public function formatHijriDayNum($gregorianDate): string
    {
        return $this->hijri($gregorianDate, 'd');
    }

    /** "السبت". */
    public function formatHijriDayName($gregorianDate): string
    {
        return $this->hijri($gregorianDate, 'EEEE');
    }

    /** "صفر ١٤٤٨" — the heading the day columns group under. */
    public function formatHijriMonthYear($gregorianDate): string
    {
        return $this->hijri($gregorianDate, 'MMMM yyyy');
    }

    private function hijri($gregorianDate, string $pattern): string
    {
        if (! $gregorianDate) {
            return '';
        }

        return HijriDate::format(is_string($gregorianDate) ? strtotime($gregorianDate) : $gregorianDate, $pattern);
    }

    public function clearFilters()
    {
        $this->fromDate = Carbon::now()->subDays(6)->toDateString();
        $this->toDate = Carbon::now()->toDateString();
    }

    public function downloadPDF()
    {
        if (! $this->fromDate || ! $this->toDate) {
            return;
        }

        $dates = [];
        $d = Carbon::parse($this->fromDate);
        $end = Carbon::parse($this->toDate);
        while ($d->lte($end)) {
            $dates[] = $d->format('Y-m-d');
            $d->addDay();
        }

        $circles = Circle::with('stage')
            ->withCount(['students' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('stage_id')->orderBy('name')->get();
        $groupedCircles = $circles->groupBy(fn ($c) => $c->stage->name ?? 'بدون مرحلة');

        $records = Attendance::query()
            ->join('users as students', 'attendances.student_id', '=', 'students.id')
            ->where(function ($q) {
                $q->whereNull('students.joined_at')
                    ->orWhereColumn('students.joined_at', '<=', 'attendances.date');
            })
            ->whereRaw(Attendance::activeStatusOnDateSql())
            ->whereDate('attendances.date', '>=', $this->fromDate)
            ->whereDate('attendances.date', '<=', $this->toDate)
            ->select(
                'attendances.circle_id',
                DB::raw('DATE(attendances.date) as day'),
                DB::raw('COUNT(attendances.id) as total'),
                DB::raw("SUM(CASE WHEN attendances.status IN ('present', 'late') THEN 1 ELSE 0 END) as present_count"),
                DB::raw("SUM(CASE WHEN attendances.status = 'absent' THEN 1 ELSE 0 END) as absent_count")
            )
            ->groupBy('attendances.circle_id', DB::raw('DATE(attendances.date)'))
            ->get();

        $attendanceData = [];
        foreach ($records as $row) {
            $attendanceData[$row->circle_id][$row->day] = [
                'total' => $row->total,
                'present' => $row->present_count,
                'absent' => $row->absent_count,
            ];
        }

        $pdf = LaravelMpdf::loadView('pdf.attendance-report', [
            'dates' => $dates,
            'groupedCircles' => $groupedCircles,
            'attendanceData' => $attendanceData,
            'fromDate' => $this->fromDate,
            'toDate' => $this->toDate,
        ], [], [
            'format' => 'A4-L',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'useSubstitutions' => true,
            'useAdobeCJK' => true,
        ]);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, 'attendance_report.pdf');
    }

    public function render()
    {
        // Build the list of dates in range
        $dates = [];
        if ($this->fromDate && $this->toDate) {
            $d = Carbon::parse($this->fromDate);
            $end = Carbon::parse($this->toDate);
            while ($d->lte($end)) {
                $dates[] = $d->format('Y-m-d');
                $d->addDay();
            }
        }

        // Fetch all circles grouped by stage (with student count, excluding
        // students still under registration)
        $circles = Circle::with('stage')
            ->withCount(['students' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('stage_id')->orderBy('name')->get();
        $groupedCircles = $circles->groupBy(fn ($c) => $c->stage->name ?? 'بدون مرحلة');

        // Fetch aggregated attendance per circle per day
        $attendanceData = [];
        if ($this->fromDate && $this->toDate) {
            $records = Attendance::query()
                ->join('users as students', 'attendances.student_id', '=', 'students.id')
                ->where(function ($q) {
                    $q->whereNull('students.joined_at')
                        ->orWhereColumn('students.joined_at', '<=', 'attendances.date');
                })
                ->whereRaw(Attendance::activeStatusOnDateSql())
                ->whereDate('attendances.date', '>=', $this->fromDate)
                ->whereDate('attendances.date', '<=', $this->toDate)
                ->select(
                    'attendances.circle_id',
                    DB::raw('DATE(attendances.date) as day'),
                    DB::raw('COUNT(attendances.id) as total'),
                    DB::raw("SUM(CASE WHEN attendances.status IN ('present', 'late') THEN 1 ELSE 0 END) as present_count"),
                    DB::raw("SUM(CASE WHEN attendances.status = 'absent' THEN 1 ELSE 0 END) as absent_count")
                )
                ->groupBy('attendances.circle_id', DB::raw('DATE(attendances.date)'))
                ->get();

            foreach ($records as $row) {
                $attendanceData[$row->circle_id][$row->day] = [
                    'total' => $row->total,
                    'present' => $row->present_count,
                    'absent' => $row->absent_count,
                ];
            }
        }

        return view('livewire.manager.attendance-reports', [
            'dates' => $dates,
            'groupedCircles' => $groupedCircles,
            'attendanceData' => $attendanceData,
        ]);
    }
}
