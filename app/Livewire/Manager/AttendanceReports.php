<?php

namespace App\Livewire\Manager;

use App\Models\Stage;
use App\Services\AttendanceReportGrid;
use App\Support\HijriDate;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf;

/**
 * The academy's attendance, circle by circle and day by day, on screen and on
 * a printed sheet alike: each day coloured by its rate, a working day nobody
 * took the roll on set apart from a day the stage does not meet, and every
 * day open to the names behind it. See AttendanceReportGrid.
 *
 * The supervisor's page extends this one, over the stages they oversee.
 */
class AttendanceReports extends Component
{
    /** The most days the screen draws; the printed sheet takes any range. */
    public const SCREEN_DAYS = 30;

    /** The role reporting here: its own day pages, and the stages it sees. */
    protected const ROLE = 'manager';

    public $fromDate;

    public $toDate;

    /**
     * The stages the manager wants to see. Empty means all of them, so the
     * report opens on the whole academy and narrows only when asked.
     *
     * @var array<int, int|string>
     */
    public array $stageIds = [];

    public function mount(): void
    {
        $this->resetDates();
    }

    /** The last seven days, today included — today in Riyadh, not in UTC. */
    private function resetDates(): void
    {
        $today = now('Asia/Riyadh');
        $this->fromDate = $today->copy()->subDays(6)->toDateString();
        $this->toDate = $today->toDateString();
    }

    public function clearStages(): void
    {
        $this->stageIds = [];
    }

    public function clearFilters(): void
    {
        $this->resetDates();
    }

    /**
     * The day of the month on its own — the month and the year are already
     * spelled out in the row above, which spans every day that shares them.
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

    /**
     * The stages the page may read: none named means every stage.
     *
     * @return array<int, int>
     */
    protected function readableStageIds(): array
    {
        return [];
    }

    /**
     * The stages the page offers to pick from.
     *
     * @return Collection<int, Stage>
     */
    protected function reportableStages(): Collection
    {
        return Stage::get(['id', 'name']);
    }

    /**
     * The stages a report reads: those picked among the page's own, or all
     * of the page's when none is.
     *
     * @return array<int, int>
     */
    private function reportedStageIds(): array
    {
        $picked = array_map('intval', $this->stageIds);
        $readable = $this->readableStageIds();

        if ($readable === []) {
            return $picked;
        }

        return array_values(array_intersect($picked, $readable)) ?: $readable;
    }

    /**
     * What the printed sheet says it covers — all of it, or the chosen few.
     */
    private function chosenStageNames(): string
    {
        $stageIds = $this->reportedStageIds();

        if ($stageIds === []) {
            return 'كل المراحل';
        }

        return Stage::whereIn('id', $stageIds)->pluck('name')->implode('، ');
    }

    /** The name of the total row: the whole academy, or the stages overseen. */
    private function totalLabel(): string
    {
        return $this->readableStageIds() === [] ? 'المجمع' : 'الإجمالي';
    }

    /** What is wrong with the chosen range, if anything. */
    private function rangeProblem(): ?string
    {
        if (! $this->fromDate || ! $this->toDate) {
            return 'حدد نطاق التاريخ لعرض تقرير الحضور.';
        }

        if ($this->fromDate > $this->toDate) {
            return 'تاريخ البداية بعد تاريخ النهاية؛ بدّل بينهما.';
        }

        return null;
    }

    public function downloadPDF()
    {
        if ($problem = $this->rangeProblem()) {
            Flux::toast($problem, variant: 'warning');

            return null;
        }

        $pdf = LaravelMpdf::loadView('pdf.attendance-report', [
            'grid' => AttendanceReportGrid::build($this->fromDate, $this->toDate, $this->reportedStageIds()),
            'fromDate' => $this->fromDate,
            'toDate' => $this->toDate,
            'stageNames' => $this->chosenStageNames(),
            'totalLabel' => $this->totalLabel(),
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
        $problem = $this->rangeProblem();
        $days = $problem ? 0 : (int) Carbon::parse($this->fromDate)->diffInDays(Carbon::parse($this->toDate)) + 1;

        // Every stage is read at once, for the range: picking stages then
        // only shows and hides rows in the browser, with no request at all.
        $grid = ! $problem && $days <= self::SCREEN_DAYS
            ? AttendanceReportGrid::build($this->fromDate, $this->toDate, $this->readableStageIds())
            : null;

        return view('livewire.manager.attendance-reports', [
            'problem' => $problem,
            'dayCount' => $days,
            'grid' => $grid,
            // What the page shows first, for the stages already picked; the
            // browser works the same out from `circleTotals` as picks change.
            'selection' => $grid ? AttendanceReportGrid::select($grid, $this->stageIds) : null,
            'circleTotals' => $grid ? collect($grid['groups'])->flatMap(fn (array $group) => $group['circles'])
                ->map(fn (array $row) => [
                    'stage' => $row['stage_id'],
                    'name' => $row['circle']->name,
                    'present' => $row['totals']['present'],
                    'counted' => $row['totals']['counted'],
                    'missing' => $row['totals']['missing'],
                    'unmarked' => $row['totals']['unmarked'],
                    'rate' => $row['totals']['rate'],
                ])->values()->all() : [],
            'stages' => $this->reportableStages(),
            'listRoute' => static::ROLE.'.attendance-list',
            'totalLabel' => $this->totalLabel(),
        ]);
    }
}
