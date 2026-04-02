<?php

namespace App\Livewire\Manager;

use Livewire\Component;
use App\Models\Attendance;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AttendanceReports extends Component
{
    public $tab = 'by_date'; // 'by_date' for Overall, 'by_tree' for Stage/Circle
    public $viewType = 'days'; // 'days' or 'months'
    public $fromDate;
    public $toDate;

    // PDF Print properties
    public $printFrom;
    public $printTo;
    public $showPrintModal = false;

    public function mount()
    {
        // Default to last 30 days if needed, but here we'll leave empty for all history
        // Or set default to start of current month
    }

    public function formatHijriDate($gregorianDate)
    {
        if (!$gregorianDate) return '';
        $formatter = new \IntlDateFormatter(
            'ar_SA@calendar=islamic-umalqura',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'Asia/Riyadh',
            \IntlDateFormatter::TRADITIONAL,
            'EEEE d MMMM yyyy'
        );
        return $formatter->format(is_string($gregorianDate) ? strtotime($gregorianDate) : $gregorianDate);
    }

    public function formatHijriMonth($dateStr)
    {
        if (!$dateStr) return '';
        // If dateStr is YYYY-MM, append -01
        if (strlen($dateStr) === 7) {
            $dateStr .= '-01';
        }
        $formatter = new \IntlDateFormatter(
            'ar_SA@calendar=islamic-umalqura',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'Asia/Riyadh',
            \IntlDateFormatter::TRADITIONAL,
            'MMMM yyyy'
        );
        return $formatter->format(strtotime($dateStr));
    }

    public function clearFilters()
    {
        $this->fromDate = null;
        $this->toDate = null;
        $this->viewType = 'days';
    }

    public function downloadPDF()
    {
        $this->validate([
            'printFrom' => 'required|date',
            'printTo' => 'required|date|after_or_equal:printFrom',
        ]);

        $startDate = Carbon::parse($this->printFrom);
        $endDate = Carbon::parse($this->printTo);
        $dates = [];
        for ($d = $startDate->copy(); $d->lte($endDate); $d->addDay()) {
            $dates[] = $d->format('Y-m-d');
        }

        $circles = \App\Models\Circle::with('stage')->get();

        $attendances = Attendance::whereBetween('date', [$this->printFrom, $this->printTo])
            ->select('circle_id', 'date', DB::raw('COUNT(*) as total'), DB::raw("SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count"))
            ->groupBy('circle_id', 'date')
            ->get();

        $attendanceData = [];
        foreach ($attendances as $att) {
            $dateStr = $att->date instanceof \Carbon\Carbon ? $att->date->format('Y-m-d') : substr((string) $att->date, 0, 10);
            $attendanceData[$att->circle_id][$dateStr] = [
                'present' => $att->present_count,
                'total' => $att->total
            ];
        }

        $groupedCircles = $circles->groupBy('stage.name');

        $pdf = \Mccarlosen\LaravelMpdf\Facades\LaravelMpdf::loadView('pdf.attendance-report', [
            'dates' => $dates,
            'groupedCircles' => $groupedCircles,
            'attendanceData' => $attendanceData,
            'printFrom' => $this->printFrom,
            'printTo' => $this->printTo,
        ], [], [
            'format' => 'A4-L',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'useSubstitutions' => true,
            'useAdobeCJK' => true,
        ]);

        $this->showPrintModal = false;

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, 'attendance_report.pdf');
    }

    public function render()
    {
        $query = Attendance::query();

        if ($this->fromDate) {
            $query->where('date', '>=', $this->fromDate);
        }
        if ($this->toDate) {
            $query->where('date', '<=', $this->toDate);
        }

        // --- 1. Overall Reports (By Date or By Month) ---
        $overallQuery = clone $query;
        
        if ($this->viewType === 'months') {
            // Group by month using SQLite strftime
            $overallQuery->select(
                DB::raw("strftime('%Y-%m', date) as period"),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count"),
                DB::raw("SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count"),
                DB::raw("SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count"),
                DB::raw("SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_count")
            )->groupBy('period')->orderBy('period', 'desc');
        } else {
            $overallQuery->select(
                'date as period',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count"),
                DB::raw("SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count"),
                DB::raw("SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count"),
                DB::raw("SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_count")
            )->groupBy('date')->orderBy('date', 'desc');
        }

        $reportsByPeriod = $overallQuery->get();

        // --- 2. Tree Query (Stage -> Period -> Circle) ---
        $treeQuery = clone $query;
        $treeQuery->join('circles', 'attendances.circle_id', '=', 'circles.id')
            ->join('stages', 'circles.stage_id', '=', 'stages.id');

        if ($this->viewType === 'months') {
            $treeQuery->select(
                DB::raw("strftime('%Y-%m', date) as period"),
                'stages.id as stage_id',
                'stages.name as stage_name',
                'circles.id as circle_id',
                'circles.name as circle_name',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count"),
                DB::raw("SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count"),
                DB::raw("SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count"),
                DB::raw("SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_count")
            )->groupBy('period', 'stages.id', 'stages.name', 'circles.id', 'circles.name')
            ->orderBy('stages.id')->orderBy('period', 'desc')->orderBy('circles.id');
        } else {
            $treeQuery->select(
                'date as period',
                'stages.id as stage_id',
                'stages.name as stage_name',
                'circles.id as circle_id',
                'circles.name as circle_name',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count"),
                DB::raw("SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count"),
                DB::raw("SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count"),
                DB::raw("SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_count")
            )->groupBy('date', 'stages.id', 'stages.name', 'circles.id', 'circles.name')
            ->orderBy('stages.id')->orderBy('date', 'desc')->orderBy('circles.id');
        }

        $rawRecords = $treeQuery->get();

        // Build the Tree
        $tree = [];
        foreach ($rawRecords as $row) {
            $period = $row->period;
            
            // Setup Stage
            if (!isset($tree[$row->stage_id])) {
                $tree[$row->stage_id] = [
                    'id' => $row->stage_id,
                    'name' => $row->stage_name,
                    'stats' => ['total' => 0, 'present_count' => 0, 'absent_count' => 0, 'late_count' => 0, 'excused_count' => 0],
                    'periods' => []
                ];
            }

            // Accumulate Stage
            $tree[$row->stage_id]['stats']['total'] += $row->total;
            $tree[$row->stage_id]['stats']['present_count'] += $row->present_count;
            $tree[$row->stage_id]['stats']['absent_count'] += $row->absent_count;
            $tree[$row->stage_id]['stats']['late_count'] += $row->late_count;
            $tree[$row->stage_id]['stats']['excused_count'] += $row->excused_count;

            // Setup Period within Stage
            if (!isset($tree[$row->stage_id]['periods'][$period])) {
                $tree[$row->stage_id]['periods'][$period] = [
                    'period' => $period,
                    'stats' => ['total' => 0, 'present_count' => 0, 'absent_count' => 0, 'late_count' => 0, 'excused_count' => 0],
                    'circles' => []
                ];
            }

            // Accumulate Period
            $tree[$row->stage_id]['periods'][$period]['stats']['total'] += $row->total;
            $tree[$row->stage_id]['periods'][$period]['stats']['present_count'] += $row->present_count;
            $tree[$row->stage_id]['periods'][$period]['stats']['absent_count'] += $row->absent_count;
            $tree[$row->stage_id]['periods'][$period]['stats']['late_count'] += $row->late_count;
            $tree[$row->stage_id]['periods'][$period]['stats']['excused_count'] += $row->excused_count;

            // Add Circle
            $tree[$row->stage_id]['periods'][$period]['circles'][] = [
                'circle_id' => $row->circle_id,
                'circle_name' => $row->circle_name,
                'total' => $row->total,
                'present_count' => $row->present_count,
                'absent_count' => $row->absent_count,
                'late_count' => $row->late_count,
                'excused_count' => $row->excused_count,
            ];
        }

        return view('livewire.manager.attendance-reports', [
            'reportsByPeriod' => $reportsByPeriod,
            'reportsTree' => collect($tree),
        ]);
    }
}
