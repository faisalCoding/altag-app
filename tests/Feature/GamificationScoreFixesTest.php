<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\GamificationLevel;
use App\Models\GamificationNews;
use App\Models\GamificationStoreItem;
use App\Models\GamificationStorePurchase;
use App\Models\GamificationStudentState;
use App\Models\GamificationTeam;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Teacher;
use App\Services\GamificationService;
use App\Services\LeaderboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * What the gamification scores count: each product as what it is, refunds as
 * coins only, the streak as it stands today, team points on the day the work
 * was graded, purchases in the competition they were bought in, levels from
 * their real thresholds, and the level XP as the standings count the score.
 *
 * Today is 2026-06-10 (13:00 in Riyadh), and every day is a working day.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-10 10:00:00');

    $this->stage = Stage::create(['name' => 'مرحلة إصلاح النقاط']);
    $this->circle = Circle::create(['name' => 'حلقة إصلاح النقاط', 'stage_id' => $this->stage->id]);

    $this->teacher = Teacher::create([
        'name' => 'معلم إصلاح النقاط',
        'email' => 'scorefix-teacher@example.com',
        'password' => bcrypt('password'),
        'status' => 'active',
        'is_approved' => true,
    ]);

    $this->student = scoreFixStudent('طالب إصلاح النقاط', 'scorefix-1@example.com', $this->circle);

    AcademicCalendarEvent::create([
        'event_name' => 'دوام كامل لإصلاح النقاط',
        'start_date' => '2026-05-10',
        'end_date' => '2026-07-10',
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'is_visible' => true,
    ]);

    $this->leaderboard = scoreFixLeaderboard($this->circle, 'مسابقة إصلاح النقاط');
});

function scoreFixStudent(string $name, string $email, Circle $circle): Student
{
    return Student::create([
        'name' => $name,
        'email' => $email,
        'password' => bcrypt('password'),
        'circle_id' => $circle->id,
        'is_approved' => true,
        'status' => 'active',
    ]);
}

/**
 * A running competition, 2026-05-31 to 2026-06-20. Enthusiasm needs only a
 * present attendance, so a day's attendance is a day's enthusiasm.
 */
function scoreFixLeaderboard(Circle $circle, string $title): Leaderboard
{
    $leaderboard = Leaderboard::create([
        'circle_id' => $circle->id,
        'title' => $title,
        'competition_type' => 'gamification',
        'start_date' => '2026-05-31',
        'end_date' => '2026-06-20',
        'is_active' => true,
        'settings' => [
            'hifz_enabled' => true,
            'hifz_excellent' => 10,
            'hifz_good' => 7,
            'hifz_acceptable' => 4,
            'enthusiasm_enabled' => true,
            'attendance_enthusiasm_trigger' => true,
            'hifz_enthusiasm_trigger' => false,
            'review_enthusiasm_trigger' => false,
        ],
    ]);
    $leaderboard->circles()->attach($circle->id);

    return $leaderboard;
}

function scoreFixTeam(Leaderboard $leaderboard, string $name, Student $leader, int $coins = 0): GamificationTeam
{
    $team = GamificationTeam::create(['leaderboard_id' => $leaderboard->id, 'name' => $name, 'coins' => $coins]);
    $team->students()->attach($leader->id, ['role' => 'leader']);

    return $team;
}

function scoreFixItem(Leaderboard $leaderboard, string $type, array $attributes = []): GamificationStoreItem
{
    return GamificationStoreItem::create(array_merge([
        'leaderboard_id' => $leaderboard->id,
        'name' => "منتج {$type}",
        'price' => 10,
        'item_type' => $type,
        'value' => 2,
        'is_active' => true,
    ], $attributes));
}

function scoreFixApprovedPurchase(GamificationStoreItem $item, Student $buyer, ?GamificationTeam $team, string $date): GamificationStorePurchase
{
    return GamificationStorePurchase::create([
        'store_item_id' => $item->id,
        'student_id' => $buyer->id,
        'team_id' => $team?->id,
        'status' => 'approved',
        'price_paid' => $item->price,
        'target_date' => $date,
    ]);
}

function scoreFixEarn(Leaderboard $leaderboard, Student $student, int $xp, int $coins = 0, array $attributes = []): GamificationTransaction
{
    return GamificationTransaction::create(array_merge([
        'leaderboard_id' => $leaderboard->id,
        'student_id' => $student->id,
        'type' => 'earn',
        'amount' => $coins,
        'xp_amount' => $xp,
        'description' => 'كسب للاختبار',
    ], $attributes));
}

function scoreFixPresent(Student $student, Teacher $teacher, string $date): void
{
    Attendance::create([
        'student_id' => $student->id,
        'circle_id' => $student->circle_id,
        'teacher_id' => $teacher->id,
        'date' => $date,
        'status' => 'present',
    ]);
}

function scoreFixGradedDay(Student $student, string $date, string $gradedAt): StudentPlanDay
{
    $plan = StudentPlan::firstOrCreate(
        ['student_id' => $student->id],
        [
            'plan_type' => 'hifz_review',
            'start_date' => '2026-06-01',
            'is_approved' => 1,
            'days_count' => 30,
            'active_days' => [0, 1, 2, 3, 4, 5, 6],
        ],
    );

    return StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => $date,
        'day_name' => 'يوم',
        'hifz_achievement' => 3, // ممتاز: 10 XP
        'hifz_graded_at' => Carbon::parse($gradedAt),
    ]);
}

/* -------------------------------------------- each product counts as itself */

it('does not count a team multiplier as a shield', function () {
    $target = scoreFixTeam($this->leaderboard, 'الفريق المستهدف', $this->student, 100);
    $attacker = scoreFixStudent('قائد المهاجمين', 'scorefix-attacker@example.com', $this->circle);
    scoreFixTeam($this->leaderboard, 'الفريق المهاجم', $attacker, 200);

    $multiplier = scoreFixItem($this->leaderboard, 'multiplier', ['is_team_product' => true]);
    scoreFixApprovedPurchase($multiplier, $this->student, $target, '2026-06-10');

    expect(GamificationService::isShieldActiveForTeam($target, '2026-06-10'))->toBeFalse()
        ->and(GamificationService::isMultiplierActiveForTeam($target, '2026-06-10'))->toBeTrue();

    $attack = scoreFixItem($this->leaderboard, 'team_attack', ['is_team_product' => true, 'value' => 30]);

    expect(GamificationService::requestStorePurchase($attacker->id, $attack->id, $target->id))->toBe('success')
        ->and($target->fresh()->coins)->toBe(70);
});

it('does not double a team score on a shield day', function () {
    $team = scoreFixTeam($this->leaderboard, 'فريق الدرع', $this->student);

    $shield = scoreFixItem($this->leaderboard, 'shield', ['is_team_product' => true]);
    scoreFixApprovedPurchase($shield, $this->student, $team, '2026-06-10');

    scoreFixEarn($this->leaderboard, $this->student, 10);

    expect(GamificationService::isShieldActiveForTeam($team, '2026-06-10'))->toBeTrue()
        ->and(GamificationService::isMultiplierActiveForTeam($team, '2026-06-10'))->toBeFalse()
        ->and(GamificationService::getTeamScore($team, $this->leaderboard))->toBe(10)
        ->and(GamificationService::getTeamStandings($this->leaderboard, '2026-06-10')[0]['points_today'])->toBe(10);
});

it('does not count a streak freeze as a multiplier day', function () {
    $team = scoreFixTeam($this->leaderboard, 'فريق التجميد', $this->student);

    $freeze = scoreFixItem($this->leaderboard, 'freeze', ['is_streak_freeze' => true, 'value' => 0]);
    scoreFixApprovedPurchase($freeze, $this->student, null, '2026-06-09');

    GamificationService::syncStudentPlanDayXP(scoreFixGradedDay($this->student, '2026-06-09', '2026-06-09 08:00:00'));

    expect(GamificationService::getMultiplierForStudent($this->student, $this->leaderboard->id, '2026-06-09'))->toBe(1)
        ->and(GamificationService::getStudentXP($this->student->id, $this->leaderboard->id))->toBe(10)
        ->and(GamificationService::getTeamScore($team, $this->leaderboard))->toBe(10);
});

/* ----------------------------------------------- refunds are coins, not XP */

it('gives the leader no XP when a purchase is refused by vote', function () {
    $this->leaderboard->update(['settings' => array_merge($this->leaderboard->settings, ['team_purchase_voting_enabled' => true])]);

    $team = scoreFixTeam($this->leaderboard, 'فريق التصويت', $this->student, 100);
    $member = scoreFixStudent('عضو مصوت', 'scorefix-voter@example.com', $this->circle);
    $team->students()->attach($member->id, ['role' => 'member']);

    scoreFixEarn($this->leaderboard, $this->student, 100);

    $item = scoreFixItem($this->leaderboard, 'team_points', ['is_team_product' => true, 'price' => 80, 'value' => 50]);

    expect(GamificationService::requestStorePurchase($this->student->id, $item->id))->toBe('pending_voting');

    $purchase = GamificationStorePurchase::where('store_item_id', $item->id)->firstOrFail();
    GamificationService::voteForPurchase($member->id, $purchase->id, false);

    expect($purchase->fresh()->status)->toBe('rejected')
        ->and($team->fresh()->coins)->toBe(100)
        ->and(GamificationService::getStudentXP($this->student->id, $this->leaderboard->id))->toBe(100)
        ->and(GamificationService::getTeamScore($team, $this->leaderboard))->toBe(100);
});

it('gives the price back but no XP when a purchase is cancelled', function () {
    scoreFixEarn($this->leaderboard, $this->student, 100, 100);
    GamificationService::recalculateStudentState($this->student->id, $this->leaderboard->id);

    $item = scoreFixItem($this->leaderboard, 'custom', ['price' => 40]);

    expect(GamificationService::requestStorePurchase($this->student->id, $item->id))->toBe('success');

    $purchase = GamificationStorePurchase::where('store_item_id', $item->id)->firstOrFail();

    expect(GamificationService::cancelPurchase($purchase->id))->toBe('success');

    $state = GamificationStudentState::where('student_id', $this->student->id)->where('leaderboard_id', $this->leaderboard->id)->first();

    expect($state->coins)->toBe(100)
        ->and(GamificationService::getStudentXP($this->student->id, $this->leaderboard->id))->toBe(100);
});

it('leaves the attacked team its score when an attack is cancelled', function () {
    $target = scoreFixTeam($this->leaderboard, 'الفريق المستهدف', $this->student, 100);
    $attacker = scoreFixStudent('قائد المهاجمين', 'scorefix-attacker@example.com', $this->circle);
    scoreFixTeam($this->leaderboard, 'الفريق المهاجم', $attacker, 200);

    scoreFixEarn($this->leaderboard, $this->student, 20);

    $attack = scoreFixItem($this->leaderboard, 'team_attack', ['is_team_product' => true, 'value' => 30]);
    GamificationService::requestStorePurchase($attacker->id, $attack->id, $target->id);

    $purchase = GamificationStorePurchase::where('store_item_id', $attack->id)->firstOrFail();
    GamificationService::cancelPurchase($purchase->id);

    expect($target->fresh()->coins)->toBe(100)
        ->and(GamificationService::getTeamScore($target, $this->leaderboard))->toBe(20);
});

it('takes cancelled team support points off the team score', function () {
    $team = scoreFixTeam($this->leaderboard, 'فريق الدعم', $this->student, 100);

    $item = scoreFixItem($this->leaderboard, 'team_points', ['is_team_product' => true, 'price' => 30, 'value' => 50]);
    GamificationService::requestStorePurchase($this->student->id, $item->id);

    expect(GamificationService::getTeamScore($team, $this->leaderboard))->toBe(50);

    $purchase = GamificationStorePurchase::where('store_item_id', $item->id)->firstOrFail();
    GamificationService::cancelPurchase($purchase->id);

    expect(GamificationService::getTeamScore($team, $this->leaderboard))->toBe(0)
        ->and(GamificationService::getStudentXP($this->student->id, $this->leaderboard->id))->toBe(0)
        ->and($team->fresh()->coins)->toBe(100);
});

it('returns the team score to what it was when support bought on a plain day is cancelled on a team multiplier day', function () {
    $team = scoreFixTeam($this->leaderboard, 'فريق الدعم الملغى', $this->student, 100);
    scoreFixEarn($this->leaderboard, $this->student, 10, 0, ['created_at' => '2026-06-04 09:00:00']);

    Carbon::setTestNow('2026-06-05 09:00:00');
    $item = scoreFixItem($this->leaderboard, 'team_points', ['is_team_product' => true, 'price' => 30, 'value' => 50]);
    GamificationService::requestStorePurchase($this->student->id, $item->id);

    $multiplier = scoreFixItem($this->leaderboard, 'multiplier', ['is_team_product' => true]);
    scoreFixApprovedPurchase($multiplier, $this->student, $team, '2026-06-07');

    expect(GamificationService::getTeamScore($team, $this->leaderboard))->toBe(60);

    Carbon::setTestNow('2026-06-07 09:00:00');
    $purchase = GamificationStorePurchase::where('store_item_id', $item->id)->firstOrFail();
    GamificationService::cancelPurchase($purchase->id);

    $support = GamificationTransaction::where('description', 'like', 'تفعيل قوة ميزة دعم الفريق%')->firstOrFail();
    $cancel = GamificationTransaction::where('description', 'like', 'إلغاء دعم الفريق%')->firstOrFail();

    expect(GamificationService::getTeamScore($team, $this->leaderboard))->toBe(10)
        ->and(GamificationService::getTeamScore($team, $this->leaderboard, '2026-06-07'))->toBe(0)
        ->and($support->reference_type)->toBe(GamificationStorePurchase::class)
        ->and($support->reference_id)->toBe($purchase->id)
        ->and($cancel->created_at->eq($support->created_at))->toBeTrue()
        ->and($cancel->updated_at->toDateString())->toBe('2026-06-07');
});

it('takes back what the support gave even after the item\'s value was edited, on the support\'s own day', function () {
    $team = scoreFixTeam($this->leaderboard, 'فريق القيمة المعدلة', $this->student, 100);
    scoreFixEarn($this->leaderboard, $this->student, 10, 0, ['created_at' => '2026-06-04 09:00:00']);

    Carbon::setTestNow('2026-06-05 09:00:00');
    $item = scoreFixItem($this->leaderboard, 'team_points', ['is_team_product' => true, 'price' => 30, 'value' => 50]);
    GamificationService::requestStorePurchase($this->student->id, $item->id);

    $multiplier = scoreFixItem($this->leaderboard, 'multiplier', ['is_team_product' => true]);
    scoreFixApprovedPurchase($multiplier, $this->student, $team, '2026-06-07');

    // A supervisor raises the item's value after it was bought.
    $item->update(['value' => 80]);

    Carbon::setTestNow('2026-06-07 09:00:00');
    GamificationService::cancelPurchase(GamificationStorePurchase::where('store_item_id', $item->id)->firstOrFail()->id);

    $support = GamificationTransaction::where('description', 'like', 'تفعيل قوة ميزة دعم الفريق%')->firstOrFail();
    $cancel = GamificationTransaction::where('description', 'like', 'إلغاء دعم الفريق%')->firstOrFail();

    expect(GamificationService::getTeamScore($team, $this->leaderboard))->toBe(10)
        ->and((int) $cancel->amount)->toBe(-50)
        ->and((int) $cancel->xp_amount)->toBe(-50)
        ->and($cancel->created_at->eq($support->created_at))->toBeTrue();
});

it('returns the team score to what it was when support bought on a team multiplier day is cancelled on a plain day', function () {
    $team = scoreFixTeam($this->leaderboard, 'فريق الدعم المضاعف', $this->student, 100);

    $multiplier = scoreFixItem($this->leaderboard, 'multiplier', ['is_team_product' => true]);
    scoreFixApprovedPurchase($multiplier, $this->student, $team, '2026-06-05');

    Carbon::setTestNow('2026-06-05 09:00:00');
    $item = scoreFixItem($this->leaderboard, 'team_points', ['is_team_product' => true, 'price' => 30, 'value' => 50]);
    GamificationService::requestStorePurchase($this->student->id, $item->id);

    expect(GamificationService::getTeamScore($team, $this->leaderboard))->toBe(100);

    Carbon::setTestNow('2026-06-08 09:00:00');
    GamificationService::cancelPurchase(GamificationStorePurchase::where('store_item_id', $item->id)->value('id'));

    expect(GamificationService::getTeamScore($team, $this->leaderboard))->toBe(0);
});

it('finds the support row the old code wrote with no reference when its purchase is cancelled', function () {
    $team = scoreFixTeam($this->leaderboard, 'فريق الدعم القديم', $this->student, 100);
    $item = scoreFixItem($this->leaderboard, 'team_points', ['is_team_product' => true, 'price' => 30, 'value' => 50]);

    $multiplier = scoreFixItem($this->leaderboard, 'multiplier', ['is_team_product' => true]);
    scoreFixApprovedPurchase($multiplier, $this->student, $team, '2026-06-07');

    // A support of the same size bought before this purchase must not be taken for its row.
    GamificationTransaction::create([
        'leaderboard_id' => $this->leaderboard->id,
        'team_id' => $team->id,
        'type' => 'earn',
        'amount' => 50,
        'description' => 'تفعيل قوة ميزة دعم الفريق: +50 نقاط',
        'created_at' => '2026-06-01 09:00:00',
    ]);

    Carbon::setTestNow('2026-06-05 09:00:00');
    $purchase = scoreFixApprovedPurchase($item, $this->student, $team, '2026-06-05');
    GamificationTransaction::create([
        'leaderboard_id' => $this->leaderboard->id,
        'team_id' => $team->id,
        'type' => 'earn',
        'amount' => 50,
        'description' => 'تفعيل قوة ميزة دعم الفريق: +50 نقاط',
    ]);

    Carbon::setTestNow('2026-06-07 09:00:00');
    GamificationService::cancelPurchase($purchase->id);

    expect(GamificationTransaction::where('description', 'like', 'إلغاء دعم الفريق%')->firstOrFail()->created_at->toDateTimeString())
        ->toBe('2026-06-05 09:00:00')
        ->and(GamificationService::getTeamScore($team, $this->leaderboard))->toBe(50);
});

it('takes the XP off refunds already written, once', function () {
    $team = scoreFixTeam($this->leaderboard, 'فريق قديم', $this->student, 100);

    // As the old code wrote them: a refund with XP equal to its coins, and a
    // support cancel written as a spend the team score never read.
    scoreFixEarn($this->leaderboard, $this->student, 150, 150, [
        'team_id' => $team->id,
        'description' => 'استرداد قيمة شراء ملغى: خصم على فريق اخر',
        'reference_type' => GamificationStorePurchase::class,
        'reference_id' => 991,
    ]);
    GamificationTransaction::create([
        'leaderboard_id' => $this->leaderboard->id,
        'team_id' => $team->id,
        'type' => 'earn',
        'amount' => 50,
        'description' => 'تفعيل قوة ميزة دعم الفريق: +50 نقاط',
    ]);
    GamificationTransaction::create([
        'leaderboard_id' => $this->leaderboard->id,
        'team_id' => $team->id,
        'type' => 'spend',
        'amount' => -50,
        'description' => 'إلغاء دعم الفريق: -50 نقاط',
        'reference_type' => GamificationStorePurchase::class,
        'reference_id' => 992,
    ]);

    expect(GamificationService::getStudentXP($this->student->id, $this->leaderboard->id))->toBe(150)
        ->and(GamificationService::getTeamScore($team, $this->leaderboard))->toBe(200);

    $migration = require database_path('migrations/2026_10_05_101500_stop_store_refunds_counting_as_xp.php');
    $migration->up();
    $migration->up();

    expect(GamificationService::getStudentXP($this->student->id, $this->leaderboard->id))->toBe(0)
        ->and(GamificationService::getTeamScore($team, $this->leaderboard))->toBe(0)
        ->and(GamificationTransaction::where('description', 'like', 'إلغاء دعم الفريق%')->value('xp_amount'))->toBe(-50);
});

it('dates the support cancels already written as the support they take back, once', function () {
    $team = scoreFixTeam($this->leaderboard, 'فريق الإلغاء القديم', $this->student, 100);
    $item = scoreFixItem($this->leaderboard, 'team_points', ['is_team_product' => true, 'price' => 30, 'value' => 50]);

    $multiplier = scoreFixItem($this->leaderboard, 'multiplier', ['is_team_product' => true]);
    scoreFixApprovedPurchase($multiplier, $this->student, $team, '2026-06-07');

    scoreFixEarn($this->leaderboard, $this->student, 10, 0, ['created_at' => '2026-06-04 09:00:00']);

    // As the old code wrote them: the support with no reference on a plain
    // day, and its cancel as a spend on a team multiplier day.
    Carbon::setTestNow('2026-06-05 09:00:00');
    $purchase = scoreFixApprovedPurchase($item, $this->student, $team, '2026-06-05');
    GamificationTransaction::create([
        'leaderboard_id' => $this->leaderboard->id,
        'team_id' => $team->id,
        'type' => 'earn',
        'amount' => 50,
        'description' => 'تفعيل قوة ميزة دعم الفريق: +50 نقاط',
    ]);

    Carbon::setTestNow('2026-06-07 09:00:00');
    $purchase->update(['status' => 'cancelled']);
    GamificationTransaction::create([
        'leaderboard_id' => $this->leaderboard->id,
        'team_id' => $team->id,
        'type' => 'spend',
        'amount' => -50,
        'description' => 'إلغاء دعم الفريق: -50 نقاط',
        'reference_type' => GamificationStorePurchase::class,
        'reference_id' => $purchase->id,
    ]);

    expect(GamificationService::getTeamScore($team, $this->leaderboard))->toBe(60);

    $migration = require database_path('migrations/2026_10_05_101500_stop_store_refunds_counting_as_xp.php');
    $migration->up();
    $migration->up();

    $cancel = GamificationTransaction::where('description', 'like', 'إلغاء دعم الفريق%')->firstOrFail();

    expect($cancel->type)->toBe('earn')
        ->and($cancel->created_at->toDateTimeString())->toBe('2026-06-05 09:00:00')
        ->and(GamificationService::getTeamScore($team, $this->leaderboard))->toBe(10);
});

it('halves the points a freeze day or another competition\'s multiplier doubled, once', function () {
    $other = scoreFixLeaderboard($this->circle, 'مسابقة أخرى');

    $freeze = scoreFixItem($this->leaderboard, 'freeze', ['is_streak_freeze' => true]);
    scoreFixApprovedPurchase($freeze, $this->student, null, '2026-06-08');
    $multiplier = scoreFixItem($this->leaderboard, 'multiplier');
    scoreFixApprovedPurchase($multiplier, $this->student, null, '2026-06-09');

    $frozenDay = scoreFixGradedDay($this->student, '2026-06-08', '2026-06-08 08:00:00');
    $multiplierDay = scoreFixGradedDay($this->student, '2026-06-09', '2026-06-09 08:00:00');

    // As the old lookup baked them: doubled and tagged.
    $baked = fn (Leaderboard $leaderboard, StudentPlanDay $day) => scoreFixEarn($leaderboard, $this->student, 20, 10, [
        'description' => 'ممتاز في الحفظ لليوم '.$day->date->format('Y-m-d').' [مضاعف النقاط نشط!]',
        'reference_type' => StudentPlanDay::class,
        'reference_id' => $day->id,
    ]);
    $frozen = $baked($this->leaderboard, $frozenDay);
    $owed = $baked($this->leaderboard, $multiplierDay);
    $elsewhere = $baked($other, $multiplierDay);

    $this->artisan('gamification:unbake-unowed-multipliers')->assertSuccessful();
    $this->artisan('gamification:unbake-unowed-multipliers')->assertSuccessful();

    expect($frozen->fresh()->xp_amount)->toBe(10)
        ->and($frozen->fresh()->amount)->toBe(5)
        ->and($frozen->fresh()->description)->not->toContain('مضاعف')
        ->and($owed->fresh()->xp_amount)->toBe(20)
        ->and($owed->fresh()->description)->toContain('مضاعف')
        ->and($elsewhere->fresh()->xp_amount)->toBe(10)
        ->and(GamificationStudentState::where('student_id', $this->student->id)->where('leaderboard_id', $other->id)->value('coins'))->toBe(5);
});

/* --------------------------------------------- the streak as it stands today */

it('breaks the streak once a working day before today passes without enthusiasm', function () {
    foreach (['2026-06-03', '2026-06-04', '2026-06-05'] as $date) {
        scoreFixPresent($this->student, $this->teacher, $date);
    }

    GamificationService::recalculateStudentStreak($this->student, $this->leaderboard);

    $state = GamificationStudentState::where('student_id', $this->student->id)->where('leaderboard_id', $this->leaderboard->id)->first();

    expect($state->current_streak)->toBe(0)
        ->and($state->max_streak)->toBe(3)
        ->and($state->last_activity_date->format('Y-m-d'))->toBe('2026-06-05');
});

it('keeps the streak while today is still in progress', function () {
    foreach (['2026-06-07', '2026-06-08', '2026-06-09'] as $date) {
        scoreFixPresent($this->student, $this->teacher, $date);
    }

    GamificationService::recalculateStudentStreak($this->student, $this->leaderboard);

    $state = GamificationStudentState::where('student_id', $this->student->id)->where('leaderboard_id', $this->leaderboard->id)->first();

    expect($state->current_streak)->toBe(3);
});

it('keeps the streak across a frozen day', function () {
    foreach (['2026-06-06', '2026-06-07', '2026-06-08'] as $date) {
        scoreFixPresent($this->student, $this->teacher, $date);
    }

    $freeze = scoreFixItem($this->leaderboard, 'freeze', ['is_streak_freeze' => true]);
    scoreFixApprovedPurchase($freeze, $this->student, null, '2026-06-09');

    GamificationService::recalculateStudentStreak($this->student, $this->leaderboard);

    $state = GamificationStudentState::where('student_id', $this->student->id)->where('leaderboard_id', $this->leaderboard->id)->first();

    expect($state->current_streak)->toBe(4);
});

it('reads a stored streak as broken once a working day before today was missed', function () {
    $workingDays = GamificationService::getWorkingDaysForLeaderboard($this->leaderboard);

    $stale = new GamificationStudentState(['current_streak' => 5, 'last_activity_date' => '2026-06-05']);
    $kept = new GamificationStudentState(['current_streak' => 5, 'last_activity_date' => '2026-06-09']);
    $doneToday = new GamificationStudentState(['current_streak' => 6, 'last_activity_date' => '2026-06-10']);

    expect(GamificationService::liveStreak($stale, $workingDays))->toBe(0)
        ->and(GamificationService::liveStreak($kept, $workingDays))->toBe(5)
        ->and(GamificationService::liveStreak($doneToday, $workingDays))->toBe(6)
        ->and(GamificationService::liveStreak(null, $workingDays))->toBe(0);
});

it('shows the live streak on the page and places the next milestone from today', function () {
    foreach (['2026-06-03', '2026-06-04', '2026-06-05'] as $date) {
        scoreFixPresent($this->student, $this->teacher, $date);
    }

    DB::table('gamification_streak_milestones')->insert([
        'leaderboard_id' => $this->leaderboard->id,
        'days_required' => 2,
        'reward_xp' => 0,
        'reward_coins' => 5,
        'description' => 'يومان متتاليان',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $team = scoreFixTeam($this->leaderboard, 'فريق الحماسة', $this->student);
    $teammate = scoreFixStudent('زميل متوقف', 'scorefix-mate@example.com', $this->circle);
    $team->students()->attach($teammate->id, ['role' => 'member']);

    // Stored before the teammate stopped, and not rebuilt since.
    GamificationStudentState::create([
        'leaderboard_id' => $this->leaderboard->id,
        'student_id' => $teammate->id,
        'current_streak' => 5,
        'max_streak' => 5,
        'last_activity_date' => '2026-06-04',
    ]);

    $this->actingAs($this->student, 'student');

    Livewire::test('student.gamification-dashboard')
        ->assertViewHas('currentStreak', 0)
        ->assertViewHas('teamStudentStreaks', fn (array $streaks) => $streaks[$teammate->id] === 0)
        ->assertViewHas('workingDays', fn (array $days) => collect($days)->first(fn ($day) => isset($day['milestone']))['date'] === '2026-06-11');
});

/* ------------------------------------- team points dated by the grading day */

it('counts a late plan day recited today in today\'s team points, under today\'s team multiplier', function () {
    $team = scoreFixTeam($this->leaderboard, 'فريق المتأخرين', $this->student);

    $multiplier = scoreFixItem($this->leaderboard, 'multiplier', ['is_team_product' => true]);
    scoreFixApprovedPurchase($multiplier, $this->student, $team, '2026-06-10');

    // Scheduled for 06-05, recited today.
    GamificationService::syncStudentPlanDayXP(scoreFixGradedDay($this->student, '2026-06-05', '2026-06-10 08:00:00'));

    $standing = GamificationService::getTeamStandings($this->leaderboard, '2026-06-10')[0];

    expect(GamificationService::getStudentXP($this->student->id, $this->leaderboard->id))->toBe(10)
        ->and($standing['points_today'])->toBe(20)
        ->and($standing['score'])->toBe(20);
});

it('still finds the individual multiplier of a late plan day by its scheduled date', function () {
    $team = scoreFixTeam($this->leaderboard, 'فريق المضاعفين', $this->student);

    $individual = scoreFixItem($this->leaderboard, 'multiplier');
    scoreFixApprovedPurchase($individual, $this->student, null, '2026-06-05');
    $teamMultiplier = scoreFixItem($this->leaderboard, 'multiplier', ['is_team_product' => true]);
    scoreFixApprovedPurchase($teamMultiplier, $this->student, $team, '2026-06-10');

    // syncStudentPlanDayXP() doubles it by the scheduled date: 20 XP.
    GamificationService::syncStudentPlanDayXP(scoreFixGradedDay($this->student, '2026-06-05', '2026-06-10 08:00:00'));

    // The team takes the base 10 back and doubles it once: 20, not 40.
    expect(GamificationService::getStudentXP($this->student->id, $this->leaderboard->id))->toBe(20)
        ->and(GamificationService::getTeamScore($team, $this->leaderboard))->toBe(20);
});

/* ---------------------------- purchases count in the competition bought in */

it('keeps an individual multiplier to the competition it was bought in', function () {
    $other = scoreFixLeaderboard($this->circle, 'مسابقة أخرى');

    $multiplier = scoreFixItem($this->leaderboard, 'multiplier');
    scoreFixApprovedPurchase($multiplier, $this->student, null, '2026-06-09');
    scoreFixApprovedPurchase($multiplier, $this->student, null, '2026-06-12');

    GamificationService::syncStudentPlanDayXP(scoreFixGradedDay($this->student, '2026-06-09', '2026-06-09 08:00:00'));

    expect(GamificationService::getMultiplierForStudent($this->student, $this->leaderboard->id, '2026-06-09'))->toBe(2)
        ->and(GamificationService::getMultiplierForStudent($this->student, $other->id, '2026-06-09'))->toBe(1)
        ->and(GamificationService::getStudentXP($this->student->id, $this->leaderboard->id))->toBe(20)
        ->and(GamificationService::getStudentXP($this->student->id, $other->id))->toBe(10);

    // Buying one in the other competition for a day already covered here.
    scoreFixEarn($other, $this->student, 0, 100);
    GamificationService::recalculateStudentState($this->student->id, $other->id);
    $otherMultiplier = scoreFixItem($other, 'multiplier', ['price' => 30]);

    expect(GamificationService::requestStorePurchase($this->student->id, $otherMultiplier->id, null, '2026-06-12'))->toBe('success');
});

it('holds only the streak of the competition a freeze was bought in', function () {
    $other = scoreFixLeaderboard($this->circle, 'مسابقة أخرى');

    foreach (['2026-06-07', '2026-06-08'] as $date) {
        scoreFixPresent($this->student, $this->teacher, $date);
    }

    $otherFreeze = scoreFixItem($other, 'freeze', ['is_streak_freeze' => true]);
    scoreFixApprovedPurchase($otherFreeze, $this->student, null, '2026-06-09');

    GamificationService::recalculateStudentStreak($this->student, $this->leaderboard);
    GamificationService::recalculateStudentStreak($this->student, $other);

    $streakIn = fn (Leaderboard $leaderboard) => GamificationStudentState::where('student_id', $this->student->id)
        ->where('leaderboard_id', $leaderboard->id)
        ->value('current_streak');

    expect($streakIn($this->leaderboard))->toBe(0)
        ->and($streakIn($other))->toBe(3);

    // And it doesn't stop the same day being frozen here.
    scoreFixEarn($this->leaderboard, $this->student, 0, 100);
    GamificationService::recalculateStudentState($this->student->id, $this->leaderboard->id);
    $freeze = scoreFixItem($this->leaderboard, 'freeze', ['is_streak_freeze' => true]);

    expect(GamificationService::requestStorePurchase($this->student->id, $freeze->id, null, '2026-06-09'))->toBe('success');
});

it('shows only this competition\'s multipliers on the streak timeline', function () {
    $other = scoreFixLeaderboard($this->circle, 'مسابقة أقدم');
    $other->forceFill(['created_at' => now()->subDay()])->save();

    $otherMultiplier = scoreFixItem($other, 'multiplier');
    scoreFixApprovedPurchase($otherMultiplier, $this->student, null, '2026-06-12');

    $this->actingAs($this->student, 'student');

    Livewire::test('student.gamification-dashboard')
        ->assertViewHas('activeGamification', fn ($leaderboard) => $leaderboard->id === $this->leaderboard->id)
        ->assertViewHas('workingDays', fn (array $days) => collect($days)->firstWhere('date', '2026-06-12')['has_individual_multiplier'] === false);
});

/* -------------------------------------------------- levels from thresholds */

function scoreFixLevels(Leaderboard $leaderboard, array $thresholds, array $settings = []): void
{
    foreach ($thresholds as $number => $xp) {
        GamificationLevel::create([
            'leaderboard_id' => $leaderboard->id,
            'level_number' => $number,
            'name' => "المستوى {$number}",
            'xp_required' => $xp,
            'icon' => 'star',
            'settings' => array_merge(['reward_coins' => 20, 'freeze_max_days' => 3], $settings),
        ]);
    }
}

it('has no level below a first level that needs XP, and pays its reward only on reaching it', function () {
    scoreFixLevels($this->leaderboard, [1 => 50, 2 => 200]);
    scoreFixEarn($this->leaderboard, $this->student, 10);

    GamificationService::recalculateStudentState($this->student->id, $this->leaderboard->id);
    $level = GamificationService::getStudentLevel($this->student->id, $this->leaderboard->id);

    expect($level['current'])->toBeNull()
        ->and($level['next']->level_number)->toBe(1)
        ->and($level['perks']->level_number)->toBe(1)
        ->and(GamificationService::levelProgressPercentage($level))->toBe(20.0)
        ->and(GamificationService::getStudentMaxFreezeDays($this->student, $this->leaderboard, $level))->toBe(3)
        ->and(GamificationTransaction::where('reference_type', GamificationLevel::class)->exists())->toBeFalse();

    scoreFixEarn($this->leaderboard, $this->student, 40);
    GamificationService::recalculateStudentState($this->student->id, $this->leaderboard->id);
    $level = GamificationService::getStudentLevel($this->student->id, $this->leaderboard->id);

    expect($level['current']->level_number)->toBe(1)
        ->and($level['next']->level_number)->toBe(2)
        ->and(GamificationTransaction::where('reference_type', GamificationLevel::class)->count())->toBe(1);
});

it('keeps a student on a first level that needs no XP, even below zero', function () {
    scoreFixLevels($this->leaderboard, [1 => 0, 2 => 100]);
    scoreFixEarn($this->leaderboard, $this->student, -5);

    $level = GamificationService::getStudentLevel($this->student->id, $this->leaderboard->id);

    expect($level['current']->level_number)->toBe(1)
        ->and($level['next']->level_number)->toBe(2)
        ->and(GamificationService::levelProgressPercentage($level))->toBe(0.0);
});

it('shows level 0 and the real progress below a first level that needs XP', function () {
    scoreFixLevels($this->leaderboard, [1 => 50, 2 => 200]);
    scoreFixEarn($this->leaderboard, $this->student, 10);

    $this->actingAs($this->student, 'student');

    Livewire::test('student.gamification-dashboard')
        ->assertViewHas('studentLevel', 0)
        ->assertSee('width: 20%', false)
        ->assertSee('20% نحو المستوى التالي')
        ->assertDontSee('100% نحو المستوى التالي');
});

/* ------------------------------------- level XP counted as the score is */

it('counts the level XP as the standings count the score', function () {
    scoreFixEarn($this->leaderboard, $this->student, 30);
    // Credited before the competition began: outside its dates.
    scoreFixEarn($this->leaderboard, $this->student, 100, 0, ['created_at' => '2026-05-20 10:00:00']);

    $score = (new LeaderboardService)->getStandings($this->leaderboard)
        ->firstWhere('student.id', $this->student->id)['score'];

    expect(GamificationService::getStudentXP($this->student->id, $this->leaderboard->id))->toBe(30)
        ->and($score)->toBe(30);

    $this->actingAs($this->student, 'student');

    Livewire::test('student.gamification-dashboard')
        ->assertViewHas('studentXP', 30);
});

it('reads the student\'s XP once per recalculation, and still pays and announces the level it reaches', function () {
    scoreFixLevels($this->leaderboard, [1 => 0, 2 => 50]);
    GamificationService::recalculateStudentState($this->student->id, $this->leaderboard->id);
    scoreFixEarn($this->leaderboard, $this->student, 60);

    // getStudentXP()'s read: every claimed earning, to date each one.
    $xpReads = 0;
    DB::listen(function ($query) use (&$xpReads) {
        if (str_contains($query->sql, '"xp_amount", "created_at" from "gamification_transactions"')) {
            $xpReads++;
        }
    });

    GamificationService::recalculateStudentState($this->student->id, $this->leaderboard->id);

    expect($xpReads)->toBe(1)
        ->and(GamificationTransaction::where('reference_type', GamificationLevel::class)->count())->toBe(2)
        ->and(GamificationNews::where('type', 'level_up')->sole()->data['level'])->toBe(2);
});
