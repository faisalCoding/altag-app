<?php

namespace App\Services;

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Ayah;
use App\Models\Circle;
use App\Models\Hadith;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentHadithAchievement;
use App\Models\StudentOdeAchievement;
use App\Models\StudentPlanDay;
use App\Models\StudentStatusHistory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class CircleReportService
{
    /** @var array<string, string> */
    public const PRESETS = [
        'last_week' => 'منذ أسبوع',
        'this_week' => 'هذا الأسبوع',
        'last_month' => 'منذ شهر',
        'this_month' => 'هذا الشهر',
        'custom' => 'تاريخ من إلى',
    ];

    /**
     * Resolve a preset (or custom from/to pair) into a concrete date range.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function resolveRange(string $preset, ?string $fromDate = null, ?string $toDate = null): array
    {
        $today = Carbon::today();

        return match ($preset) {
            'last_week' => [$today->copy()->subDays(6), $today],
            'this_week' => [$today->copy()->startOfWeek(Carbon::SATURDAY), $today],
            'last_month' => [$today->copy()->subDays(29), $today],
            'this_month' => [$today->copy()->startOfMonth(), $today],
            default => self::customRange($fromDate, $toDate, $today),
        };
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private static function customRange(?string $fromDate, ?string $toDate, Carbon $today): array
    {
        try {
            $from = $fromDate ? Carbon::parse($fromDate)->startOfDay() : $today->copy()->subDays(6);
            $to = $toDate ? Carbon::parse($toDate)->startOfDay() : $today->copy();
        } catch (\Throwable) {
            return [$today->copy()->subDays(6), $today];
        }

        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }

    /**
     * The active students of a circle, ordered by name.
     *
     * @return EloquentCollection<int, Student>
     */
    public static function studentsForCircle(Circle $circle): EloquentCollection
    {
        return Student::with('circle')
            ->where('circle_id', $circle->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }

    /**
     * The active students of a whole stage: students whose circle belongs to the
     * stage, plus circle-less students directly assigned to it (the circle's
     * stage always wins over the student's own stage_id).
     *
     * @return EloquentCollection<int, Student>
     */
    public static function studentsForStage(Stage $stage): EloquentCollection
    {
        return Student::with('circle')
            ->where('status', 'active')
            ->where(function ($query) use ($stage) {
                $query->whereHas('circle', fn ($q) => $q->where('stage_id', $stage->id))
                    ->orWhere(fn ($q) => $q->whereNull('circle_id')->where('stage_id', $stage->id));
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * Build the achievement report for the given students within a date range:
     * Quran pages and grades, attendance, memorized hadiths and ode verses.
     * Graded work is attributed to its grading date, falling back to the
     * plan-day date for legacy rows without a graded_at timestamp.
     *
     * Hifz pages are distinct — the extent of the mushaf reached. Review pages
     * are the running total, with the distinct figure alongside it, because
     * revisiting the same pages is the substance of revision rather than a
     * duplicate to be removed. See pageCountsPerStudent().
     *
     * Attendance is measured against the circle's working days over each
     * student's own membership, and a day off taken with permission is set
     * aside from both sides of the ratio: it is neither attendance nor a day
     * the student was expected. So a student who attended eight of ten working
     * days and was excused for the other two counts as a full attendance.
     *
     * @param  EloquentCollection<int, Student>  $students
     * @return array{totals: array{hifz: array{pages: int, days: int, average: float|null}, review: array{pages: int, pages_distinct: int, days: int, average: float|null}, attendance: array{present: int, late: int, absent: int, excused: int, total: int, working_days: int, expected_days: int, rate: int|null}, hadiths: int, verses: int}, perStudent: Collection<int, array<string, mixed>>}
     */
    public static function build(EloquentCollection $students, Carbon $from, Carbon $to): array
    {
        $studentIds = $students->pluck('id')->all();
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $per = [];
        foreach ($studentIds as $id) {
            $per[$id] = [
                'hifz_pages' => 0, 'hifz_days' => 0, 'hifz_score_sum' => 0,
                'review_pages' => 0, 'review_pages_distinct' => 0, 'review_days' => 0, 'review_score_sum' => 0,
                'present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0, 'attendance_total' => 0,
                'working_days' => 0, 'expected_days' => 0,
                'hadiths' => 0, 'verses' => 0,
            ];
        }

        $inRange = fn (?string $basis): bool => $basis !== null && $basis >= $fromDate && $basis <= $toDate;

        // Quran plan days with hifz or review graded within the range.
        $quranDays = StudentPlanDay::query()
            ->join('student_plans', 'student_plan_days.student_plan_id', '=', 'student_plans.id')
            ->whereIn('student_plans.student_id', $studentIds)
            ->where('student_plans.is_approved', true)
            ->where(function ($query) use ($fromDate, $toDate) {
                $query->where(function ($q) use ($fromDate, $toDate) {
                    $q->whereNotNull('student_plan_days.hifz_achievement')
                        ->whereRaw('date(coalesce(student_plan_days.hifz_graded_at, student_plan_days.date)) between ? and ?', [$fromDate, $toDate]);
                })->orWhere(function ($q) use ($fromDate, $toDate) {
                    $q->whereNotNull('student_plan_days.review_achievement')
                        ->whereRaw('date(coalesce(student_plan_days.review_graded_at, student_plan_days.date)) between ? and ?', [$fromDate, $toDate]);
                });
            })
            ->get(['student_plan_days.*', 'student_plans.student_id as owner_student_id']);

        $hifzRanges = [];
        $reviewRanges = [];

        foreach ($quranDays as $day) {
            $sid = $day->owner_student_id;
            if (! isset($per[$sid])) {
                continue;
            }

            $hifzBasis = $day->hifz_graded_at?->toDateString() ?? $day->date?->toDateString();
            if ($day->hifz_achievement !== null && $inRange($hifzBasis)) {
                $per[$sid]['hifz_days']++;
                $per[$sid]['hifz_score_sum'] += (int) $day->hifz_achievement;
                if ($day->from_ayah_id && $day->to_ayah_id) {
                    $hifzRanges[$sid][] = [min($day->from_ayah_id, $day->to_ayah_id), max($day->from_ayah_id, $day->to_ayah_id)];
                }
            }

            $reviewBasis = $day->review_graded_at?->toDateString() ?? $day->date?->toDateString();
            if ($day->review_achievement !== null && $inRange($reviewBasis)) {
                $per[$sid]['review_days']++;
                $per[$sid]['review_score_sum'] += (int) $day->review_achievement;
                if ($day->review_from_ayah_id && $day->review_to_ayah_id) {
                    $reviewRanges[$sid][] = [min($day->review_from_ayah_id, $day->review_to_ayah_id), max($day->review_from_ayah_id, $day->review_to_ayah_id)];
                }
            }
        }

        foreach (self::pageCountsPerStudent($hifzRanges) as $sid => $counts) {
            $per[$sid]['hifz_pages'] = $counts['distinct'];
        }
        foreach (self::pageCountsPerStudent($reviewRanges) as $sid => $counts) {
            $per[$sid]['review_pages'] = $counts['total'];
            $per[$sid]['review_pages_distinct'] = $counts['distinct'];
        }

        // Attendance within the range, only while the student was active on that date.
        $attendanceRows = Attendance::query()
            ->join('users', 'attendances.student_id', '=', 'users.id')
            ->whereIn('attendances.student_id', $studentIds)
            ->where(function ($query) {
                $query->whereNull('users.joined_at')
                    ->orWhereColumn('users.joined_at', '<=', 'attendances.date');
            })
            ->whereRaw(Attendance::activeStatusOnDateSql())
            ->whereDate('attendances.date', '>=', $fromDate)
            ->whereDate('attendances.date', '<=', $toDate)
            ->get(['attendances.student_id as owner_student_id', 'attendances.status', 'attendances.date']);

        $metDays = [];

        foreach ($attendanceRows as $row) {
            $sid = $row->owner_student_id;
            if (! isset($per[$sid]) || ! in_array($row->status, ['present', 'late', 'absent', 'excused'], true)) {
                continue;
            }
            $per[$sid][$row->status]++;
            $per[$sid]['attendance_total']++;
            $metDays[$sid][Carbon::parse($row->date)->format('Y-m-d')] = true;
        }

        foreach (self::participationDays($students, $fromDate, $toDate, $metDays) as $sid => $days) {
            $per[$sid]['working_days'] = $days;
            // Days off with permission are set aside entirely: they neither count
            // as attendance nor as a day the student was expected.
            $per[$sid]['expected_days'] = max(0, $days - $per[$sid]['excused']);
        }

        foreach (self::completedHadithsByStudent($studentIds, $inRange) as $sid => $count) {
            if (isset($per[$sid])) {
                $per[$sid]['hadiths'] = $count;
            }
        }

        foreach (self::completedVersesByStudent($studentIds, $inRange) as $sid => $count) {
            if (isset($per[$sid])) {
                $per[$sid]['verses'] = $count;
            }
        }

        $totals = [
            'hifz' => ['pages' => 0, 'days' => 0, 'average' => null],
            'review' => ['pages' => 0, 'pages_distinct' => 0, 'days' => 0, 'average' => null],
            'attendance' => [
                'present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0, 'total' => 0,
                'working_days' => 0, 'expected_days' => 0, 'rate' => null,
            ],
            'hadiths' => 0,
            'verses' => 0,
        ];
        $hifzScoreSum = 0;
        $reviewScoreSum = 0;

        foreach ($per as $row) {
            $totals['hifz']['pages'] += $row['hifz_pages'];
            $totals['hifz']['days'] += $row['hifz_days'];
            $hifzScoreSum += $row['hifz_score_sum'];
            $totals['review']['pages'] += $row['review_pages'];
            $totals['review']['pages_distinct'] += $row['review_pages_distinct'];
            $totals['review']['days'] += $row['review_days'];
            $reviewScoreSum += $row['review_score_sum'];
            $totals['attendance']['present'] += $row['present'];
            $totals['attendance']['late'] += $row['late'];
            $totals['attendance']['absent'] += $row['absent'];
            $totals['attendance']['excused'] += $row['excused'];
            $totals['attendance']['total'] += $row['attendance_total'];
            $totals['attendance']['working_days'] += $row['working_days'];
            $totals['attendance']['expected_days'] += $row['expected_days'];
            $totals['hadiths'] += $row['hadiths'];
            $totals['verses'] += $row['verses'];
        }

        if ($totals['hifz']['days'] > 0) {
            $totals['hifz']['average'] = round($hifzScoreSum / $totals['hifz']['days'], 1);
        }
        if ($totals['review']['days'] > 0) {
            $totals['review']['average'] = round($reviewScoreSum / $totals['review']['days'], 1);
        }
        if ($totals['attendance']['expected_days'] > 0) {
            $totals['attendance']['rate'] = (int) round(
                ($totals['attendance']['present'] + $totals['attendance']['late']) / $totals['attendance']['expected_days'] * 100
            );
        }

        $perStudent = $students->map(fn (Student $student) => array_merge(
            ['student' => $student],
            $per[$student->id]
        ))->values();

        return ['totals' => $totals, 'perStudent' => $perStudent];
    }

    /**
     * How many circle days each student was there for, within the range.
     *
     * Attendance is measured against the days the circle was meant to meet, not
     * against the days someone remembered to record — otherwise a student who
     * simply never appears in the register reads as a full attendance. A day
     * counts for a student only while they belong to the academy: from the day
     * they joined, and only while their status stood at active, so a student who
     * arrives mid-term is not judged against the weeks before they came.
     *
     * A day the circle actually met counts even if the calendar calls it a day
     * off — a make-up session is still a day the student was there for, and
     * without this the attended days could outnumber the expected ones.
     *
     * The working days come from the student's own stage, since a stage may
     * keep a different week, extra days or closures of its own.
     *
     * @param  EloquentCollection<int, Student>  $students
     * @param  array<int, array<string, true>>  $metDays  Dates each student has a record for.
     * @return array<int, int>
     */
    private static function participationDays(EloquentCollection $students, string $fromDate, string $toDate, array $metDays): array
    {
        // Stages keep their own schedules, so the working days are resolved per
        // stage and shared by every student of that stage.
        $workingDaysByStage = [];

        $histories = StudentStatusHistory::whereIn('student_id', $students->pluck('id'))
            ->whereDate('start_date', '<=', $toDate)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_id');

        $counts = [];

        foreach ($students as $student) {
            $joinedAt = $student->joined_at ? Carbon::parse($student->joined_at)->format('Y-m-d') : null;
            $studentHistory = $histories->get($student->id) ?? collect();
            $stageId = $student->effective_stage_id;

            $workingDaysByStage[$stageId] ??= AcademicCalendarEvent::workingDaysBetween($fromDate, $toDate, $stageId);

            // Every working day, plus any other day the circle met for them.
            $days = array_unique(array_merge($workingDaysByStage[$stageId], array_keys($metDays[$student->id] ?? [])));

            $counts[$student->id] = count(array_filter(
                $days,
                fn (string $day) => ($joinedAt === null || $joinedAt <= $day)
                    && self::statusOn($studentHistory, $day) === 'active'
            ));
        }

        return $counts;
    }

    /**
     * The student's status on a date: the most recent change on or before it.
     * Mirrors Attendance::activeStatusOnDateSql, which the attendance query above
     * applies in SQL — a student with no history at all counts as active.
     *
     * @param  Collection<int, StudentStatusHistory>  $history  Newest first.
     */
    private static function statusOn(Collection $history, string $day): string
    {
        foreach ($history as $change) {
            if (Carbon::parse($change->start_date)->format('Y-m-d') <= $day) {
                return $change->status;
            }
        }

        return 'active';
    }

    /**
     * Count the mushaf pages each student's ayah ranges cover, two ways.
     *
     * "distinct" counts a page once however often it recurs — the extent of the
     * mushaf the student reached. "total" adds every day's pages up — the work
     * actually done. The two answer different questions and neither replaces the
     * other: memorisation is read by extent, since re-reciting a page you already
     * hold is not another page memorised, while review is read by total, since
     * revisiting the same pages is the whole point of revision and deduplicating
     * it hides most of the effort.
     *
     * @param  array<int, array<int, array{0: int, 1: int}>>  $rangesByStudent
     * @return array<int, array{distinct: int, total: int}>
     */
    private static function pageCountsPerStudent(array $rangesByStudent): array
    {
        if ($rangesByStudent === []) {
            return [];
        }

        $envelopeMin = null;
        $envelopeMax = null;
        foreach ($rangesByStudent as $ranges) {
            foreach ($ranges as [$min, $max]) {
                $envelopeMin = $envelopeMin === null ? $min : min($envelopeMin, $min);
                $envelopeMax = $envelopeMax === null ? $max : max($envelopeMax, $max);
            }
        }

        $pageByAyah = Ayah::whereBetween('id', [$envelopeMin, $envelopeMax])
            ->pluck('page_number', 'id');

        $counts = [];
        foreach ($rangesByStudent as $sid => $ranges) {
            $pages = [];
            $total = 0;

            foreach ($ranges as [$min, $max]) {
                $dayPages = [];
                for ($ayahId = $min; $ayahId <= $max; $ayahId++) {
                    $page = $pageByAyah[$ayahId] ?? null;
                    if ($page !== null) {
                        $pages[$page] = true;
                        $dayPages[$page] = true;
                    }
                }
                // Counted per day, so a page spanning two days of one range adds
                // once for each — while a page read twice in a single day is one.
                $total += count($dayPages);
            }

            $counts[$sid] = ['distinct' => count($pages), 'total' => $total];
        }

        return $counts;
    }

    /**
     * Count the hadiths each student completed within the range: hadith-range
     * days mark every hadith in the range, line days count once all of the
     * hadith's lines are covered by in-range graded days.
     *
     * @param  array<int, int>  $studentIds
     * @param  callable(?string): bool  $inRange
     * @return array<int, int>
     */
    private static function completedHadithsByStudent(array $studentIds, callable $inRange): array
    {
        $achievements = StudentHadithAchievement::with(['pathDay', 'plan.path'])
            ->whereHas('plan', fn ($q) => $q->whereIn('student_id', $studentIds))
            ->whereNotNull('hifz_achievement')
            ->get()
            ->filter(function (StudentHadithAchievement $achievement) use ($inRange) {
                $basis = $achievement->hifz_graded_at?->toDateString()
                    ?? $achievement->pathDay?->date?->toDateString();

                return $achievement->pathDay && $achievement->plan?->path && $inRange($basis);
            });

        if ($achievements->isEmpty()) {
            return [];
        }

        $textIds = $achievements->map(fn ($a) => $a->plan->path->hadith_text_id)->unique()->values();
        $hadithsByText = [];
        $hadithById = collect();
        foreach ($textIds as $textId) {
            $hadithsByText[$textId] = Hadith::with('lines')
                ->where(function ($query) use ($textId) {
                    $query->where('hadith_text_id', $textId)
                        ->orWhereHas('chapter', fn ($q) => $q->where('hadith_text_id', $textId));
                })
                ->orderBy('hadith_chapter_id')
                ->orderBy('id')
                ->get();
            $hadithById = $hadithById->union($hadithsByText[$textId]->keyBy('id'));
        }

        $completed = [];
        $coveredLines = [];

        foreach ($achievements as $achievement) {
            $day = $achievement->pathDay;
            $sid = $achievement->plan->student_id;
            $orderedIds = ($hadithsByText[$achievement->plan->path->hadith_text_id] ?? collect())->pluck('id')->values();

            if ($day->from_line_number && $day->to_line_number && $day->from_hadith_id) {
                foreach (range($day->from_line_number, $day->to_line_number) as $lineNumber) {
                    $coveredLines[$sid][$day->from_hadith_id][$lineNumber] = true;
                }
            } elseif ($day->from_hadith_id && $day->to_hadith_id) {
                $startIdx = $orderedIds->search($day->from_hadith_id);
                $endIdx = $orderedIds->search($day->to_hadith_id);
                if ($startIdx !== false && $endIdx !== false) {
                    foreach ($orderedIds->slice(min($startIdx, $endIdx), abs($endIdx - $startIdx) + 1) as $hadithId) {
                        $completed[$sid][$hadithId] = true;
                    }
                }
            }
        }

        foreach ($coveredLines as $sid => $byHadith) {
            foreach ($byHadith as $hadithId => $lines) {
                if (isset($completed[$sid][$hadithId])) {
                    continue;
                }
                $lineNumbers = $hadithById->get($hadithId)?->lines->pluck('line_number') ?? collect();
                if ($lineNumbers->isNotEmpty() && $lineNumbers->diff(array_keys($lines))->isEmpty()) {
                    $completed[$sid][$hadithId] = true;
                }
            }
        }

        return array_map('count', $completed);
    }

    /**
     * Count the distinct ode verses each student completed within the range.
     *
     * @param  array<int, int>  $studentIds
     * @param  callable(?string): bool  $inRange
     * @return array<int, int>
     */
    private static function completedVersesByStudent(array $studentIds, callable $inRange): array
    {
        $achievements = StudentOdeAchievement::with(['pathDay', 'plan.path'])
            ->whereHas('plan', fn ($q) => $q->whereIn('student_id', $studentIds))
            ->whereNotNull('hifz_achievement')
            ->get();

        $verses = [];
        foreach ($achievements as $achievement) {
            $day = $achievement->pathDay;
            $basis = $achievement->hifz_graded_at?->toDateString() ?? $day?->date?->toDateString();
            if (! $day || ! $inRange($basis) || ! $day->from_verse_number || ! $day->to_verse_number) {
                continue;
            }

            $sid = $achievement->plan->student_id;
            $odeId = $achievement->plan->path?->ode_id;
            foreach (range($day->from_verse_number, $day->to_verse_number) as $verseNumber) {
                $verses[$sid]["{$odeId}:{$verseNumber}"] = true;
            }
        }

        return array_map('count', $verses);
    }
}
