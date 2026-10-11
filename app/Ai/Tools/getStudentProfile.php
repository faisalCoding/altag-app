<?php

namespace App\Ai\Tools;

use App\Models\Student;
use App\Models\StudentExam;
use App\Models\StudentHadithPlan;
use App\Models\StudentOdePlan;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Support\ArabicSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class getStudentProfile implements Tool
{
    /** How many recent graded days the report carries. */
    private const RECENT_DAYS = 10;

    private const PLAN_TYPES = ['hifz' => 'حفظ فقط', 'hifz_review' => 'حفظ ومراجعة', 'review' => 'مراجعة فقط'];

    private const PLAN_STATUSES = ['active' => 'مفعّلة', 'inactive' => 'موقوفة'];

    private const GRADES = [3 => 'ممتاز', 2 => 'جيد', 1 => 'مقبول', 0 => 'لم يسمع'];

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Get one full report about a single student (طالب): their circle, stage, guardian, status history, attendance summary, '
            .'Quran memorization progress, their memorization/review plans, the last days they recited and how each was graded, '
            .'mutun and ode plans, exams (put forward, seated in a batch, or finished), and gamification balances. '
            .'Pass the student name (or part of it) in "student". If several students match, the matching names are returned so you can ask which one is meant.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $name = trim((string) ($request['student'] ?? null));

        if ($name === '') {
            return json_encode(['error' => 'A student name is required.'], JSON_UNESCAPED_UNICODE);
        }

        $matches = ArabicSearch::where(Student::query(), 'name', $name)
            ->with(['circle.stage', 'stage', 'guardian:id,name,phone,email'])
            ->limit(10)
            ->get();

        // A name given in full wins over the longer names that contain it.
        $exact = $matches->filter(fn (Student $student) => ArabicSearch::fold((string) $student->name) === ArabicSearch::fold($name));

        if ($exact->count() === 1) {
            $matches = $exact;
        }

        if ($matches->isEmpty()) {
            return json_encode(['error' => 'No student matches "'.$name.'".'], JSON_UNESCAPED_UNICODE);
        }

        if ($matches->count() > 1) {
            return json_encode([
                'ambiguous' => true,
                'message' => 'Several students match; ask the user which one is meant.',
                'matches' => $matches->map(fn (Student $student) => [
                    'name' => $student->name,
                    'circle' => $student->circle?->name,
                ])->all(),
            ], JSON_UNESCAPED_UNICODE);
        }

        $student = $matches->first();

        return json_encode([
            'identity' => [
                'name' => $student->name,
                'email' => $student->email,
                'phone' => $student->phone,
                // The national id stays on the server: no answer needs it.
                'nationality' => $student->nationality,
                'age' => $student->birth_date?->age,
                'joined_at' => $student->joined_at?->format('Y-m-d'),
                'status' => $this->statusLabel($student->status),
                'is_approved' => $student->is_approved,
                'circle' => $student->circle?->name,
                'stage' => $student->circle?->stage?->name ?? $student->stage?->name,
                'guardian' => $student->guardian ? [
                    'name' => $student->guardian->name,
                    'phone' => $student->guardian->phone,
                    'email' => $student->guardian->email,
                ] : null,
            ],
            'attendance' => $this->attendance($student),
            'quran' => [
                'memorized' => $student->memorizationText(),
                'memorized_pages' => $student->memorizedPagesCount(),
                'memorized_percentage' => $student->memorizationPercentage(),
            ],
            'quran_plans' => $this->quranPlans($student),
            'recent_recitations' => $this->recentRecitations($student),
            'mutun_plans' => $this->hadithPlans($student),
            'ode_plans' => $this->odePlans($student),
            'exams' => $this->exams($student),
            'status_history' => $student->statusHistories()->orderByDesc('start_date')->limit(20)->get()
                ->map(fn ($history) => [
                    'status' => $this->statusLabel($history->status),
                    'from' => $history->start_date?->format('Y-m-d'),
                    'to' => $history->end_date?->format('Y-m-d'),
                    'notes' => $history->notes,
                    'changed_by' => $history->changed_by_name,
                ])->all(),
            'gamification' => $this->gamification($student),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    private function attendance(Student $student): array
    {
        $counts = $student->attendances()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'حاضر' => (int) $counts->get('present', 0),
            'غائب' => (int) $counts->get('absent', 0),
            'متأخر' => (int) $counts->get('late', 0),
            'مستأذن' => (int) $counts->get('excused', 0),
            'absences_last_30_days' => $student->getAbsencesInLast30DaysCount(),
            'current_attendance_streak_days' => $student->currentAttendanceStreakDays(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function quranPlans(Student $student): array
    {
        return StudentPlan::where('student_id', $student->id)
            ->with('teacher:id,name')
            ->latest()
            ->limit(10)
            ->get()
            ->map(function (StudentPlan $plan) {
                $counts = $plan->achievementDistribution();

                return [
                    'type' => self::PLAN_TYPES[$plan->plan_type] ?? $plan->plan_type,
                    'teacher' => $plan->teacher?->name,
                    'start_date' => $plan->start_date?->format('Y-m-d'),
                    'days_count' => $plan->days_count,
                    'status' => self::PLAN_STATUSES[$plan->status] ?? $plan->status,
                    'is_approved' => (bool) $plan->is_approved,
                    'completion_percentage' => $plan->completionPercentage(),
                    'grades' => $this->gradeLabels($counts) + ['لم يسمع' => $counts['not_heard']],
                ];
            })->all();
    }

    /**
     * The last days the student was graded on, newest first: the portion of
     * each part — what they actually recited when it differed from the plan
     * — and its grade.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentRecitations(Student $student): array
    {
        return StudentPlanDay::query()
            ->whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
            ->where(fn ($q) => $q->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement'))
            ->with([
                'fromAyah.surah', 'toAyah.surah', 'reviewFromAyah.surah', 'reviewToAyah.surah',
                'hifzRecitedFromAyah.surah', 'hifzRecitedToAyah.surah', 'reviewRecitedFromAyah.surah', 'reviewRecitedToAyah.surah',
            ])
            ->orderByDesc('date')
            ->limit(self::RECENT_DAYS)
            ->get()
            ->map(fn (StudentPlanDay $day) => [
                'date' => $day->date?->format('Y-m-d'),
                'hifz' => $day->hifz_achievement === null ? null : [
                    'portion' => $day->formatRecitedRange('hifz') ?? $day->formatRange('hifz'),
                    'grade' => self::GRADES[$day->hifz_achievement] ?? null,
                ],
                'review' => $day->review_achievement === null ? null : [
                    'portion' => $day->formatRecitedRange('review') ?? $day->formatRange('review'),
                    'grade' => self::GRADES[$day->review_achievement] ?? null,
                ],
            ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function hadithPlans(Student $student): array
    {
        return StudentHadithPlan::where('student_id', $student->id)
            ->with(['path.text:id,name', 'achievements'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (StudentHadithPlan $plan) => [
                'text' => $plan->path?->text?->name,
                'path' => $plan->path?->name,
                'start_date' => $plan->start_date?->format('Y-m-d'),
                'status' => $plan->status,
                'graded_days' => $plan->achievements->whereNotNull('hifz_achievement')->count(),
                'grades' => $this->gradeLabels($this->distribution($plan->achievements)),
            ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function odePlans(Student $student): array
    {
        return StudentOdePlan::where('student_id', $student->id)
            ->with(['path.ode:id,name', 'achievements'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (StudentOdePlan $plan) => [
                'ode' => $plan->path?->ode?->name,
                'path' => $plan->path?->name,
                'start_date' => $plan->start_date?->format('Y-m-d'),
                'status' => $plan->status,
                'graded_days' => $plan->achievements->whereNotNull('hifz_achievement')->count(),
                'grades' => $this->gradeLabels($this->distribution($plan->achievements)),
            ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exams(Student $student): array
    {
        return StudentExam::where('student_id', $student->id)
            ->with('examLevel:id,name')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (StudentExam $exam) => [
                'level' => $exam->examLevel?->name,
                'status' => StudentExam::STATUS_LABELS[$exam->status] ?? $exam->status,
                'readiness' => $exam->status !== StudentExam::NOMINATED ? null
                    : ($exam->isReady() ? 'جاهز' : 'جاهزيته متوقعة '.$exam->expected_ready_on?->format('Y-m-d')),
                'date' => $exam->date_time?->format('Y-m-d H:i'),
                'location' => $exam->location,
                'score_percentage' => $exam->score_percentage,
                'notes' => $exam->notes,
            ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function gamification(Student $student): array
    {
        $states = $student->gamificationStates()->with('leaderboard:id,title')->get();

        $xpByLeaderboard = DB::table('gamification_transactions')
            ->where('student_id', $student->id)
            ->where('type', 'earn')
            ->selectRaw('leaderboard_id, sum(xp_amount) as xp')
            ->groupBy('leaderboard_id')
            ->pluck('xp', 'leaderboard_id');

        return $states->map(fn ($state) => [
            'competition' => $state->leaderboard?->title,
            'coins' => $state->coins,
            'xp' => (int) ($xpByLeaderboard[$state->leaderboard_id] ?? 0),
            'current_streak' => $state->current_streak,
            'max_streak' => $state->max_streak,
            'last_activity_date' => $state->last_activity_date?->format('Y-m-d'),
        ])->all();
    }

    /**
     * Bucket hifz/review achievement values into the three grade tiers.
     *
     * @param  Collection<int, object>  $achievements
     * @return array{excellent: int, good: int, weak: int}
     */
    private function distribution($achievements): array
    {
        $counts = ['excellent' => 0, 'good' => 0, 'weak' => 0];

        foreach ($achievements as $achievement) {
            foreach ([$achievement->hifz_achievement, $achievement->review_achievement] as $value) {
                match ($value) {
                    3 => $counts['excellent']++,
                    2 => $counts['good']++,
                    1 => $counts['weak']++,
                    default => null,
                };
            }
        }

        return $counts;
    }

    /**
     * @param  array{excellent: int, good: int, weak: int}  $counts
     * @return array<string, int>
     */
    private function gradeLabels(array $counts): array
    {
        return [
            'ممتاز' => $counts['excellent'],
            'جيد' => $counts['good'],
            'مقبول' => $counts['weak'],
        ];
    }

    private function statusLabel(?string $status): ?string
    {
        return match ($status) {
            'active' => 'مشارك',
            'registering' => 'تحت التسجيل',
            'suspended' => 'موقوف',
            'left' => 'غادر الحلقات',
            default => $status,
        };
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'student' => $schema->string()->description('The student name, or part of it.')->required(),
        ];
    }
}
