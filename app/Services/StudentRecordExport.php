<?php

namespace App\Services;

use App\Models\AttendanceRevision;
use App\Support\HijriDate;
use App\Support\Xlsx;

/**
 * The supervisor's record of students as an Excel workbook, over the same
 * days, circles and kind of recitation the page shows, in four shapes:
 *
 * - grid: the table as it reads, a column a day, with the report beside the
 *   students and under the days;
 * - days: a row a student and day, for sorting and pivoting;
 * - summary: a row a student, their counts alone;
 * - recitations: a row a recitation, what it was and how it was graded.
 *
 * A workbook rather than a CSV: Excel splits a CSV by the list separator of
 * the reader's regional settings, a semicolon in Arabic ones, and laid every
 * row of a comma CSV in one column (App\Support\Xlsx).
 */
final class StudentRecordExport
{
    public const KINDS = [
        'grid' => 'الجدول كما يظهر',
        'days' => 'السجل يوماً بيوم',
        'summary' => 'ملخص الطلاب',
        'recitations' => 'تفاصيل التسميع',
    ];

    private const GRADES = [3 => 'ممتاز', 2 => 'جيد', 1 => 'مقبول', 0 => 'لم يسمع'];

    /**
     * The rows of one shape, the header first.
     *
     * @param  array<string, mixed>  $grid  StudentRecordGrid::build()
     * @return list<list<string|int|null>>
     */
    public static function rows(string $kind, array $grid, string $from, string $to, ?string $filter): array
    {
        return match ($kind) {
            'grid' => self::grid($grid),
            'days' => self::days($grid),
            'summary' => self::summary($grid),
            'recitations' => self::recitations($grid, $from, $to, $filter),
        };
    }

    /**
     * One shape as a workbook's bytes: its header bold, and the grid's
     * report row under the students too.
     *
     * @param  list<list<string|int|null>>  $rows
     */
    public static function xlsx(string $kind, array $rows): string
    {
        return Xlsx::make($rows, self::KINDS[$kind], $kind === 'grid' ? [count($rows) - 1] : []);
    }

    /**
     * @param  array<string, mixed>  $grid
     * @return list<list<string|int|null>>
     */
    private static function grid(array $grid): array
    {
        $labels = AttendanceRevision::statusLabels();
        $rows = [[
            'الحلقة', 'الطالب',
            ...array_map(fn (array $date) => HijriDate::format($date['date'], 'EEEE d MMMM'), $grid['dates']),
            'حاضر', 'متأخر', 'مستأذن', 'غائب', 'لم يُحضَّر', 'نسبة الحضور %', 'الأوراد',
        ]];

        foreach (self::students($grid) as [$circle, $student]) {
            $rows[] = [
                $circle['name'],
                $student['name'],
                ...array_map(function (array $date) use ($student, $labels) {
                    $cell = $student['cells'][$date['date']];

                    if (! $cell['open']) {
                        return '—';
                    }

                    $status = $cell['status'] ? $labels[$cell['status']] : 'لم يُحضَّر';

                    return $cell['graded'] ? "{$status} / {$cell['recited']}" : $status;
                }, $grid['dates']),
                ...self::counts($student['totals']),
            ];
        }

        $rows[] = [
            'التقرير اليومي', 'نسبة الحضور % / الأوراد',
            ...array_map(function (array $date) use ($grid) {
                $day = $grid['days'][$date['date']];

                return ($day['rate'] === null ? '—' : $day['rate']).' / '.$day['recited'];
            }, $grid['dates']),
            ...self::counts($grid['totals']),
        ];

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $grid
     * @return list<list<string|int|null>>
     */
    private static function days(array $grid): array
    {
        $labels = AttendanceRevision::statusLabels();
        $rows = [['التاريخ', 'التاريخ الهجري', 'اليوم', 'الحلقة', 'الطالب', 'الحضور', 'الأوراد المسمَّعة', 'قُيِّم']];

        foreach (self::students($grid) as [$circle, $student]) {
            foreach ($grid['dates'] as $date) {
                $cell = $student['cells'][$date['date']];

                if (! $cell['open']) {
                    continue;
                }

                $rows[] = [
                    $date['date'],
                    HijriDate::full($date['date']),
                    HijriDate::weekday($date['date']),
                    $circle['name'],
                    $student['name'],
                    $cell['status'] ? $labels[$cell['status']] : 'لم يُحضَّر',
                    $cell['recited'],
                    $cell['graded'] ? 'نعم' : 'لا',
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $grid
     * @return list<list<string|int|null>>
     */
    private static function summary(array $grid): array
    {
        $rows = [['الحلقة', 'الطالب', 'حاضر', 'متأخر', 'مستأذن', 'غائب', 'لم يُحضَّر', 'نسبة الحضور %', 'الأوراد']];

        foreach (self::students($grid) as [$circle, $student]) {
            $rows[] = [$circle['name'], $student['name'], ...self::counts($student['totals'])];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $grid
     * @return list<list<string|int|null>>
     */
    private static function recitations(array $grid, string $from, string $to, ?string $filter): array
    {
        $students = [];
        $order = [];

        foreach (self::students($grid) as $position => [$circle, $student]) {
            $students[$student['id']] = [$circle['name'], $student['name']];
            $order[$student['id']] = $position;
        }

        $rows = [['التاريخ', 'التاريخ الهجري', 'الحلقة', 'الطالب', 'ما سُمِّع', 'المقطع', 'التقدير', 'سجّله']];

        // Day by day, and in a day student by student in the table's order:
        // the record comes plan sessions first, then free recitations, then
        // the mutun and the odes, each day's students woven through each other.
        $entries = RecitationRecord::between(array_keys($students), $from, $to)
            ->filter(fn (array $entry) => RecitationRecord::matches($entry, $filter))
            ->sortBy([['date', 'asc'], fn (array $a, array $b) => $order[$a['student_id']] <=> $order[$b['student_id']]]);

        foreach ($entries as $entry) {
            [$circle, $name] = $students[$entry['student_id']];

            $rows[] = [
                $entry['date'],
                HijriDate::full($entry['date']),
                $circle,
                $name,
                RecitationRecord::title($entry),
                RecitationRecord::text($entry),
                self::GRADES[$entry['grade']] ?? (string) $entry['grade'],
                $entry['by'],
            ];
        }

        return $rows;
    }

    /**
     * Every student of the grid with their circle, in the grid's order.
     *
     * @param  array<string, mixed>  $grid
     * @return list<array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    private static function students(array $grid): array
    {
        $students = [];

        foreach ($grid['circles'] as $circle) {
            foreach ($circle['students'] as $student) {
                $students[] = [$circle, $student];
            }
        }

        return $students;
    }

    /**
     * @param  array{present: int, late: int, excused: int, absent: int, unmarked: int, rate: ?int, recited: int}  $totals
     * @return list<int|string|null>
     */
    private static function counts(array $totals): array
    {
        return [$totals['present'], $totals['late'], $totals['excused'], $totals['absent'], $totals['unmarked'], $totals['rate'], $totals['recited']];
    }
}
