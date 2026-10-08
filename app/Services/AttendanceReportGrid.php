<?php

namespace App\Services;

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The manager's attendance report, circle by circle and day by day, read the
 * same for the screen and the printed sheet.
 *
 * Each cell measures a day against the students who should have been on that
 * day's roll — the ones the teacher's roll call lists (in the circle, joined,
 * active that day), and anyone recorded there — so a student left unmarked
 * shows as such rather than shrinking the count. Its rate is those present
 * (late included) over those present or absent: an excused day is no absence.
 * A day the stage does not meet is told apart from a working day nobody took
 * the roll on, and today, whose roll may still be called.
 */
final class AttendanceReportGrid
{
    /**
     * @param  array<int, int|string>  $stageIds  none means every stage
     * @return array{dates: array<int, array{date: string, today: bool, future: bool}>, groups: array<int, array{stage_id: int, stage: string, circles: array<int, array{circle: Circle, stage_id: int, cells: array<string, array<string, mixed>>, totals: array<string, mixed>}>}>, days_by_stage: array<int, array<string, array{present: int, counted: int, missing: int}>>, days: array<string, array{rate: ?int, present: int, counted: int, missing: int}>, summary: array{rate: ?int, missing: int, missing_circles: int, unmarked: int, worst: ?array{name: string, rate: int}}}
     */
    public static function build(string $from, string $to, array $stageIds = []): array
    {
        $today = now('Asia/Riyadh')->format('Y-m-d');
        $dates = [];
        for ($day = Carbon::parse($from); $day->format('Y-m-d') <= $to; $day->addDay()) {
            $date = $day->format('Y-m-d');
            $dates[] = ['date' => $date, 'today' => $date === $today, 'future' => $date > $today];
        }

        $circles = self::circles($stageIds);
        $circleIds = $circles->pluck('id');

        // The records every attendance report counts: taken once the student
        // had joined, on a day they were active.
        $recorded = [];
        Attendance::query()
            ->join('users as students', 'attendances.student_id', '=', 'students.id')
            ->where(fn ($q) => $q->whereNull('students.joined_at')->orWhereRaw('date(students.joined_at) <= date(attendances.date)'))
            ->whereRaw(Attendance::activeStatusOnDateSql())
            ->whereIn('attendances.circle_id', $circleIds)
            ->whereDate('attendances.date', '>=', $from)
            ->whereDate('attendances.date', '<=', $to)
            ->get(['attendances.student_id', 'attendances.circle_id', 'attendances.date', 'attendances.status'])
            ->each(function (Attendance $record) use (&$recorded) {
                $recorded[$record->circle_id][$record->date->format('Y-m-d')][$record->student_id] = $record->status;
            });

        $members = Student::query()
            ->whereIn('circle_id', $circleIds)
            ->whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->with(['statusHistories' => fn ($q) => $q->reorder('start_date')->orderBy('id')])
            ->get()
            ->groupBy('circle_id');

        $working = [];
        $isWorking = function (?int $stageId, string $date) use (&$working): bool {
            return $working[$stageId ?? 0][$date] ??= AcademicCalendarEvent::isWorkingDay($date, $stageId);
        };

        $byStage = [];
        $rows = [];

        foreach ($circles as $circle) {
            $stageKey = $circle->stage_id ?? 0;
            $byStage[$stageKey] ??= collect($dates)->mapWithKeys(fn (array $day) => [$day['date'] => ['present' => 0, 'counted' => 0, 'missing' => 0]])->all();
            $totals = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0, 'unmarked' => 0, 'missing' => 0];
            $cells = [];

            foreach ($dates as $day) {
                $date = $day['date'];
                $marks = $recorded[$circle->id][$date] ?? [];
                $expected = collect($members->get($circle->id, collect()))
                    ->filter(fn (Student $student) => self::onRoll($student, $date))
                    ->pluck('id')
                    ->merge(array_keys($marks))
                    ->unique()
                    ->count();

                $counts = array_count_values($marks);
                $cell = [
                    'present' => ($counts['present'] ?? 0) + ($counts['late'] ?? 0),
                    'late' => $counts['late'] ?? 0,
                    'absent' => $counts['absent'] ?? 0,
                    'excused' => $counts['excused'] ?? 0,
                    'expected' => $expected,
                    'unmarked' => $marks === [] ? 0 : $expected - count($marks),
                ];
                $counted = $cell['present'] + $cell['absent'];
                $cell['rate'] = self::rate($cell['present'], $counted);
                $cell['state'] = match (true) {
                    $marks !== [] => 'data',
                    $day['future'] => 'future',
                    ! $isWorking($circle->stage_id, $date) => 'off',
                    $expected === 0 => 'empty',
                    // Today's roll may still be called.
                    $day['today'] => 'pending',
                    default => 'missing',
                };

                if ($cell['state'] === 'missing') {
                    $totals['missing']++;
                    $byStage[$stageKey][$date]['missing']++;
                }

                foreach (['present', 'late', 'absent', 'excused', 'unmarked'] as $key) {
                    $totals[$key] += $cell[$key];
                }
                $byStage[$stageKey][$date]['present'] += $cell['present'];
                $byStage[$stageKey][$date]['counted'] += $counted;
                $cells[$date] = $cell;
            }

            $totals['counted'] = $totals['present'] + $totals['absent'];
            $totals['rate'] = self::rate($totals['present'], $totals['counted']);

            $rows[] = ['circle' => $circle, 'stage_id' => $stageKey, 'cells' => $cells, 'totals' => $totals];
        }

        $grid = [
            'dates' => $dates,
            'groups' => collect($rows)
                ->groupBy('stage_id')
                ->map(fn (Collection $circles) => [
                    'stage_id' => $circles->first()['stage_id'],
                    'stage' => $circles->first()['circle']->stage->name ?? 'بدون مرحلة',
                    'circles' => $circles->values()->all(),
                ])
                ->values()
                ->all(),
            'days_by_stage' => $byStage,
        ];

        return $grid + self::select($grid, []);
    }

    /**
     * The academy's line and summary over the chosen stages — all of them when
     * none is chosen: each day's rate and missed roll calls, and the period's
     * rate, missed roll calls, unmarked students and the circle that missed
     * most. The page works the same out in the browser as stages are picked
     * (see the report's view), so a pick costs no request.
     *
     * @param  array{dates: array<int, array{date: string}>, groups: array<int, array{stage_id: int, circles: array<int, array<string, mixed>>}>, days_by_stage: array<int, array<string, array{present: int, counted: int, missing: int}>>}  $grid
     * @param  array<int, int|string>  $stageIds
     * @return array{days: array<string, array{rate: ?int, present: int, counted: int, missing: int}>, summary: array{rate: ?int, missing: int, missing_circles: int, unmarked: int, worst: ?array{name: string, rate: int}}}
     */
    public static function select(array $grid, array $stageIds): array
    {
        $picked = array_map('intval', $stageIds);
        $shown = fn (int $stageKey) => $picked === [] || in_array($stageKey, $picked, true);

        $days = [];
        foreach ($grid['dates'] as $day) {
            $total = ['present' => 0, 'counted' => 0, 'missing' => 0];
            foreach ($grid['days_by_stage'] as $stageKey => $stageDays) {
                if ($shown((int) $stageKey)) {
                    foreach ($total as $key => $value) {
                        $total[$key] += $stageDays[$day['date']][$key];
                    }
                }
            }
            $days[$day['date']] = $total + ['rate' => self::rate($total['present'], $total['counted'])];
        }

        $rows = collect($grid['groups'])
            ->filter(fn (array $group) => $shown((int) $group['stage_id']))
            ->flatMap(fn (array $group) => $group['circles']);

        // The circle that missed most, among those with a week's worth of records.
        $worst = $rows
            ->filter(fn (array $row) => $row['totals']['rate'] !== null && $row['totals']['rate'] < 100 && $row['totals']['counted'] >= 5)
            ->sortBy(fn (array $row) => $row['totals']['rate'])
            ->first();

        return [
            'days' => $days,
            'summary' => [
                'rate' => self::rate($rows->sum('totals.present'), $rows->sum('totals.counted')),
                'missing' => $rows->sum('totals.missing'),
                'missing_circles' => $rows->filter(fn (array $row) => $row['totals']['missing'] > 0)->count(),
                'unmarked' => $rows->sum('totals.unmarked'),
                'worst' => $worst ? ['name' => $worst['circle']->name, 'rate' => $worst['totals']['rate']] : null,
            ],
        ];
    }

    private static function rate(int $present, int $counted): ?int
    {
        return $counted > 0 ? (int) round($present / $counted * 100) : null;
    }

    /**
     * Whether the teacher's roll call lists the student on a day: joined by
     * then, and active by the last status change on or before it.
     */
    public static function onRoll(Student $student, string $date): bool
    {
        if ($student->joined_at && $student->joined_at->format('Y-m-d') > $date) {
            return false;
        }

        // Sorted here: the relation loads newest first unless told otherwise.
        $change = $student->statusHistories
            ->filter(fn ($history) => $history->start_date->format('Y-m-d') <= $date)
            ->sortBy(fn ($history) => $history->start_date->format('Y-m-d').'|'.str_pad((string) $history->id, 12, '0', STR_PAD_LEFT))
            ->last();

        return ($change?->status ?? $student->status) === 'active';
    }

    /**
     * The circles, stage by stage, in the order the academy arranged its
     * stages; circles with no stage last.
     *
     * @param  array<int, int|string>  $stageIds
     * @return Collection<int, Circle>
     */
    private static function circles(array $stageIds): Collection
    {
        return Circle::with('stage')
            ->leftJoin('stages', 'stages.id', '=', 'circles.stage_id')
            ->when($stageIds !== [], fn ($q) => $q->whereIn('circles.stage_id', $stageIds))
            ->select('circles.*')
            ->orderByRaw('stages.position is null, stages.position')
            ->orderBy('stages.name')
            ->orderBy('circles.name')
            ->get();
    }
}
