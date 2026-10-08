<?php

namespace App\Livewire\Shared;

use App\Models\AcademicCalendarEvent;
use App\Models\Stage;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use App\Support\HijriDate;
use App\Support\TeacherRollScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf;

/**
 * What the teachers' roll calls add up to over a period — the supervisor's
 * over their stages, the manager's over the academy.
 *
 * Each teacher's days are counted against the working days of their stage,
 * so a day nobody marked shows as a gap rather than vanishing. The follow-up
 * grid turns that around: stage by stage and day by day, whether the roll
 * call was taken at all.
 */
class TeacherAttendanceReport extends Component
{
    /** The day-by-day grids stop being readable much past two months of columns. */
    public const FOLLOW_UP_MAX_DAYS = 62;

    /** What a teacher's day says in the grid, and what it is called. */
    public const STATUS_LETTERS = ['present' => 'ح', 'late' => 'ت', 'excused' => 'س', 'absent' => 'غ'];

    public const STATUS_LABELS = ['present' => 'حاضر', 'late' => 'متأخر', 'excused' => 'مستأذن', 'absent' => 'غائب'];

    #[Locked]
    public string $role = 'supervisor';

    public string $fromDate = '';

    public string $toDate = '';

    /** @var array<int, int|string> Empty means every stage the scope reaches. */
    public array $stageIds = [];

    private ?TeacherRollScope $scopeCache = null;

    public function mount(string $role = 'supervisor'): void
    {
        $this->role = $role;
        $this->scope();

        $today = $this->today();
        // The Hijri month so far: the period the academy counts its months by.
        $this->fromDate = HijriDate::months($today, 1)[0]['first_day'];
        $this->toDate = $today;
    }

    public function clearStages(): void
    {
        $this->stageIds = [];
    }

    /**
     * Everything the page and the printed sheet read.
     *
     * @return array{from: string, to: string, rows: Collection<int, array<string, mixed>>, totals: array<string, int|null>, days: array<int, string>|null, dayTotals: array<string, array<string, int|null>>, followUp: array<string, mixed>|null}
     */
    private function report(): array
    {
        [$from, $to] = $this->period();
        $chosen = $this->scope()->narrow($this->stageIds);

        $teachers = $this->scope()->teachers($from, $to, $this->stageIds === [] ? null : $chosen);

        $records = TeacherAttendance::whereIn('teacher_id', $teachers->modelKeys())
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            // The manager with nothing chosen reads every day, those of a
            // teacher with no circle among them; anyone else, the chosen stages'.
            ->when($this->role === 'supervisor' || $this->stageIds !== [], fn ($q) => $q->whereIn('stage_id', $chosen))
            ->with('substitute:id,name')
            ->orderBy('date')
            ->get();

        $workingDays = $this->workingDays($from, $to, $chosen);
        $byTeacher = $records->groupBy('teacher_id');

        // The teachers' grid: a column for each working day of the chosen
        // stages, and any other day someone was marked — none when the period
        // is too long to lay out as columns.
        $days = $this->tooLongForColumns($from, $to) ? null : collect($workingDays)
            ->flatMap(fn (array $set) => array_keys($set))
            ->merge($records->map(fn (TeacherAttendance $record) => $record->date->format('Y-m-d')))
            ->unique()->sort()->values()->all();

        $rows = $teachers
            ->map(fn (Teacher $teacher) => $this->row($teacher, $byTeacher->get($teacher->id, collect()), $workingDays, $chosen, $days))
            ->values();

        $present = $rows->sum('present') + $rows->sum('late');
        $counted = $present + $rows->sum('absent');

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => [
                'present' => $rows->sum('present'),
                'late' => $rows->sum('late'),
                'excused' => $rows->sum('excused'),
                'absent' => $rows->sum('absent'),
                'unrecorded' => $rows->sum('unrecorded'),
                'rate' => $counted > 0 ? (int) round($present / $counted * 100) : null,
            ],
            'days' => $days,
            'dayTotals' => $this->dayTotals($rows, $days ?? []),
            'followUp' => $this->followUp($from, $to, $chosen, $rows, $records, $workingDays),
        ];
    }

    /**
     * Each day's column summed: the teachers present (late ones included) of
     * those marked present or absent, and the ones owed that day nobody marked.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $days
     * @return array<string, array{present: int, counted: int, missing: int, rate: ?int}>
     */
    private function dayTotals(Collection $rows, array $days): array
    {
        return collect($days)->mapWithKeys(function (string $day) use ($rows) {
            $states = $rows->map(fn (array $row) => $row['cells'][$day]['state']);
            $present = $states->filter(fn (string $state) => in_array($state, ['present', 'late'], true))->count();
            $counted = $present + $states->filter(fn (string $state) => $state === 'absent')->count();

            return [$day => [
                'present' => $present,
                'counted' => $counted,
                'missing' => $states->filter(fn (string $state) => $state === 'missing')->count(),
                'rate' => $counted > 0 ? (int) round($present / $counted * 100) : null,
            ]];
        })->all();
    }

    private function tooLongForColumns(string $from, string $to): bool
    {
        return Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1 > self::FOLLOW_UP_MAX_DAYS;
    }

    /**
     * One teacher's period. The rate leaves out the days they were excused:
     * a leave granted is not an absence.
     *
     * The days owed are those the roll call lists the teacher on: the working
     * days of their circles' stages. The manager's whole academy lists every
     * teacher every day, one with no circle too, so such a teacher owes every
     * stage's days; anywhere else a teacher off the chosen stages' circles —
     * one who has moved on — is here only for the days they were marked, and
     * owes no others. Today is not owed yet: its roll may still be called.
     *
     * The cells are the teacher's grid line: each day's status, or owed and
     * unmarked (pending, today), or not owed at all; with what was said about
     * a day off the usual, for the cell's title.
     *
     * @param  Collection<int, TeacherAttendance>  $records
     * @param  array<int, array<string, true>>  $workingDays
     * @param  array<int, int>  $chosen
     * @param  array<int, string>|null  $gridDays
     * @return array<string, mixed>
     */
    private function row(Teacher $teacher, Collection $records, array $workingDays, array $chosen, ?array $gridDays = null): array
    {
        $wholeAcademy = $this->role === 'manager' && $this->stageIds === [];
        $stages = array_values(array_intersect($this->scope()->circleStages($teacher), $chosen))
            ?: ($wholeAcademy ? $chosen : []);
        $days = collect($stages)->flatMap(fn (int $stageId) => array_keys($workingDays[$stageId] ?? []))->unique();
        $today = $this->today();

        $marked = $records->map(fn (TeacherAttendance $record) => $record->date->format('Y-m-d'))->flip();
        $counts = $records->countBy('status');
        $present = ($counts['present'] ?? 0) + ($counts['late'] ?? 0);
        $counted = $present + ($counts['absent'] ?? 0);

        return [
            'teacher' => $teacher,
            'circles' => $teacher->circles->pluck('name')->implode('، '),
            'working' => $days->count(),
            'present' => $counts['present'] ?? 0,
            'late' => $counts['late'] ?? 0,
            'excused' => $counts['excused'] ?? 0,
            'absent' => $counts['absent'] ?? 0,
            'unrecorded' => $days->reject(fn (string $day) => $marked->has($day) || $day >= $today)->count(),
            'rate' => $counted > 0 ? (int) round($present / $counted * 100) : null,
            // What needs a second look: every day that was not a plain "present".
            'away' => $records->where('status', '!=', 'present')->sortByDesc('date')->values(),
            'cells' => $gridDays === null ? null : $this->cells($records, $days->flip(), $gridDays, $today),
        ];
    }

    /**
     * @param  Collection<int, TeacherAttendance>  $records
     * @param  Collection<string, int>  $owed
     * @param  array<int, string>  $gridDays
     * @return array<string, array{state: string, title: ?string}>
     */
    private function cells(Collection $records, Collection $owed, array $gridDays, string $today): array
    {
        $byDay = $records->keyBy(fn (TeacherAttendance $record) => $record->date->format('Y-m-d'));

        return collect($gridDays)->mapWithKeys(function (string $day) use ($byDay, $owed, $today) {
            $record = $byDay->get($day);

            if ($record) {
                $said = array_filter([
                    self::STATUS_LABELS[$record->status] ?? $record->status,
                    $record->status === 'late' && $record->arrived_at
                        ? 'حضر الساعة '.$record->arrivalLabel().(($minutes = $record->minutesLate()) ? '، متأخراً '.HijriDate::arabicDigits($minutes).' دقيقة' : '')
                        : null,
                    $record->notes ? 'السبب: '.$record->notes : null,
                    $record->substitute && in_array($record->status, TeacherAttendance::AWAY, true) ? 'البديل: '.$record->substitute->name : null,
                ]);

                return [$day => [
                    'state' => $record->status,
                    // A plain «present» needs no second look.
                    'title' => $record->status === 'present' && count($said) === 1 ? null : HijriDate::withWeekday($day).': '.implode('، ', $said),
                ]];
            }

            return [$day => match (true) {
                ! $owed->has($day) => ['state' => 'off', 'title' => null],
                $day >= $today => ['state' => 'pending', 'title' => HijriDate::withWeekday($day).': لم يُحضَّر بعد'],
                default => ['state' => 'missing', 'title' => HijriDate::withWeekday($day).': لم يُحضَّر'],
            }];
        })->all();
    }

    /**
     * Stage by stage and day by day, how many of the stage's teachers were
     * marked — those teachers, not any record filed under the stage: a teacher
     * who has moved on, still filed there, made a day look complete while one
     * of the stage's own went unmarked. Null when the period is too long to
     * lay out as columns.
     *
     * @param  array<int, int>  $chosen
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, TeacherAttendance>  $records
     * @param  array<int, array<string, true>>  $workingDays
     * @return array{days: array<int, string>, stages: array<int, array<string, mixed>>}|null
     */
    private function followUp(string $from, string $to, array $chosen, Collection $rows, Collection $records, array $workingDays): ?array
    {
        if ($this->tooLongForColumns($from, $to)) {
            return null;
        }

        $days = collect($workingDays)->flatMap(fn (array $set) => array_keys($set))->unique()->sort()->values()->all();
        $markedOn = $records->groupBy(fn (TeacherAttendance $record) => $record->date->format('Y-m-d'))
            ->map(fn (Collection $day) => $day->pluck('teacher_id')->flip());

        $stages = Stage::whereIn('id', $chosen)->with('supervisors:id,name')->get()
            ->map(function (Stage $stage) use ($rows, $days, $markedOn, $workingDays) {
                $teacherIds = $rows
                    ->filter(fn (array $row) => in_array($stage->id, $this->scope()->circleStages($row['teacher']), true))
                    ->map(fn (array $row) => $row['teacher']->id);
                $expected = $teacherIds->count();

                return [
                    'name' => $stage->name,
                    'supervisors' => $stage->supervisors->pluck('name')->implode('، '),
                    'expected' => $expected,
                    'cells' => collect($days)->mapWithKeys(fn (string $day) => [$day => isset($workingDays[$stage->id][$day])
                        ? ['marked' => $teacherIds->filter(fn (int $id) => isset($markedOn[$day][$id]))->count(), 'expected' => $expected]
                        : null])->all(),
                ];
            })
            ->filter(fn (array $stage) => $stage['expected'] > 0)
            ->values()
            ->all();

        return ['days' => $days, 'stages' => $stages];
    }

    /**
     * Each chosen stage's working days in the period, as sets.
     *
     * @param  array<int, int>  $stageIds
     * @return array<int, array<string, true>>
     */
    private function workingDays(string $from, string $to, array $stageIds): array
    {
        return collect($stageIds)
            ->mapWithKeys(fn (int $stageId) => [$stageId => array_fill_keys(AcademicCalendarEvent::workingDaysBetween($from, $to, $stageId), true)])
            ->all();
    }

    /**
     * The period as asked, put in order and stopped at today — a day not yet
     * come has nothing to count.
     *
     * @return array{0: string, 1: string}
     */
    private function period(): array
    {
        $today = $this->today();
        $from = $this->validDate($this->fromDate) ?? $today;
        $to = min($this->validDate($this->toDate) ?? $today, $today);

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    private function validDate(string $date): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        return Carbon::createFromFormat('!Y-m-d', $date)->format('Y-m-d') === $date ? $date : null;
    }

    private function stageNames(): string
    {
        return $this->stageIds === []
            ? ($this->role === 'manager' ? 'كل المراحل' : 'كل مراحلك')
            : Stage::whereIn('id', $this->scope()->narrow($this->stageIds))->pluck('name')->implode('، ');
    }

    public function downloadPdf()
    {
        $report = $this->report();

        $pdf = LaravelMpdf::loadView('pdf.teacher-attendance-report', $report + [
            'stageNames' => $this->stageNames(),
        ], [], [
            'format' => 'A4-L',
            'default_font' => 'lamasans',
            // Left on, these hand the Arabic to a font mPDF picks itself.
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
            'useSubstitutions' => false,
        ]);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, 'teacher_attendance_report.pdf');
    }

    private function scope(): TeacherRollScope
    {
        return $this->scopeCache ??= TeacherRollScope::for($this->role);
    }

    private function today(): string
    {
        return now('Asia/Riyadh')->format('Y-m-d');
    }

    public function render()
    {
        return view('livewire.shared.teacher-attendance-report', $this->report() + [
            'stages' => Stage::whereIn('id', $this->scope()->stageIds())->get(['id', 'name']),
            'today' => $this->today(),
            'rollRoute' => $this->role.'.teacher-attendance',
        ]);
    }
}
