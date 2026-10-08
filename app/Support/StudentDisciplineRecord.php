<?php

namespace App\Support;

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A student's discipline record, read so a page can draw it rather than list
 * it: where they stand against the absence and lateness limits, a Hijri month
 * of their stage's working days coloured by what was marked, the weekday that
 * keeps going wrong, and the run of days attended. The student and their
 * guardian read the same record.
 */
final class StudentDisciplineRecord
{
    public const STATUSES = ['present', 'late', 'excused', 'absent'];

    /** The academy's week, Saturday first, keyed by Carbon's dayOfWeek (0 = Sunday). */
    private const WEEKDAYS = [6 => 'السبت', 0 => 'الأحد', 1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة'];

    /**
     * Where the student stands against each limit. The counts are the ones the
     * teacher's roll call checks (User::getAbsencesInPeriodCount), over the
     * same rolling window; `frees_on` is the day the oldest of them leaves the
     * window, which is when the count next goes down.
     *
     * `counts_from` is the day the manager set counting to start from, while
     * it still cuts the window short.
     *
     * @return array{window: int, counts_from: ?string, absence: array{used: int, limit: int, frees_on: ?string}, lateness: array{used: int, limit: int, frees_on: ?string}, state: string}
     */
    public static function limits(Student $student): array
    {
        $window = DisciplineWindow::days();
        $span = DisciplineWindow::for();

        $standing = function (string $status, string $setting, int $default) use ($student, $window, $span): array {
            $dates = $student->attendances()
                ->where('status', $status)
                ->whereDate('date', '>=', $span['from'])
                ->whereDate('date', '<=', $span['to'])
                ->orderBy('date')
                ->pluck('date');

            return [
                'used' => $dates->count(),
                'limit' => max(1, (int) Setting::getVal($setting, $default)),
                'frees_on' => $dates->isEmpty() ? null : Carbon::parse($dates->first())->addDays($window)->format('Y-m-d'),
            ];
        };

        $absence = $standing('absent', 'absence_limit', 3);
        $lateness = $standing('late', 'lateness_limit', 5);
        $atLimit = fn (array $limit) => $limit['used'] >= $limit['limit'];
        $oneShort = fn (array $limit) => $limit['used'] > 0 && $limit['used'] === $limit['limit'] - 1;

        return [
            'window' => $window,
            'counts_from' => $span['from'] === DisciplineWindow::countsFrom() ? $span['from'] : null,
            'absence' => $absence,
            'lateness' => $lateness,
            'state' => match (true) {
                $atLimit($absence) || $atLimit($lateness) => 'over',
                $oneShort($absence) || $oneShort($lateness) => 'near',
                default => 'good',
            },
        ];
    }

    /**
     * One Hijri month of the record: a cell for every day of it in calendar
     * order, each telling whether it was a working day of the student's stage,
     * what was marked on it and whether it is still to come; the month's counts
     * and attendance rate so far; and the days off the usual — late, absent or
     * excused — newest first, with the note left on them.
     *
     * @return array{month: string, title: string, previous: string, next: ?string, offset: int, cells: array<int, array{date: string, day: int, status: ?string, working: bool, future: bool, today: bool, notes: ?string}>, counts: array<string, int>, rate: ?int, unrecorded: int, exceptions: Collection<int, Attendance>}
     */
    public static function month(Student $student, string $day): array
    {
        $today = now('Asia/Riyadh')->format('Y-m-d');
        $month = HijriDate::months(min($day, $today), 1)[0];
        $from = $month['first_day'];
        $after = Carbon::parse($from)->addDays($month['length'])->format('Y-m-d');
        $last = Carbon::parse($after)->subDay()->format('Y-m-d');

        $records = Attendance::where('student_id', $student->id)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $last)
            ->get()
            ->keyBy(fn (Attendance $record) => $record->date->format('Y-m-d'));

        $working = array_flip(AcademicCalendarEvent::workingDaysBetween($from, $last, self::stageId($student)));

        $cells = [];
        for ($i = 0; $i < $month['length']; $i++) {
            $date = Carbon::parse($from)->addDays($i)->format('Y-m-d');
            $record = $records->get($date);

            $cells[] = [
                'date' => $date,
                'day' => $i + 1,
                'status' => $record?->status,
                // A day marked off the calendar was still met.
                'working' => isset($working[$date]) || $record !== null,
                'future' => $date > $today,
                'today' => $date === $today,
                'notes' => $record?->notes,
            ];
        }

        $counts = collect(self::STATUSES)
            ->mapWithKeys(fn (string $status) => [$status => $records->where('status', $status)->count()])
            ->all();
        $attended = $counts['present'] + $counts['late'];
        $counted = $attended + $counts['absent'];

        return [
            'month' => $from,
            'title' => $month['title'],
            'previous' => HijriDate::months(Carbon::parse($from)->subDay()->format('Y-m-d'), 1)[0]['first_day'],
            // Nothing is marked ahead of today, so there is no month to move on to.
            'next' => $after <= $today ? $after : null,
            // Blank cells before the first day, the week starting on Saturday.
            'offset' => (Carbon::parse($from)->dayOfWeek + 1) % 7,
            'cells' => $cells,
            'counts' => $counts,
            // Present and late over the days counted; an excused day is not an absence.
            'rate' => $counted > 0 ? (int) round($attended / $counted * 100) : null,
            // Today's roll may not be called yet, so it is not missing.
            'unrecorded' => collect($cells)->filter(fn (array $cell) => $cell['working'] && $cell['date'] < $today && $cell['status'] === null)->count(),
            'exceptions' => $records->reject(fn (Attendance $record) => $record->status === 'present')
                ->sortByDesc(fn (Attendance $record) => $record->date->format('Y-m-d'))
                ->values(),
        ];
    }

    /**
     * Over the term so far: late arrivals and absences by weekday, with the
     * weekday they gather on when one stands out; and the runs of working days
     * attended — the current one and the longest. An excused day neither
     * breaks a run nor lengthens it, and neither does a day nobody marked.
     *
     * @return array{weekdays: array<int, array{label: string, late: int, absent: int}>, worst: ?array{label: string, status: string}, streak: int, best: int}
     */
    public static function habits(Student $student): array
    {
        $days = self::termDays($student);

        $records = $days === [] ? collect() : Attendance::where('student_id', $student->id)
            ->whereDate('date', '>=', $days[0])
            ->whereDate('date', '<=', $days[count($days) - 1])
            ->get(['date', 'status'])
            ->keyBy(fn (Attendance $record) => $record->date->format('Y-m-d'));

        $met = collect($days)->map(fn (string $day) => Carbon::parse($day)->dayOfWeek)->unique()->flip();
        $weekdays = collect(self::WEEKDAYS)
            ->filter(fn (string $label, int $dayOfWeek) => $met->has($dayOfWeek))
            ->map(fn (string $label) => ['label' => $label, 'late' => 0, 'absent' => 0])
            ->all();

        $streak = 0;
        $best = 0;

        foreach ($days as $day) {
            $status = $records->get($day)?->status;

            if (in_array($status, ['late', 'absent'], true)) {
                $weekdays[Carbon::parse($day)->dayOfWeek][$status]++;
            }

            if (in_array($status, ['present', 'late'], true)) {
                $best = max($best, ++$streak);
            } elseif ($status === 'absent') {
                $streak = 0;
            }
        }

        // A weekday stands out with two incidents or more, and more than any other.
        $totals = collect($weekdays)->map(fn (array $weekday) => $weekday['late'] + $weekday['absent']);
        $most = $totals->max() ?? 0;
        $worst = null;

        if ($most >= 2 && $totals->filter(fn (int $total) => $total === $most)->count() === 1) {
            $weekday = $weekdays[$totals->search($most)];
            $worst = ['label' => $weekday['label'], 'status' => $weekday['late'] >= $weekday['absent'] ? 'late' : 'absent'];
        }

        return [
            'weekdays' => array_values($weekdays),
            'worst' => $worst,
            'streak' => $streak,
            'best' => $best,
        ];
    }

    /**
     * The working days the habits are read over: the student's stage's current
     * term up to today, or the last ninety days when the calendar holds none.
     *
     * @return array<int, string>
     */
    private static function termDays(Student $student): array
    {
        $today = now('Asia/Riyadh')->format('Y-m-d');
        $stageId = self::stageId($student);
        $from = AcademicCalendarEvent::attendancePeriodOn($today, $stageId)['start']
            ?? Carbon::parse($today)->subDays(90)->format('Y-m-d');

        return AcademicCalendarEvent::workingDaysBetween($from, $today, $stageId);
    }

    /** The circle's stage wins over the one set on the student. */
    private static function stageId(Student $student): ?int
    {
        return $student->circle?->stage_id ?? $student->stage_id;
    }
}
