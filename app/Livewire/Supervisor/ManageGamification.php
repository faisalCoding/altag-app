<?php

namespace App\Livewire\Supervisor;

use App\Models\GamificationActivity;
use App\Models\GamificationActivityRound;
use App\Models\GamificationActivityWinner;
use App\Models\GamificationBadge;
use App\Models\GamificationLevel;
use App\Models\GamificationStoreItem;
use App\Models\GamificationStorePurchase;
use App\Models\GamificationTeam;
use App\Models\GamificationTeamTask;
use App\Models\GamificationTeamTaskAssignment;
use App\Models\GamificationTrack;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\LeaderboardCriterion;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\GamificationNewsService;
use App\Services\GamificationRecalculator;
use App\Services\GamificationService;
use App\Services\GamificationThemeService;
use App\Services\LeaderboardService;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class ManageGamification extends Component
{
    use WithFileUploads;

    // Task Definitions (Templates) State
    #[Locked]
    public $editingTeamTaskId = null;

    public string $task_name = '';

    public string $task_description = '';

    public string $task_evaluation_criteria = '';

    public int $task_xp_reward = 50;

    public int $task_coins_reward = 50;

    public bool $showTeamTaskModal = false;

    public array $task_criteria = [];

    // Task Assignments State
    #[Locked]
    public $editingAssignmentId = null;

    public $assignment_task_id = null;

    public $assignment_team_id = null;

    public $assignment_teacher_id = null;

    public $assignment_start_date = '';

    public $assignment_end_date = '';

    public $assignment_grade = null;

    public string $assignment_notes = '';

    public array $assignment_scores = [];

    public bool $showAssignmentModal = false;

    #[Locked]
    public $competitionId;

    public $competition;

    public $theme;

    public string $activeTab = 'levels';

    // Student standings ("مراكز الطلاب") State
    public string $standingsDate = '';

    #[Locked]
    public ?int $achievementsStudentId = null;

    public bool $showAchievementsModal = false;

    // Levels State
    public array $levels = [];

    // Criteria State
    public array $criteria = [];

    // Streaks State
    #[Locked]
    public $editingMilestoneId = null;

    public $days_required;

    public $reward_xp;

    public $reward_coins;

    public $reward_badge_id = null;

    public $milestone_description = '';

    public bool $showMilestoneModal = false;

    // Badges State
    #[Locked]
    public $editingBadgeId = null;

    public $badge_name = '';

    public $badge_description = '';

    public $badge_icon = 'star';

    public $badge_type = 'custom';

    public $badge_requirement_value = 0;

    public $badge_leaderboard_criterion_id = null;

    public bool $showBadgeModal = false;

    // Automatic settings state
    public bool $hifz_enabled = true;

    public int $hifz_excellent_xp = 10;

    public int $hifz_excellent_coins = 10;

    public int $hifz_good_xp = 7;

    public int $hifz_good_coins = 7;

    public int $hifz_acceptable_xp = 4;

    public int $hifz_acceptable_coins = 4;

    public bool $hifz_enthusiasm_trigger = true;

    public bool $review_enabled = true;

    public int $review_excellent_xp = 5;

    public int $review_excellent_coins = 5;

    public int $review_good_xp = 3;

    public int $review_good_coins = 3;

    public bool $review_enthusiasm_trigger = true;

    public bool $attendance_enabled = true;

    public int $attendance_present_xp = 4;

    public int $attendance_present_coins = 4;

    public int $attendance_late_xp = 2;

    public int $attendance_late_coins = 2;

    public bool $attendance_enthusiasm_trigger = true;

    public bool $manual_claim_enabled = false;

    public bool $ode_hifz_enabled = false;

    public int $ode_hifz_excellent_xp = 10;

    public int $ode_hifz_excellent_coins = 10;

    public int $ode_hifz_good_xp = 7;

    public int $ode_hifz_good_coins = 7;

    public int $ode_hifz_acceptable_xp = 4;

    public int $ode_hifz_acceptable_coins = 4;

    public bool $ode_hifz_enthusiasm_trigger = false;

    public bool $ode_review_enabled = false;

    public int $ode_review_excellent_xp = 5;

    public int $ode_review_excellent_coins = 5;

    public int $ode_review_good_xp = 3;

    public int $ode_review_good_coins = 3;

    public bool $ode_review_enthusiasm_trigger = false;

    public bool $hadith_hifz_enabled = false;

    public int $hadith_hifz_excellent_xp = 10;

    public int $hadith_hifz_excellent_coins = 10;

    public int $hadith_hifz_good_xp = 7;

    public int $hadith_hifz_good_coins = 7;

    public int $hadith_hifz_acceptable_xp = 4;

    public int $hadith_hifz_acceptable_coins = 4;

    public bool $hadith_hifz_enthusiasm_trigger = false;

    public bool $hadith_review_enabled = false;

    public int $hadith_review_excellent_xp = 5;

    public int $hadith_review_excellent_coins = 5;

    public int $hadith_review_good_xp = 3;

    public int $hadith_review_good_coins = 3;

    public bool $hadith_review_enthusiasm_trigger = false;

    public $badge_image_file;

    public int $badgeStep = 1;

    public string $badge_mechanism = 'manual';

    public string $badge_achievement_type = '';

    public int $badge_reward_xp = 0;

    public int $badge_reward_coins = 0;

    // Handing out a manual badge
    public bool $showGrantBadgeModal = false;

    #[Locked]
    public ?int $grantingBadgeId = null;

    /** @var array<int, string> Student ids ticked in the hand-out panel. */
    public array $grantStudentIds = [];

    public string $grantSearch = '';

    // Teams State
    #[Locked]
    public $editingTeamId = null;

    public $team_name = '';

    public bool $showTeamModal = false;

    public array $studentTeams = []; // student_id => team_id

    public array $studentRoles = []; // student_id => role (leader, assistant, member)

    public int $team_step = 1;

    public string $team_color = '#4f46e5';

    public string $team_slogan = '';

    public $team_logo_file = null;

    public ?string $existing_logo_path = null;

    public $team_leader_id = null;

    public $team_assistant_id = null;

    public array $team_student_ids = [];

    // Store State
    #[Locked]
    public $editingItemId = null;

    public $item_name = '';

    public $item_description = '';

    public $item_price;

    public $item_type = 'custom';

    public $item_value = 0;

    public bool $showItemModal = false;

    public bool $item_is_team_product = false;

    public bool $item_is_streak_freeze = false;

    public ?string $item_target_date = null;

    public bool $item_require_assistant_approval = false;

    public int $item_require_member_approval_count = 0;

    public bool $item_is_active = true;

    public array $freezeLevelRules = [];

    // Activity State
    public bool $showActivityModal = false;

    #[Locked]
    public $editingActivityId = null;

    public string $activity_name = '';

    public string $activity_description = '';

    public array $activity_ranks = [];

    public array $activity_rounds = [];

    public string $activity_color = '#10b981';

    public $activity_icon_file = null;

    public ?string $activity_icon_path = null;

    // Winner State
    public bool $showWinnerModal = false;

    #[Locked]
    public $editingWinnerId = null;

    #[Locked]
    public $winner_activity_id = null;

    #[Locked]
    public $winner_round_id = null;

    #[Locked]
    public $winner_rank_id = null;

    #[Locked]
    public $winner_team_id = null;

    public string $winner_awarded_at = '';

    // New Round Winner State
    public bool $showRoundWinnersModal = false;

    #[Locked]
    public $selectedRoundId = null;

    public array $roundRanksWinners = [];

    // Manual Adjustments State
    public string $adjTargetType = 'individual';

    public ?int $adjStudentId = null;

    public ?int $adjTeamId = null;

    public string $adjActionType = 'add';

    public bool $adjHasXp = false;

    public int $adjXpVal = 0;

    public bool $adjHasCoins = false;

    public int $adjCoinsVal = 0;

    public string $adjDescription = '';

    public bool $adjShowInNews = false;

    public bool $adjShowTargetName = true;

    public function mount($competitionId): void
    {
        $this->competitionId = $competitionId;
        $this->standingsDate = now()->format('Y-m-d');
        $this->loadCompetition();
        $this->initializeLevels();
        $this->initializeCriteria();
        $this->initializeAutomaticSettings();
    }

    /**
     * Open the achievements-by-day breakdown for a single student.
     */
    public function viewStudentAchievements(int $studentId): void
    {
        $this->achievementsStudentId = $studentId;
        $this->showAchievementsModal = true;
    }

    public function initializeAutomaticSettings(): void
    {
        $settings = $this->competition->settings ?? [];

        $this->hifz_enabled = (bool) ($settings['hifz_enabled'] ?? true);
        $this->hifz_excellent_xp = (int) ($settings['hifz_excellent_xp'] ?? ($settings['hifz_excellent'] ?? 10));
        $this->hifz_excellent_coins = (int) ($settings['hifz_excellent_coins'] ?? ($settings['hifz_excellent'] ?? 10));
        $this->hifz_good_xp = (int) ($settings['hifz_good_xp'] ?? ($settings['hifz_good'] ?? 7));
        $this->hifz_good_coins = (int) ($settings['hifz_good_coins'] ?? ($settings['hifz_good'] ?? 7));
        $this->hifz_acceptable_xp = (int) ($settings['hifz_acceptable_xp'] ?? ($settings['hifz_acceptable'] ?? 4));
        $this->hifz_acceptable_coins = (int) ($settings['hifz_acceptable_coins'] ?? ($settings['hifz_acceptable'] ?? 4));
        $this->hifz_enthusiasm_trigger = (bool) ($settings['hifz_enthusiasm_trigger'] ?? true);

        $this->review_enabled = (bool) ($settings['review_enabled'] ?? true);
        $this->review_excellent_xp = (int) ($settings['review_excellent_xp'] ?? ($settings['review_excellent'] ?? 5));
        $this->review_excellent_coins = (int) ($settings['review_excellent_coins'] ?? ($settings['review_excellent'] ?? 5));
        $this->review_good_xp = (int) ($settings['review_good_xp'] ?? ($settings['review_good'] ?? 3));
        $this->review_good_coins = (int) ($settings['review_good_coins'] ?? ($settings['review_good'] ?? 3));
        $this->review_enthusiasm_trigger = (bool) ($settings['review_enthusiasm_trigger'] ?? true);

        $this->attendance_enabled = (bool) ($settings['attendance_enabled'] ?? true);
        $this->attendance_present_xp = (int) ($settings['attendance_present_xp'] ?? ($settings['attendance_present'] ?? 4));
        $this->attendance_present_coins = (int) ($settings['attendance_present_coins'] ?? ($settings['attendance_present'] ?? 4));
        $this->attendance_late_xp = (int) ($settings['attendance_late_xp'] ?? ($settings['attendance_late'] ?? 2));
        $this->attendance_late_coins = (int) ($settings['attendance_late_coins'] ?? ($settings['attendance_late'] ?? 2));
        $this->attendance_enthusiasm_trigger = (bool) ($settings['attendance_enthusiasm_trigger'] ?? true);

        $this->manual_claim_enabled = (bool) ($settings['manual_claim_enabled'] ?? false);

        $this->ode_hifz_enabled = (bool) ($settings['ode_hifz_enabled'] ?? false);
        $this->ode_hifz_excellent_xp = (int) ($settings['ode_hifz_excellent_xp'] ?? 10);
        $this->ode_hifz_excellent_coins = (int) ($settings['ode_hifz_excellent_coins'] ?? 10);
        $this->ode_hifz_good_xp = (int) ($settings['ode_hifz_good_xp'] ?? 7);
        $this->ode_hifz_good_coins = (int) ($settings['ode_hifz_good_coins'] ?? 7);
        $this->ode_hifz_acceptable_xp = (int) ($settings['ode_hifz_acceptable_xp'] ?? 4);
        $this->ode_hifz_acceptable_coins = (int) ($settings['ode_hifz_acceptable_coins'] ?? 4);
        $this->ode_hifz_enthusiasm_trigger = (bool) ($settings['ode_hifz_enthusiasm_trigger'] ?? false);

        $this->ode_review_enabled = (bool) ($settings['ode_review_enabled'] ?? false);
        $this->ode_review_excellent_xp = (int) ($settings['ode_review_excellent_xp'] ?? 5);
        $this->ode_review_excellent_coins = (int) ($settings['ode_review_excellent_coins'] ?? 5);
        $this->ode_review_good_xp = (int) ($settings['ode_review_good_xp'] ?? 3);
        $this->ode_review_good_coins = (int) ($settings['ode_review_good_coins'] ?? 3);
        $this->ode_review_enthusiasm_trigger = (bool) ($settings['ode_review_enthusiasm_trigger'] ?? false);

        $this->hadith_hifz_enabled = (bool) ($settings['hadith_hifz_enabled'] ?? false);
        $this->hadith_hifz_excellent_xp = (int) ($settings['hadith_hifz_excellent_xp'] ?? 10);
        $this->hadith_hifz_excellent_coins = (int) ($settings['hadith_hifz_excellent_coins'] ?? 10);
        $this->hadith_hifz_good_xp = (int) ($settings['hadith_hifz_good_xp'] ?? 7);
        $this->hadith_hifz_good_coins = (int) ($settings['hadith_hifz_good_coins'] ?? 7);
        $this->hadith_hifz_acceptable_xp = (int) ($settings['hadith_hifz_acceptable_xp'] ?? 4);
        $this->hadith_hifz_acceptable_coins = (int) ($settings['hadith_hifz_acceptable_coins'] ?? 4);
        $this->hadith_hifz_enthusiasm_trigger = (bool) ($settings['hadith_hifz_enthusiasm_trigger'] ?? false);

        $this->hadith_review_enabled = (bool) ($settings['hadith_review_enabled'] ?? false);
        $this->hadith_review_excellent_xp = (int) ($settings['hadith_review_excellent_xp'] ?? 5);
        $this->hadith_review_excellent_coins = (int) ($settings['hadith_review_excellent_coins'] ?? 5);
        $this->hadith_review_good_xp = (int) ($settings['hadith_review_good_xp'] ?? 3);
        $this->hadith_review_good_coins = (int) ($settings['hadith_review_good_coins'] ?? 3);
        $this->hadith_review_enthusiasm_trigger = (bool) ($settings['hadith_review_enthusiasm_trigger'] ?? false);
    }

    /**
     * The students this competition is played among — its circles' rolls.
     * Every student the forms send back is held to these, so an edited
     * request cannot pull a student from another circle into a team, a
     * badge or an adjustment.
     *
     * @return array<int, int>
     */
    private function competitionStudentIds(): array
    {
        return Student::query()
            ->whereIn('circle_id', $this->competitionCircleIds())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function competitionTeacherIds(): array
    {
        return Teacher::query()
            ->whereHas('circles', fn ($query) => $query->whereIn('circles.id', $this->competitionCircleIds()))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function competitionCircleIds(): array
    {
        return $this->competition->circles()->pluck('circles.id')
            ->push($this->competition->circle_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * An id that must name a row of this competition, not merely any row.
     */
    private function ownRow(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('leaderboard_id', $this->competitionId);
    }

    public function loadCompetition(): void
    {
        $supervisorId = auth()->guard('supervisor')->id();
        $this->competition = Leaderboard::with(['circles.students'])->where('supervisor_id', $supervisorId)->findOrFail($this->competitionId);
        $this->theme = GamificationThemeService::getTheme($this->competition);
    }

    public function initializeLevels(): void
    {
        $existing = GamificationLevel::where('leaderboard_id', $this->competitionId)
            ->orderBy('level_number')
            ->get();

        if ($existing->isEmpty()) {
            $defaultLevels = $this->theme['default_levels'] ?? [];
            foreach ($defaultLevels as $num => $lvl) {
                GamificationLevel::create([
                    'leaderboard_id' => $this->competitionId,
                    'level_number' => $num,
                    'name' => $lvl['name'],
                    'xp_required' => $lvl['xp_required'],
                    'icon' => $lvl['icon'],
                    'settings' => [
                        'has_individual_multiplier' => true,
                        'individual_multiplier_price' => 150,
                        'has_team_multiplier' => true,
                        'team_multiplier_price' => 150,
                        'has_freeze' => true,
                        'freeze_price' => 100,
                        'freeze_max_days' => 1,
                        'has_donation' => true,
                        'donation_max_limit' => 10,
                    ],
                ]);
            }
            $existing = GamificationLevel::where('leaderboard_id', $this->competitionId)
                ->orderBy('level_number')
                ->get();
        }

        $this->levels = $existing->map(fn ($l) => [
            'id' => $l->id,
            'level_number' => $l->level_number,
            'name' => $l->name,
            'xp_required' => $l->xp_required,
            'icon' => $l->icon,
            'settings' => $l->settings ?? [
                'has_individual_multiplier' => true,
                'individual_multiplier_price' => 150,
                'has_team_multiplier' => true,
                'team_multiplier_price' => 150,
                'has_freeze' => true,
                'freeze_price' => 100,
                'freeze_max_days' => 1,
                'has_donation' => true,
                'donation_max_limit' => 10,
            ],
        ])->toArray();
    }

    public function saveLevels(): void
    {
        $this->validate([
            'levels.*.name' => 'required|string|max:255',
            'levels.*.xp_required' => 'required|integer|min:0',
        ]);

        DB::transaction(function () {
            // Get IDs of levels we want to keep
            $keptIds = collect($this->levels)->pluck('id')->filter()->toArray();

            // Delete removed levels
            GamificationLevel::where('leaderboard_id', $this->competitionId)
                ->whereNotIn('id', $keptIds)
                ->delete();

            // Update or Create levels
            foreach ($this->levels as $lvl) {
                $lvlSettings = $lvl['settings'] ?? [
                    'has_individual_multiplier' => true,
                    'individual_multiplier_price' => 150,
                    'has_team_multiplier' => true,
                    'team_multiplier_price' => 150,
                    'has_freeze' => true,
                    'freeze_price' => 100,
                    'freeze_max_days' => 1,
                    'has_donation' => true,
                    'donation_max_limit' => 10,
                ];

                // Cast settings properties
                $lvlSettings['has_individual_multiplier'] = (bool) ($lvlSettings['has_individual_multiplier'] ?? true);
                $lvlSettings['individual_multiplier_price'] = (int) ($lvlSettings['individual_multiplier_price'] ?? 150);
                $lvlSettings['has_team_multiplier'] = (bool) ($lvlSettings['has_team_multiplier'] ?? true);
                $lvlSettings['team_multiplier_price'] = (int) ($lvlSettings['team_multiplier_price'] ?? 150);
                $lvlSettings['has_freeze'] = (bool) ($lvlSettings['has_freeze'] ?? true);
                $lvlSettings['freeze_price'] = (int) ($lvlSettings['freeze_price'] ?? 100);
                $lvlSettings['freeze_max_days'] = (int) ($lvlSettings['freeze_max_days'] ?? 1);
                $lvlSettings['has_donation'] = (bool) ($lvlSettings['has_donation'] ?? true);
                $lvlSettings['donation_max_limit'] = (int) ($lvlSettings['donation_max_limit'] ?? 10);
                $lvlSettings['reward_coins'] = max(0, (int) ($lvlSettings['reward_coins'] ?? 0));

                GamificationLevel::updateOrCreate(
                    ['id' => $lvl['id'], 'leaderboard_id' => $this->competitionId],
                    [
                        'level_number' => $lvl['level_number'],
                        'name' => $lvl['name'],
                        'xp_required' => $lvl['xp_required'],
                        'icon' => $lvl['icon'] ?? 'sparkles',
                        'settings' => $lvlSettings,
                    ]
                );
            }
        });

        $this->initializeLevels();

        Flux::toast('تم حفظ مستويات التلعيب بنجاح', variant: 'success');
    }

    public function addLevel(): void
    {
        $newLevelNumber = count($this->levels) + 1;
        $themeDefaultLevels = $this->theme['default_levels'] ?? [];
        $icon = $themeDefaultLevels[$newLevelNumber]['icon'] ?? 'sparkles';

        $this->levels[] = [
            'id' => null,
            'level_number' => $newLevelNumber,
            'name' => '',
            'xp_required' => 0,
            'icon' => $icon,
            'settings' => [
                'has_individual_multiplier' => true,
                'individual_multiplier_price' => 150,
                'has_team_multiplier' => true,
                'team_multiplier_price' => 150,
                'has_freeze' => true,
                'freeze_price' => 100,
                'freeze_max_days' => 1,
                'has_donation' => true,
                'donation_max_limit' => 10,
            ],
        ];
    }

    public function removeLevel(int $index): void
    {
        if (count($this->levels) <= 1) {
            Flux::toast('يجب أن تحتوي المسابقة على مستوى واحد على الأقل.', variant: 'danger');

            return;
        }

        unset($this->levels[$index]);
        $this->levels = array_values($this->levels);

        // Re-index level numbers to be sequential starting from 1
        foreach ($this->levels as $i => &$lvl) {
            $lvl['level_number'] = $i + 1;
        }
    }

    public function initializeCriteria(): void
    {
        $this->criteria = LeaderboardCriterion::where('leaderboard_id', $this->competitionId)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'points' => $c->points,
                'coins' => $c->coins,
                'is_enthusiasm_trigger' => (bool) $c->is_enthusiasm_trigger,
            ])->toArray();
    }

    public function addTodoCriterion(): void
    {
        $this->criteria[] = [
            'id' => null,
            'name' => '',
            'points' => 5,
            'coins' => 5,
            'is_enthusiasm_trigger' => false,
        ];
    }

    public function removeTodoCriterion(int $index): void
    {
        unset($this->criteria[$index]);
        $this->criteria = array_values($this->criteria);
    }

    /**
     * The recalculation cursor, or null when nothing is running. Held in the
     * component so each step is a short request the browser drives — a real
     * competition has thousands of gradings, minutes of work, which no single
     * request can finish.
     *
     * @var array<string, mixed>|null
     */
    public ?array $recalcCursor = null;

    public function startRecalculation(): void
    {
        $this->recalcCursor = GamificationRecalculator::start();
    }

    /**
     * Advance the replay by one batch. Called repeatedly by the page until the
     * cursor reports it is done.
     */
    public function recalculateStep(): void
    {
        if (! $this->recalcCursor || ($this->recalcCursor['done'] ?? false)) {
            return;
        }

        $this->recalcCursor = GamificationRecalculator::step($this->competition, $this->recalcCursor);

        if (! $this->recalcCursor['done']) {
            return;
        }

        $counts = $this->recalcCursor['counts'];
        $this->recalcCursor = null;
        $this->loadCompetition();

        if ($counts['students'] === 0) {
            Flux::toast('لا يوجد طلاب في نطاق هذه المسابقة', variant: 'warning');

            return;
        }

        Flux::toast(
            "تمت إعادة احتساب النقاط لـ {$counts['students']} طالباً: "
            ."{$counts['quran']} تقييم قرآن، ".($counts['free'] ?? 0).' تسميع حر، '
            ."{$counts['ode']} منظومة، {$counts['hadith']} متن، {$counts['attendance']} حضور.",
            variant: 'success',
        );
    }

    /** Human label for the stage currently being replayed. */
    public function recalcStageLabel(): string
    {
        return match ($this->recalcCursor['stage'] ?? null) {
            'quran' => 'تقييمات القرآن',
            'ode' => 'تقييمات المنظومات',
            'hadith' => 'تقييمات المتون',
            'attendance' => 'سجلات الحضور',
            'students' => 'المستويات والأرصدة والاستريك',
            default => '',
        };
    }

    public function saveCriteria(): void
    {
        $this->validate([
            'criteria.*.name' => 'required|string|max:255',
            'criteria.*.points' => 'required|integer|min:0',
            'criteria.*.coins' => 'required|integer|min:0',
            'criteria.*.is_enthusiasm_trigger' => 'boolean',
            'hifz_excellent_xp' => 'required|integer|min:0',
            'hifz_excellent_coins' => 'required|integer|min:0',
            'hifz_good_xp' => 'required|integer|min:0',
            'hifz_good_coins' => 'required|integer|min:0',
            'hifz_acceptable_xp' => 'required|integer|min:0',
            'hifz_acceptable_coins' => 'required|integer|min:0',
            'review_excellent_xp' => 'required|integer|min:0',
            'review_excellent_coins' => 'required|integer|min:0',
            'review_good_xp' => 'required|integer|min:0',
            'review_good_coins' => 'required|integer|min:0',
            'attendance_present_xp' => 'required|integer|min:0',
            'attendance_present_coins' => 'required|integer|min:0',
            'attendance_late_xp' => 'required|integer|min:0',
            'attendance_late_coins' => 'required|integer|min:0',
            'ode_hifz_excellent_xp' => 'required|integer|min:0',
            'ode_hifz_excellent_coins' => 'required|integer|min:0',
            'ode_hifz_good_xp' => 'required|integer|min:0',
            'ode_hifz_good_coins' => 'required|integer|min:0',
            'ode_hifz_acceptable_xp' => 'required|integer|min:0',
            'ode_hifz_acceptable_coins' => 'required|integer|min:0',
            'ode_review_excellent_xp' => 'required|integer|min:0',
            'ode_review_excellent_coins' => 'required|integer|min:0',
            'ode_review_good_xp' => 'required|integer|min:0',
            'ode_review_good_coins' => 'required|integer|min:0',
            'hadith_hifz_excellent_xp' => 'required|integer|min:0',
            'hadith_hifz_excellent_coins' => 'required|integer|min:0',
            'hadith_hifz_good_xp' => 'required|integer|min:0',
            'hadith_hifz_good_coins' => 'required|integer|min:0',
            'hadith_hifz_acceptable_xp' => 'required|integer|min:0',
            'hadith_hifz_acceptable_coins' => 'required|integer|min:0',
            'hadith_review_excellent_xp' => 'required|integer|min:0',
            'hadith_review_excellent_coins' => 'required|integer|min:0',
            'hadith_review_good_xp' => 'required|integer|min:0',
            'hadith_review_good_coins' => 'required|integer|min:0',
        ]);

        DB::transaction(function () {
            // Save custom criteria
            $keptIds = collect($this->criteria)->pluck('id')->filter()->toArray();

            LeaderboardCriterion::where('leaderboard_id', $this->competitionId)
                ->whereNotIn('id', $keptIds)
                ->delete();

            foreach ($this->criteria as $c) {
                LeaderboardCriterion::updateOrCreate(
                    ['id' => $c['id'], 'leaderboard_id' => $this->competitionId],
                    [
                        'name' => $c['name'],
                        'points' => $c['points'],
                        'coins' => $c['coins'] ?? 0,
                        'is_enthusiasm_trigger' => $c['is_enthusiasm_trigger'],
                    ]
                );
            }

            // Save automatic settings in Leaderboard settings
            $this->competition->settings = array_merge($this->competition->settings ?? [], [
                'hifz_enabled' => $this->hifz_enabled,
                'hifz_excellent_xp' => $this->hifz_excellent_xp,
                'hifz_excellent_coins' => $this->hifz_excellent_coins,
                'hifz_good_xp' => $this->hifz_good_xp,
                'hifz_good_coins' => $this->hifz_good_coins,
                'hifz_acceptable_xp' => $this->hifz_acceptable_xp,
                'hifz_acceptable_coins' => $this->hifz_acceptable_coins,
                'hifz_enthusiasm_trigger' => $this->hifz_enthusiasm_trigger,

                'review_enabled' => $this->review_enabled,
                'review_excellent_xp' => $this->review_excellent_xp,
                'review_excellent_coins' => $this->review_excellent_coins,
                'review_good_xp' => $this->review_good_xp,
                'review_good_coins' => $this->review_good_coins,
                'review_enthusiasm_trigger' => $this->review_enthusiasm_trigger,

                'attendance_enabled' => $this->attendance_enabled,
                'attendance_present_xp' => $this->attendance_present_xp,
                'attendance_present_coins' => $this->attendance_present_coins,
                'attendance_late_xp' => $this->attendance_late_xp,
                'attendance_late_coins' => $this->attendance_late_coins,
                'attendance_enthusiasm_trigger' => $this->attendance_enthusiasm_trigger,

                'manual_claim_enabled' => $this->manual_claim_enabled,

                'ode_hifz_enabled' => $this->ode_hifz_enabled,
                'ode_hifz_excellent_xp' => $this->ode_hifz_excellent_xp,
                'ode_hifz_excellent_coins' => $this->ode_hifz_excellent_coins,
                'ode_hifz_good_xp' => $this->ode_hifz_good_xp,
                'ode_hifz_good_coins' => $this->ode_hifz_good_coins,
                'ode_hifz_acceptable_xp' => $this->ode_hifz_acceptable_xp,
                'ode_hifz_acceptable_coins' => $this->ode_hifz_acceptable_coins,
                'ode_hifz_enthusiasm_trigger' => $this->ode_hifz_enthusiasm_trigger,

                'ode_review_enabled' => $this->ode_review_enabled,
                'ode_review_excellent_xp' => $this->ode_review_excellent_xp,
                'ode_review_excellent_coins' => $this->ode_review_excellent_coins,
                'ode_review_good_xp' => $this->ode_review_good_xp,
                'ode_review_good_coins' => $this->ode_review_good_coins,
                'ode_review_enthusiasm_trigger' => $this->ode_review_enthusiasm_trigger,

                'hadith_hifz_enabled' => $this->hadith_hifz_enabled,
                'hadith_hifz_excellent_xp' => $this->hadith_hifz_excellent_xp,
                'hadith_hifz_excellent_coins' => $this->hadith_hifz_excellent_coins,
                'hadith_hifz_good_xp' => $this->hadith_hifz_good_xp,
                'hadith_hifz_good_coins' => $this->hadith_hifz_good_coins,
                'hadith_hifz_acceptable_xp' => $this->hadith_hifz_acceptable_xp,
                'hadith_hifz_acceptable_coins' => $this->hadith_hifz_acceptable_coins,
                'hadith_hifz_enthusiasm_trigger' => $this->hadith_hifz_enthusiasm_trigger,

                'hadith_review_enabled' => $this->hadith_review_enabled,
                'hadith_review_excellent_xp' => $this->hadith_review_excellent_xp,
                'hadith_review_excellent_coins' => $this->hadith_review_excellent_coins,
                'hadith_review_good_xp' => $this->hadith_review_good_xp,
                'hadith_review_good_coins' => $this->hadith_review_good_coins,
                'hadith_review_enthusiasm_trigger' => $this->hadith_review_enthusiasm_trigger,
            ]);
            $this->competition->save();
        });

        $this->initializeCriteria();
        $this->initializeAutomaticSettings();

        Flux::toast('تم حفظ بنود التقييم بنجاح', variant: 'success');
    }

    // --- Streak Milestones Logic ---
    public function createMilestone(): void
    {
        $this->reset('editingMilestoneId', 'days_required', 'reward_xp', 'reward_coins', 'reward_badge_id', 'milestone_description');
        $this->showMilestoneModal = true;
    }

    public function editMilestone($id): void
    {
        $milestone = DB::table('gamification_streak_milestones')->where('leaderboard_id', $this->competitionId)->where('id', $id)->first();
        if ($milestone) {
            $this->editingMilestoneId = $milestone->id;
            $this->days_required = $milestone->days_required;
            $this->reward_xp = $milestone->reward_xp;
            $this->reward_coins = $milestone->reward_coins;
            $this->reward_badge_id = $milestone->reward_badge_id;
            $this->milestone_description = $milestone->description;
            $this->showMilestoneModal = true;
        }
    }

    public function saveMilestone(): void
    {
        $this->validate([
            'days_required' => 'required|integer|min:1',
            'reward_xp' => 'required|integer|min:0',
            'reward_coins' => 'required|integer|min:0',
            'reward_badge_id' => ['nullable', $this->ownRow('gamification_badges')],
            'milestone_description' => 'nullable|string|max:255',
        ]);

        if ($this->editingMilestoneId) {
            DB::table('gamification_streak_milestones')->where('leaderboard_id', $this->competitionId)->where('id', $this->editingMilestoneId)->update([
                'days_required' => $this->days_required,
                'reward_xp' => $this->reward_xp,
                'reward_coins' => $this->reward_coins,
                'reward_badge_id' => $this->reward_badge_id,
                'description' => $this->milestone_description,
                'updated_at' => now(),
            ]);
            Flux::toast('تم تحديث مكافأة الحماسة بنجاح', variant: 'success');
        } else {
            DB::table('gamification_streak_milestones')->insert([
                'leaderboard_id' => $this->competitionId,
                'days_required' => $this->days_required,
                'reward_xp' => $this->reward_xp,
                'reward_coins' => $this->reward_coins,
                'reward_badge_id' => $this->reward_badge_id,
                'description' => $this->milestone_description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Flux::toast('تم إضافة مكافأة الحماسة بنجاح', variant: 'success');
        }

        $this->showMilestoneModal = false;
    }

    public function deleteMilestone($id): void
    {
        DB::table('gamification_streak_milestones')
            ->where('leaderboard_id', $this->competitionId)
            ->where('id', $id)
            ->delete();
        Flux::toast('تم حذف مكافأة الحماسة', variant: 'success');
    }

    // --- Badges Logic ---
    public function createBadge(): void
    {
        $this->reset('editingBadgeId', 'badge_name', 'badge_description', 'badge_icon', 'badge_type', 'badge_requirement_value', 'badge_leaderboard_criterion_id', 'badge_image_file', 'badge_reward_xp', 'badge_reward_coins');
        $this->badgeStep = 1;
        $this->badge_mechanism = 'manual';
        $this->badge_achievement_type = '';
        $this->showBadgeModal = true;
    }

    public function editBadge($id): void
    {
        $badge = GamificationBadge::where('leaderboard_id', $this->competitionId)->findOrFail($id);
        $this->editingBadgeId = $badge->id;
        $this->badge_name = $badge->name;
        $this->badge_description = $badge->description;
        $this->badge_icon = $badge->icon;
        $this->badge_type = $badge->badge_type;
        $this->badge_requirement_value = $badge->requirement_value;
        $this->badge_leaderboard_criterion_id = $badge->leaderboard_criterion_id;
        $this->badge_reward_xp = (int) $badge->reward_xp;
        $this->badge_reward_coins = (int) $badge->reward_coins;
        $this->badge_image_file = null;

        // Decompose badge_type
        $this->badgeStep = 1;
        $type = $badge->badge_type;
        if (str_starts_with($type, 'streak_')) {
            $this->badge_mechanism = 'streak';
            $this->badge_achievement_type = substr($type, 7);
        } elseif (str_starts_with($type, 'count_')) {
            $this->badge_mechanism = 'count';
            $this->badge_achievement_type = substr($type, 6);
        } elseif ($type === 'hifz_streak') {
            $this->badge_mechanism = 'streak';
            $this->badge_achievement_type = 'hifz';
        } elseif ($type === 'attendance_streak') {
            $this->badge_mechanism = 'streak';
            $this->badge_achievement_type = 'attendance';
        } elseif ($type === 'custom' || $type === 'manual') {
            if ($badge->leaderboard_criterion_id) {
                $this->badge_mechanism = 'count';
                $this->badge_achievement_type = 'criterion';
            } else {
                $this->badge_mechanism = 'manual';
                $this->badge_achievement_type = '';
            }
        } else {
            $this->badge_mechanism = 'manual';
            $this->badge_achievement_type = '';
        }

        $this->showBadgeModal = true;
    }

    public function nextBadgeStep(): void
    {
        if ($this->badgeStep === 1) {
            $this->validate([
                'badge_name' => 'required|string|max:255',
                'badge_description' => 'nullable|string',
            ]);
            $this->badgeStep = 2;
        } elseif ($this->badgeStep === 2) {
            $rules = [];
            if (! $this->editingBadgeId) {
                $rules['badge_image_file'] = 'required|image|max:2048';
            } else {
                $rules['badge_image_file'] = 'nullable|image|max:2048';
            }

            $this->validate($rules);
            $this->badgeStep = 3;
        }
    }

    public function prevBadgeStep(): void
    {
        if ($this->badgeStep > 1) {
            $this->badgeStep--;
        }
    }

    public function saveBadge(): void
    {
        $rules = [
            'badge_name' => 'required|string|max:255',
            'badge_description' => 'nullable|string',
            'badge_mechanism' => 'required|in:streak,count,manual',
        ];

        if (! $this->editingBadgeId) {
            $rules['badge_image_file'] = 'required|image|max:2048';
        } else {
            $rules['badge_image_file'] = 'nullable|image|max:2048';
        }

        $this->validate($rules);

        // Process file upload and convert to WebP
        if ($this->badge_image_file) {
            $manager = new ImageManager(new Driver);
            $tempPath = $this->badge_image_file->getRealPath();

            $image = $manager->decode($tempPath);
            $image->scale(height: 256);

            $webpData = $image->encode(new WebpEncoder(80))->toString();
            $filename = 'badges/'.uniqid().'.webp';

            Storage::disk('public')->put($filename, $webpData);
            $this->badge_icon = $filename;
        }

        if ($this->badge_mechanism === 'manual') {
            $this->badge_type = 'manual';
            $this->badge_requirement_value = 0;
            $this->badge_leaderboard_criterion_id = null;
        } else {
            $this->validate([
                'badge_achievement_type' => 'required|in:hifz,review,attendance,criterion',
                'badge_requirement_value' => 'required|integer|min:1',
            ]);

            if ($this->badge_achievement_type === 'criterion') {
                $this->validate([
                    'badge_leaderboard_criterion_id' => ['required', $this->ownRow('leaderboard_criteria')],
                ]);
            } else {
                $this->badge_leaderboard_criterion_id = null;
            }

            $this->badge_type = $this->badge_mechanism.'_'.$this->badge_achievement_type;
        }

        GamificationBadge::updateOrCreate(
            ['id' => $this->editingBadgeId],
            [
                'leaderboard_id' => $this->competitionId,
                'name' => $this->badge_name,
                'description' => $this->badge_description,
                'icon' => $this->badge_icon,
                'badge_type' => $this->badge_type,
                'requirement_value' => $this->badge_requirement_value,
                'leaderboard_criterion_id' => $this->badge_leaderboard_criterion_id ?: null,
                'reward_xp' => $this->badge_reward_xp,
                'reward_coins' => $this->badge_reward_coins,
            ]
        );

        // Re-sync every participant so students who already meet a newly created or
        // edited automatic badge's requirement are awarded it immediately (otherwise
        // they would only get it on their next attendance/grading event).
        foreach ($this->getStudents() as $student) {
            GamificationService::syncStudentBadges($student->id, $this->competitionId);
        }

        Flux::toast('تم حفظ الوسام بنجاح', variant: 'success');
        $this->showBadgeModal = false;
    }

    public function deleteBadge($id): void
    {
        GamificationBadge::where('leaderboard_id', $this->competitionId)->findOrFail($id)->delete();
        Flux::toast('تم حذف الوسام', variant: 'success');
    }

    /**
     * Open the hand-out panel for a badge.
     *
     * A badge whose mechanism is "manual" has no progress to measure, so the
     * automatic sync never touches it. Until now nothing else did either: the
     * mechanism could be chosen — it is the default in the badge form — but
     * the badge could not reach a student by any route.
     */
    public function openGrantBadge(int $badgeId): void
    {
        $this->grantingBadgeId = $badgeId;
        $this->grantStudentIds = [];
        $this->grantSearch = '';
        $this->showGrantBadgeModal = true;
    }

    /**
     * The students of this competition, with whether they already hold the
     * badge being handed out and how far along they are with it.
     *
     * @return array<int, array{student: Student, status: string|null}>
     */
    #[Computed]
    public function grantCandidates(): array
    {
        if (! $this->grantingBadgeId) {
            return [];
        }

        $held = DB::table('gamification_badge_student')
            ->where('badge_id', $this->grantingBadgeId)
            ->pluck('status', 'student_id');

        return $this->getStudents()
            ->when($this->grantSearch !== '', fn ($students) => $students->filter(
                fn (Student $student) => str_contains($student->name, $this->grantSearch)
            ))
            ->sortBy('name')
            ->map(fn (Student $student) => [
                'student' => $student,
                'status' => $held[$student->id] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Hand the badge to the chosen students.
     *
     * It lands as approved rather than pending: the supervisor choosing a
     * student by name is the decision an approval would otherwise record. The
     * student still claims it, so the reward flows through the same path as
     * every other badge.
     */
    public function grantBadge(): void
    {
        $this->validate([
            'grantingBadgeId' => ['required', $this->ownRow('gamification_badges')],
            'grantStudentIds' => 'required|array|min:1',
            'grantStudentIds.*' => 'exists:users,id',
        ], [
            'grantStudentIds.required' => 'اختر طالباً واحداً على الأقل.',
        ]);

        $badge = GamificationBadge::where('leaderboard_id', $this->competitionId)->findOrFail($this->grantingBadgeId);
        $eligible = $this->getStudents()->pluck('id');
        $granted = 0;

        foreach ($this->grantStudentIds as $studentId) {
            $studentId = (int) $studentId;

            // Only students this competition covers, and never over a badge the
            // student already holds — that would reset a claimed one to unclaimed.
            if (! $eligible->contains($studentId)) {
                continue;
            }

            $exists = DB::table('gamification_badge_student')
                ->where('badge_id', $badge->id)
                ->where('student_id', $studentId)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('gamification_badge_student')->insert([
                'badge_id' => $badge->id,
                'student_id' => $studentId,
                'status' => 'approved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            GamificationNewsService::record($this->competitionId, 'badge', [
                'student_id' => $studentId,
                'student_name' => Student::find($studentId)?->name ?? '',
                'badge_name' => $badge->name,
                'badge_icon' => $badge->icon,
            ]);

            $granted++;
        }

        $this->showGrantBadgeModal = false;
        $this->grantStudentIds = [];

        Flux::toast($granted > 0
            ? "تم منح الوسام لـ {$granted} طالب، وينتظر استلامهم له."
            : 'كل من اخترتهم يحملون هذا الوسام بالفعل.',
            variant: $granted > 0 ? 'success' : 'warning');
    }

    /**
     * Take back a badge that was handed out by mistake. A claimed one stays:
     * its reward has already been paid.
     */
    public function revokeBadge(int $badgeId, int $studentId): void
    {
        abort_unless(GamificationBadge::where('leaderboard_id', $this->competitionId)->whereKey($badgeId)->exists(), 404);

        $deleted = DB::table('gamification_badge_student')
            ->where('badge_id', $badgeId)
            ->where('student_id', $studentId)
            ->where('status', '!=', 'claimed')
            ->delete();

        Flux::toast($deleted
            ? 'تم سحب الوسام.'
            : 'لا يمكن سحب وسام استلمه الطالب.',
            variant: $deleted ? 'success' : 'warning');
    }

    // --- Teams Logic ---
    public function createTeam(): void
    {
        $this->reset('editingTeamId', 'team_name', 'team_color', 'team_slogan', 'team_logo_file', 'existing_logo_path', 'team_leader_id', 'team_assistant_id', 'team_student_ids');
        $this->team_color = '#4f46e5';
        $this->team_step = 1;
        $this->showTeamModal = true;
    }

    public function editTeam($id): void
    {
        $team = GamificationTeam::with('students')->where('leaderboard_id', $this->competitionId)->findOrFail($id);
        $this->editingTeamId = $team->id;
        $this->team_name = $team->name;
        $this->team_color = $team->color ?? '#4f46e5';
        $this->team_slogan = $team->slogan ?? '';
        $this->existing_logo_path = $team->logo_path;
        $this->team_logo_file = null;

        $this->team_leader_id = $team->students()->wherePivot('role', 'leader')->first()?->id;
        $this->team_assistant_id = $team->students()->wherePivot('role', 'assistant')->first()?->id;

        $this->team_student_ids = $team->students()->pluck('users.id')->map(fn ($id) => (string) $id)->toArray();

        $this->team_step = 1;
        $this->showTeamModal = true;
    }

    public function nextTeamStep(): void
    {
        if ($this->team_step === 1) {
            $this->validate([
                'team_name' => 'required|string|max:255',
                'team_slogan' => 'nullable|string|max:255',
                'team_logo_file' => 'nullable|image|max:2048',
            ]);
        } elseif ($this->team_step === 2) {
            $this->validate([
                'team_color' => ['required', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],
            ]);
        }

        if ($this->team_step < 4) {
            $this->team_step++;
        }
    }

    public function prevTeamStep(): void
    {
        if ($this->team_step > 1) {
            $this->team_step--;
        }
    }

    public function saveTeam(): void
    {
        $this->validate([
            'team_name' => 'required|string|max:255',
            'team_slogan' => 'nullable|string|max:255',
            'team_logo_file' => 'nullable|image|max:2048',
            'team_color' => ['required', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],
            'team_leader_id' => ['nullable', Rule::in($this->competitionStudentIds())],
            'team_assistant_id' => ['nullable', Rule::in($this->competitionStudentIds())],
            'team_student_ids.*' => [Rule::in($this->competitionStudentIds())],
        ]);

        $logoPath = $this->existing_logo_path;

        if ($this->team_logo_file) {
            $manager = new ImageManager(new Driver);
            $tempPath = $this->team_logo_file->getRealPath();

            $image = $manager->decode($tempPath);
            $image->scale(height: 256);

            $webpData = $image->encode(new WebpEncoder(80))->toString();
            $filename = 'teams_logos/'.uniqid().'.webp';

            Storage::disk('public')->put($filename, $webpData);
            $logoPath = $filename;
        }

        DB::transaction(function () use ($logoPath) {
            $team = GamificationTeam::updateOrCreate(
                ['id' => $this->editingTeamId],
                [
                    'leaderboard_id' => $this->competitionId,
                    'name' => $this->team_name,
                    'color' => $this->team_color,
                    'logo_path' => $logoPath,
                    'slogan' => $this->team_slogan ?: null,
                ]
            );

            // Get selected student IDs
            $selectedStudentIds = collect($this->team_student_ids)->map(fn ($id) => (int) $id)->filter()->unique()->toArray();

            // Include leader and assistant if chosen
            if ($this->team_leader_id) {
                $selectedStudentIds[] = (int) $this->team_leader_id;
            }
            if ($this->team_assistant_id) {
                $selectedStudentIds[] = (int) $this->team_assistant_id;
            }
            $selectedStudentIds = array_unique($selectedStudentIds);

            // 1. Remove these students from any other teams in the same leaderboard to maintain uniqueness
            $otherTeamIds = GamificationTeam::where('leaderboard_id', $this->competitionId)
                ->where('id', '!=', $team->id)
                ->pluck('id')
                ->toArray();

            if (! empty($otherTeamIds) && ! empty($selectedStudentIds)) {
                DB::table('gamification_team_student')
                    ->whereIn('team_id', $otherTeamIds)
                    ->whereIn('student_id', $selectedStudentIds)
                    ->delete();
            }

            // 2. Clear current members of this team
            DB::table('gamification_team_student')
                ->where('team_id', $team->id)
                ->delete();

            // 3. Insert new student assignments with roles
            foreach ($selectedStudentIds as $studentId) {
                $role = 'member';
                if ($studentId === (int) $this->team_leader_id) {
                    $role = 'leader';
                } elseif ($studentId === (int) $this->team_assistant_id) {
                    $role = 'assistant';
                }

                DB::table('gamification_team_student')->insert([
                    'team_id' => $team->id,
                    'student_id' => $studentId,
                    'role' => $role,
                ]);
            }
        });

        Flux::toast('تم حفظ الأسرة بنجاح', variant: 'success');
        $this->showTeamModal = false;
        $this->loadDistributionData();
    }

    public function deleteTeam($id): void
    {
        GamificationTeam::where('leaderboard_id', $this->competitionId)->findOrFail($id)->delete();
        Flux::toast('تم حذف الفريق', variant: 'success');
    }

    // --- Tracks (ranking divisions) Logic ---
    public bool $showTrackModal = false;

    #[Locked]
    public ?int $editingTrackId = null;

    public string $track_name = '';

    public string $track_description = '';

    public array $track_student_ids = [];

    public function createTrack(): void
    {
        $this->reset('editingTrackId', 'track_name', 'track_description', 'track_student_ids');
        $this->showTrackModal = true;
    }

    public function editTrack($id): void
    {
        $track = GamificationTrack::with('students:id')->where('leaderboard_id', $this->competitionId)->findOrFail($id);
        $this->editingTrackId = $track->id;
        $this->track_name = $track->name;
        $this->track_description = $track->description ?? '';
        $this->track_student_ids = $track->students->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        $this->showTrackModal = true;
    }

    public function saveTrack(): void
    {
        $this->validate([
            'track_name' => 'required|string|max:255',
            'track_description' => 'nullable|string|max:1000',
            'track_student_ids.*' => [Rule::in($this->competitionStudentIds())],
        ]);

        DB::transaction(function () {
            $track = GamificationTrack::updateOrCreate(
                ['id' => $this->editingTrackId],
                [
                    'leaderboard_id' => $this->competitionId,
                    'name' => $this->track_name,
                    'description' => $this->track_description ?: null,
                    'sort_order' => $this->editingTrackId
                        ? (GamificationTrack::where('leaderboard_id', $this->competitionId)->find($this->editingTrackId)->sort_order ?? 0)
                        : (GamificationTrack::where('leaderboard_id', $this->competitionId)->max('sort_order') + 1),
                ]
            );

            $studentIds = collect($this->track_student_ids)->map(fn ($id) => (int) $id)->filter()->unique()->toArray();

            // Enforce one track per student within this competition.
            $otherTrackIds = GamificationTrack::where('leaderboard_id', $this->competitionId)
                ->where('id', '!=', $track->id)
                ->pluck('id')
                ->toArray();

            if (! empty($otherTrackIds) && ! empty($studentIds)) {
                DB::table('gamification_track_student')
                    ->whereIn('track_id', $otherTrackIds)
                    ->whereIn('student_id', $studentIds)
                    ->delete();
            }

            $track->students()->sync($studentIds);
        });

        $this->showTrackModal = false;
        $this->reset('editingTrackId', 'track_name', 'track_description', 'track_student_ids');
        Flux::toast('تم حفظ المسار بنجاح', variant: 'success');
    }

    public function deleteTrack($id): void
    {
        GamificationTrack::where('leaderboard_id', $this->competitionId)->findOrFail($id)->delete();
        Flux::toast('تم حذف المسار', variant: 'success');
    }

    // --- Store Logic ---
    public function isEditingPermanentItem(): bool
    {
        if (! $this->editingItemId) {
            return false;
        }
        $item = GamificationStoreItem::where('leaderboard_id', $this->competitionId)->find($this->editingItemId);

        return $item && (
            ($item->item_type === 'multiplier' && ! $item->is_team_product) ||
            ($item->item_type === 'freeze' && ! $item->is_team_product)
        );
    }

    public function createItem(): void
    {
        $this->reset('editingItemId', 'item_name', 'item_description', 'item_price', 'item_type', 'item_value', 'item_is_team_product', 'item_is_streak_freeze', 'item_target_date', 'item_require_assistant_approval', 'item_require_member_approval_count', 'item_is_active', 'freezeLevelRules');
        $this->item_type = 'freeze';
        $this->item_value = 0;
        $this->item_is_team_product = false;
        $this->item_is_streak_freeze = true;
        $this->item_is_active = true;
        $this->showItemModal = true;
    }

    public function editItem($id): void
    {
        $item = GamificationStoreItem::where('leaderboard_id', $this->competitionId)->findOrFail($id);
        $this->editingItemId = $item->id;
        $this->item_name = $item->name;
        $this->item_description = $item->description;
        $this->item_price = $item->price;
        $this->item_type = $item->item_type;
        $this->item_value = $item->value;
        $this->item_is_team_product = (bool) $item->is_team_product;
        $this->item_is_streak_freeze = (bool) $item->is_streak_freeze;
        $this->item_target_date = $item->target_date ? $item->target_date->format('Y-m-d') : null;
        $this->item_require_assistant_approval = (bool) $item->require_assistant_approval;
        $this->item_require_member_approval_count = (int) $item->require_member_approval_count;
        $this->item_is_active = (bool) $item->is_active;

        if ($this->item_type === 'freeze' && ! $this->item_is_team_product) {
            $this->freezeLevelRules = $this->competition->settings['streak_freeze_levels'] ?? [];
        } else {
            $this->freezeLevelRules = [];
        }

        $this->showItemModal = true;
    }

    public function saveItem(): void
    {
        $rules = [
            'item_name' => 'required|string|max:255',
            'item_description' => 'nullable|string',
            'item_price' => 'required|integer|min:0',
            'item_type' => 'required|in:freeze,team_attack,shield,team_points',
        ];

        $isPermanent = $this->isEditingPermanentItem();

        if ($isPermanent) {
            $item = GamificationStoreItem::where('leaderboard_id', $this->competitionId)->find($this->editingItemId);
            $this->item_type = $item->item_type;
            $this->item_is_team_product = false;
            $this->item_is_streak_freeze = ($this->item_type === 'freeze');
            $this->item_value = ($this->item_type === 'multiplier') ? 2 : 0;
            $this->item_target_date = null;
        } else {
            if ($this->item_type === 'freeze') {
                $this->item_is_team_product = false;
                $this->item_is_streak_freeze = true;
            } else {
                $this->item_is_team_product = true;
                $this->item_is_streak_freeze = false;
            }
        }

        if (in_array($this->item_type, ['team_attack', 'team_points'])) {
            $rules['item_value'] = 'required|integer|min:1';
        } else {
            $rules['item_value'] = 'nullable|integer|min:0';
        }

        $rules['item_target_date'] = 'nullable|date';
        $rules['item_require_assistant_approval'] = 'boolean';
        $rules['item_require_member_approval_count'] = 'integer|min:0';
        $rules['item_is_active'] = 'boolean';

        if ($this->item_type === 'freeze' && ! $this->item_is_team_product) {
            $rules['freezeLevelRules.*.level'] = 'required|integer|min:1';
            $rules['freezeLevelRules.*.days'] = 'required|integer|min:1';
        }

        $this->validate($rules);

        // If not a team product, reset voting conditions to defaults
        if (! $this->item_is_team_product) {
            $this->item_require_assistant_approval = false;
            $this->item_require_member_approval_count = 0;
        }

        GamificationStoreItem::updateOrCreate(
            ['id' => $this->editingItemId],
            [
                'leaderboard_id' => $this->competitionId,
                'name' => $this->item_name,
                'description' => $this->item_description ?: null,
                'price' => $this->item_price,
                'item_type' => $this->item_type,
                'value' => in_array($this->item_type, ['team_attack', 'team_points']) ? (int) $this->item_value : 0,
                'target_date' => $this->item_type === 'shield' ? ($this->item_target_date ?: null) : null,
                'is_team_product' => $this->item_is_team_product,
                'is_streak_freeze' => $this->item_is_streak_freeze,
                'require_assistant_approval' => $this->item_require_assistant_approval,
                'require_member_approval_count' => $this->item_require_member_approval_count,
                'is_active' => $this->item_is_active,
            ]
        );

        if ($this->item_type === 'freeze' && ! $this->item_is_team_product) {
            $this->competition->settings = array_merge($this->competition->settings ?? [], [
                'streak_freeze_levels' => $this->freezeLevelRules,
            ]);
            $this->competition->save();
        }

        Flux::toast('تم حفظ عنصر المتجر بنجاح', variant: 'success');
        $this->showItemModal = false;
    }

    public function updatedItemType($value): void
    {
        if ($value === 'freeze' || $value === 'shield') {
            $this->item_value = 0;
            $this->item_target_date = null;
        } elseif ($value === 'team_attack' || $value === 'team_points') {
            $this->item_value = 50;
            $this->item_target_date = null;
        }
    }

    public function deleteItem($id): void
    {
        $item = GamificationStoreItem::where('leaderboard_id', $this->competitionId)->findOrFail($id);
        if (($item->item_type === 'multiplier' && ! $item->is_team_product) || ($item->item_type === 'freeze' && ! $item->is_team_product)) {
            Flux::toast('خطأ: لا يمكن حذف المنتجات الثابتة.', variant: 'danger');

            return;
        }
        $item->delete();
        Flux::toast('تم حذف عنصر المتجر', variant: 'success');
    }

    public function toggleProductStatus(int $id): void
    {
        $item = GamificationStoreItem::where('leaderboard_id', $this->competitionId)->findOrFail($id);
        $item->is_active = ! $item->is_active;
        $item->save();

        Flux::toast('تم تحديث حالة المنتج بنجاح', variant: 'success');
    }

    /**
     * Cancel a store purchase for this competition and reverse its effect
     * (refund coins, undo team support/attack, strip multiplier/shield/freeze).
     */
    public function cancelPurchase(int $id): void
    {
        $purchase = GamificationStorePurchase::whereHas('item', function ($query) {
            $query->where('leaderboard_id', $this->competitionId);
        })->find($id);

        if (! $purchase) {
            Flux::toast('لم يتم العثور على عملية الشراء.', variant: 'danger');

            return;
        }

        $status = GamificationService::cancelPurchase($id);

        if ($status === 'success') {
            Flux::toast('تم إلغاء عملية الشراء واسترداد العملات وإلغاء تأثيرها بنجاح.', variant: 'success');
        } elseif ($status === 'not_cancellable') {
            Flux::toast('لا يمكن إلغاء هذه العملية (ملغاة أو مرفوضة مسبقاً).', variant: 'danger');
        } else {
            Flux::toast('تعذر إلغاء عملية الشراء.', variant: 'danger');
        }
    }

    public function addFreezeLevelRule(): void
    {
        $this->freezeLevelRules[] = ['level' => 1, 'days' => 1];
    }

    public function removeFreezeLevelRule(int $index): void
    {
        unset($this->freezeLevelRules[$index]);
        $this->freezeLevelRules = array_values($this->freezeLevelRules);
    }

    // --- Group/Team Tasks (Definitions) Logic ---
    public function addTaskCriterion(): void
    {
        $this->task_criteria[] = [
            'id' => null,
            'name' => '',
            'description' => '',
            'coins_reward' => 10,
        ];
    }

    public function removeTaskCriterion(int $index): void
    {
        unset($this->task_criteria[$index]);
        $this->task_criteria = array_values($this->task_criteria);
    }

    public function createTeamTask(): void
    {
        $this->reset(
            'editingTeamTaskId',
            'task_name',
            'task_description',
            'task_evaluation_criteria',
            'task_criteria'
        );
        $this->task_criteria = [];
        $this->task_xp_reward = 50;
        $this->task_coins_reward = 50;
        $this->showTeamTaskModal = true;
    }

    public function editTeamTask($id): void
    {
        $task = GamificationTeamTask::with('criteria')->where('leaderboard_id', $this->competitionId)->findOrFail($id);
        $this->editingTeamTaskId = $task->id;
        $this->task_name = $task->name;
        $this->task_description = $task->description ?? '';
        $this->task_evaluation_criteria = $task->evaluation_criteria ?? '';
        $this->task_xp_reward = $task->xp_reward;
        $this->task_coins_reward = $task->coins_reward;
        $this->task_criteria = $task->criteria->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'description' => $c->description ?? '',
            'coins_reward' => $c->coins_reward ?? 0,
        ])->toArray();
        $this->showTeamTaskModal = true;
    }

    public function saveTeamTask(): void
    {
        $this->validate([
            'task_name' => 'required|string|max:255',
            'task_description' => 'nullable|string',
            'task_evaluation_criteria' => 'nullable|string',
            'task_xp_reward' => 'required|integer|min:0',
            'task_coins_reward' => 'required|integer|min:0',
            'task_criteria.*.name' => 'required|string|max:255',
            'task_criteria.*.description' => 'nullable|string',
            'task_criteria.*.coins_reward' => 'required|integer|min:0',
        ]);

        DB::transaction(function () {
            if (! empty($this->task_criteria)) {
                $this->task_coins_reward = array_sum(array_column($this->task_criteria, 'coins_reward'));
            }

            $task = GamificationTeamTask::updateOrCreate(
                ['id' => $this->editingTeamTaskId],
                [
                    'leaderboard_id' => $this->competitionId,
                    'name' => $this->task_name,
                    'description' => $this->task_description ?: null,
                    'evaluation_criteria' => $this->task_evaluation_criteria ?: null,
                    'xp_reward' => $this->task_xp_reward,
                    'coins_reward' => $this->task_coins_reward,
                ]
            );

            $keptIds = collect($this->task_criteria)->pluck('id')->filter()->toArray();
            $task->criteria()->whereNotIn('id', $keptIds)->delete();

            foreach ($this->task_criteria as $criterion) {
                $task->criteria()->updateOrCreate(
                    ['id' => $criterion['id']],
                    [
                        'name' => $criterion['name'],
                        'description' => $criterion['description'] ?: null,
                        'coins_reward' => $criterion['coins_reward'] ?? 0,
                    ]
                );
            }
        });

        Flux::toast('تم حفظ تعريف المهمة بنجاح', variant: 'success');
        $this->showTeamTaskModal = false;
    }

    public function deleteTeamTask($id): void
    {
        $task = GamificationTeamTask::where('leaderboard_id', $this->competitionId)->findOrFail($id);

        DB::transaction(function () use ($task) {
            $assignments = $task->assignments()->get();

            foreach ($assignments as $assignment) {
                $tx = GamificationTransaction::where('leaderboard_id', $this->competitionId)
                    ->where('reference_type', GamificationTeamTaskAssignment::class)
                    ->where('reference_id', $assignment->id)
                    ->first();

                if ($tx) {
                    $team = GamificationTeam::where('leaderboard_id', $this->competitionId)->find($tx->team_id);
                    if ($team) {
                        $team->decrement('coins', $tx->amount);
                    }
                    $tx->delete();
                }
            }

            $task->delete();
        });

        Flux::toast('تم حذف المهمة وجميع تكليفاتها وإلغاء المكافآت المرتبطة بها بنجاح', variant: 'success');
    }

    // --- Group/Team Tasks (Assignments) Logic ---
    public function updatedAssignmentTaskId($value): void
    {
        $this->assignment_scores = [];
        if ($value) {
            $task = GamificationTeamTask::with('criteria')->where('leaderboard_id', $this->competitionId)->find($value);
            if ($task) {
                foreach ($task->criteria as $criterion) {
                    $this->assignment_scores[$criterion->id] = null;
                }
            }
        }
    }

    public function createAssignment(): void
    {
        $this->reset(
            'editingAssignmentId',
            'assignment_task_id',
            'assignment_team_id',
            'assignment_start_date',
            'assignment_end_date',
            'assignment_grade',
            'assignment_notes',
            'assignment_teacher_id',
            'assignment_scores'
        );
        $this->assignment_scores = [];
        $this->showAssignmentModal = true;
    }

    public function editAssignment($id): void
    {
        $assignment = GamificationTeamTaskAssignment::with(['task.criteria', 'scores'])->whereHas('task', fn ($query) => $query->where('leaderboard_id', $this->competitionId))->findOrFail($id);
        $this->editingAssignmentId = $assignment->id;
        $this->assignment_task_id = $assignment->team_task_id;
        $this->assignment_team_id = $assignment->team_id;
        $this->assignment_start_date = $assignment->start_date ? $assignment->start_date->format('Y-m-d') : '';
        $this->assignment_end_date = $assignment->end_date ? $assignment->end_date->format('Y-m-d') : '';
        $this->assignment_grade = $assignment->grade;
        $this->assignment_notes = $assignment->notes ?? '';
        $this->assignment_teacher_id = $assignment->teacher_id;

        // Load scores
        $this->assignment_scores = [];
        foreach ($assignment->scores as $score) {
            $this->assignment_scores[$score->criterion_id] = $score->score;
        }

        $this->showAssignmentModal = true;
    }

    public function saveAssignment(): void
    {
        $this->validate([
            'assignment_task_id' => ['required', $this->ownRow('gamification_team_tasks')],
            'assignment_team_id' => ['required', $this->ownRow('gamification_teams')],
            'assignment_start_date' => 'required|date',
            'assignment_end_date' => 'required|date|after_or_equal:assignment_start_date',
            'assignment_teacher_id' => ['nullable', Rule::in($this->competitionTeacherIds())],
            'assignment_notes' => 'nullable|string',
        ]);

        $task = GamificationTeamTask::with('criteria')->where('leaderboard_id', $this->competitionId)->findOrFail($this->assignment_task_id);
        $hasCriteria = $task->criteria->isNotEmpty();

        if ($hasCriteria) {
            $hasScores = false;
            foreach ($task->criteria as $criterion) {
                $score = $this->assignment_scores[$criterion->id] ?? null;
                if ($score !== null && $score !== '') {
                    $hasScores = true;
                    break;
                }
            }

            if ($hasScores) {
                $rules = [];
                foreach ($task->criteria as $criterion) {
                    $rules["assignment_scores.{$criterion->id}"] = 'required|integer|between:0,10';
                }
                $this->validate($rules, [
                    'assignment_scores.*.required' => 'يجب إدخال تقييم لجميع البنود.',
                    'assignment_scores.*.between' => 'التقييم يجب أن يكون بين 0 و 10.',
                ]);

                $totalScore = 0;
                foreach ($task->criteria as $criterion) {
                    $totalScore += (int) $this->assignment_scores[$criterion->id];
                }
                $this->assignment_grade = (int) round(($totalScore / ($task->criteria->count() * 10)) * 100);
            } else {
                $this->assignment_grade = null;
            }
        } else {
            $this->validate([
                'assignment_grade' => 'nullable|integer|between:0,100',
            ]);
        }

        // Overlap check on assignments for the same task template
        $overlap = GamificationTeamTaskAssignment::where('team_task_id', $this->assignment_task_id)
            ->where('id', '!=', $this->editingAssignmentId)
            ->where('start_date', '<=', $this->assignment_end_date)
            ->where('end_date', '>=', $this->assignment_start_date)
            ->exists();

        if ($overlap) {
            $this->addError('assignment_start_date', 'تنبيه: تواريخ هذا التكليف تتداخل مع تكليف آخر لنفس المهمة.');

            return;
        }

        DB::transaction(function () use ($task, $hasCriteria) {
            $status = $this->assignment_grade !== null ? 'completed' : 'assigned';

            $assignment = GamificationTeamTaskAssignment::updateOrCreate(
                ['id' => $this->editingAssignmentId],
                [
                    'team_task_id' => $this->assignment_task_id,
                    'team_id' => $this->assignment_team_id,
                    'teacher_id' => $this->assignment_teacher_id ?: null,
                    'start_date' => $this->assignment_start_date,
                    'end_date' => $this->assignment_end_date,
                    'status' => $status,
                    'grade' => $this->assignment_grade,
                    'notes' => $this->assignment_notes ?: null,
                ]
            );

            // Save criteria scores
            $assignment->scores()->delete();
            if ($hasCriteria && $this->assignment_grade !== null) {
                foreach ($task->criteria as $criterion) {
                    $scoreVal = $this->assignment_scores[$criterion->id] ?? null;
                    if ($scoreVal !== null && $scoreVal !== '') {
                        $assignment->scores()->create([
                            'criterion_id' => $criterion->id,
                            'score' => (int) $scoreVal,
                        ]);
                    }
                }
            }

            // Revert previous transaction if exists (for team coins adjustment)
            $tx = GamificationTransaction::where('leaderboard_id', $this->competitionId)
                ->where('reference_type', GamificationTeamTaskAssignment::class)
                ->where('reference_id', $assignment->id)
                ->first();

            if ($tx) {
                $oldTeam = GamificationTeam::where('leaderboard_id', $this->competitionId)->find($tx->team_id);
                if ($oldTeam) {
                    $oldTeam->decrement('coins', $tx->amount);
                }
            }

            $awardedCoins = 0;
            $awardedXp = 0;
            if ($this->assignment_grade !== null) {
                if ($hasCriteria) {
                    foreach ($task->criteria as $criterion) {
                        $score = (int) ($this->assignment_scores[$criterion->id] ?? 0);
                        $awardedCoins += (int) round(($score / 10) * ($criterion->coins_reward ?? 0));
                    }
                } else {
                    $awardedCoins = (int) round(($this->assignment_grade / 100) * $task->coins_reward);
                }
                $awardedXp = (int) round(($this->assignment_grade / 100) * $task->xp_reward);
            }

            if ($this->assignment_grade !== null) {
                GamificationTransaction::updateOrCreate(
                    [
                        'leaderboard_id' => $this->competitionId,
                        'reference_type' => GamificationTeamTaskAssignment::class,
                        'reference_id' => $assignment->id,
                    ],
                    [
                        'student_id' => null,
                        'team_id' => $this->assignment_team_id,
                        'type' => 'earn',
                        'amount' => $awardedCoins,
                        'xp_amount' => $awardedXp,
                        'description' => "تقييم مهمة: {$task->name} (درجة: {$this->assignment_grade}%)",
                    ]
                );

                $newTeam = GamificationTeam::where('leaderboard_id', $this->competitionId)->findOrFail($this->assignment_team_id);
                $newTeam->increment('coins', $awardedCoins);

                GamificationNewsService::record($this->competitionId, 'team_task', [
                    'team_name' => $newTeam->name,
                    'task_name' => $task->name,
                    'grade' => (int) $this->assignment_grade,
                ]);
            } else {
                if ($tx) {
                    $tx->delete();
                }
            }
        });

        Flux::toast('تم حفظ التكليف بنجاح', variant: 'success');
        $this->showAssignmentModal = false;
    }

    public function deleteAssignment($id): void
    {
        $assignment = GamificationTeamTaskAssignment::whereHas('task', fn ($query) => $query->where('leaderboard_id', $this->competitionId))->findOrFail($id);

        DB::transaction(function () use ($assignment) {
            $tx = GamificationTransaction::where('leaderboard_id', $this->competitionId)
                ->where('reference_type', GamificationTeamTaskAssignment::class)
                ->where('reference_id', $assignment->id)
                ->first();

            if ($tx) {
                $team = GamificationTeam::where('leaderboard_id', $this->competitionId)->find($tx->team_id);
                if ($team) {
                    $team->decrement('coins', $tx->amount);
                }
                $tx->delete();
            }

            $assignment->delete();
        });

        Flux::toast('تم حذف التكليف وإلغاء المكافآت المرتبطة به بنجاح', variant: 'success');
    }

    // Activities Methods
    public function createActivity(): void
    {
        $this->reset('activity_name', 'activity_description', 'editingActivityId', 'activity_ranks', 'activity_rounds', 'activity_color', 'activity_icon_file', 'activity_icon_path');
        $this->activity_color = '#10b981';
        $this->activity_ranks = [
            ['name' => 'المركز الأول', 'team_xp' => 100, 'team_coins' => 100, 'member_xp' => 50, 'member_coins' => 50],
            ['name' => 'المركز الثاني', 'team_xp' => 50, 'team_coins' => 50, 'member_xp' => 25, 'member_coins' => 25],
            ['name' => 'المركز الثالث', 'team_xp' => 25, 'team_coins' => 25, 'member_xp' => 10, 'member_coins' => 10],
        ];
        $this->activity_rounds = [];
        $this->showActivityModal = true;
    }

    public function editActivity($id): void
    {
        $activity = GamificationActivity::with(['ranks', 'rounds'])->where('leaderboard_id', $this->competitionId)->findOrFail($id);
        $this->editingActivityId = $activity->id;
        $this->activity_name = $activity->name;
        $this->activity_description = $activity->description ?? '';
        $this->activity_color = $activity->color ?? '#10b981';
        $this->activity_icon_path = $activity->icon_path;
        $this->activity_icon_file = null;
        $this->activity_ranks = $activity->ranks->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'team_xp' => $r->team_xp,
            'team_coins' => $r->team_coins,
            'member_xp' => $r->member_xp,
            'member_coins' => $r->member_coins,
        ])->toArray();
        $this->activity_rounds = $activity->rounds->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'round_date' => $r->round_date->format('Y-m-d'),
        ])->toArray();
        $this->showActivityModal = true;
    }

    public function addActivityRank(): void
    {
        $this->activity_ranks[] = [
            'name' => '',
            'team_xp' => 0,
            'team_coins' => 0,
            'member_xp' => 0,
            'member_coins' => 0,
        ];
    }

    public function removeActivityRank($index): void
    {
        unset($this->activity_ranks[$index]);
        $this->activity_ranks = array_values($this->activity_ranks);
    }

    public function addActivityRound(): void
    {
        $this->activity_rounds[] = [
            'name' => '',
            'round_date' => now()->format('Y-m-d'),
        ];
    }

    public function removeActivityRound($index): void
    {
        unset($this->activity_rounds[$index]);
        $this->activity_rounds = array_values($this->activity_rounds);
    }

    public function saveActivity(): void
    {
        $this->validate([
            'activity_name' => 'required|string|max:255',
            'activity_description' => 'nullable|string',
            'activity_color' => 'required|string',
            'activity_icon_file' => 'nullable|image|max:2048',
            'activity_ranks.*.name' => 'required|string|max:255',
            'activity_ranks.*.team_xp' => 'required|integer|min:0',
            'activity_ranks.*.team_coins' => 'required|integer|min:0',
            'activity_ranks.*.member_xp' => 'required|integer|min:0',
            'activity_ranks.*.member_coins' => 'required|integer|min:0',
            'activity_rounds.*.name' => 'required|string|max:255',
            'activity_rounds.*.round_date' => 'required|date',
        ], [
            'activity_ranks.*.name.required' => 'حقل الاسم للمركز مطلوب',
            'activity_rounds.*.name.required' => 'حقل الاسم للجولة مطلوب',
            'activity_rounds.*.round_date.required' => 'حقل التاريخ للجولة مطلوب',
        ]);

        DB::transaction(function () {
            $iconPath = $this->activity_icon_path;
            if ($this->activity_icon_file) {
                $iconPath = $this->activity_icon_file->store('activities_icons', 'public');
            }

            $activity = GamificationActivity::updateOrCreate(
                ['id' => $this->editingActivityId],
                [
                    'leaderboard_id' => $this->competitionId,
                    'name' => $this->activity_name,
                    'description' => $this->activity_description ?: null,
                    'color' => $this->activity_color,
                    'icon_path' => $iconPath,
                ]
            );

            // Sync ranks
            $existingIds = collect($this->activity_ranks)->pluck('id')->filter()->toArray();
            $activity->ranks()->whereNotIn('id', $existingIds)->delete();

            foreach ($this->activity_ranks as $r) {
                $activity->ranks()->updateOrCreate(
                    ['id' => $r['id'] ?? null],
                    [
                        'name' => $r['name'],
                        'team_xp' => (int) $r['team_xp'],
                        'team_coins' => (int) $r['team_coins'],
                        'member_xp' => (int) $r['member_xp'],
                        'member_coins' => (int) $r['member_coins'],
                    ]
                );
            }

            // Sync rounds
            $existingRoundIds = collect($this->activity_rounds)->pluck('id')->filter()->toArray();
            $activity->rounds()->whereNotIn('id', $existingRoundIds)->delete();

            foreach ($this->activity_rounds as $r) {
                $activity->rounds()->updateOrCreate(
                    ['id' => $r['id'] ?? null],
                    [
                        'name' => $r['name'],
                        'round_date' => $r['round_date'],
                    ]
                );
            }
        });

        Flux::toast('تم حفظ الفعالية بنجاح', variant: 'success');
        $this->showActivityModal = false;
    }

    public function deleteActivity($id): void
    {
        $activity = GamificationActivity::where('leaderboard_id', $this->competitionId)->findOrFail($id);

        $rounds = $activity->rounds;
        foreach ($rounds as $round) {
            $winners = GamificationActivityWinner::where('round_id', $round->id)->get();
            foreach ($winners as $winner) {
                $this->deleteWinner($winner->id);
            }
        }

        $activity->delete();
        Flux::toast('تم حذف الفعالية وكل المكافآت المرتبطة بها بنجاح', variant: 'success');
    }

    // Winners Methods
    public function editRoundWinners(int $roundId): void
    {
        $round = GamificationActivityRound::with(['activity.ranks', 'winners'])->whereHas('activity', fn ($query) => $query->where('leaderboard_id', $this->competitionId))->findOrFail($roundId);
        $this->selectedRoundId = $round->id;
        $this->winner_round_id = $round->id;

        $this->roundRanksWinners = [];
        foreach ($round->activity->ranks as $rank) {
            $this->roundRanksWinners[$rank->id] = '';
        }

        foreach ($round->winners as $winner) {
            $this->roundRanksWinners[$winner->rank_id] = $winner->team_id;
        }

        $this->showRoundWinnersModal = true;
    }

    public function saveRoundWinners(): void
    {
        $round = GamificationActivityRound::with('activity.ranks')->whereHas('activity', fn ($query) => $query->where('leaderboard_id', $this->competitionId))->findOrFail($this->selectedRoundId);

        $rules = [];
        foreach ($round->activity->ranks as $rank) {
            $rules["roundRanksWinners.{$rank->id}"] = 'nullable|exists:gamification_teams,id';
        }
        $this->validate($rules);

        DB::transaction(function () use ($round) {
            foreach ($round->activity->ranks as $rank) {
                $teamId = $this->roundRanksWinners[$rank->id] ?? null;

                $existingWinner = GamificationActivityWinner::where('round_id', $round->id)
                    ->where('rank_id', $rank->id)
                    ->first();

                if (! empty($teamId)) {
                    if ($existingWinner) {
                        if ($existingWinner->team_id !== (int) $teamId) {
                            $this->revertWinnerRewards($existingWinner);
                            $existingWinner->update([
                                'team_id' => $teamId,
                            ]);
                            $this->applyWinnerRewards($existingWinner);
                        }
                    } else {
                        $newWinner = GamificationActivityWinner::create([
                            'round_id' => $round->id,
                            'rank_id' => $rank->id,
                            'team_id' => $teamId,
                        ]);
                        $this->applyWinnerRewards($newWinner);
                    }
                } else {
                    if ($existingWinner) {
                        $this->revertWinnerRewards($existingWinner);
                        $existingWinner->delete();
                    }
                }
            }
        });

        Flux::toast('تم حفظ الفائزين وتوزيع الجوائز بنجاح', variant: 'success');
        $this->showRoundWinnersModal = false;
    }

    public function deleteWinner($id): void
    {
        $winner = GamificationActivityWinner::whereHas('round.activity', fn ($query) => $query->where('leaderboard_id', $this->competitionId))->findOrFail($id);
        DB::transaction(function () use ($winner) {
            $this->revertWinnerRewards($winner);
            $winner->delete();
        });
        Flux::toast('تم حذف نتيجة الفائز وتراجع الجوائز بنجاح', variant: 'success');
    }

    private function applyWinnerRewards(GamificationActivityWinner $winner): void
    {
        $rank = $winner->rank;
        $round = $winner->round;
        $activity = $round->activity;
        $team = $winner->team;

        $transactionDate = $round->round_date->startOfDay();

        // News: announce the round placement.
        GamificationNewsService::record($this->competitionId, 'activity_win', [
            'team_name' => $team->name,
            'rank_name' => $rank->name,
            'activity_name' => $activity->name,
            'round_name' => $round->name,
        ]);

        // 1. Award Team directly
        if ($rank->team_xp > 0 || $rank->team_coins > 0) {
            GamificationTransaction::create([
                'leaderboard_id' => $this->competitionId,
                'team_id' => $team->id,
                'type' => 'earn',
                'amount' => $rank->team_coins,
                'xp_amount' => $rank->team_xp,
                'description' => "فوز المجموعة بالمركز: {$rank->name} في فعالية {$activity->name} - {$round->name}",
                'reference_type' => GamificationActivityWinner::class,
                'reference_id' => $winner->id,
                'created_at' => $transactionDate,
                'updated_at' => $transactionDate,
            ]);

            if ($rank->team_coins > 0) {
                $team->increment('coins', $rank->team_coins);
            }
        }

        // 2. Award Members individually
        if ($rank->member_xp > 0 || $rank->member_coins > 0) {
            $members = $team->students()->get();
            foreach ($members as $member) {
                GamificationTransaction::create([
                    'leaderboard_id' => $this->competitionId,
                    'student_id' => $member->id,
                    'type' => 'earn',
                    'amount' => $rank->member_coins,
                    'xp_amount' => $rank->member_xp,
                    'description' => "فوز فردي كعضو في المجموعة بالمركز: {$rank->name} في فعالية {$activity->name} - {$round->name}",
                    'reference_type' => GamificationActivityWinner::class,
                    'reference_id' => $winner->id,
                    'created_at' => $transactionDate,
                    'updated_at' => $transactionDate,
                    'claimed_at' => GamificationService::resolveClaimedAt($this->competition, (int) $rank->member_coins, (int) $rank->member_xp),
                ]);

                GamificationService::recalculateStudentState($member->id, $this->competitionId);
            }
        }
    }

    private function revertWinnerRewards(GamificationActivityWinner $winner): void
    {
        $transactions = GamificationTransaction::where('leaderboard_id', $this->competitionId)
            ->where('reference_type', GamificationActivityWinner::class)
            ->where('reference_id', $winner->id)
            ->get();

        foreach ($transactions as $tx) {
            if ($tx->team_id && ! $tx->student_id) {
                // Team transaction
                $team = GamificationTeam::where('leaderboard_id', $this->competitionId)->find($tx->team_id);
                if ($team && $tx->amount > 0) {
                    $team->decrement('coins', $tx->amount);
                }
            } elseif ($tx->student_id) {
                // Student transaction
                $studentId = $tx->student_id;
                $tx->delete();
                GamificationService::recalculateStudentState($studentId, $this->competitionId);

                continue;
            }
            $tx->delete();
        }
    }

    // Helper functions
    public function getStudents()
    {
        $circleIds = $this->competition->circles->pluck('id')->toArray();

        return Student::whereIn('circle_id', $circleIds)->get();
    }

    public function applyAdjustment(): void
    {
        $rules = [
            'adjTargetType' => 'required|in:individual,team',
            'adjActionType' => 'required|in:add,deduct',
            'adjDescription' => 'required|string|max:255',
        ];

        if ($this->adjTargetType === 'individual') {
            $rules['adjStudentId'] = ['required', Rule::in($this->competitionStudentIds())];
        } else {
            $rules['adjTeamId'] = ['required', $this->ownRow('gamification_teams')];
        }

        if (! $this->adjHasXp && ! $this->adjHasCoins) {
            $this->addError('adjHasXp', 'يجب اختيار تعديل نقاط XP أو تعديل العملات أو كليهما لإجراء التسوية اليدوية.');

            return;
        }

        if ($this->adjHasXp) {
            $rules['adjXpVal'] = 'required|integer|min:1';
        }
        if ($this->adjHasCoins) {
            $rules['adjCoinsVal'] = 'required|integer|min:1';
        }

        $this->validate($rules);

        $xp = $this->adjHasXp ? $this->adjXpVal : 0;
        $coins = $this->adjHasCoins ? $this->adjCoinsVal : 0;

        if ($this->adjActionType === 'deduct') {
            $xp = -$xp;
            $coins = -$coins;
        }

        DB::transaction(function () use ($xp, $coins) {
            if ($this->adjTargetType === 'individual') {
                GamificationTransaction::create([
                    'leaderboard_id' => $this->competitionId,
                    'student_id' => $this->adjStudentId,
                    'team_id' => null,
                    'type' => 'earn',
                    'amount' => $coins,
                    'xp_amount' => $xp,
                    'description' => 'تسوية يدوية (من المشرف): '.$this->adjDescription,
                    'claimed_at' => GamificationService::resolveClaimedAt($this->competition, (int) $coins, (int) $xp),
                ]);

                GamificationService::recalculateStudentState($this->adjStudentId, $this->competitionId);
                GamificationService::syncStudentBadges($this->adjStudentId, $this->competitionId);
            } else {
                GamificationTransaction::create([
                    'leaderboard_id' => $this->competitionId,
                    'student_id' => null,
                    'team_id' => $this->adjTeamId,
                    'type' => 'earn',
                    'amount' => $coins,
                    'xp_amount' => $xp,
                    'description' => 'تسوية يدوية للأسرة (من المشرف): '.$this->adjDescription,
                ]);

                $team = GamificationTeam::where('leaderboard_id', $this->competitionId)->findOrFail($this->adjTeamId);
                $team->coins += $coins;
                if ($team->coins < 0) {
                    $team->coins = 0;
                }
                $team->save();
            }
        });

        // Adjustments stay out of the news feed unless the supervisor opts in,
        // and the target's name is included only when they choose to reveal it.
        if ($this->adjShowInNews) {
            $name = null;
            if ($this->adjShowTargetName) {
                $name = $this->adjTargetType === 'individual'
                    ? (Student::find($this->adjStudentId)?->name ?? '')
                    : (GamificationTeam::where('leaderboard_id', $this->competitionId)->find($this->adjTeamId)?->name ?? '');
            }

            GamificationNewsService::record($this->competitionId, 'adjustment', [
                'target_type' => $this->adjTargetType,
                'target_name' => $name,
                'name_hidden' => ! $this->adjShowTargetName,
                'action' => $this->adjActionType,
                'xp' => abs($xp),
                'coins' => abs($coins),
                'description' => $this->adjDescription,
            ]);
        }

        Flux::toast('تم تطبيق التسوية بنجاح', variant: 'success');

        $this->reset([
            'adjStudentId',
            'adjTeamId',
            'adjXpVal',
            'adjCoinsVal',
            'adjHasXp',
            'adjHasCoins',
            'adjDescription',
            'adjShowInNews',
            'adjShowTargetName',
        ]);
    }

    public function deleteAdjustment(int $id): void
    {
        $transaction = GamificationTransaction::where('leaderboard_id', $this->competitionId)->findOrFail($id);

        DB::transaction(function () use ($transaction) {
            if ($transaction->student_id) {
                $studentId = $transaction->student_id;
                $transaction->delete();

                GamificationService::recalculateStudentState($studentId, $this->competitionId);
                GamificationService::syncStudentBadges($studentId, $this->competitionId);
            } else {
                $team = GamificationTeam::where('leaderboard_id', $this->competitionId)->find($transaction->team_id);
                if ($team) {
                    $team->coins -= $transaction->amount;
                    if ($team->coins < 0) {
                        $team->coins = 0;
                    }
                    $team->save();
                }
                $transaction->delete();
            }
        });

        Flux::toast('تم حذف وتراجع التسوية بنجاح', variant: 'success');
    }

    public function loadDistributionData(): void
    {
        $teams = GamificationTeam::where('leaderboard_id', $this->competitionId)->pluck('id')->toArray();
        $assigned = DB::table('gamification_team_student')
            ->whereIn('team_id', $teams)
            ->get();

        foreach ($assigned as $row) {
            $this->studentTeams[$row->student_id] = $row->team_id;
            $this->studentRoles[$row->student_id] = $row->role;
        }
    }

    public function render()
    {
        $milestones = DB::table('gamification_streak_milestones')
            ->where('leaderboard_id', $this->competitionId)
            ->orderBy('days_required')
            ->get();

        $dbBadges = GamificationBadge::with('leaderboardCriterion')->where('leaderboard_id', $this->competitionId)->get();
        $dbTeams = GamificationTeam::withCount('students')->where('leaderboard_id', $this->competitionId)->get();
        $dbTracks = GamificationTrack::withCount('students')->where('leaderboard_id', $this->competitionId)->orderBy('sort_order')->orderBy('id')->get();

        // Ensure permanent individual multiplier store item exists
        GamificationStoreItem::firstOrCreate([
            'leaderboard_id' => $this->competitionId,
            'item_type' => 'multiplier',
            'is_team_product' => false,
        ], [
            'name' => 'مضاعف النقاط الفردي',
            'description' => 'مضاعفة نقاط الحفظ والمراجعة والحضور الخاصة بك ليوم واحد.',
            'price' => 150,
            'value' => 2,
            'is_active' => true,
        ]);

        // Ensure permanent individual streak freeze store item exists
        GamificationStoreItem::firstOrCreate([
            'leaderboard_id' => $this->competitionId,
            'item_type' => 'freeze',
            'is_team_product' => false,
        ], [
            'name' => 'تجميد أيام الحماسة',
            'description' => 'حماية سلسلة أيام الحماسة المتتالية من الانقطاع ليوم واحد.',
            'price' => 100,
            'value' => 0,
            'is_active' => true,
            'is_streak_freeze' => true,
        ]);

        $dbStoreItems = GamificationStoreItem::where('leaderboard_id', $this->competitionId)
            ->whereNotIn('item_type', ['multiplier', 'freeze'])
            ->get();

        // Purchases log (store tab) — every made purchase with its buyer/target so a
        // supervisor can cancel one and reverse its effect. Grouped by product and,
        // within each product, ordered by date (newest first).
        $purchasesByProduct = collect();
        if ($this->activeTab === 'store') {
            $sortKey = fn ($purchase) => $purchase->target_date
                ? Carbon::parse($purchase->target_date)->toDateString()
                : $purchase->created_at->toDateString();

            $purchasesByProduct = GamificationStorePurchase::whereHas('item', function ($query) {
                $query->where('leaderboard_id', $this->competitionId);
            })
                ->with(['item', 'student', 'team', 'targetTeam'])
                ->get()
                ->filter(fn ($purchase) => $purchase->item !== null)
                ->groupBy('store_item_id')
                ->map(fn ($group) => [
                    'item' => $group->first()->item,
                    'purchases' => $group->sortByDesc($sortKey)->values(),
                ])
                ->sortByDesc(fn ($group) => $sortKey($group['purchases']->first()))
                ->values();
        }

        $students = $this->getStudents();
        if (empty($this->studentTeams) && count($students) > 0) {
            $this->loadDistributionData();
        }

        $dbCriteria = LeaderboardCriterion::where('leaderboard_id', $this->competitionId)->get();
        $dbTeamTasks = GamificationTeamTask::where('leaderboard_id', $this->competitionId)->get();
        $dbAssignments = GamificationTeamTaskAssignment::with(['task', 'team'])
            ->whereHas('task', function ($query) {
                $query->where('leaderboard_id', $this->competitionId);
            })
            ->get();

        $circleIds = $this->competition->circles->pluck('id')->toArray();
        $dbTeachers = Teacher::whereHas('circles', function ($query) use ($circleIds) {
            $query->whereIn('circles.id', $circleIds);
        })->orderBy('name')->get();

        $dbActivities = GamificationActivity::with(['ranks', 'rounds'])
            ->where('leaderboard_id', $this->competitionId)
            ->get();
        $dbRounds = GamificationActivityRound::with(['activity.ranks', 'winners.team', 'winners.rank'])
            ->whereHas('activity', function ($query) {
                $query->where('leaderboard_id', $this->competitionId);
            })
            ->orderBy('round_date', 'asc')
            ->get();

        $studentsGrouped = Student::with('circle')
            ->whereIn('circle_id', $circleIds)
            ->get()
            ->groupBy(fn ($s) => $s->circle?->name ?? 'بدون حلقة');

        $dbAdjustments = GamificationTransaction::with(['student', 'team'])
            ->where('leaderboard_id', $this->competitionId)
            ->where('description', 'like', 'تسوية يدوية%')
            ->latest()
            ->get();

        // Student standings ("مراكز الطلاب") — only computed when the tab is open.
        $standingsGroups = [];
        $achievementsByDay = [];
        $achievementsStudentName = null;

        if ($this->activeTab === 'standings') {
            $service = new LeaderboardService;
            $groups = $service->getStandingsByTrack($this->competition);

            // No tracks defined → fall back to a single, flat overall ranking.
            if ($groups->isEmpty()) {
                $flat = $service->getDetailedStandings($this->competition)
                    ->map(function ($row) {
                        $row['track_rank'] = $row['rank'];

                        return $row;
                    })->all();

                $groups = collect([[
                    'id' => null,
                    'name' => 'الترتيب العام',
                    'description' => null,
                    'standings' => $flat,
                ]]);
            }

            $achievements = GamificationService::getAchievementsByStudentAndDay($this->competition);

            $standingsGroups = $groups->map(function ($group) use ($achievements) {
                $group['standings'] = collect($group['standings'])->map(function ($row) use ($achievements) {
                    $studentId = $row['student']->id;
                    $today = $achievements[$studentId][$this->standingsDate] ?? [];
                    $row['today_achievements'] = $today;
                    $row['today_points'] = collect($today)->sum('xp');

                    return $row;
                })->all();

                return $group;
            })->all();

            if ($this->achievementsStudentId) {
                $achievementsByDay = $achievements[$this->achievementsStudentId] ?? [];
                $achievementsStudentName = Student::find($this->achievementsStudentId)?->name;
            }
        }

        return view('livewire.supervisor.manage-gamification', [
            'milestones' => $milestones,
            'dbBadges' => $dbBadges,
            'dbTeams' => $dbTeams,
            'dbTracks' => $dbTracks,
            'dbStoreItems' => $dbStoreItems,
            'purchasesByProduct' => $purchasesByProduct,
            'students' => $students,
            'dbCriteria' => $dbCriteria,
            'dbTeamTasks' => $dbTeamTasks,
            'dbAssignments' => $dbAssignments,
            'dbTeachers' => $dbTeachers,
            'dbActivities' => $dbActivities,
            'dbRounds' => $dbRounds,
            'studentsGrouped' => $studentsGrouped,
            'dbAdjustments' => $dbAdjustments,
            'standingsGroups' => $standingsGroups,
            'achievementsByDay' => $achievementsByDay,
            'achievementsStudentName' => $achievementsStudentName,
        ])->layout('layouts.role-shell');
    }
}
