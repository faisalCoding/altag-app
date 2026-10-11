<?php

namespace App\Ai\Tools;

use App\Models\GamificationTeamTask;
use App\Models\GamificationTrack;
use App\Models\Leaderboard;
use App\Models\TeacherCompetition;
use App\Services\LeaderboardService;
use App\Services\TeacherCompetitionService;
use App\Support\ArabicSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class getCompetitions implements Tool
{
    private const MAX_STANDINGS = 50;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Get the competitions (المسابقات) in full detail. Two kinds exist: student competitions (مسابقات الطلاب), which may be plain point '
            .'competitions or gamification ones with teams, tracks, levels, badges and a coin store; and teacher competitions (مسابقات المعلمين) '
            .'run by supervisors over scored criteria. Pass "competition" to focus on one by title, and "include_standings" to get the ranking '
            .'of students or teachers. Without a title only a summary of every competition is returned.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $kind = (string) (($request['kind'] ?? null) ?: 'both');
        $title = ($request['competition'] ?? null);
        $includeStandings = (bool) ($request['include_standings'] ?? null);

        $result = [];

        if ($kind === 'student' || $kind === 'both') {
            $result['student_competitions'] = $this->studentCompetitions($title, $includeStandings);
        }

        if ($kind === 'teacher' || $kind === 'both') {
            $result['teacher_competitions'] = $this->teacherCompetitions($title, $includeStandings);
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function studentCompetitions(?string $title, bool $includeStandings): array
    {
        $query = Leaderboard::with(['circle:id,name', 'circles:id,name', 'supervisor:id,name', 'criteria']);

        if ($title) {
            ArabicSearch::where($query, 'title', $title);
        }

        $competitions = $query->orderByDesc('start_date')->get();

        // The full breakdown is only affordable for a focused query.
        $detailed = $title !== null && $title !== '';

        return $competitions->map(function (Leaderboard $competition) use ($detailed, $includeStandings) {
            $row = [
                'title' => $competition->title,
                'type' => $competition->competition_type === 'gamification' ? 'تحفيزية (نقاط وعملات وفرق)' : 'نقاط',
                'scope' => $competition->isSupervisorCompetition() ? 'مسابقة مشرف على عدة حلقات' : 'مسابقة حلقة',
                'circles' => $competition->isSupervisorCompetition()
                    ? $competition->circles->pluck('name')->all()
                    : array_filter([$competition->circle?->name]),
                'supervisor' => $competition->supervisor?->name,
                'start_date' => $competition->start_date?->format('Y-m-d'),
                'end_date' => $competition->end_date?->format('Y-m-d'),
                'is_active' => $competition->is_active,
                'is_active_for_grading' => $competition->is_active_for_grading,
                'criteria' => $competition->criteria->map(fn ($criterion) => [
                    'name' => $criterion->name,
                    'points' => $criterion->points,
                    'coins' => $criterion->coins,
                    'is_enthusiasm_trigger' => (bool) $criterion->is_enthusiasm_trigger,
                ])->all(),
            ];

            if ($detailed) {
                $row += $this->gamificationDetail($competition);
                $row['settings'] = $competition->settings;
            }

            if ($includeStandings) {
                $row['standings'] = $this->standings($competition);
            }

            return $row;
        })->all();
    }

    /**
     * The gamification furniture attached to a competition: tracks, teams,
     * levels, badges, streak milestones, store items and team tasks.
     *
     * @return array<string, mixed>
     */
    private function gamificationDetail(Leaderboard $competition): array
    {
        $competition->load([
            'gamificationTeams:id,leaderboard_id,name,coins,slogan',
            'gamificationLevels:id,leaderboard_id,level_number,name,xp_required',
            'gamificationBadges:id,leaderboard_id,name,description,badge_type,requirement_value,reward_xp,reward_coins',
            'gamificationStreakMilestones:id,leaderboard_id,days_required,reward_xp,reward_coins,description',
            'gamificationStoreItems:id,leaderboard_id,name,description,price,item_type,is_active,is_team_product',
        ]);

        $tracks = GamificationTrack::where('leaderboard_id', $competition->id)
            ->withCount('students')
            ->orderBy('sort_order')
            ->get();

        $teamTasks = GamificationTeamTask::where('leaderboard_id', $competition->id)
            ->with('criteria:id,team_task_id,name,coins_reward')
            ->get();

        return [
            'tracks' => $tracks->map(fn ($track) => [
                'name' => $track->name,
                'description' => $track->description,
                'students_count' => $track->students_count,
            ])->all(),
            'teams' => $competition->gamificationTeams->map(fn ($team) => [
                'name' => $team->name,
                'coins' => $team->coins,
                'slogan' => $team->slogan,
            ])->all(),
            'levels' => $competition->gamificationLevels->map(fn ($level) => [
                'level' => $level->level_number,
                'name' => $level->name,
                'xp_required' => $level->xp_required,
            ])->all(),
            'badges' => $competition->gamificationBadges->map(fn ($badge) => [
                'name' => $badge->name,
                'description' => $badge->description,
                'type' => $badge->badge_type,
                'requirement_value' => $badge->requirement_value,
                'reward_xp' => $badge->reward_xp,
                'reward_coins' => $badge->reward_coins,
            ])->all(),
            'streak_milestones' => $competition->gamificationStreakMilestones->map(fn ($milestone) => [
                'days_required' => $milestone->days_required,
                'reward_xp' => $milestone->reward_xp,
                'reward_coins' => $milestone->reward_coins,
                'description' => $milestone->description,
            ])->all(),
            'store_items' => $competition->gamificationStoreItems->map(fn ($item) => [
                'name' => $item->name,
                'description' => $item->description,
                'price' => $item->price,
                'type' => $item->item_type,
                'is_active' => (bool) $item->is_active,
                'is_team_product' => (bool) $item->is_team_product,
            ])->all(),
            'team_tasks' => $teamTasks->map(fn ($task) => [
                'name' => $task->name,
                'description' => $task->description,
                'xp_reward' => $task->xp_reward,
                'coins_reward' => $task->coins_reward,
                'criteria' => $task->criteria->map(fn ($criterion) => [
                    'name' => $criterion->name,
                    'coins_reward' => $criterion->coins_reward,
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function standings(Leaderboard $competition): array
    {
        return app(LeaderboardService::class)
            ->getDetailedStandings($competition)
            ->take(self::MAX_STANDINGS)
            ->map(fn (array $row) => [
                'rank' => $row['rank'],
                'student' => $row['student']->name,
                'circle' => $row['student']->circle?->name,
                'score' => $row['score'],
                'breakdown' => [
                    'حفظ' => $row['details']['hifz'] ?? 0,
                    'مراجعة' => $row['details']['review'] ?? 0,
                    'حضور' => $row['details']['attendance'] ?? 0,
                    'معايير يدوية' => $row['details']['manual'] ?? 0,
                    'نقاط إضافية' => $row['details']['extra_points_score'] ?? 0,
                ],
            ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teacherCompetitions(?string $title, bool $includeStandings): array
    {
        $query = TeacherCompetition::with(['supervisor:id,name', 'criteria', 'participants:id,name']);

        if ($title) {
            ArabicSearch::where($query, 'name', $title);
        }

        $service = app(TeacherCompetitionService::class);

        return $query->orderByDesc('start_date')->get()->map(function (TeacherCompetition $competition) use ($includeStandings, $service) {
            $row = [
                'name' => $competition->name,
                'supervisor' => $competition->supervisor?->name,
                'start_date' => $competition->start_date?->format('Y-m-d'),
                'end_date' => $competition->end_date?->format('Y-m-d'),
                'is_active' => (bool) $competition->is_active,
                'is_currently_active' => $competition->isCurrentlyActive(),
                'criteria' => $competition->criteria->map(fn ($criterion) => [
                    'name' => $criterion->name,
                    'max_points' => $criterion->max_points,
                ])->all(),
                'participants' => $competition->participants->pluck('name')->all(),
            ];

            if ($includeStandings) {
                $row['standings'] = $service->getStandings($competition)
                    ->take(self::MAX_STANDINGS)
                    ->map(fn (array $standing) => [
                        'rank' => $standing['rank'],
                        'teacher' => $standing['teacher']->name,
                        'score' => $standing['score'],
                        'max_score' => $standing['max_score'],
                        'percentage' => $standing['percentage'],
                    ])->values()->all();
            }

            return $row;
        })->all();
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(['student', 'teacher', 'both'])->description('student=مسابقات الطلاب, teacher=مسابقات المعلمين. Defaults to both.'),
            'competition' => $schema->string()->description('Competition title, or part of it. Required to get the full gamification breakdown.'),
            'include_standings' => $schema->boolean()->description('Include the ranking table. Capped at the top 50.'),
        ];
    }
}
