<?php

namespace App\Livewire\Public;

use App\Models\GamificationTrack;
use App\Models\Leaderboard;
use App\Models\Student;
use App\Services\GamificationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Fullscreen projector slideshow of competition results, reached through a
 * signed link generated from the supervisor's gamification management page.
 * Builds one slide deck server-side — two ranking slides per track (whole
 * competition period and current week), an attendance-discipline list, and
 * top ode/hadith memorizers — while navigation between the pre-rendered
 * slides stays fully client-side (arrow keys / on-screen arrows / optional
 * auto-advance) so flipping never waits on the server.
 */
class ResultsDisplay extends Component
{
    #[Locked]
    public int $leaderboardId;

    /** Last day counted in both ranking ranges. */
    public string $endDate = '';

    /** First day of the attendance-discipline window. */
    public string $attendanceStart = '';

    /** Slide keys the operator has hidden. @var list<string> */
    public array $hiddenSlides = [];

    public bool $settingsOpen = false;

    public function mount(Request $request): void
    {
        $this->leaderboardId = (int) $request->query('leaderboard');

        $leaderboard = $this->leaderboardOrFail();

        $this->endDate = now()->format('Y-m-d');
        $this->attendanceStart = $leaderboard->start_date->format('Y-m-d');
    }

    protected function leaderboardOrFail(): Leaderboard
    {
        return Leaderboard::where('competition_type', 'gamification')
            ->findOrFail($this->leaderboardId);
    }

    public function toggleSlide(string $key): void
    {
        if (in_array($key, $this->hiddenSlides, true)) {
            $this->hiddenSlides = array_values(array_diff($this->hiddenSlides, [$key]));
        } else {
            $this->hiddenSlides[] = $key;
        }
    }

    /** @return Collection<int, Student> */
    protected function scopedStudents(Leaderboard $leaderboard)
    {
        $circleIds = $leaderboard->circles()->pluck('circles.id')->all() ?: [$leaderboard->circle_id];

        return Student::with('circle')
            ->whereIn('circle_id', $circleIds)
            ->where('status', 'active')
            ->get();
    }

    /**
     * @param  array<int, int>  $totals
     * @return list<array{name: string, circle: ?string, value: int}>
     */
    protected function rankingRows(array $totals, $students, int $limit = 10): array
    {
        $rows = [];

        foreach ($totals as $studentId => $value) {
            $student = $students->firstWhere('id', $studentId);

            if (! $student || $value <= 0) {
                continue;
            }

            $rows[] = [
                'name' => $student->name,
                'circle' => $student->circle?->name,
                'value' => (int) $value,
            ];

            if (count($rows) === $limit) {
                break;
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    protected function buildSlides(Leaderboard $leaderboard): array
    {
        $students = $this->scopedStudents($leaderboard);
        $studentIds = $students->pluck('id')->all();

        $endDate = $this->endDate ?: now()->format('Y-m-d');
        $totalFrom = $leaderboard->start_date->format('Y-m-d');
        // The Saudi week runs Sunday through Saturday.
        $weekFrom = Carbon::parse($endDate)->startOfWeek(Carbon::SUNDAY)->format('Y-m-d');

        $totalXp = GamificationService::xpTotalsForRange($leaderboard, $totalFrom, $endDate);
        $weekXp = GamificationService::xpTotalsForRange($leaderboard, $weekFrom, $endDate);

        $slides = [];

        // One ranking group per track; a single general group when no tracks exist.
        $tracks = GamificationTrack::where('leaderboard_id', $leaderboard->id)
            ->orderBy('sort_order')->orderBy('id')
            ->with('students:users.id')
            ->get();

        $groups = $tracks->isEmpty()
            ? collect([['key' => 'general', 'name' => 'الترتيب العام', 'studentIds' => $studentIds]])
            : $tracks->map(fn ($track) => [
                'key' => "track-{$track->id}",
                'name' => $track->name,
                'studentIds' => $track->students->pluck('id')->all(),
            ]);

        foreach ($groups as $group) {
            $inGroup = fn (array $totals) => array_filter(
                $totals,
                fn ($id) => in_array($id, $group['studentIds'], true),
                ARRAY_FILTER_USE_KEY
            );

            $slides[] = [
                'key' => "{$group['key']}-total",
                'title' => $group['name'],
                'subtitle' => 'النقاط منذ بداية الدورة — '.$totalFrom.' إلى '.$endDate,
                'type' => 'ranking',
                'unit' => 'نقطة',
                'rows' => $this->rankingRows($inGroup($totalXp), $students),
            ];

            $slides[] = [
                'key' => "{$group['key']}-week",
                'title' => $group['name'].' — هذا الأسبوع',
                'subtitle' => 'النقاط منذ بداية الأسبوع — '.$weekFrom.' إلى '.$endDate,
                'type' => 'ranking',
                'unit' => 'نقطة',
                'rows' => $this->rankingRows($inGroup($weekXp), $students),
            ];
        }

        // Attendance discipline: fewest absences since the term started.
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
            ->take(50);

        $slides[] = [
            'key' => 'attendance',
            'title' => 'الأكثر انضباطاً في الحضور',
            'subtitle' => 'الأقل غياباً منذ '.$this->attendanceStart.' إلى '.$endDate,
            'type' => 'attendance',
            'rows' => $attendance->map(function ($row) use ($students) {
                $student = $students->firstWhere('id', $row->student_id);
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

        // Top ode memorizers by memorized verse count.
        $odeTotals = DB::table('student_ode_achievements as a')
            ->join('student_ode_plans as p', 'p.id', '=', 'a.student_ode_plan_id')
            ->join('ode_path_days as d', 'd.id', '=', 'a.ode_path_day_id')
            ->whereNotNull('a.hifz_achievement')
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
            ->whereNotNull('a.hifz_achievement')
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

        $slides = $this->buildSlides($leaderboard);

        $visibleSlides = array_values(array_filter(
            $slides,
            fn ($slide) => ! in_array($slide['key'], $this->hiddenSlides, true)
        ));

        return view('livewire.public.results-display', [
            'leaderboard' => $leaderboard,
            'allSlides' => $slides,
            'slides' => $visibleSlides,
        ])->layout('layouts.display', ['title' => 'شاشة النتائج — '.$leaderboard->title]);
    }
}
