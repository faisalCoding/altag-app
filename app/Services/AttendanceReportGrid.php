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
     * @return array{dates: array<int, array{date: string, today: bool, future: bool}>, groups: array<int, array{stage: string, circles: array<int, array{circle: Circle, cells: array<string, array<string, mixed>>, totals: array<string, mixed>}>}>, days: array<string, array{rate: ?int, present: int, counted: int, missing: int}>, summary: array{rate: ?int, missing: int, missing_circles: int, unmarked: int, worst: ?array{name: string, rate: int}}}
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

        $days = collect($dates)->mapWithKeys(fn (array $day) => [$day['date'] => ['present' => 0, 'counted' => 0, 'missing' => 0]])->all();
        $summary = ['present' => 0, 'counted' => 0, 'missing' => 0, 'missing_circles' => [], 'unmarked' => 0];
        $rows = [];

        foreach ($circles as $circle) {
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
                $cell['rate'] = $counted > 0 ? (int) round($cell['present'] / $counted * 100) : null;
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
                    $days[$date]['missing']++;
                    $summary['missing_circles'][$circle->id] = true;
                }

                foreach (['present', 'late', 'absent', 'excused', 'unmarked'] as $key) {
                    $totals[$key] += $cell[$key];
                }
                $days[$date]['present'] += $cell['present'];
                $days[$date]['counted'] += $counted;
                $cells[$date] = $cell;
            }

            $counted = $totals['present'] + $totals['absent'];
            $totals['rate'] = $counted > 0 ? (int) round($totals['present'] / $counted * 100) : null;

            $summary['present'] += $totals['present'];
            $summary['counted'] += $counted;
            $summary['missing'] += $totals['missing'];
            $summary['unmarked'] += $totals['unmarked'];

            $rows[] = ['circle' => $circle, 'cells' => $cells, 'totals' => $totals];
        }

        // The circle that missed most, among those with a week's worth of records.
        $worst = collect($rows)
            ->filter(fn (array $row) => $row['totals']['rate'] !== null && $row['totals']['rate'] < 100
                && $row['totals']['present'] + $row['totals']['absent'] >= 5)
            ->sortBy(fn (array $row) => $row['totals']['rate'])
            ->first();

        return [
            'dates' => $dates,
            'groups' => collect($rows)
                ->groupBy(fn (array $row) => $row['circle']->stage->name ?? 'بدون مرحلة')
                ->map(fn (Collection $circles, string $stage) => ['stage' => $stage, 'circles' => $circles->values()->all()])
                ->values()
                ->all(),
            'days' => collect($days)->map(fn (array $day) => $day + [
                'rate' => $day['counted'] > 0 ? (int) round($day['present'] / $day['counted'] * 100) : null,
            ])->all(),
            'summary' => [
                'rate' => $summary['counted'] > 0 ? (int) round($summary['present'] / $summary['counted'] * 100) : null,
                'missing' => $summary['missing'],
                'missing_circles' => count($summary['missing_circles']),
                'unmarked' => $summary['unmarked'],
                'worst' => $worst ? ['name' => $worst['circle']->name, 'rate' => $worst['totals']['rate']] : null,
            ],
        ];
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
