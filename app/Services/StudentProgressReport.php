<?php

namespace App\Services;

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Ayah;
use App\Models\FreeRecitation;
use App\Models\Student;
use App\Models\StudentExam;
use App\Support\HijriDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A student's progress in the Quran, read so a page can draw it: how much of
 * the mushaf they hold, how fast it grows, how well they recite, how closely
 * they keep to their plan, their exams, and this month against the last.
 *
 * Quantities are in mushaf pages (أوجه) of fifteen lines, measured from the
 * lines each recited range spans, so a long verse weighs what it weighs on
 * the page. Every recitation counts: each session of a plan day as the
 * teacher graded it (plan_day_attempts) and each free recitation. What is
 * memorised is counted once: a line recited again — graded twice, or a
 * portion recited anew — adds nothing the second time.
 */
final class StudentProgressReport
{
    public const LINES_PER_PAGE = 15;

    public const RECENT = 20;

    /**
     * How much of the mushaf the student holds — the same count the home page
     * shows — and how much of each juz that is; with the juz being worked on,
     * the first one short of full in the order they memorise, and the pages
     * left in it.
     *
     * @return array{pages: int, juz: float, percentage: float, juzs: array<int, array{juz: int, fraction: float}>, current: ?array{juz: int, left: float}}
     */
    public static function mushaf(Student $student): array
    {
        $range = $student->getMemorizedRange();

        // The memorised pages as one span, read as memorizedPagesCount() reads them.
        $reverse = $range !== null && $range['max'] === 6236 && $range['min'] !== 1;
        $span = match (true) {
            $range === null => null,
            $reverse => [Ayah::find($range['min'])?->page_number ?? 604, 604],
            default => [1, Ayah::find($range['max'])?->page_number ?? 1],
        };

        $bounds = Ayah::selectRaw('juz_number, MIN(page_number) as first_page, MAX(page_number) as last_page')
            ->groupBy('juz_number')
            ->get()
            ->keyBy('juz_number');

        $juzs = [];
        $current = null;

        foreach ($reverse ? range(30, 1) : range(1, 30) as $juz) {
            $first = (int) ($bounds->get($juz)?->first_page ?? 0);
            $last = (int) ($bounds->get($juz)?->last_page ?? 0);
            $length = max(1, $last - $first + 1);
            $held = $span === null || $first === 0 ? 0 : max(0, min($last, $span[1]) - max($first, $span[0]) + 1);
            $fraction = round(min(1, $held / $length), 2);

            $juzs[$juz] = ['juz' => $juz, 'fraction' => $fraction];

            if ($span !== null && $current === null && $fraction < 1) {
                $current = ['juz' => $juz, 'left' => round($length - $held, 1)];
            }
        }

        ksort($juzs);

        return [
            'pages' => $student->memorizedPagesCount(),
            'juz' => MemorizationJourneyService::memorizedJuzCount($student),
            'percentage' => $student->memorizationPercentage(),
            'juzs' => array_values($juzs),
            'current' => $current,
        ];
    }

    /**
     * New pages memorised each week (Saturday to Friday) over the last weeks,
     * this one last — lines recited for the first time, never a line already
     * recited, in the window or before it; and the weekly average since the
     * student's first memorising week in the window, this unfinished week
     * left out of it.
     *
     * @return array{weeks: array<int, array{start: string, label: string, pages: float}>, average: float}
     */
    public static function pace(Student $student, int $weeks = 12): array
    {
        $thisWeek = Carbon::parse(self::today())->startOfWeek(Carbon::SATURDAY);
        $from = $thisWeek->copy()->subWeeks($weeks - 1);

        $buckets = [];
        for ($i = 0; $i < $weeks; $i++) {
            $start = $from->copy()->addWeeks($i);
            $buckets[$start->format('Y-m-d')] = ['start' => $start->format('Y-m-d'), 'label' => HijriDate::dayMonth($start), 'lines' => 0];
        }

        foreach (self::memorised($student) as $recitation) {
            $week = Carbon::parse($recitation['date'])->startOfWeek(Carbon::SATURDAY)->format('Y-m-d');

            if (isset($buckets[$week])) {
                $buckets[$week]['lines'] += $recitation['new'];
            }
        }

        $weeksOut = collect($buckets)->map(fn (array $week) => [
            'start' => $week['start'],
            'label' => $week['label'],
            'pages' => round($week['lines'] / self::LINES_PER_PAGE, 1),
        ])->values();

        $finished = $weeksOut->slice(0, -1);
        $firstActive = $finished->search(fn (array $week) => $week['pages'] > 0);
        $counted = $firstActive === false ? collect() : $finished->slice($firstActive);

        return [
            'weeks' => $weeksOut->all(),
            'average' => $counted->isEmpty() ? 0.0 : round($counted->sum('pages') / $counted->count(), 1),
        ];
    }

    /**
     * When the juz being worked on will be done at the student's pace.
     *
     * @param  array{current: ?array{juz: int, left: float}}  $mushaf
     * @param  array{average: float}  $pace
     * @return array{juz: int, weeks: int}|null
     */
    public static function forecast(array $mushaf, array $pace): ?array
    {
        if ($mushaf['current'] === null || $pace['average'] <= 0) {
            return null;
        }

        return ['juz' => $mushaf['current']['juz'], 'weeks' => max(1, (int) ceil($mushaf['current']['left'] / $pace['average']))];
    }

    /**
     * How the recitations of the period were graded, for memorising and for
     * review apart; and the last recitations of all, oldest first, whatever
     * the period.
     *
     * @return array{hifz: array<int, int>, review: array<int, int>, recent: array<int, array{label: string, part: string, grade: int}>}
     */
    public static function quality(Student $student, string $from, string $to): array
    {
        $sessions = self::sessions($student, $from, $to);
        $grades = fn (string $part) => collect([3, 2, 1, 0])
            ->mapWithKeys(fn (int $grade) => [$grade => $sessions->where('part', $part)->where('grade', $grade)->count()])
            ->all();

        $recent = self::sessions($student, '2000-01-01', self::today())
            ->sortBy('date')
            ->slice(-self::RECENT)
            ->map(fn (array $session) => [
                'label' => HijriDate::dayMonth($session['date']),
                'part' => $session['part'],
                'grade' => $session['grade'],
            ])
            ->values()
            ->all();

        return ['hifz' => $grades('hifz'), 'review' => $grades('review'), 'recent' => $recent];
    }

    /**
     * How the period's memorising sessions of the plan went: the portion
     * recited whole (or past its end), recited short — the rest carried into
     * the next day — or not heard.
     *
     * @return array{whole: int, short: int, not_heard: int, total: int, rate: ?int}
     */
    public static function adherence(Student $student, string $from, string $to): array
    {
        $sessions = self::sessions($student, $from, $to)
            ->filter(fn (array $session) => $session['part'] === 'hifz' && ! $session['free']);

        $whole = $sessions->filter(fn (array $session) => $session['grade'] >= 1 && self::coversPortion($session))->count();
        $notHeard = $sessions->where('grade', 0)->count();
        $total = $sessions->count();

        return [
            'whole' => $whole,
            'short' => $total - $whole - $notHeard,
            'not_heard' => $notHeard,
            'total' => $total,
            'rate' => $total > 0 ? (int) round($whole / $total * 100) : null,
        ];
    }

    /**
     * The student's exams in order: the last ones taken and the next to come.
     *
     * @return array<int, array{level: string, status: string, score: ?int, date: string}>
     */
    public static function exams(Student $student): array
    {
        $exams = StudentExam::where('student_id', $student->id)
            ->with('examLevel:id,name')
            ->orderBy('date_time')
            ->get();

        $taken = $exams->where('status', '!=', 'pending')->slice(-4);
        $next = $exams->where('status', 'pending')->first();

        return $taken->push($next)->filter()
            ->map(fn (StudentExam $exam) => [
                'level' => $exam->examLevel?->name ?? 'اختبار',
                'status' => $exam->status,
                'score' => $exam->score_percentage === null ? null : (int) round((float) $exam->score_percentage),
                'date' => HijriDate::dayMonth($exam->date_time),
            ])
            ->values()
            ->all();
    }

    /**
     * This Hijri month so far against the same days at the start of the last
     * one: pages memorised, the share of recitations graded excellent, and
     * the days attended.
     *
     * @return array{days: int, pages: array{now: float, before: float}, excellent: array{now: ?int, before: ?int}, attended: array{now: int, before: int}}
     */
    public static function comparison(Student $student): array
    {
        $today = self::today();
        $monthStart = HijriDate::months($today, 1)[0]['first_day'];
        $days = (int) Carbon::parse($monthStart)->diffInDays(Carbon::parse($today)) + 1;
        $lastStart = HijriDate::months(Carbon::parse($monthStart)->subDay()->format('Y-m-d'), 1)[0]['first_day'];
        $lastEnd = min(Carbon::parse($lastStart)->addDays($days - 1)->format('Y-m-d'), Carbon::parse($monthStart)->subDay()->format('Y-m-d'));

        $measure = function (string $from, string $to) use ($student): array {
            $sessions = self::sessions($student, $from, $to);
            $newLines = self::memorised($student)
                ->filter(fn (array $recitation) => $recitation['date'] >= $from && $recitation['date'] <= $to)
                ->sum('new');

            return [
                'pages' => round($newLines / self::LINES_PER_PAGE, 1),
                'excellent' => $sessions->isEmpty() ? null : (int) round($sessions->where('grade', 3)->count() / $sessions->count() * 100),
                'attended' => Attendance::where('student_id', $student->id)
                    ->whereIn('status', ['present', 'late'])
                    ->whereDate('date', '>=', $from)
                    ->whereDate('date', '<=', $to)
                    ->count(),
            ];
        };

        $now = $measure($monthStart, $today);
        $before = $measure($lastStart, $lastEnd);

        return [
            'days' => $days,
            'pages' => ['now' => $now['pages'], 'before' => $before['pages']],
            'excellent' => ['now' => $now['excellent'], 'before' => $before['excellent']],
            'attended' => ['now' => $now['attended'], 'before' => $before['attended']],
        ];
    }

    /**
     * The span a period names: this Hijri month so far, or the term of the
     * student's stage so far (the last ninety days when the calendar holds
     * no term).
     *
     * @return array{from: string, to: string, label: string}
     */
    public static function period(Student $student, string $scope): array
    {
        $today = self::today();

        if ($scope === 'term') {
            $stageId = $student->circle?->stage_id ?? $student->stage_id;

            return [
                'from' => AcademicCalendarEvent::attendancePeriodOn($today, $stageId)['start']
                    ?? Carbon::parse($today)->subDays(90)->format('Y-m-d'),
                'to' => $today,
                'label' => 'هذا الفصل',
            ];
        }

        $month = HijriDate::months($today, 1)[0];

        return ['from' => $month['first_day'], 'to' => $today, 'label' => $month['title']];
    }

    /**
     * Every graded recitation between two days: plan sessions with the plan
     * day's own portion beside what was recited, and free recitations.
     *
     * @return Collection<int, array{date: string, part: string, grade: int, from: ?int, to: ?int, recited: bool, planned_to: ?int, free: bool}>
     */
    private static function sessions(Student $student, string $from, string $to): Collection
    {
        $planned = DB::table('plan_day_attempts')
            ->join('student_plan_days', 'student_plan_days.id', '=', 'plan_day_attempts.student_plan_day_id')
            ->join('student_plans', 'student_plans.id', '=', 'student_plan_days.student_plan_id')
            ->where('student_plans.student_id', $student->id)
            ->whereNotNull('plan_day_attempts.grade')
            ->whereDate('plan_day_attempts.recited_on', '>=', $from)
            ->whereDate('plan_day_attempts.recited_on', '<=', $to)
            ->get([
                'plan_day_attempts.part', 'plan_day_attempts.recited_on', 'plan_day_attempts.grade',
                'plan_day_attempts.recited_from_ayah_id', 'plan_day_attempts.recited_to_ayah_id',
                'student_plan_days.from_ayah_id', 'student_plan_days.to_ayah_id',
                'student_plan_days.review_from_ayah_id', 'student_plan_days.review_to_ayah_id',
            ])
            ->map(function (object $row) {
                $review = $row->part === 'review';
                $plannedFrom = $review ? $row->review_from_ayah_id : $row->from_ayah_id;
                $plannedTo = $review ? $row->review_to_ayah_id : $row->to_ayah_id;
                $recited = $row->recited_from_ayah_id && $row->recited_to_ayah_id;

                return [
                    'date' => substr((string) $row->recited_on, 0, 10),
                    'part' => $row->part,
                    'grade' => (int) $row->grade,
                    'from' => $recited ? (int) $row->recited_from_ayah_id : ($plannedFrom ? (int) $plannedFrom : null),
                    'to' => $recited ? (int) $row->recited_to_ayah_id : ($plannedTo ? (int) $plannedTo : null),
                    'recited' => $recited,
                    'planned_to' => $plannedTo ? (int) $plannedTo : null,
                    'free' => false,
                ];
            });

        $free = FreeRecitation::where('student_id', $student->id)
            ->whereNotNull('achievement')
            ->whereDate('recited_on', '>=', $from)
            ->whereDate('recited_on', '<=', $to)
            ->get(['type', 'recited_on', 'achievement', 'from_ayah_id', 'to_ayah_id'])
            ->map(fn (FreeRecitation $recitation) => [
                'date' => $recitation->recited_on->format('Y-m-d'),
                'part' => $recitation->type,
                'grade' => (int) $recitation->achievement,
                'from' => $recitation->from_ayah_id,
                'to' => $recitation->to_ayah_id,
                'recited' => true,
                'planned_to' => null,
                'free' => true,
            ]);

        return $planned->concat($free)->sortBy('date')->values();
    }

    /**
     * Whether a session recited its day's whole portion: nothing else was
     * recorded than the portion, or what was recited reaches the portion's
     * last verse (or runs past it).
     *
     * @param  array{recited: bool, from: ?int, to: ?int, planned_to: ?int}  $session
     */
    private static function coversPortion(array $session): bool
    {
        if (! $session['recited'] || $session['planned_to'] === null) {
            return true;
        }

        return min($session['from'], $session['to']) <= $session['planned_to']
            && $session['planned_to'] <= max($session['from'], $session['to']);
    }

    /**
     * Every memorising recitation graded «مقبول» or better up to today, oldest
     * first, with the lines of the mushaf it added: those of its range no
     * earlier recitation had reached. Kept on the request, since the pace and
     * the month's comparison both ask.
     *
     * @return Collection<int, array{date: string, new: int}>
     */
    private static function memorised(Student $student): Collection
    {
        $request = request();
        $key = self::class."|memorised|{$student->id}|".self::today();

        if ($request->attributes->has($key)) {
            return $request->attributes->get($key);
        }

        $sessions = self::sessions($student, '2000-01-01', self::today())
            ->filter(fn (array $session) => $session['part'] === 'hifz' && $session['grade'] >= 1)
            ->values();

        $ayahs = Ayah::whereIn('id', $sessions->flatMap(fn (array $session) => [$session['from'], $session['to']])->filter()->unique()->values())
            ->get(['id', 'page_number', 'line_number_start', 'line_number_end'])
            ->keyBy('id');

        // Every line of the mushaf reached so far, by its place in the whole.
        $reached = [];

        $memorised = $sessions->map(function (array $session) use ($ayahs, &$reached) {
            $new = 0;

            if ($span = self::span($session, $ayahs)) {
                for ($line = $span[0]; $line <= $span[1]; $line++) {
                    if (! isset($reached[$line])) {
                        $reached[$line] = true;
                        $new++;
                    }
                }
            }

            return ['date' => $session['date'], 'new' => $new];
        });

        $request->attributes->set($key, $memorised);

        return $memorised;
    }

    /**
     * The lines of the mushaf a session's range spans, first and last, each
     * by its place in the whole mushaf; null for a range with an unknown
     * verse. Without line numbers a range covers its pages whole.
     *
     * @param  array{from: ?int, to: ?int}  $session
     * @param  Collection<int, Ayah>  $ayahs
     * @return array{0: int, 1: int}|null
     */
    private static function span(array $session, Collection $ayahs): ?array
    {
        $first = $ayahs->get(min($session['from'] ?? 0, $session['to'] ?? 0));
        $last = $ayahs->get(max($session['from'] ?? 0, $session['to'] ?? 0));

        if (! $first || ! $last) {
            return null;
        }

        if ($first->line_number_start === null || $last->line_number_end === null) {
            return [
                ($first->page_number - 1) * self::LINES_PER_PAGE + 1,
                $last->page_number * self::LINES_PER_PAGE,
            ];
        }

        return [
            ($first->page_number - 1) * self::LINES_PER_PAGE + $first->line_number_start,
            ($last->page_number - 1) * self::LINES_PER_PAGE + $last->line_number_end,
        ];
    }

    private static function today(): string
    {
        return now('Asia/Riyadh')->format('Y-m-d');
    }
}
