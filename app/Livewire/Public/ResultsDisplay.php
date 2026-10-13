<?php

namespace App\Livewire\Public;

use App\Models\Leaderboard;
use App\Models\Student;
use App\Services\LeaderboardService;
use App\Support\CompetitionWeeks;
use App\Support\HijriDate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Fullscreen projector slideshow of competition results, reached through a
 * signed link generated from the supervisor's gamification management page.
 * Builds one slide deck server-side — two ranking slides per track (whole
 * competition period and the week of the last day counted), an
 * attendance-discipline list, and top ode/hadith memorizers — while
 * navigation, hiding lists and the settings panel stay fully client-side so
 * none of them waits on the server; only a change of dates asks it again.
 *
 * The rankings are the competition's own standings (LeaderboardService), so
 * the projector never disagrees with the standings tab: claimed XP only,
 * counted by the day its work was done, inside the competition's window.
 */
class ResultsDisplay extends Component
{
    /** Rows a ranking slide shows: one screen of them. */
    private const RANKING_ROWS = 10;

    /** Rows the attendance slide rotates through, a screen at a time. */
    private const ATTENDANCE_ROWS = 50;

    #[Locked]
    public int $leaderboardId;

    /** Last day counted in both ranking ranges. */
    public string $endDate = '';

    /** First day of the attendance-discipline window. */
    public string $attendanceStart = '';

    public function mount(Request $request): void
    {
        $this->leaderboardId = (int) $request->query('leaderboard');

        $leaderboard = $this->leaderboardOrFail();

        $this->endDate = $this->lastDay($leaderboard);
        $this->attendanceStart = $leaderboard->start_date->format('Y-m-d');
    }

    protected function leaderboardOrFail(): Leaderboard
    {
        return Leaderboard::where('competition_type', 'gamification')
            ->findOrFail($this->leaderboardId);
    }

    /**
     * The last day the competition has: today in Riyadh while it runs — on
     * UTC it is still yesterday for the first three hours of every morning —
     * and its end once it is over.
     */
    protected function lastDay(Leaderboard $leaderboard): string
    {
        $today = now('Asia/Riyadh')->toDateString();
        $end = $leaderboard->end_date?->toDateString();

        return max($leaderboard->start_date->toDateString(), $end !== null ? min($end, $today) : $today);
    }

    /**
     * The day counted to stays a day of the competition, and the attendance
     * window never starts after it.
     */
    public function updatedEndDate(): void
    {
        $leaderboard = $this->leaderboardOrFail();
        $first = $leaderboard->start_date->toDateString();
        $last = $this->lastDay($leaderboard);

        $this->endDate = $this->isDay($this->endDate) ? min(max($this->endDate, $first), $last) : $last;

        if ($this->attendanceStart > $this->endDate) {
            $this->attendanceStart = $this->endDate;
        }
    }

    public function updatedAttendanceStart(): void
    {
        if (! $this->isDay($this->attendanceStart) || $this->attendanceStart > $this->endDate) {
            $this->attendanceStart = $this->leaderboardOrFail()->start_date->toDateString();
        }
    }

    private function isDay(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    /** @return Collection<int, Student> */
    protected function scopedStudents(Leaderboard $leaderboard): Collection
    {
        $circleIds = $leaderboard->circles()->pluck('circles.id')->all() ?: [$leaderboard->circle_id];

        return Student::with('circle:id,name')
            ->whereIn('circle_id', $circleIds)
            ->where('status', 'active')
            ->get(['id', 'name', 'circle_id'])
            ->keyBy('id');
    }

    /**
     * The first rows of a ranking with points to show, among the students
     * still active.
     *
     * @param  iterable<int, array{student: Student, score: int}>  $standings
     * @param  Collection<int, Student>  $students
     * @return list<array{name: string, circle: ?string, value: int}>
     */
    protected function standingRows(iterable $standings, Collection $students): array
    {
        return collect($standings)
            ->filter(fn (array $row) => $row['score'] > 0 && $students->has($row['student']->id))
            ->take(self::RANKING_ROWS)
            ->map(fn (array $row) => [
                'name' => $students[$row['student']->id]->name,
                'circle' => $students[$row['student']->id]->circle?->name,
                'value' => (int) $row['score'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int|string>  $totals
     * @param  Collection<int, Student>  $students
     * @return list<array{name: string, circle: ?string, value: int}>
     */
    protected function rankingRows(array $totals, Collection $students): array
    {
        $rows = [];

        foreach ($totals as $studentId => $value) {
            $student = $students->get($studentId);

            if (! $student || $value <= 0) {
                continue;
            }

            $rows[] = ['name' => $student->name, 'circle' => $student->circle?->name, 'value' => (int) $value];

            if (count($rows) === self::RANKING_ROWS) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Each track's standings — every student in a track of none — or the
     * whole competition's as one group when it has no tracks, as the
     * standings tab groups them.
     *
     * @return \Illuminate\Support\Collection<int, array{key: string, name: string, standings: array<int, mixed>}>
     */
    protected function groups(LeaderboardService $service, Leaderboard $leaderboard, array $range)
    {
        $standings = $service->getDetailedStandings($leaderboard, $range);
        $groups = $service->getStandingsByTrack($leaderboard, $standings);

        if ($groups->isEmpty()) {
            return collect([['key' => 'general', 'name' => 'الترتيب العام', 'standings' => $standings->all()]]);
        }

        return $groups->map(fn (array $group) => [
            'key' => $group['id'] ? "track-{$group['id']}" : 'general',
            'name' => $group['name'],
            'standings' => $group['standings'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    protected function buildSlides(Leaderboard $leaderboard): array
    {
        $students = $this->scopedStudents($leaderboard);
        $studentIds = $students->keys()->all();

        $endDate = $this->endDate ?: $this->lastDay($leaderboard);
        $totalFrom = $leaderboard->start_date->format('Y-m-d');
        // The week the last day counted falls in, as the standings number it.
        $week = collect(CompetitionWeeks::of($leaderboard, $endDate))->last()
            ?? ['from' => $totalFrom, 'to' => $endDate, 'title' => 'الأسبوع'];

        $service = new LeaderboardService;
        $totals = $this->groups($service, $leaderboard, ['from' => $totalFrom, 'to' => $endDate]);
        $weekly = $this->groups($service, $leaderboard, ['from' => $week['from'], 'to' => $endDate])->keyBy('key');

        $slides = [];

        foreach ($totals as $group) {
            // A track none of whose students is still active has nothing to show.
            if (! collect($group['standings'])->contains(fn (array $row) => $students->has($row['student']->id))) {
                continue;
            }

            $slides[] = [
                'key' => "{$group['key']}-total",
                'title' => $group['name'],
                'subtitle' => 'مجموع النقاط من '.HijriDate::full($totalFrom).' إلى '.HijriDate::full($endDate),
                'type' => 'ranking',
                'unit' => 'نقطة',
                'rows' => $this->standingRows($group['standings'], $students),
            ];

            $slides[] = [
                'key' => "{$group['key']}-week",
                'title' => $group['name'].' — '.$week['title'],
                'subtitle' => 'نقاط الأسبوع من '.HijriDate::full($week['from']).' إلى '.HijriDate::full($endDate),
                'type' => 'ranking',
                'unit' => 'نقطة',
                'rows' => $this->standingRows($weekly[$group['key']]['standings'] ?? [], $students),
            ];
        }

        // Attendance discipline: fewest absences in the window.
        $attendance = DB::table('attendances')
            ->whereIn('student_id', $studentIds)
            ->whereDate('date', '>=', $this->attendanceStart)
            ->whereDate('date', '<=', $endDate)
            ->groupBy('student_id')
            ->selectRaw("student_id,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absences,
                SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as presents")
            ->having('presents', '>', 0)
            ->get()
            ->sortBy([['absences', 'asc'], ['presents', 'desc']])
            // More than fit one screen: the slide rotates through pages of this
            // list client-side, showing a different chunk on each visit.
            ->take(self::ATTENDANCE_ROWS);

        $slides[] = [
            'key' => 'attendance',
            'title' => 'الأكثر انضباطاً في الحضور',
            'subtitle' => 'الأقل غياباً من '.HijriDate::full($this->attendanceStart).' إلى '.HijriDate::full($endDate),
            'type' => 'attendance',
            'rows' => $attendance->map(function ($row) use ($students) {
                $student = $students->get($row->student_id);
                $total = $row->absences + $row->presents;

                return $student ? [
                    'name' => $student->name,
                    'circle' => $student->circle?->name,
                    'absences' => (int) $row->absences,
                    'presents' => (int) $row->presents,
                    'percentage' => $total > 0 ? (int) round($row->presents / $total * 100) : 0,
                ] : null;
            })->filter()->values()->all(),
        ];

        // Top ode memorizers by memorized verse count; a day graded «لم يسمع» was not memorized.
        $odeTotals = DB::table('student_ode_achievements as a')
            ->join('student_ode_plans as p', 'p.id', '=', 'a.student_ode_plan_id')
            ->join('ode_path_days as d', 'd.id', '=', 'a.ode_path_day_id')
            ->where('a.hifz_achievement', '>', 0)
            ->whereNotNull('d.from_verse_number')
            ->whereNotNull('d.to_verse_number')
            ->whereIn('p.student_id', $studentIds)
            ->groupBy('p.student_id')
            ->selectRaw('p.student_id, SUM(ABS(d.to_verse_number - d.from_verse_number) + 1) as total')
            ->orderByDesc('total')
            ->pluck('total', 'student_id')
            ->all();

        $slides[] = [
            'key' => 'odes',
            'title' => 'الأكثر حفظاً للمنظومات',
            'subtitle' => 'بعدد الأبيات المسمَّعة منذ البداية',
            'type' => 'ranking',
            'unit' => 'بيتاً',
            'rows' => $this->rankingRows($odeTotals, $students),
        ];

        // Top hadith memorizers by memorized line count (falling back to the
        // day's memorize_amount for days defined by hadith count, not lines).
        $hadithTotals = DB::table('student_hadith_achievements as a')
            ->join('student_hadith_plans as p', 'p.id', '=', 'a.student_hadith_plan_id')
            ->join('hadith_path_days as d', 'd.id', '=', 'a.hadith_path_day_id')
            ->where('a.hifz_achievement', '>', 0)
            ->whereIn('p.student_id', $studentIds)
            ->groupBy('p.student_id')
            ->selectRaw('p.student_id, SUM(CASE
                WHEN d.from_line_number IS NOT NULL AND d.to_line_number IS NOT NULL
                THEN ABS(d.to_line_number - d.from_line_number) + 1
                ELSE COALESCE(d.memorize_amount, 1)
            END) as total')
            ->orderByDesc('total')
            ->pluck('total', 'student_id')
            ->all();

        $slides[] = [
            'key' => 'hadiths',
            'title' => 'الأكثر حفظاً للمتون الحديثية',
            'subtitle' => 'بمقدار المحفوظ المسمَّع (أسطر/أحاديث) منذ البداية',
            'type' => 'ranking',
            'unit' => 'سطراً',
            'rows' => $this->rankingRows($hadithTotals, $students),
        ];

        return $slides;
    }

    public function render()
    {
        $leaderboard = $this->leaderboardOrFail();

        return view('livewire.public.results-display', [
            'leaderboard' => $leaderboard,
            'slides' => $this->buildSlides($leaderboard),
            'firstDay' => $leaderboard->start_date->toDateString(),
            'lastDay' => $this->lastDay($leaderboard),
        ])->layout('layouts.display', ['title' => 'شاشة النتائج — '.$leaderboard->title]);
    }
}
