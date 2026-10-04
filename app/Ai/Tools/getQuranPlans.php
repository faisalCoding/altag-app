<?php

namespace App\Ai\Tools;

use App\Models\StudentPlan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class getQuranPlans implements Tool
{
    private const MAX_LIMIT = 150;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'List the Quran memorization and review plans (خطط الحفظ والمراجعة) with each plan\'s completion percentage and grade breakdown. '
            .'Grades are ممتاز/جيد/ضعيف, counted over every graded hifz and review day; لم يسمع means the student was graded as not having recited. '
            .'Filter with "student", "circle", "teacher", "plan_type" (memorization=حفظ, review=مراجعة) or "status". '
            .'Set "include_days" to true only when the day-by-day schedule of a single student\'s plan is genuinely needed.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $limit = min(max((int) (($request['limit'] ?? null) ?: 50), 1), self::MAX_LIMIT);
        $includeDays = (bool) ($request['include_days'] ?? null);

        $query = StudentPlan::with(['student:id,name,circle_id', 'student.circle:id,name', 'teacher:id,name']);

        if ($student = ($request['student'] ?? null)) {
            $query->whereHas('student', fn ($q) => $q->where('name', 'like', '%'.$student.'%'));
        }

        if ($circle = ($request['circle'] ?? null)) {
            $query->whereHas('student.circle', fn ($q) => $q->where('name', 'like', '%'.$circle.'%'));
        }

        if ($teacher = ($request['teacher'] ?? null)) {
            $query->whereHas('teacher', fn ($q) => $q->where('name', 'like', '%'.$teacher.'%'));
        }

        if ($planType = ($request['plan_type'] ?? null)) {
            $query->where('plan_type', $planType === 'مراجعة' ? 'review' : ($planType === 'حفظ' ? 'memorization' : $planType));
        }

        if ($status = ($request['status'] ?? null)) {
            $query->where('status', $status);
        }

        if ($includeDays) {
            $query->with(['days' => fn ($q) => $q->orderBy('date')]);
        }

        $plans = $query->latest()->limit($limit)->get();

        $rows = $plans->map(function (StudentPlan $plan) use ($includeDays) {
            $counts = $plan->achievementDistribution();

            $row = [
                'student' => $plan->student?->name,
                'circle' => $plan->student?->circle?->name,
                'teacher' => $plan->teacher?->name,
                'type' => $plan->plan_type === 'review' ? 'مراجعة' : 'حفظ',
                'start_date' => $plan->start_date?->format('Y-m-d'),
                'days_count' => $plan->days_count,
                'status' => $plan->status,
                'is_approved' => (bool) $plan->is_approved,
                'description' => $plan->description,
                'completion_percentage' => $plan->completionPercentage(),
                'grades' => [
                    'ممتاز' => $counts['excellent'],
                    'جيد' => $counts['good'],
                    'ضعيف' => $counts['weak'],
                    'لم يسمع' => $counts['not_heard'],
                ],
            ];

            if ($includeDays) {
                $row['days'] = $plan->days->map(fn ($day) => [
                    'date' => $day->date?->format('Y-m-d'),
                    'day_name' => $day->day_name,
                    'hifz_grade' => $this->gradeLabel($day->hifz_achievement),
                    'review_grade' => $this->gradeLabel($day->review_achievement),
                    'hifz_graded_at' => $day->hifz_graded_at?->format('Y-m-d'),
                    'review_graded_at' => $day->review_graded_at?->format('Y-m-d'),
                ])->all();
            }

            return $row;
        });

        return json_encode([
            'returned' => $rows->count(),
            'note' => $rows->count() === $limit
                ? 'The result was capped at the limit; narrow the filters or raise "limit" to see more.'
                : null,
            'grade_scale' => '3=ممتاز، 2=جيد، 1=ضعيف، 0=لم يسمع',
            'plans' => $rows->all(),
        ], JSON_UNESCAPED_UNICODE);
    }

    private function gradeLabel(?int $value): ?string
    {
        return match ($value) {
            3 => 'ممتاز',
            2 => 'جيد',
            1 => 'ضعيف',
            0 => 'لم يسمع',
            default => null,
        };
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'student' => $schema->string()->description('Student name, or part of it.'),
            'circle' => $schema->string()->description('Circle name (حلقة).'),
            'teacher' => $schema->string()->description('Name of the teacher who owns the plan.'),
            'plan_type' => $schema->string()->enum(['memorization', 'review'])->description('memorization=حفظ, review=مراجعة.'),
            'status' => $schema->string()->description('Plan status, for example active or completed.'),
            'include_days' => $schema->boolean()->description('Include the day-by-day schedule and its grades. Use only for a single student.'),
            'limit' => $schema->integer()->description('Maximum plans to return. Defaults to 50, maximum 150.'),
        ];
    }
}
