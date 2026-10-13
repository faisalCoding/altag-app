<?php

namespace App\Services;

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Student;
use App\Support\HijriDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The supervisor's record of students: one row a student, one column a day,
 * each day in its attendance colour holding how many portions the student
 * recited on it — the square the teacher app's student card draws — with a
 * report under every day and beside every student.
 *
 * A portion counts on the day it was graded: each session of a plan day
 * (plan_day_attempts) and each free recitation, «لم يسمع» excepted. A day
 * whose only grade is «لم يسمع» still shows its 0, as the app does.
 *
 * The columns are the days any of the circles meets, and any day a roll was
 * taken on; a day a student's own stage does not meet, or one before they
 * joined or while they were not active, is left out of their counts.
 */
final class StudentRecordGrid
{
    public const STATUSES = ['present', 'late', 'excused', 'absent'];

    /** Short weekday names, Sunday first, as the teacher app heads its week. */
    private const WEEKDAYS = ['أحد', 'اثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت'];

    /**
     * @param  array<int, int>  $circleIds
     * @return array{
     *     dates: list<array{date: string, weekday: string, day: string, month: string, week_start: bool, today: bool}>,
     *     circles: list<array{id: int, name: string, teacher: ?string, students: list<array{id: int, name: string, cells: array<string, array{status: ?string, recited: int, graded: bool, open: bool}>, totals: array{present: int, late: int, excused: int, absent: int, unmarked: int, rate: ?int, recited: int}}>}>,
     *     days: array<string, array{present: int, late: int, excused: int, absent: int, marked: int, rate: ?int, recited: int}>,
     *     totals: array{present: int, late: int, excused: int, absent: int, unmarked: int, rate: ?int, recited: int, students: int}
     * }
     */
    public static function build(array $circleIds, string $from, string $to): array
    {
        $today = now('Asia/Riyadh')->toDateString();
        $circles = Circle::with(['stage', 'teachers:id,name'])
            ->leftJoin('stages', 'stages.id', '=', 'circles.stage_id')
            ->whereIn('circles.id', $circleIds)
            ->select('circles.*')
            ->orderByRaw('stages.position is null, stages.position')
            ->orderBy('stages.name')
            ->orderBy('circles.name')
            ->get();

        $students = Student::query()
            ->whereIn('circle_id', $circles->modelKeys())
            ->whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->with(['statusHistories' => fn ($q) => $q->reorder('start_date')->orderBy('id')])
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.circle_id', 'users.status', 'users.joined_at']);
        $studentIds = $students->modelKeys();

        $statuses = Attendance::whereIn('student_id', $studentIds)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->get(['student_id', 'date', 'status'])
            ->mapWithKeys(fn (Attendance $record) => [$record->student_id.'|'.$record->date->format('Y-m-d') => $record->status]);

        $sessions = self::sessions($studentIds, $from, $to);

        // A day is a column when any of the circles meets on it, or a roll was taken on it.
        $working = $circles->pluck('stage_id')->unique()->mapWithKeys(fn (?int $stageId) => [
            (int) $stageId => array_flip(AcademicCalendarEvent::workingDaysBetween($from, $to, $stageId)),
        ]);
        $marked = $statuses->keys()->map(fn (string $key) => substr($key, strpos($key, '|') + 1))->flip();
        $dates = collect(self::daysBetween($from, min($to, $today)))
            ->filter(fn (string $date) => $marked->has($date) || $working->contains(fn (array $days) => isset($days[$date])))
            ->values();

        $days = $dates->mapWithKeys(fn (string $date) => [$date => array_fill_keys([...self::STATUSES, 'marked', 'recited'], 0)])->all();
        $totals = array_fill_keys([...self::STATUSES, 'unmarked', 'recited', 'students'], 0);
        $byCircle = $students->groupBy('circle_id');
        $rows = [];

        foreach ($circles as $circle) {
            $stageDays = $working[(int) $circle->stage_id] ?? [];
            $members = [];

            foreach ($byCircle->get($circle->id, collect()) as $student) {
                $cells = [];
                $sums = array_fill_keys([...self::STATUSES, 'unmarked', 'recited'], 0);

                foreach ($dates as $date) {
                    $status = $statuses[$student->id.'|'.$date] ?? null;
                    $session = $sessions[$student->id][$date] ?? null;
                    // Not one of the student's days: their stage did not meet, or they were not on the roll.
                    $open = $status !== null || $session !== null || (isset($stageDays[$date]) && AttendanceReportGrid::onRoll($student, $date));

                    $cells[$date] = [
                        'status' => $status,
                        'recited' => $session['recited'] ?? 0,
                        'graded' => $session !== null,
                        'open' => $open,
                    ];

                    if ($status !== null) {
                        $sums[$status]++;
                        $days[$date][$status]++;
                        $days[$date]['marked']++;
                    } elseif ($open && $date < $today) {
                        $sums['unmarked']++;
                    }

                    $sums['recited'] += $session['recited'] ?? 0;
                    $days[$date]['recited'] += $session['recited'] ?? 0;
                }

                // Nobody to show: not on the roll on any of the days, and nothing recorded.
                if (! collect($cells)->contains('open', true)) {
                    continue;
                }

                $members[] = [
                    'id' => $student->id,
                    'name' => $student->name,
                    'cells' => $cells,
                    'totals' => $sums + ['rate' => self::rate($sums)],
                ];

                foreach ([...self::STATUSES, 'unmarked', 'recited'] as $key) {
                    $totals[$key] += $sums[$key];
                }
                $totals['students']++;
            }

            if ($members !== []) {
                $rows[] = [
                    'id' => $circle->id,
                    'name' => $circle->name,
                    'teacher' => $circle->teachers->pluck('name')->implode('، ') ?: null,
                    'students' => $members,
                ];
            }
        }

        return [
            'dates' => $dates->map(fn (string $date) => [
                'date' => $date,
                'weekday' => self::WEEKDAYS[Carbon::parse($date)->dayOfWeek],
                'day' => HijriDate::format($date, 'd'),
                'month' => HijriDate::format($date, 'MMMM'),
                // A Sunday opens a week: the table draws a firmer line before it.
                'week_start' => Carbon::parse($date)->dayOfWeek === Carbon::SUNDAY,
                'today' => $date === $today,
            ])->all(),
            'circles' => $rows,
            'days' => array_map(fn (array $day) => $day + ['rate' => self::rate($day)], $days),
            'totals' => $totals + ['rate' => self::rate($totals)],
        ];
    }

    /**
     * Each graded recitation of the students between two days, by student and
     * day: whether any was graded, and how many were recited (not «لم يسمع»).
     *
     * @param  array<int, int>  $studentIds
     * @return array<int, array<string, array{recited: int}>>
     */
    private static function sessions(array $studentIds, string $from, string $to): array
    {
        $planned = DB::table('plan_day_attempts')
            ->join('student_plan_days', 'student_plan_days.id', '=', 'plan_day_attempts.student_plan_day_id')
            ->join('student_plans', 'student_plans.id', '=', 'student_plan_days.student_plan_id')
            ->whereIn('student_plans.student_id', $studentIds)
            ->whereNotNull('plan_day_attempts.grade')
            ->whereDate('plan_day_attempts.recited_on', '>=', $from)
            ->whereDate('plan_day_attempts.recited_on', '<=', $to)
            ->get(['student_plans.student_id', 'plan_day_attempts.recited_on as date', 'plan_day_attempts.grade']);

        $free = DB::table('free_recitations')
            ->whereIn('student_id', $studentIds)
            ->whereNotNull('achievement')
            ->whereDate('recited_on', '>=', $from)
            ->whereDate('recited_on', '<=', $to)
            ->get(['student_id', 'recited_on as date', 'achievement as grade']);

        $sessions = [];

        foreach ($planned->concat($free) as $row) {
            $date = substr((string) $row->date, 0, 10);
            $sessions[$row->student_id][$date] ??= ['recited' => 0];

            if ((int) $row->grade >= 1) {
                $sessions[$row->student_id][$date]['recited']++;
            }
        }

        return $sessions;
    }

    /**
     * Present (late included) over present or absent: an excused day is no absence.
     *
     * @param  array{present: int, late: int, absent: int}  $counts
     */
    private static function rate(array $counts): ?int
    {
        $present = $counts['present'] + $counts['late'];
        $counted = $present + $counts['absent'];

        return $counted > 0 ? (int) round($present / $counted * 100) : null;
    }

    /**
     * @return list<string>
     */
    private static function daysBetween(string $from, string $to): array
    {
        $days = [];

        for ($day = Carbon::parse($from); $day->toDateString() <= $to; $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }
}
