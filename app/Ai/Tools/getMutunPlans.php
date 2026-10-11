<?php

namespace App\Ai\Tools;

use App\Models\HadithText;
use App\Models\Ode;
use App\Models\StudentHadithPlan;
use App\Models\StudentOdePlan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class getMutunPlans implements Tool
{
    private const MAX_LIMIT = 150;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Get the mutun (المتون الحديثية) and odes (المنظومات) available in the academy, their memorization paths (مسارات), '
            .'and the student plans on those paths together with their grading (ممتاز/جيد/مقبول) and progress. '
            .'Use "kind" to ask for hadith texts only, odes only, or both, and filter the plans with "student" or "circle". '
            .'A path is shared: many students follow the same path and each keeps their own achievements.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $kind = (string) (($request['kind'] ?? null) ?: 'both');
        $limit = min(max((int) (($request['limit'] ?? null) ?: 50), 1), self::MAX_LIMIT);

        $result = ['grade_scale' => '3=ممتاز، 2=جيد، 1=مقبول'];

        if ($kind === 'hadith' || $kind === 'both') {
            $result['mutun_catalogue'] = $this->hadithCatalogue();
            $result['mutun_plans'] = $this->hadithPlans($request, $limit);
        }

        if ($kind === 'ode' || $kind === 'both') {
            $result['odes_catalogue'] = $this->odeCatalogue();
            $result['ode_plans'] = $this->odePlans($request, $limit);
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function hadithCatalogue(): array
    {
        return HadithText::withCount(['chapters', 'hadiths'])
            ->with(['paths:id,hadith_text_id,name,memorize_type,memorize_amount,start_date,end_date'])
            ->get()
            ->map(fn (HadithText $text) => [
                'text' => $text->name,
                'description' => $text->description,
                'chapters_count' => $text->chapters_count,
                'hadiths_count' => $text->hadiths_count,
                'paths' => $text->paths->map(fn ($path) => [
                    'path' => $path->name,
                    'memorize_type' => $path->memorize_type,
                    'memorize_amount' => $path->memorize_amount,
                    'start_date' => $path->start_date?->format('Y-m-d'),
                    'end_date' => $path->end_date?->format('Y-m-d'),
                ])->all(),
            ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function odeCatalogue(): array
    {
        return Ode::withCount('verses')
            ->with(['paths:id,ode_id,name,start_date,end_date'])
            ->get()
            ->map(fn (Ode $ode) => [
                'ode' => $ode->name,
                'description' => $ode->description,
                'verses_count' => $ode->verses_count,
                'paths' => $ode->paths->map(fn ($path) => [
                    'path' => $path->name,
                    'start_date' => $path->start_date?->format('Y-m-d'),
                    'end_date' => $path->end_date?->format('Y-m-d'),
                ])->all(),
            ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function hadithPlans(Request $request, int $limit): array
    {
        $query = StudentHadithPlan::with([
            'student:id,name,circle_id',
            'student.circle:id,name',
            'path:id,name,hadith_text_id',
            'path.text:id,name',
            'achievements',
        ]);

        $this->applyPlanFilters($query, $request);

        return $query->latest()->limit($limit)->get()->map(fn (StudentHadithPlan $plan) => [
            'student' => $plan->student?->name,
            'circle' => $plan->student?->circle?->name,
            'text' => $plan->path?->text?->name,
            'path' => $plan->path?->name,
            'start_date' => $plan->start_date?->format('Y-m-d'),
            'status' => $plan->status,
            'created_by_role' => $plan->created_by_role,
            'graded_hifz_days' => $plan->achievements->whereNotNull('hifz_achievement')->count(),
            'graded_review_days' => $plan->achievements->whereNotNull('review_achievement')->count(),
            'grades' => $this->gradeLabels($plan->achievements),
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function odePlans(Request $request, int $limit): array
    {
        $query = StudentOdePlan::with([
            'student:id,name,circle_id',
            'student.circle:id,name',
            'path:id,name,ode_id',
            'path.ode:id,name',
            'achievements',
        ]);

        $this->applyPlanFilters($query, $request);

        return $query->latest()->limit($limit)->get()->map(fn (StudentOdePlan $plan) => [
            'student' => $plan->student?->name,
            'circle' => $plan->student?->circle?->name,
            'ode' => $plan->path?->ode?->name,
            'path' => $plan->path?->name,
            'start_date' => $plan->start_date?->format('Y-m-d'),
            'status' => $plan->status,
            'created_by_role' => $plan->created_by_role,
            'graded_hifz_days' => $plan->achievements->whereNotNull('hifz_achievement')->count(),
            'graded_review_days' => $plan->achievements->whereNotNull('review_achievement')->count(),
            'grades' => $this->gradeLabels($plan->achievements),
        ])->all();
    }

    /**
     * @param  Builder<StudentHadithPlan|StudentOdePlan>  $query
     */
    private function applyPlanFilters($query, Request $request): void
    {
        if ($student = ($request['student'] ?? null)) {
            $query->whereHas('student', fn ($q) => $q->where('name', 'like', '%'.$student.'%'));
        }

        if ($circle = ($request['circle'] ?? null)) {
            $query->whereHas('student.circle', fn ($q) => $q->where('name', 'like', '%'.$circle.'%'));
        }

        if ($status = ($request['status'] ?? null)) {
            $query->where('status', $status);
        }
    }

    /**
     * @param  Collection<int, object>  $achievements
     * @return array<string, int>
     */
    private function gradeLabels($achievements): array
    {
        $counts = ['ممتاز' => 0, 'جيد' => 0, 'مقبول' => 0];

        foreach ($achievements as $achievement) {
            foreach ([$achievement->hifz_achievement, $achievement->review_achievement] as $value) {
                match ($value) {
                    3 => $counts['ممتاز']++,
                    2 => $counts['جيد']++,
                    1 => $counts['مقبول']++,
                    default => null,
                };
            }
        }

        return $counts;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(['hadith', 'ode', 'both'])->description('hadith=المتون, ode=المنظومات. Defaults to both.'),
            'student' => $schema->string()->description('Student name, or part of it.'),
            'circle' => $schema->string()->description('Circle name (حلقة).'),
            'status' => $schema->string()->description('Plan status, for example active or completed.'),
            'limit' => $schema->integer()->description('Maximum plans per kind. Defaults to 50, maximum 150.'),
        ];
    }
}
