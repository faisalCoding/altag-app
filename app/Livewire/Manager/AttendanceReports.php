<?php

namespace App\Livewire\Manager;

use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Stage;
use App\Support\HijriDate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * The stages the manager wants to see. Empty means all of them, so the
     * report opens on the whole academy and narrows only when asked.
     *
     * @var array<int, int|string>
     */
    public array $stageIds = [];

    public function mount()
    {
        $this->fromDate = Carbon::now()->subDays(6)->toDateString();
        $this->toDate = Carbon::now()->toDateString();
    }

    public function clearStages(): void
    {
        $this->stageIds = [];
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

    /**
     * The circles, stage by stage, in the order the academy arranged its stages.
     *
     * This used to order by stage_id, which is the order the stages happened to
     * be created in — so arranging them on the stages page moved them
     * everywhere except the one report that lists them all.
     *
     * The join is what makes it work: the stage model's own ordering never
     * reaches a query that starts from circles.
     *
     * @return Collection<int, Circle>
     */
    private function circlesInAcademyOrder()
    {
        return Circle::with('stage')
            ->withCount(['students' => fn ($q) => $q->where('status', 'active')])
            ->leftJoin('stages', 'stages.id', '=', 'circles.stage_id')
            ->when($this->stageIds !== [], fn ($q) => $q->whereIn('circles.stage_id', $this->stageIds))
            ->select('circles.*')
            // Circles with no stage at all sit at the end rather than the front,
            // where a null position would otherwise put them.
            ->orderByRaw('stages.position is null, stages.position')
            ->orderBy('stages.name')
            ->orderBy('circles.name')
            ->get();
    }

    /**
     * What the printed sheet says it covers — all of it, or the chosen few.
     */
    private function chosenStageNames(): string
    {
        if ($this->stageIds === []) {
            return 'كل المراحل';
        }

        return Stage::whereIn('id', $this->stageIds)->pluck('name')->implode(' · ');
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

        $circles = $this->circlesInAcademyOrder();
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
            'stageNames' => $this->chosenStageNames(),
        ], [], [
            'format' => 'A4-L',
            'default_font' => 'lamasans',
            // Left on, these hand the Arabic to a font mPDF picks itself, and
            // the report comes out in a face the site never uses.
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
            'useSubstitutions' => false,
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
        $circles = $this->circlesInAcademyOrder();
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
            'stages' => Stage::get(['id', 'name']),
        ]);
    }
}
