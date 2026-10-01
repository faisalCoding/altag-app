<?php

use App\Livewire\Supervisor\Competitions;
use App\Livewire\Supervisor\ManageGamification;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\GamificationActivity;
use App\Models\GamificationActivityRound;
use App\Models\GamificationActivityWinner;
use App\Models\GamificationBadge;
use App\Models\GamificationLevel;
use App\Models\GamificationNews;
use App\Models\GamificationStoreItem;
use App\Models\GamificationStorePurchase;
use App\Models\GamificationStudentState;
use App\Models\GamificationTeam;
use App\Models\GamificationTeamTask;
use App\Models\GamificationTeamTaskAssignment;
use App\Models\GamificationTrack;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\LeaderboardCriterion;
use App\Models\LeaderboardScore;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Services\GamificationService;
use App\Services\LeaderboardService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Freeze to a Tuesday so relative test dates (today / -1 / -2 days) all land on
    // circle working days (Sun–Thu), keeping working-day streak assertions stable.
    Carbon::setTestNow('2026-06-16 10:00:00');

    $this->stage = Stage::create(['name' => 'مرحلة اختبار التلعيب']);
    $this->circle = Circle::create(['name' => 'حلقة اختبار التلعيب', 'stage_id' => $this->stage->id]);

    $this->supervisor = Supervisor::factory()->create();
    $this->supervisor->stages()->attach($this->stage->id);

    $this->student = Student::create([
        'name' => 'طالب تلعيب',
        'email' => 'gamestudent@example.com',
        'password' => bcrypt('password'),
        'circle_id' => $this->circle->id,
        'is_approved' => true,
        'status' => 'active',
    ]);

    $this->leaderboard = Leaderboard::create([
        'supervisor_id' => $this->supervisor->id,
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة التلعيب الكبرى',
        'competition_type' => 'gamification',
        'start_date' => now()->subDays(5),
        'end_date' => now()->addDays(5),
        'is_active' => true,
    ]);

    $this->leaderboard->circles()->attach($this->circle->id);
    Storage::fake('public');
});

it('renders the manage gamification page for supervisors', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $response = $this->get(route('supervisor.competitions.gamification', $this->leaderboard->id));
    $response->assertStatus(200);
    $response->assertSee('مستويات التلعيب للطلاب');
});

it('initializes default levels on mount if empty', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    // Initially levels table should be empty for this leaderboard
    expect(GamificationLevel::where('leaderboard_id', $this->leaderboard->id)->count())->toBe(0);

    Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->assertSet('activeTab', 'levels');

    // Should generate default space levels (5 levels)
    expect(GamificationLevel::where('leaderboard_id', $this->leaderboard->id)->count())->toBe(5);
});

it('saves custom levels', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    $levels = $component->get('levels');
    $levels[0]['name'] = 'نجم متطور';
    $levels[0]['xp_required'] = 15;

    $component->set('levels', $levels)
        ->call('saveLevels')
        ->assertHasNoErrors();

    $firstLevel = GamificationLevel::where('leaderboard_id', $this->leaderboard->id)
        ->orderBy('level_number')
        ->first();

    expect($firstLevel->name)->toBe('نجم متطور');
    expect($firstLevel->xp_required)->toBe(15);
});

it('manages streak milestones', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    // Create Milestone
    $component->call('createMilestone')
        ->set('days_required', 5)
        ->set('reward_xp', 50)
        ->set('reward_coins', 100)
        ->set('milestone_description', 'هدية الحماسة الكبرى')
        ->call('saveMilestone')
        ->assertHasNoErrors();

    $milestone = DB::table('gamification_streak_milestones')
        ->where('leaderboard_id', $this->leaderboard->id)
        ->first();

    expect($milestone)->not->toBeNull();
    expect($milestone->days_required)->toBe(5);
    expect($milestone->reward_xp)->toBe(50);
    expect($milestone->reward_coins)->toBe(100);

    // Edit Milestone
    $component->call('editMilestone', $milestone->id)
        ->set('reward_coins', 150)
        ->call('saveMilestone')
        ->assertHasNoErrors();

    expect(DB::table('gamification_streak_milestones')->where('id', $milestone->id)->value('reward_coins'))->toBe(150);

    // Delete Milestone
    $component->call('deleteMilestone', $milestone->id);
    expect(DB::table('gamification_streak_milestones')->where('id', $milestone->id)->count())->toBe(0);
});

it('manages badges', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    // Create Badge
    $component->call('createBadge')
        ->set('badge_name', 'وسام الالتزام')
        ->set('badge_description', 'يمنح للملتزمين بالحضور')
        ->set('badge_image_file', UploadedFile::fake()->image('badge.png'))
        ->set('badge_mechanism', 'streak')
        ->set('badge_achievement_type', 'attendance')
        ->set('badge_requirement_value', 10)
        ->call('saveBadge')
        ->assertHasNoErrors();

    $badge = GamificationBadge::where('leaderboard_id', $this->leaderboard->id)->first();
    expect($badge)->not->toBeNull();
    expect($badge->name)->toBe('وسام الالتزام');
    expect($badge->badge_type)->toBe('streak_attendance');

    // Edit Badge
    $component->call('editBadge', $badge->id)
        ->set('badge_name', 'وسام الالتزام الذهبي')
        ->call('saveBadge')
        ->assertHasNoErrors();

    expect($badge->refresh()->name)->toBe('وسام الالتزام الذهبي');

    // Delete Badge
    $component->call('deleteBadge', $badge->id);
    expect(GamificationBadge::where('id', $badge->id)->count())->toBe(0);
});

it('manages teams and student distribution', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    // Create Team via Wizard steps
    $component->call('createTeam')
        ->set('team_name', 'كتيبة الأمل')
        ->set('team_slogan', 'بالقرآن نحيا')
        ->call('nextTeamStep') // go to color
        ->set('team_color', '#7c3aed')
        ->call('nextTeamStep') // go to leader
        ->set('team_leader_id', $this->student->id)
        ->call('nextTeamStep') // go to members
        ->set('team_student_ids', [(string) $this->student->id])
        ->call('saveTeam')
        ->assertHasNoErrors();

    $team = GamificationTeam::where('leaderboard_id', $this->leaderboard->id)->first();
    expect($team)->not->toBeNull();
    expect($team->name)->toBe('كتيبة الأمل');
    expect($team->color)->toBe('#7c3aed');
    expect($team->slogan)->toBe('بالقرآن نحيا');

    $assignment = DB::table('gamification_team_student')
        ->where('student_id', $this->student->id)
        ->where('team_id', $team->id)
        ->first();

    expect($assignment)->not->toBeNull();
    expect($assignment->role)->toBe('leader');

    // Edit Team
    $component->call('editTeam', $team->id)
        ->set('team_name', 'كتيبة الأمل المطورة')
        ->call('saveTeam')
        ->assertHasNoErrors();

    expect($team->refresh()->name)->toBe('كتيبة الأمل المطورة');

    // Delete Team
    $component->call('deleteTeam', $team->id);
    expect(GamificationTeam::where('id', $team->id)->count())->toBe(0);
    expect(DB::table('gamification_team_student')->where('team_id', $team->id)->count())->toBe(0);
});

it('manages store items', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    // Create Store Item
    $component->call('createItem')
        ->set('item_name', 'درع الحماية القوي')
        ->set('item_description', 'يحمي فريقك من الهجمات')
        ->set('item_price', 200)
        ->set('item_type', 'shield')
        ->set('item_value', 1)
        ->set('item_target_date', now()->addDay()->format('Y-m-d'))
        ->set('item_is_team_product', true)
        ->call('saveItem')
        ->assertHasNoErrors();

    $item = GamificationStoreItem::where('leaderboard_id', $this->leaderboard->id)
        ->where('item_type', 'shield')
        ->first();
    expect($item)->not->toBeNull();
    expect($item->name)->toBe('درع الحماية القوي');
    expect($item->price)->toBe(200);
    expect($item->is_team_product)->toBeTrue();

    // Edit Store Item
    $component->call('editItem', $item->id)
        ->set('item_price', 250)
        ->call('saveItem')
        ->assertHasNoErrors();

    expect($item->refresh()->price)->toBe(250);

    // Delete Store Item
    $component->call('deleteItem', $item->id);
    expect(GamificationStoreItem::where('id', $item->id)->count())->toBe(0);
});

it('can add and remove levels dynamically', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    // Initial default count is 5
    expect($component->get('levels'))->toHaveCount(5);

    // Add level
    $component->call('addLevel');
    expect($component->get('levels'))->toHaveCount(6);

    // The new level has level_number = 6
    $levels = $component->get('levels');
    expect($levels[5]['level_number'])->toBe(6);

    // Customize and save levels
    $levels[5]['name'] = 'مستوى جديد مضاف';
    $levels[5]['xp_required'] = 2000;

    $component->set('levels', $levels)
        ->call('saveLevels')
        ->assertHasNoErrors();

    expect(GamificationLevel::where('leaderboard_id', $this->leaderboard->id)->count())->toBe(6);
    expect(GamificationLevel::where('leaderboard_id', $this->leaderboard->id)->where('level_number', 6)->value('name'))->toBe('مستوى جديد مضاف');

    // Remove level at index 5 (the newly added level)
    $component->call('removeLevel', 5)
        ->call('saveLevels')
        ->assertHasNoErrors();

    expect(GamificationLevel::where('leaderboard_id', $this->leaderboard->id)->count())->toBe(5);
});

it('can manage custom criteria inside gamification management', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    // Initial criteria is empty
    expect($component->get('criteria'))->toBeEmpty();

    // Add criterion
    $component->call('addTodoCriterion');
    expect($component->get('criteria'))->toHaveCount(1);

    $criteria = $component->get('criteria');
    $criteria[0]['name'] = 'الأخلاق والآداب';
    $criteria[0]['points'] = 10;
    $criteria[0]['is_enthusiasm_trigger'] = true;

    $component->set('criteria', $criteria)
        ->call('saveCriteria')
        ->assertHasNoErrors();

    // Verify it is saved in database
    $dbCriterion = LeaderboardCriterion::where('leaderboard_id', $this->leaderboard->id)->first();
    expect($dbCriterion)->not->toBeNull();
    expect($dbCriterion->name)->toBe('الأخلاق والآداب');
    expect($dbCriterion->points)->toBe(10);
    expect((bool) $dbCriterion->is_enthusiasm_trigger)->toBeTrue();

    // Remove criterion
    $component->call('removeTodoCriterion', 0)
        ->call('saveCriteria')
        ->assertHasNoErrors();

    expect(LeaderboardCriterion::where('leaderboard_id', $this->leaderboard->id)->count())->toBe(0);
});

it('saves badge with custom criterion association and awards badge automatically when criterion limit is reached', function () {
    // 1. Create a custom criterion
    $criterion = LeaderboardCriterion::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'المشاركة في الأنشطة',
        'points' => 5,
    ]);

    $this->actingAs($this->supervisor, 'supervisor');

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    $component->call('createBadge')
        ->set('badge_name', 'وسام المشارك الفعال')
        ->set('badge_description', 'يمنح للمشاركين في الأنشطة 3 مرات')
        ->set('badge_image_file', UploadedFile::fake()->image('badge.png'))
        ->set('badge_mechanism', 'count')
        ->set('badge_achievement_type', 'criterion')
        ->set('badge_requirement_value', 3)
        ->set('badge_leaderboard_criterion_id', $criterion->id)
        ->call('saveBadge')
        ->assertHasNoErrors();

    $badge = GamificationBadge::where('leaderboard_id', $this->leaderboard->id)
        ->where('leaderboard_criterion_id', $criterion->id)
        ->first();
    expect($badge)->not->toBeNull();
    expect($badge->requirement_value)->toBe(3);

    // 2. Teacher grades the student for this criterion
    // First time
    $score1 = LeaderboardScore::create([
        'leaderboard_id' => $this->leaderboard->id,
        'student_id' => $this->student->id,
        'leaderboard_criterion_id' => $criterion->id,
        'date' => now()->format('Y-m-d'),
    ]);
    GamificationService::syncStudentCustomCriterionXP($score1);

    // Check student has not received the badge yet (score count = 1, required = 3)
    $hasBadge = DB::table('gamification_badge_student')
        ->where('student_id', $this->student->id)
        ->where('badge_id', $badge->id)
        ->exists();
    expect($hasBadge)->toBeFalse();

    // Second time
    $score2 = LeaderboardScore::create([
        'leaderboard_id' => $this->leaderboard->id,
        'student_id' => $this->student->id,
        'leaderboard_criterion_id' => $criterion->id,
        'date' => now()->subDay()->format('Y-m-d'),
    ]);
    GamificationService::syncStudentCustomCriterionXP($score2);

    $hasBadge = DB::table('gamification_badge_student')
        ->where('student_id', $this->student->id)
        ->where('badge_id', $badge->id)
        ->exists();
    expect($hasBadge)->toBeFalse();

    // Third time (Requirement reached!)
    $score3 = LeaderboardScore::create([
        'leaderboard_id' => $this->leaderboard->id,
        'student_id' => $this->student->id,
        'leaderboard_criterion_id' => $criterion->id,
        'date' => now()->subDays(2)->format('Y-m-d'),
    ]);
    GamificationService::syncStudentCustomCriterionXP($score3);

    // Now, the student should have the badge!
    $hasBadge = DB::table('gamification_badge_student')
        ->where('student_id', $this->student->id)
        ->where('badge_id', $badge->id)
        ->exists();
    expect($hasBadge)->toBeTrue();

    // Test Revocation: Untoggle one score (drops count to 2, under requirement)
    $score3Id = $score3->id;
    $score3->delete();

    // Delete corresponding gamification transaction if any
    GamificationTransaction::where('reference_type', LeaderboardScore::class)
        ->where('reference_id', $score3Id)
        ->delete();
    GamificationService::recalculateStudentState($this->student->id, $this->leaderboard->id);
    GamificationService::syncStudentBadges($this->student->id, $this->leaderboard->id);
    // Student should no longer have the badge!
    $hasBadgeAfterRevocation = DB::table('gamification_badge_student')
        ->where('student_id', $this->student->id)
        ->where('badge_id', $badge->id)
        ->exists();
    expect($hasBadgeAfterRevocation)->toBeFalse();
});

it('awards and revokes badges based on streak and count criteria for hifz, review, and attendance', function () {
    $teacher = Teacher::factory()->create();

    // 1. Create badges of different types
    $streakAttendanceBadge = GamificationBadge::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'وسام المواظبة المتتالية',
        'icon' => 'bolt',
        'badge_type' => 'streak_attendance',
        'requirement_value' => 3,
    ]);

    $countHifzBadge = GamificationBadge::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'وسام الحافظ التراكمي',
        'icon' => 'star',
        'badge_type' => 'count_hifz',
        'requirement_value' => 2,
    ]);

    // Setup active student plans
    $plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'plan_type' => 'hifz_review',
        'start_date' => now()->subDays(5),
        'is_approved' => 1,
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);

    // 2. Attendance Streak test: Create 3 consecutive attendances
    // Day 1:
    $att1 = Attendance::create([
        'student_id' => $this->student->id,
        'circle_id' => $this->circle->id,
        'teacher_id' => $teacher->id,
        'date' => now()->subDays(2)->format('Y-m-d'),
        'status' => 'present',
    ]);
    GamificationService::syncStudentAttendanceXP($att1);

    // Day 2:
    $att2 = Attendance::create([
        'student_id' => $this->student->id,
        'circle_id' => $this->circle->id,
        'teacher_id' => $teacher->id,
        'date' => now()->subDay()->format('Y-m-d'),
        'status' => 'present',
    ]);
    GamificationService::syncStudentAttendanceXP($att2);

    expect(DB::table('gamification_badge_student')->where('student_id', $this->student->id)->where('badge_id', $streakAttendanceBadge->id)->exists())->toBeFalse();

    // Day 3:
    $att3 = Attendance::create([
        'student_id' => $this->student->id,
        'circle_id' => $this->circle->id,
        'teacher_id' => $teacher->id,
        'date' => now()->format('Y-m-d'),
        'status' => 'present',
    ]);
    GamificationService::syncStudentAttendanceXP($att3);

    // Should now have the streak badge!
    expect(DB::table('gamification_badge_student')->where('student_id', $this->student->id)->where('badge_id', $streakAttendanceBadge->id)->exists())->toBeTrue();

    // 3. Count Hifz test: Create 2 hifz achievements
    // Achievement 1:
    $day1 = StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => now()->subDays(2)->format('Y-m-d'),
        'day_name' => 'الأربعاء',
        'hifz_achievement' => 3, // Excellent
    ]);
    GamificationService::syncStudentPlanDayXP($day1);

    expect(DB::table('gamification_badge_student')->where('student_id', $this->student->id)->where('badge_id', $countHifzBadge->id)->exists())->toBeFalse();

    // Achievement 2:
    $day2 = StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => now()->subDay()->format('Y-m-d'),
        'day_name' => 'الخميس',
        'hifz_achievement' => 3, // Excellent
    ]);
    GamificationService::syncStudentPlanDayXP($day2);

    // Should now have the count badge!
    expect(DB::table('gamification_badge_student')->where('student_id', $this->student->id)->where('badge_id', $countHifzBadge->id)->exists())->toBeTrue();
});

it('counts a streak across a weekend gap as consecutive circle days', function () {
    $teacher = Teacher::factory()->create();

    // Requires 4 consecutive circle days. Wider window so the streak spans a weekend.
    $this->leaderboard->update([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    $streakBadge = GamificationBadge::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'وسام المواظبة',
        'icon' => 'bolt',
        'badge_type' => 'streak_attendance',
        'requirement_value' => 4,
    ]);

    // Wed 10, Thu 11 — then Fri 12 & Sat 13 are days off — then Sun 14, Mon 15.
    // As calendar days this is only a 2-day run (broken by the weekend), but as
    // circle working days it is a 4-day consecutive streak.
    foreach (['2026-06-10', '2026-06-11', '2026-06-14', '2026-06-15'] as $date) {
        $attendance = Attendance::create([
            'student_id' => $this->student->id,
            'circle_id' => $this->circle->id,
            'teacher_id' => $teacher->id,
            'date' => $date,
            'status' => 'present',
        ]);
        GamificationService::syncStudentAttendanceXP($attendance);
    }

    expect(GamificationService::calculateMaxStreakOnWorkingDays(
        collect(['2026-06-10', '2026-06-11', '2026-06-14', '2026-06-15']),
        GamificationService::getWorkingDaysForLeaderboard($this->leaderboard->fresh())
    ))->toBe(4);

    // The weekend-spanning run meets the 4-day requirement, so the badge is awarded.
    expect(DB::table('gamification_badge_student')
        ->where('student_id', $this->student->id)
        ->where('badge_id', $streakBadge->id)
        ->exists())->toBeTrue();
});

it('breaks a streak when a middle circle day is missed', function () {
    $teacher = Teacher::factory()->create();

    $this->leaderboard->update([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    $streakBadge = GamificationBadge::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'وسام المواظبة',
        'icon' => 'bolt',
        'badge_type' => 'streak_attendance',
        'requirement_value' => 4,
    ]);

    // Sun 14, Mon 15, (missed Tue 16 — a circle day), Wed 17, Thu 18.
    // Longest working-day run is 2, below the requirement of 4.
    foreach (['2026-06-14', '2026-06-15', '2026-06-17', '2026-06-18'] as $date) {
        $attendance = Attendance::create([
            'student_id' => $this->student->id,
            'circle_id' => $this->circle->id,
            'teacher_id' => $teacher->id,
            'date' => $date,
            'status' => 'present',
        ]);
        GamificationService::syncStudentAttendanceXP($attendance);
    }

    expect(GamificationService::calculateMaxStreakOnWorkingDays(
        collect(['2026-06-14', '2026-06-15', '2026-06-17', '2026-06-18']),
        GamificationService::getWorkingDaysForLeaderboard($this->leaderboard->fresh())
    ))->toBe(2);

    expect(DB::table('gamification_badge_student')
        ->where('student_id', $this->student->id)
        ->where('badge_id', $streakBadge->id)
        ->exists())->toBeFalse();
});

it('manages individual store items and streak freezes', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    // Create Individual Streak Freeze item
    $component->call('createItem')
        ->set('item_name', 'بطاقة تجميد الحماسة')
        ->set('item_description', 'تحمي سلسلة حماستك من الانقطاع ليوم واحد')
        ->set('item_price', 150)
        ->set('item_type', 'freeze')
        ->call('saveItem')
        ->assertHasNoErrors();

    $item = GamificationStoreItem::where('leaderboard_id', $this->leaderboard->id)
        ->where('is_streak_freeze', true)
        ->first();

    expect($item)->not->toBeNull();
    expect($item->is_team_product)->toBeFalse();
    expect($item->is_streak_freeze)->toBeTrue();
    expect($item->item_type)->toBe('freeze');

    // Create Team Shield item (a team product carrying a target date)
    $component->call('createItem')
        ->set('item_name', 'درع حماية الأسرة')
        ->set('item_price', 300)
        ->set('item_type', 'shield')
        ->set('item_target_date', now()->addDay()->format('Y-m-d'))
        ->call('saveItem')
        ->assertHasNoErrors();

    $teamItem = GamificationStoreItem::where('leaderboard_id', $this->leaderboard->id)
        ->where('item_type', 'shield')
        ->where('is_team_product', true)
        ->first();

    expect($teamItem)->not->toBeNull();
    expect($teamItem->is_team_product)->toBeTrue();
    expect($teamItem->is_streak_freeze)->toBeFalse();
    expect($teamItem->target_date->format('Y-m-d'))->toBe(now()->addDay()->format('Y-m-d'));

    // Create Team Points item
    $component->call('createItem')
        ->set('item_name', 'صندوق ذهب للأسرة')
        ->set('item_price', 100)
        ->set('item_type', 'team_points')
        ->set('item_value', 150)
        ->call('saveItem')
        ->assertHasNoErrors();

    $pointsItem = GamificationStoreItem::where('leaderboard_id', $this->leaderboard->id)
        ->where('item_type', 'team_points')
        ->first();

    expect($pointsItem)->not->toBeNull();
    expect($pointsItem->is_team_product)->toBeTrue();
    expect($pointsItem->value)->toBe(150);
});

it('does not allow the supervisor to create a multiplier store product', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    // The team multiplier is governed solely by level settings; it must not be
    // creatable as a duplicate store product through the supervisor store form.
    Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->call('createItem')
        ->set('item_name', 'مضاعف مكرر')
        ->set('item_price', 300)
        ->set('item_type', 'multiplier')
        ->set('item_value', 2)
        ->call('saveItem')
        ->assertHasErrors('item_type');

    expect(
        GamificationStoreItem::where('leaderboard_id', $this->leaderboard->id)
            ->where('item_type', 'multiplier')
            ->where('is_team_product', true)
            ->exists()
    )->toBeFalse();
});

it('allows supervisor to upload custom images for custom theme settings and compresses them to webp', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    // Make sure storage disk public is clean
    Storage::fake('public');

    // Create a fake image for coin and team icons
    $coinImage = UploadedFile::fake()->image('coin_icon.png', 500, 500);
    $teamImage = UploadedFile::fake()->image('team_icon.png', 400, 400);

    // Test the Competitions Livewire component
    Livewire::test(Competitions::class)
        ->set('title', 'مسابقة مخصصة رائعة')
        ->set('start_date', now()->format('Y-m-d'))
        ->set('end_date', now()->addDays(5)->format('Y-m-d'))
        ->set('selectedCircles', [$this->circle->id])
        ->set('competition_type', 'gamification')
        ->set('custom_theme_name', 'فرسان الأقصى')
        ->set('custom_theme_color', '#10b981')
        ->set('custom_theme_currency', 'دينار')
        ->set('custom_theme_currency_emoji', '🪙')
        ->set('custom_theme_xp', 'نجم')
        ->set('custom_theme_team', 'فرقة')
        ->set('custom_theme_team_plural', 'فرق')
        ->set('custom_theme_team_emoji', '🚩')
        ->set('custom_theme_team_possessive_your', 'فرقتك')
        ->set('custom_theme_team_possessive_my', 'فرقتي')
        // Set file uploads
        ->set('coin_image_file', $coinImage)
        ->set('team_image_file', $teamImage)
        ->call('save')
        ->assertHasNoErrors();

    // Verify the leaderboard is created/updated
    $leaderboard = Leaderboard::where('title', 'مسابقة مخصصة رائعة')->first();
    expect($leaderboard)->not->toBeNull();

    $settings = $leaderboard->settings;
    expect($settings)->toHaveKey('theme');

    $theme = $settings['theme'];
    expect($theme['coin_emoji'])->toStartWith('custom_themes/');
    expect($theme['coin_emoji'])->toEndWith('_coin.webp');

    expect($theme['team_emoji'])->toStartWith('custom_themes/');
    expect($theme['team_emoji'])->toEndWith('_team.webp');

    // Verify files exist in storage
    Storage::disk('public')->assertExists($theme['coin_emoji']);
    Storage::disk('public')->assertExists($theme['team_emoji']);
});

it('can toggle product status directly from store list', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    // Create an item first
    $component->call('createItem')
        ->set('item_name', 'بطاقة تجميد الحماسة')
        ->set('item_description', 'تحمي سلسلة حماستك من الانقطاع ليوم واحد')
        ->set('item_price', 150)
        ->set('item_type', 'freeze')
        ->call('saveItem')
        ->assertHasNoErrors();

    $item = GamificationStoreItem::where('leaderboard_id', $this->leaderboard->id)
        ->where('name', 'بطاقة تجميد الحماسة')
        ->first();

    expect($item)->not->toBeNull();
    expect($item->is_active)->toBeTrue();

    // Toggle status to inactive
    $component->call('toggleProductStatus', $item->id)
        ->assertHasNoErrors();

    $item->refresh();
    expect($item->is_active)->toBeFalse();

    // Toggle status back to active
    $component->call('toggleProductStatus', $item->id)
        ->assertHasNoErrors();

    $item->refresh();
    expect($item->is_active)->toBeTrue();
});

it('can save automatic evaluation settings and custom criteria coins', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    // Check default loaded settings
    expect($component->get('hifz_enabled'))->toBeTrue();
    expect($component->get('hifz_excellent_xp'))->toBe(10);
    expect($component->get('hifz_excellent_coins'))->toBe(10);

    // Add custom criterion
    $component->call('addTodoCriterion');
    $criteria = $component->get('criteria');
    $criteria[0]['name'] = 'الانضباط والهدوء';
    $criteria[0]['points'] = 8;
    $criteria[0]['coins'] = 12;
    $criteria[0]['is_enthusiasm_trigger'] = false;

    // Modify automatic settings
    $component->set('hifz_excellent_xp', 20)
        ->set('hifz_excellent_coins', 25)
        ->set('review_good_xp', 6)
        ->set('review_good_coins', 8)
        ->set('attendance_enthusiasm_trigger', false)
        ->set('manual_claim_enabled', true)
        ->set('criteria', $criteria)
        ->call('saveCriteria')
        ->assertHasNoErrors();

    // Verify database settings
    $leaderboard = $this->leaderboard->refresh();
    $settings = $leaderboard->settings;

    expect($settings['hifz_excellent_xp'])->toBe(20);
    expect($settings['hifz_excellent_coins'])->toBe(25);
    expect($settings['review_good_xp'])->toBe(6);
    expect($settings['review_good_coins'])->toBe(8);
    expect((bool) $settings['attendance_enthusiasm_trigger'])->toBeFalse();
    expect((bool) $settings['manual_claim_enabled'])->toBeTrue();

    // Verify custom criterion coins
    $dbCriterion = LeaderboardCriterion::where('leaderboard_id', $this->leaderboard->id)->first();
    expect($dbCriterion)->not->toBeNull();
    expect($dbCriterion->points)->toBe(8);
    expect($dbCriterion->coins)->toBe(12);
});

it('calculates separate coin amounts and XP amounts for syncStudentPlanDayXP and syncStudentAttendanceXP', function () {
    // Modify leaderboard settings for testing
    $this->leaderboard->settings = array_merge($this->leaderboard->settings ?? [], [
        'hifz_enabled' => true,
        'hifz_excellent_xp' => 15,
        'hifz_excellent_coins' => 20,
        'attendance_enabled' => true,
        'attendance_present_xp' => 5,
        'attendance_present_coins' => 8,
    ]);
    $this->leaderboard->save();

    $plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'plan_type' => 'hifz_review',
        'start_date' => now()->subDays(5),
        'is_approved' => 1,
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);

    // Test syncStudentPlanDayXP (Hifz)
    $day = StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => now()->format('Y-m-d'),
        'day_name' => 'السبت',
        'hifz_achievement' => 3, // Excellent
    ]);

    GamificationService::syncStudentPlanDayXP($day);

    $planTx = GamificationTransaction::where('student_id', $this->student->id)
        ->where('reference_type', StudentPlanDay::class)
        ->where('reference_id', $day->id)
        ->first();

    expect($planTx)->not->toBeNull();
    expect($planTx->xp_amount)->toBe(15);
    expect($planTx->amount)->toBe(20);

    // Test syncStudentAttendanceXP
    $teacher = Teacher::factory()->create();
    $att = Attendance::create([
        'student_id' => $this->student->id,
        'circle_id' => $this->circle->id,
        'teacher_id' => $teacher->id,
        'date' => now()->format('Y-m-d'),
        'status' => 'present',
    ]);

    GamificationService::syncStudentAttendanceXP($att);

    $attTx = GamificationTransaction::where('student_id', $this->student->id)
        ->where('reference_type', Attendance::class)
        ->where('reference_id', $att->id)
        ->first();

    expect($attTx)->not->toBeNull();
    expect($attTx->xp_amount)->toBe(5);
    expect($attTx->amount)->toBe(8);
});

it('calculates separate coin amounts and XP amounts for syncStudentCustomCriterionXP', function () {
    $criterion = LeaderboardCriterion::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'المشاركة الصفية',
        'points' => 6,
        'coins' => 10,
    ]);

    $score = LeaderboardScore::create([
        'leaderboard_id' => $this->leaderboard->id,
        'student_id' => $this->student->id,
        'leaderboard_criterion_id' => $criterion->id,
        'date' => now()->format('Y-m-d'),
    ]);

    GamificationService::syncStudentCustomCriterionXP($score);

    $tx = GamificationTransaction::where('student_id', $this->student->id)
        ->where('reference_type', LeaderboardScore::class)
        ->where('reference_id', $score->id)
        ->first();

    expect($tx)->not->toBeNull();
    expect($tx->xp_amount)->toBe(6);
    expect($tx->amount)->toBe(10);
});

it('triggers streaks only based on enabled individual enthusiasm triggers', function () {
    $teacher = Teacher::factory()->create();

    // Disable attendance trigger, enable hifz trigger
    $this->leaderboard->settings = array_merge($this->leaderboard->settings ?? [], [
        'enthusiasm_enabled' => true,
        'attendance_enabled' => true,
        'attendance_present_xp' => 5,
        'attendance_present_coins' => 5,
        'attendance_enthusiasm_trigger' => false, // disabled!
        'hifz_enabled' => true,
        'hifz_excellent_xp' => 10,
        'hifz_excellent_coins' => 10,
        'hifz_enthusiasm_trigger' => true, // enabled!
    ]);
    $this->leaderboard->save();

    // Attendance present should not trigger enthusiasm on this date
    $attDate = now()->subDays(2)->format('Y-m-d');
    $att = Attendance::create([
        'student_id' => $this->student->id,
        'circle_id' => $this->circle->id,
        'teacher_id' => $teacher->id,
        'date' => $attDate,
        'status' => 'present',
    ]);

    GamificationService::syncStudentAttendanceXP($att);

    // Verify checkEnthusiasmForDate returns false for attendance
    $isTriggered = GamificationService::checkEnthusiasmForDate($this->student, $attDate, $this->leaderboard);
    expect($isTriggered)->toBeFalse();

    // Setup active student plans
    $plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'plan_type' => 'hifz_review',
        'start_date' => now()->subDays(5),
        'is_approved' => 1,
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);

    // Hifz should trigger enthusiasm
    $hifzDate = now()->format('Y-m-d');
    $day = StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => $hifzDate,
        'day_name' => 'الأحد',
        'hifz_achievement' => 3, // Excellent
    ]);

    GamificationService::syncStudentPlanDayXP($day);

    // Verify checkEnthusiasmForDate returns true for hifz
    $isTriggeredHifz = GamificationService::checkEnthusiasmForDate($this->student, $hifzDate, $this->leaderboard);
    expect($isTriggeredHifz)->toBeTrue();
});
it('manages gamification team tasks, prevents date overlaps, and awards/adjusts/removes team rewards', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    // A teacher of the competition's circle — the only kind the form offers
    $teacher = Teacher::factory()->create();
    $teacher->circles()->attach($this->circle->id);

    // Create a team first
    $team = GamificationTeam::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'أسرة التحدي',
        'coins' => 100,
    ]);

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    // 1. Create a Team Task Definition with criteria
    $component->call('createTeamTask')
        ->set('task_name', 'مهمة النظافة')
        ->set('task_description', 'تنظيف القاعة والمسجد')
        ->set('task_criteria', [
            [
                'id' => null,
                'name' => 'البند الأول: السجاد',
                'description' => 'تنظيف السجاد بالكامل',
                'coins_reward' => 150,
            ],
            [
                'id' => null,
                'name' => 'البند الثاني: الجدران',
                'description' => 'مسح الغبار',
                'coins_reward' => 50,
            ],
        ])
        ->call('saveTeamTask')
        ->assertHasNoErrors();

    $task = GamificationTeamTask::with('criteria')->where('leaderboard_id', $this->leaderboard->id)->first();
    expect($task)->not->toBeNull();
    expect($task->name)->toBe('مهمة النظافة');
    expect($task->criteria)->toHaveCount(2);
    expect($task->coins_reward)->toBe(200); // 150 + 50

    // 2. Create a Team Task Assignment with teacher
    $component->call('createAssignment')
        ->set('assignment_task_id', $task->id)
        ->set('assignment_team_id', $team->id)
        ->set('assignment_teacher_id', $teacher->id)
        ->set('assignment_start_date', now()->format('Y-m-d'))
        ->set('assignment_end_date', now()->addDays(2)->format('Y-m-d'))
        ->call('saveAssignment')
        ->assertHasNoErrors();

    $assignment = GamificationTeamTaskAssignment::where('team_task_id', $task->id)->first();
    expect($assignment)->not->toBeNull();
    expect($assignment->status)->toBe('assigned');
    expect($assignment->team_id)->toBe($team->id);
    expect($assignment->teacher_id)->toBe($teacher->id);

    // 3. Overlap Check: Try to create another assignment for the same task with overlapping dates
    Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->call('createAssignment')
        ->set('assignment_task_id', $task->id)
        ->set('assignment_team_id', $team->id)
        ->set('assignment_start_date', now()->addDay()->format('Y-m-d')) // overlaps!
        ->set('assignment_end_date', now()->addDays(3)->format('Y-m-d'))
        ->call('saveAssignment')
        ->assertHasErrors(['assignment_start_date']);

    // 4. Grade/Evaluate the Team Task Assignment using criteria scores (Calculates 90% grade)
    $component->call('editAssignment', $assignment->id)
        ->set('assignment_scores', [
            $task->criteria[0]->id => 8,
            $task->criteria[1]->id => 10,
        ])
        ->set('assignment_notes', 'عمل رائع جداً')
        ->call('saveAssignment')
        ->assertHasNoErrors();

    $assignment->refresh();
    expect($assignment->status)->toBe('completed');
    expect($assignment->grade)->toBe(90);
    expect($assignment->notes)->toBe('عمل رائع جداً');

    // Awarded coins: (8/10)*150 + (10/10)*50 = 120 + 50 = 170 coins.
    // Team coins should increase from 100 to 270
    $team->refresh();
    expect($team->coins)->toBe(270);

    $tx = GamificationTransaction::where('leaderboard_id', $this->leaderboard->id)
        ->where('reference_type', GamificationTeamTaskAssignment::class)
        ->where('reference_id', $assignment->id)
        ->first();

    expect($tx)->not->toBeNull();
    expect($tx->student_id)->toBeNull();
    expect($tx->team_id)->toBe($team->id);
    expect($tx->amount)->toBe(170);
    expect($tx->xp_amount)->toBe(45); // (90/100) * 50 = 45 XP

    // Verify team score includes this transaction
    $teamScore = GamificationService::getTeamScore($team, $this->leaderboard);
    expect($teamScore)->toBe(45);

    // 5. Edit/Adjust scores to 100%
    $component->call('editAssignment', $assignment->id)
        ->set('assignment_scores', [
            $task->criteria[0]->id => 10,
            $task->criteria[1]->id => 10,
        ])
        ->call('saveAssignment')
        ->assertHasNoErrors();

    // Team coins should be 100 + 200 = 300
    $team->refresh();
    expect($team->coins)->toBe(300);

    $tx->refresh();
    expect($tx->amount)->toBe(200);
    expect($tx->xp_amount)->toBe(50);

    $teamScore = GamificationService::getTeamScore($team, $this->leaderboard);
    expect($teamScore)->toBe(50);

    // 6. Delete Team Task Assignment (should revert coins and delete transaction)
    $component->call('deleteAssignment', $assignment->id)
        ->assertHasNoErrors();

    expect(GamificationTeamTaskAssignment::find($assignment->id))->toBeNull();
    expect(GamificationTransaction::find($tx->id))->toBeNull();

    // Team coins should decrease by 200 to 100
    $team->refresh();
    expect($team->coins)->toBe(100);

    $teamScore = GamificationService::getTeamScore($team, $this->leaderboard);
    expect($teamScore)->toBe(0);
});

it('allows teachers to view and grade assigned group tasks on their dashboard', function () {
    $teacher = Teacher::factory()->create();
    $teacher->circles()->attach($this->circle->id);

    // Create a team first
    $team = GamificationTeam::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'أسرة التحدي',
        'coins' => 100,
    ]);

    // Create a Team Task Definition with criteria
    $task = GamificationTeamTask::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'مهمة النظافة',
        'xp_reward' => 100,
        'coins_reward' => 200,
    ]);

    $criterion = $task->criteria()->create([
        'name' => 'الترتيب البصري',
        'coins_reward' => 200,
    ]);

    // Assign to teacher
    $assignment = GamificationTeamTaskAssignment::create([
        'team_task_id' => $task->id,
        'team_id' => $team->id,
        'teacher_id' => $teacher->id,
        'start_date' => now()->format('Y-m-d'),
        'end_date' => now()->addDays(2)->format('Y-m-d'),
        'status' => 'assigned',
    ]);

    // Act as teacher
    $this->actingAs($teacher, 'teacher');

    // Run Livewire component
    $component = Livewire::test('teacher.dashboard');
    $component->assertSee('مهمة النظافة')
        ->assertSee('أسرة التحدي');

    // Start grading
    $component->call('editGrading', $assignment->id)
        ->assertSet('showGradingModal', true)
        ->set('gradingScores', [
            $criterion->id => 9,
        ])
        ->set('gradingNotes', 'مستوى رائع')
        ->call('saveGrading')
        ->assertHasNoErrors();

    // Verify grading results
    $assignment->refresh();
    expect($assignment->status)->toBe('completed');
    expect($assignment->grade)->toBe(90);
    expect($assignment->notes)->toBe('مستوى رائع');

    // Awarded coins: (9/10) * 200 = 180. Awarded XP: (90/100) * 100 = 90.
    // Team coins should increase to 280 (100 + 180)
    $team->refresh();
    expect($team->coins)->toBe(280);

    $tx = GamificationTransaction::where('leaderboard_id', $this->leaderboard->id)
        ->where('reference_type', GamificationTeamTaskAssignment::class)
        ->where('reference_id', $assignment->id)
        ->first();

    expect($tx)->not->toBeNull();
    expect($tx->amount)->toBe(180);
    expect($tx->xp_amount)->toBe(90);
});

it('manages gamification activities and their custom ranks', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    // 1. Create Activity
    $component->call('createActivity')
        ->set('activity_name', 'مسابقة الخط العربي')
        ->set('activity_description', 'منافسة في كتابة خط المصحف الشريف')
        ->set('activity_ranks', [
            ['name' => 'المركز الأول', 'team_xp' => 120, 'team_coins' => 100, 'member_xp' => 60, 'member_coins' => 50],
            ['name' => 'المركز الثاني', 'team_xp' => 60, 'team_coins' => 50, 'member_xp' => 30, 'member_coins' => 25],
        ])
        ->call('saveActivity')
        ->assertHasNoErrors();

    $activity = GamificationActivity::where('leaderboard_id', $this->leaderboard->id)
        ->where('name', 'مسابقة الخط العربي')
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->description)->toBe('منافسة في كتابة خط المصحف الشريف');
    expect($activity->ranks()->count())->toBe(2);

    $rank1 = $activity->ranks()->where('name', 'المركز الأول')->first();
    expect($rank1->team_xp)->toBe(120);
    expect($rank1->member_coins)->toBe(50);

    // 2. Edit Activity
    $component->call('editActivity', $activity->id)
        ->set('activity_ranks', [
            ['id' => $rank1->id, 'name' => 'المركز الأول المطور', 'team_xp' => 150, 'team_coins' => 120, 'member_xp' => 70, 'member_coins' => 60],
        ])
        ->call('saveActivity')
        ->assertHasNoErrors();

    $rank1->refresh();
    expect($rank1->name)->toBe('المركز الأول المطور');
    expect($rank1->team_xp)->toBe(150);
    expect($activity->ranks()->count())->toBe(1); // Second rank deleted because not in sync

    // 3. Delete Activity
    $component->call('deleteActivity', $activity->id);
    expect(GamificationActivity::find($activity->id))->toBeNull();
});

it('records activity winners, distributes XP and coins, and allows deletion reverting rewards', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    // Create a team and associate the student
    $team = GamificationTeam::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'أسرة الريادة',
        'coins' => 100,
    ]);
    DB::table('gamification_team_student')->insert([
        'team_id' => $team->id,
        'student_id' => $this->student->id,
        'role' => 'member',
    ]);

    // Recalculate student balance (starts at 0)
    GamificationService::recalculateStudentState($this->student->id, $this->leaderboard->id);

    // Create an Activity and Ranks
    $activity = GamificationActivity::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'الدوري الرياضي المشترك',
    ]);
    $rank = $activity->ranks()->create([
        'name' => 'المركز الأول',
        'team_xp' => 100,
        'team_coins' => 150,
        'member_xp' => 50,
        'member_coins' => 75,
    ]);

    // Create a Round for the Activity
    $round = GamificationActivityRound::create([
        'activity_id' => $activity->id,
        'name' => 'الجولة الأولى',
        'round_date' => now()->format('Y-m-d'),
    ]);

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    // 1. Record Winner
    $component->call('editRoundWinners', $round->id)
        ->assertSet('selectedRoundId', $round->id)
        ->assertSet('showRoundWinnersModal', true)
        ->set("roundRanksWinners.{$rank->id}", $team->id)
        ->call('saveRoundWinners')
        ->assertHasNoErrors();

    $winner = GamificationActivityWinner::where('round_id', $round->id)
        ->where('rank_id', $rank->id)
        ->where('team_id', $team->id)
        ->first();

    expect($winner)->not->toBeNull();

    // Verify rewards distributed
    // Team coins: 100 + 150 = 250
    $team->refresh();
    expect($team->coins)->toBe(250);

    // Student state coins: 0 + 75 = 75
    $this->student->refresh();
    $state = GamificationStudentState::where('leaderboard_id', $this->leaderboard->id)
        ->where('student_id', $this->student->id)
        ->first();
    expect($state->coins)->toBe(75);

    // Student XP: 50 XP
    $xp = GamificationService::getStudentXP($this->student->id, $this->leaderboard->id);
    expect($xp)->toBe(50);

    // Verify transactions exist
    $teamTx = GamificationTransaction::where('leaderboard_id', $this->leaderboard->id)
        ->where('team_id', $team->id)
        ->whereNull('student_id')
        ->where('reference_type', GamificationActivityWinner::class)
        ->first();
    expect($teamTx)->not->toBeNull();
    expect($teamTx->amount)->toBe(150);
    expect($teamTx->xp_amount)->toBe(100);

    $studentTx = GamificationTransaction::where('leaderboard_id', $this->leaderboard->id)
        ->where('student_id', $this->student->id)
        ->where('reference_type', GamificationActivityWinner::class)
        ->first();
    expect($studentTx)->not->toBeNull();
    expect($studentTx->amount)->toBe(75);
    expect($studentTx->xp_amount)->toBe(50);

    // 2. Delete Winner (should revert rewards)
    $component->call('deleteWinner', $winner->id);

    // Team coins should return to 100
    $team->refresh();
    expect($team->coins)->toBe(100);

    // Student state coins should return to 0
    $state->refresh();
    expect($state->coins)->toBe(0);

    // Student XP should return to 0
    $xp = GamificationService::getStudentXP($this->student->id, $this->leaderboard->id);
    expect($xp)->toBe(0);

    // Verify transactions deleted
    expect(GamificationTransaction::where('reference_type', GamificationActivityWinner::class)->count())->toBe(0);
});

it('allows supervisors to apply and delete manual adjustments', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    // Create a team first
    $team = GamificationTeam::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'أسرة البركة',
        'coins' => 100,
    ]);

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    // Test 1: Apply individual addition adjustment
    $component->set('adjTargetType', 'individual')
        ->set('adjStudentId', $this->student->id)
        ->set('adjActionType', 'add')
        ->set('adjHasXp', true)
        ->set('adjXpVal', 50)
        ->set('adjHasCoins', true)
        ->set('adjCoinsVal', 30)
        ->set('adjDescription', 'مشاركة متميزة في الحفل')
        ->call('applyAdjustment')
        ->assertHasNoErrors();

    // Verify individual adjustments applied
    $xp = GamificationService::getStudentXP($this->student->id, $this->leaderboard->id);
    expect($xp)->toBe(50);

    $state = GamificationStudentState::where('leaderboard_id', $this->leaderboard->id)
        ->where('student_id', $this->student->id)
        ->first();
    expect($state->coins)->toBe(30);

    // Verify transaction exists
    $tx1 = GamificationTransaction::where('student_id', $this->student->id)
        ->where('description', 'like', '%مشاركة متميزة في الحفل')
        ->first();
    expect($tx1)->not->toBeNull();
    expect($tx1->amount)->toBe(30);
    expect($tx1->xp_amount)->toBe(50);

    // Test 2: Apply team deduction adjustment
    $component->set('adjTargetType', 'team')
        ->set('adjTeamId', $team->id)
        ->set('adjActionType', 'deduct')
        ->set('adjHasXp', false)
        ->set('adjHasCoins', true)
        ->set('adjCoinsVal', 20)
        ->set('adjDescription', 'تخريب الممتلكات العامة')
        ->call('applyAdjustment')
        ->assertHasNoErrors();

    // Verify team coins decreased (100 - 20 = 80)
    $team->refresh();
    expect($team->coins)->toBe(80);

    $tx2 = GamificationTransaction::where('team_id', $team->id)
        ->where('description', 'like', '%تخريب الممتلكات العامة')
        ->first();
    expect($tx2)->not->toBeNull();
    expect($tx2->amount)->toBe(-20);

    // Test 3: Delete adjustments
    // Delete individual adjustment
    $component->call('deleteAdjustment', $tx1->id);
    $xp = GamificationService::getStudentXP($this->student->id, $this->leaderboard->id);
    expect($xp)->toBe(0);

    $state->refresh();
    expect($state->coins)->toBe(0);

    // Delete team adjustment
    $component->call('deleteAdjustment', $tx2->id);
    $team->refresh();
    expect($team->coins)->toBe(100); // restored back to 100
});

it('creates a track, assigns students, and enforces one track per student', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $student2 = Student::create([
        'name' => 'طالب ثانٍ',
        'email' => 'track-student2@example.com',
        'password' => bcrypt('password'),
        'circle_id' => $this->circle->id,
        'is_approved' => true,
        'status' => 'active',
    ]);

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    // Create track A with both students
    $component->call('createTrack')
        ->set('track_name', 'المتقدمون')
        ->set('track_description', 'مسار المتقدمين')
        ->set('track_student_ids', [(string) $this->student->id, (string) $student2->id])
        ->call('saveTrack')
        ->assertHasNoErrors();

    $trackA = GamificationTrack::where('leaderboard_id', $this->leaderboard->id)->where('name', 'المتقدمون')->first();
    expect($trackA)->not->toBeNull();
    expect($trackA->students()->count())->toBe(2);

    // Create track B with student2 → must be removed from track A (one track per student)
    $component->call('createTrack')
        ->set('track_name', 'المبتدئون')
        ->set('track_student_ids', [(string) $student2->id])
        ->call('saveTrack')
        ->assertHasNoErrors();

    $trackB = GamificationTrack::where('leaderboard_id', $this->leaderboard->id)->where('name', 'المبتدئون')->first();
    expect($trackB->students()->count())->toBe(1);
    expect($trackA->fresh()->students()->count())->toBe(1); // student2 moved out
    expect($trackA->students()->pluck('users.id')->toArray())->toBe([$this->student->id]);

    // Delete track B
    $component->call('deleteTrack', $trackB->id);
    expect(GamificationTrack::find($trackB->id))->toBeNull();
});

it('groups a student earned achievements by the real achievement date, not when credited', function () {
    $this->leaderboard->settings = array_merge($this->leaderboard->settings ?? [], [
        'hifz_enabled' => true,
        'hifz_excellent_xp' => 10,
        'hifz_excellent_coins' => 10,
    ]);
    $this->leaderboard->save();

    $plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'plan_type' => 'hifz_review',
        'start_date' => now()->subDays(5),
        'is_approved' => 1,
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);

    $achievementDate = now()->subDays(2)->format('Y-m-d');
    $day = StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => $achievementDate,
        'day_name' => 'يوم',
        'hifz_achievement' => 3, // Excellent
    ]);
    GamificationService::syncStudentPlanDayXP($day);

    $achievements = GamificationService::getAchievementsByStudentAndDay($this->leaderboard);

    expect($achievements)->toHaveKey($this->student->id);
    // The achievement is filed under the plan day's date, even though the transaction
    // row was created "today".
    expect($achievements[$this->student->id])->toHaveKey($achievementDate);
    expect($achievements[$this->student->id][$achievementDate][0]['xp'])->toBe(10);
    expect($achievements[$this->student->id][$achievementDate][0]['description'])->toContain('لليوم '.$achievementDate);
});

it('renders the student standings tab for the selected day and opens the per-day achievements modal', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $this->leaderboard->settings = array_merge($this->leaderboard->settings ?? [], [
        'hifz_enabled' => true,
        'hifz_excellent_xp' => 10,
        'hifz_excellent_coins' => 10,
    ]);
    $this->leaderboard->save();

    $plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'plan_type' => 'hifz_review',
        'start_date' => now()->subDays(5),
        'is_approved' => 1,
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);

    $achievementDate = now()->subDays(2)->format('Y-m-d');
    $day = StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => $achievementDate,
        'day_name' => 'يوم',
        'hifz_achievement' => 3,
    ]);
    GamificationService::syncStudentPlanDayXP($day);

    Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->set('activeTab', 'standings')
        ->set('standingsDate', $achievementDate)
        ->assertSee('مراكز الطلاب')
        ->assertSee($this->student->name)
        ->assertSee('لليوم '.$achievementDate) // today-achievement chip for the selected day
        ->call('viewStudentAchievements', $this->student->id)
        ->assertSet('showAchievementsModal', true)
        ->assertSet('achievementsStudentId', $this->student->id)
        ->assertSee('إنجازات '.$this->student->name);
});

it('excludes points from work graded outside the competition window from standings and the daily view', function () {
    // Competition window is now()->subDays(5) .. now()->addDays(5) (from beforeEach).
    $plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'plan_type' => 'hifz_review',
        'start_date' => now()->subDays(50),
        'is_approved' => 1,
        'days_count' => 60,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);

    // In-window: an old scheduled day, but graded today (inside the window).
    $inDay = StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => now()->subDays(30)->format('Y-m-d'),
        'day_name' => 'يوم',
        'hifz_achievement' => 3,
        'hifz_graded_at' => now(),
    ]);

    // Out-of-window: graded before the competition even started.
    $outDay = StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => now()->subDays(40)->format('Y-m-d'),
        'day_name' => 'يوم',
        'hifz_achievement' => 3,
        'hifz_graded_at' => now()->subDays(8),
    ]);

    foreach ([$inDay, $outDay] as $d) {
        GamificationTransaction::create([
            'leaderboard_id' => $this->leaderboard->id,
            'student_id' => $this->student->id,
            'type' => 'earn',
            'amount' => 10,
            'xp_amount' => 10,
            'description' => 'حفظ ممتاز لليوم '.$d->date->format('Y-m-d'),
            'reference_type' => StudentPlanDay::class,
            'reference_id' => $d->id,
            'claimed_at' => now(),
        ]);
    }

    // Standings total counts only the in-window earning (10), not both (20).
    $standings = (new LeaderboardService)->getDetailedStandings($this->leaderboard->fresh());
    $row = $standings->first(fn ($r) => $r['student']->id === $this->student->id);
    expect($row['score'])->toBe(10);

    // The daily view files the in-window earning under its grading date (today) and
    // drops the out-of-window one entirely.
    $achievements = GamificationService::getAchievementsByStudentAndDay($this->leaderboard->fresh());
    $days = array_keys($achievements[$this->student->id] ?? []);
    expect($days)->toContain(now()->format('Y-m-d'));
    expect($days)->not->toContain(now()->subDays(8)->format('Y-m-d'));
    expect($days)->not->toContain(now()->subDays(30)->format('Y-m-d')); // scheduled date is not used
});

it('shows no achievement for a day the student did nothing', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->set('activeTab', 'standings')
        ->set('standingsDate', now()->format('Y-m-d'))
        ->assertSee($this->student->name)
        ->assertSee('لا يوجد إنجاز في هذا اليوم');
});

it('records adjustments in the news only when the supervisor opts in', function () {
    $this->actingAs($this->supervisor, 'supervisor');
    $c = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    // Default: opt-in off → no news
    $c->set('adjTargetType', 'individual')->set('adjStudentId', $this->student->id)
        ->set('adjActionType', 'add')->set('adjHasXp', true)->set('adjXpVal', 10)
        ->set('adjDescription', 'مكافأة')->set('adjShowInNews', false)
        ->call('applyAdjustment')->assertHasNoErrors();
    expect(GamificationNews::where('type', 'adjustment')->count())->toBe(0);

    // Opt-in on → news recorded with the student's name (name shown by default)
    $c->set('adjTargetType', 'individual')->set('adjStudentId', $this->student->id)
        ->set('adjActionType', 'add')->set('adjHasXp', true)->set('adjXpVal', 15)
        ->set('adjDescription', 'مكافأة ثانية')->set('adjShowInNews', true)
        ->call('applyAdjustment')->assertHasNoErrors();

    $news = GamificationNews::where('type', 'adjustment')->first();
    expect($news)->not->toBeNull();
    expect($news->data['target_name'])->toBe($this->student->name);
    expect($news->data['name_hidden'])->toBeFalse();
    expect($news->data['xp'])->toBe(15);
});

it('hides the target name from the adjustment news when the supervisor chooses to', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->set('adjTargetType', 'individual')->set('adjStudentId', $this->student->id)
        ->set('adjActionType', 'add')->set('adjHasXp', true)->set('adjXpVal', 20)
        ->set('adjDescription', 'مكافأة سرية')->set('adjShowInNews', true)
        ->set('adjShowTargetName', false)
        ->call('applyAdjustment')->assertHasNoErrors();

    $news = GamificationNews::where('type', 'adjustment')->first();
    expect($news)->not->toBeNull();
    expect($news->data['target_name'])->toBeNull();
    expect($news->data['name_hidden'])->toBeTrue();
    expect($news->data['target_type'])->toBe('individual');
});

it('lists per-circle signed coin redemption links on the redemption tab', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->set('activeTab', 'redemption')
        ->assertSee('روابط صرف العملات')
        ->assertSee($this->circle->name)
        ->assertSee('/r/coin-redemption')
        ->assertSee('signature');
});

it('offers copying all redemption links as one text with a privacy warning', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $html = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->set('activeTab', 'redemption')
        ->assertSee('نسخ جميع الروابط')
        ->html();

    expect($html)
        ->toContain('روابط صرف العملات — مسابقة')
        ->toContain('يجب عدم مشاركتها مع أي شخص')
        ->toContain('حلقة '.$this->circle->name);
});

it('lists store purchases and cancels one via the supervisor store tab', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $team = GamificationTeam::create(['leaderboard_id' => $this->leaderboard->id, 'name' => 'فريق المتجر', 'coins' => 100]);
    $team->students()->attach($this->student->id, ['role' => 'leader']);

    $item = GamificationStoreItem::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'دعم الفريق',
        'price' => 30,
        'item_type' => 'team_points',
        'value' => 40,
        'is_team_product' => true,
    ]);
    GamificationService::requestStorePurchase($this->student->id, $item->id);
    $purchase = GamificationStorePurchase::where('store_item_id', $item->id)->first();

    $team->refresh();
    expect($team->coins)->toBe(70);

    Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->set('activeTab', 'store')
        ->assertSee('سجل المشتريات')
        ->assertSee($item->name)
        ->call('cancelPurchase', $purchase->id)
        ->assertHasNoErrors();

    expect($purchase->fresh()->status)->toBe('cancelled');
    $team->refresh();
    expect($team->coins)->toBe(100);
});

it('does not cancel a purchase belonging to another competition', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $otherLeaderboard = Leaderboard::create([
        'supervisor_id' => $this->supervisor->id,
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة أخرى',
        'competition_type' => 'gamification',
        'start_date' => now()->subDays(5),
        'end_date' => now()->addDays(5),
        'is_active' => true,
    ]);

    $team = GamificationTeam::create(['leaderboard_id' => $otherLeaderboard->id, 'name' => 'فريق آخر', 'coins' => 100]);
    $team->students()->attach($this->student->id, ['role' => 'leader']);
    $item = GamificationStoreItem::create([
        'leaderboard_id' => $otherLeaderboard->id,
        'name' => 'دعم فريق آخر',
        'price' => 30,
        'item_type' => 'team_points',
        'value' => 40,
        'is_team_product' => true,
    ]);
    GamificationService::requestStorePurchase($this->student->id, $item->id);
    $purchase = GamificationStorePurchase::where('store_item_id', $item->id)->first();

    Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->call('cancelPurchase', $purchase->id);

    // Untouched: the purchase belongs to a different competition.
    expect($purchase->fresh()->status)->toBe('approved');
});

it('groups store purchases by product with a per-product count', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $team = GamificationTeam::create(['leaderboard_id' => $this->leaderboard->id, 'name' => 'فريق التجميع', 'coins' => 500]);
    $team->students()->attach($this->student->id, ['role' => 'leader']);

    $item = GamificationStoreItem::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'دعم الفريق',
        'price' => 30,
        'item_type' => 'team_points',
        'value' => 40,
        'is_team_product' => true,
    ]);

    // Two purchases of the same product -> one group with a count of 2.
    GamificationService::requestStorePurchase($this->student->id, $item->id);
    GamificationService::requestStorePurchase($this->student->id, $item->id);

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->set('activeTab', 'store');

    $groups = $component->viewData('purchasesByProduct');

    expect($groups)->toHaveCount(1);
    expect($groups->first()['item']->id)->toBe($item->id);
    expect($groups->first()['purchases'])->toHaveCount(2);

    $component->assertSee('2 عملية');
});

it('awards a qualifying student immediately when a badge is created or edited', function () {
    $this->actingAs($this->supervisor, 'supervisor');
    $teacher = Teacher::factory()->create();

    $this->leaderboard->update(['start_date' => '2026-06-01', 'end_date' => '2026-06-30']);

    // Student already has a 3-day consecutive attendance streak.
    foreach (['2026-06-14', '2026-06-15', '2026-06-16'] as $date) {
        $attendance = Attendance::create([
            'student_id' => $this->student->id,
            'circle_id' => $this->circle->id,
            'teacher_id' => $teacher->id,
            'date' => $date,
            'status' => 'present',
        ]);
        GamificationService::syncStudentAttendanceXP($attendance);
    }

    // Supervisor now creates a streak_attendance badge requiring 3 days.
    Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->call('createBadge')
        ->set('badge_name', 'وسام المواظبة')
        ->set('badge_mechanism', 'streak')
        ->set('badge_achievement_type', 'attendance')
        ->set('badge_requirement_value', 3)
        ->set('badge_image_file', UploadedFile::fake()->image('badge.png'))
        ->call('saveBadge')
        ->assertHasNoErrors();

    $badge = GamificationBadge::where('leaderboard_id', $this->leaderboard->id)->where('name', 'وسام المواظبة')->first();

    // The already-qualifying student is awarded on save, without needing a new event.
    expect(DB::table('gamification_badge_student')
        ->where('badge_id', $badge->id)
        ->where('student_id', $this->student->id)
        ->exists())->toBeTrue();
});

/**
 * "توزيع يدوي / مخصص" is the default mechanism in the badge form, and it had
 * no way to reach a student: the automatic sync skips a badge with no progress
 * to measure, and nothing else granted one. Only two places ever wrote to the
 * pivot — that sync, and an enthusiasm milestone's reward badge.
 */
it('hands a manual badge to the students the supervisor picks, and they can claim it', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $other = Student::create([
        'name' => 'طالب آخر', 'email' => 'other-grant@example.com', 'password' => bcrypt('x'),
        'circle_id' => $this->circle->id, 'is_approved' => true, 'status' => 'active',
    ]);

    $badge = GamificationBadge::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'وسام حسن الخلق',
        'icon' => 'star',
        'badge_type' => 'manual',
        'requirement_value' => 0,
        'reward_xp' => 30,
        'reward_coins' => 30,
    ]);

    // The automatic sync has nothing to say about a manual badge.
    GamificationService::syncStudentBadges($this->student->id, $this->leaderboard->id);
    expect(DB::table('gamification_badge_student')->count())->toBe(0);

    Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->call('openGrantBadge', $badge->id)
        ->assertSet('showGrantBadgeModal', true)
        ->set('grantStudentIds', [(string) $this->student->id])
        ->call('grantBadge')
        ->assertHasNoErrors()
        ->assertSet('showGrantBadgeModal', false);

    // Granted to the one picked, and only to them.
    expect(DB::table('gamification_badge_student')->where('badge_id', $badge->id)->pluck('status', 'student_id')->all())
        ->toBe([$this->student->id => 'approved']);

    // The student claims it like any other badge, and the reward lands.
    $this->actingAs($this->student, 'student');

    Livewire::test('student.gamification-dashboard')
        ->call('claimBadge', $badge->id)
        ->assertHasNoErrors();

    $reward = GamificationTransaction::where('student_id', $this->student->id)
        ->where('reference_type', GamificationBadge::class)
        ->where('reference_id', $badge->id)
        ->first();

    expect($reward?->xp_amount)->toBe(30)
        ->and($reward?->leaderboard_id)->toBe($this->leaderboard->id)
        ->and(DB::table('gamification_badge_student')->where('student_id', $other->id)->count())->toBe(0);
});

it('does not grant a badge twice, nor to a student outside the competition', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $outsider = Student::create([
        'name' => 'طالب خارج المسابقة', 'email' => 'outsider@example.com', 'password' => bcrypt('x'),
        'circle_id' => Circle::create(['name' => 'حلقة أخرى', 'stage_id' => $this->stage->id])->id,
        'is_approved' => true, 'status' => 'active',
    ]);

    $badge = GamificationBadge::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'وسام المبادرة', 'icon' => 'star', 'badge_type' => 'manual',
        'requirement_value' => 0, 'reward_xp' => 10, 'reward_coins' => 10,
    ]);

    $grant = fn () => Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->call('openGrantBadge', $badge->id)
        ->set('grantStudentIds', [(string) $this->student->id, (string) $outsider->id])
        ->call('grantBadge');

    $grant();
    $grant();

    expect(DB::table('gamification_badge_student')->where('badge_id', $badge->id)->count())->toBe(1)
        ->and(DB::table('gamification_badge_student')->where('student_id', $outsider->id)->count())->toBe(0);
});

it('takes back a badge handed out by mistake, unless the student already claimed it', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $badge = GamificationBadge::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'وسام النشاط', 'icon' => 'star', 'badge_type' => 'manual',
        'requirement_value' => 0, 'reward_xp' => 10, 'reward_coins' => 10,
    ]);

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->call('openGrantBadge', $badge->id)
        ->set('grantStudentIds', [(string) $this->student->id])
        ->call('grantBadge');

    $component->call('revokeBadge', $badge->id, $this->student->id);
    expect(DB::table('gamification_badge_student')->where('badge_id', $badge->id)->count())->toBe(0);

    // A claimed badge has already been paid for, so it stays.
    DB::table('gamification_badge_student')->insert([
        'badge_id' => $badge->id, 'student_id' => $this->student->id,
        'status' => 'claimed', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $component->call('revokeBadge', $badge->id, $this->student->id);
    expect(DB::table('gamification_badge_student')->where('badge_id', $badge->id)->count())->toBe(1);
});

it('offers the hand-out button only on a badge that is handed out by name', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    GamificationBadge::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'وسام يدوي', 'icon' => 'star', 'badge_type' => 'manual', 'requirement_value' => 0,
    ]);
    GamificationBadge::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'وسام تلقائي', 'icon' => 'star', 'badge_type' => 'streak_attendance', 'requirement_value' => 5,
    ]);

    $html = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->set('activeTab', 'badges')
        ->html();

    // Flux renders the call in both wire:click and wire:target, so count buttons.
    expect(substr_count($html, 'wire:click="openGrantBadge'))->toBe(1);
});

it('keeps a supervisor to the rows of the competition they opened', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $otherSupervisor = Supervisor::factory()->create();
    $foreign = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'supervisor_id' => $otherSupervisor->id,
        'title' => 'مسابقة مشرف آخر',
        'competition_type' => 'gamification',
        'start_date' => now()->subDays(5),
        'end_date' => now()->addDays(5),
        'is_active' => true,
        'settings' => [],
    ]);
    $foreignTeam = GamificationTeam::create(['leaderboard_id' => $foreign->id, 'name' => 'فريق غريب', 'color' => '#000000']);
    $foreignBadge = GamificationBadge::create([
        'leaderboard_id' => $foreign->id, 'name' => 'وسام غريب', 'icon' => 'star', 'badge_type' => 'manual',
        'requirement_value' => 0, 'reward_xp' => 0, 'reward_coins' => 0,
    ]);

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id]);

    expect(fn () => $component->call('deleteTeam', $foreignTeam->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $component->call('deleteBadge', $foreignBadge->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $component->set('competitionId', $foreign->id))->toThrow(CannotUpdateLockedPropertyException::class);

    expect(GamificationTeam::whereKey($foreignTeam->id)->exists())->toBeTrue()
        ->and(GamificationBadge::whereKey($foreignBadge->id)->exists())->toBeTrue();
});

it('will not put a student from another circle on a team', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $outsider = Student::factory()->create([
        'circle_id' => Circle::create(['name' => 'حلقة خارجية', 'stage_id' => $this->stage->id])->id,
    ]);

    Livewire::test(ManageGamification::class, ['competitionId' => $this->leaderboard->id])
        ->call('createTeam')
        ->set('team_name', 'فريق الاختبار')
        ->set('team_color', '#123456')
        ->set('team_student_ids', [(string) $outsider->id])
        ->call('saveTeam')
        ->assertHasErrors('team_student_ids.0');
});

it('lets a teacher open the grading of only the tasks handed to them', function () {
    $mine = Teacher::factory()->create();
    $mine->circles()->attach($this->circle->id);
    $someoneElse = Teacher::factory()->create();

    $team = GamificationTeam::create(['leaderboard_id' => $this->leaderboard->id, 'name' => 'فريق', 'color' => '#000000']);
    $task = GamificationTeamTask::create(['leaderboard_id' => $this->leaderboard->id, 'name' => 'مهمة', 'coins_reward' => 10]);
    $theirs = GamificationTeamTaskAssignment::create([
        'team_task_id' => $task->id, 'team_id' => $team->id, 'teacher_id' => $someoneElse->id,
        'start_date' => now(), 'end_date' => now()->addDay(), 'status' => 'pending',
    ]);

    $this->actingAs($mine, 'teacher');

    expect(fn () => Livewire::test('teacher.dashboard')->call('editGrading', $theirs->id))
        ->toThrow(ModelNotFoundException::class);
});
