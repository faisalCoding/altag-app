<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\GamificationActivity;
use App\Models\GamificationActivityRound;
use App\Models\GamificationActivityWinner;
use App\Models\GamificationBadge;
use App\Models\GamificationStoreItem;
use App\Models\GamificationStorePurchase;
use App\Models\GamificationTeam;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Teacher;
use App\Services\GamificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * What the student gamification dashboard shows: each figure read from the
 * same source, and on the same day, as the thing it reports on.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-08 10:00:00');

    $this->stage = Stage::create(['name' => 'مرحلة اختبار عرض اللوحة']);
    $this->circle = Circle::create(['name' => 'حلقة اختبار عرض اللوحة', 'stage_id' => $this->stage->id]);
    $this->student = makeDisplayStudent('طالب العرض', 'display-student@example.com', $this->circle);

    AcademicCalendarEvent::create([
        'event_name' => 'دوام كامل لاختبار العرض',
        'start_date' => now()->subDays(30)->format('Y-m-d'),
        'end_date' => now()->addDays(30)->format('Y-m-d'),
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'is_visible' => true,
    ]);

    $this->actingAs($this->student, 'student');
});

function makeDisplayStudent(string $name, string $email, Circle $circle): Student
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

function makeDisplayLeaderboard(Circle $circle, array $attributes = []): Leaderboard
{
    $leaderboard = Leaderboard::create(array_merge([
        'circle_id' => $circle->id,
        'title' => 'مسابقة اختبار العرض',
        'competition_type' => 'gamification',
        'start_date' => '2026-06-03',
        'end_date' => '2026-06-15',
        'is_active' => true,
        'settings' => [],
    ], $attributes));
    $leaderboard->circles()->attach($circle->id);

    return $leaderboard;
}

function makeDisplayTeam(Leaderboard $leaderboard, Student $leader, int $coins = 0): GamificationTeam
{
    $team = GamificationTeam::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'فريق العرض',
        'coins' => $coins,
    ]);
    $team->students()->attach($leader->id, ['role' => 'leader']);

    return $team;
}

/**
 * The HTML of the element carrying the given data attribute, opening tag only.
 */
function displayTagWith(string $html, string $attribute): string
{
    preg_match('/<[a-z]+[^>]*\s'.preg_quote($attribute, '/').'(?=[\s=>])[^>]*>/u', $html, $match);

    return $match[0] ?? '';
}

/* ------------------------------------------------- the team card's tiles */

it('shows the shield tile as active on the day a bought shield protects the team', function () {
    $leaderboard = makeDisplayLeaderboard($this->circle);
    $team = makeDisplayTeam($leaderboard, $this->student);

    $shield = GamificationStoreItem::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'درع اليوم',
        'price' => 40,
        'item_type' => 'shield',
        'is_team_product' => true,
    ]);

    $html = Livewire::test('student.gamification-dashboard')->html();
    expect(displayTagWith($html, 'data-team-shield-tile'))->toContain('data-active="0"');

    GamificationStorePurchase::create([
        'store_item_id' => $shield->id,
        'student_id' => $this->student->id,
        'team_id' => $team->id,
        'price_paid' => 40,
        'status' => 'approved',
        'target_date' => '2026-06-08',
    ]);

    $component = Livewire::test('student.gamification-dashboard')
        ->assertViewHas('teamShieldActiveToday', true)
        ->assertSee('team-shield-aura');

    // The tile and the aura read the same answer; the tile used to read a column nothing writes.
    expect(displayTagWith($component->html(), 'data-team-shield-tile'))->toContain('data-active="1"');
});

it('shows the team multiplier as active from midnight in Riyadh on the day bought for', function () {
    // 22:00 UTC on the 8th is 01:00 on the 9th in Riyadh.
    Carbon::setTestNow('2026-06-08 22:00:00');

    $leaderboard = makeDisplayLeaderboard($this->circle);
    $team = makeDisplayTeam($leaderboard, $this->student);

    $multiplier = GamificationStoreItem::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'مضاعف الفريق',
        'price' => 50,
        'item_type' => 'multiplier',
        'value' => 2,
        'is_team_product' => true,
    ]);

    GamificationStorePurchase::create([
        'store_item_id' => $multiplier->id,
        'student_id' => $this->student->id,
        'team_id' => $team->id,
        'price_paid' => 50,
        'status' => 'approved',
        'target_date' => '2026-06-09',
    ]);

    $component = Livewire::test('student.gamification-dashboard')
        ->assertViewHas('teamMultiplierActiveToday', true);

    expect(displayTagWith($component->html(), 'data-team-multiplier-tile'))->toContain('data-active="1"');
});

it('fills the team standings\' today columns for the academy\'s today, before the UTC date turns', function () {
    Carbon::setTestNow('2026-06-08 22:00:00');

    $leaderboard = makeDisplayLeaderboard($this->circle);
    makeDisplayTeam($leaderboard, $this->student);

    // Points for attendance on the 9th: today in Riyadh, tomorrow by UTC.
    $attendance = Attendance::create([
        'student_id' => $this->student->id,
        'teacher_id' => Teacher::factory()->create()->id,
        'circle_id' => $this->circle->id,
        'date' => '2026-06-09',
        'status' => 'present',
    ]);
    GamificationTransaction::where('reference_type', Attendance::class)->delete();
    GamificationTransaction::create([
        'leaderboard_id' => $leaderboard->id,
        'student_id' => $this->student->id,
        'type' => 'earn',
        'amount' => 0,
        'xp_amount' => 15,
        'description' => 'حضور',
        'reference_type' => Attendance::class,
        'reference_id' => $attendance->id,
    ]);

    Livewire::test('student.gamification-dashboard')
        ->assertViewHas('teamStandings', fn (array $rows) => $rows[0]['points_today'] === 15 && $rows[0]['score'] === 15);
});

/* ----------------------------------------------- the stats bar's «اليوم» */

it('counts today\'s XP and coins from midnight in Riyadh, and marks Riyadh\'s today on the timeline', function () {
    Carbon::setTestNow('2026-06-08 22:00:00');

    $leaderboard = makeDisplayLeaderboard($this->circle);

    // 00:30 on the 9th in Riyadh: today.
    GamificationTransaction::create([
        'leaderboard_id' => $leaderboard->id,
        'student_id' => $this->student->id,
        'type' => 'earn',
        'amount' => 7,
        'xp_amount' => 12,
        'description' => 'بعد منتصف الليل',
        'created_at' => '2026-06-08 21:30:00',
        'updated_at' => '2026-06-08 21:30:00',
    ]);
    // 23:30 on the 8th in Riyadh: yesterday.
    GamificationTransaction::create([
        'leaderboard_id' => $leaderboard->id,
        'student_id' => $this->student->id,
        'type' => 'earn',
        'amount' => 100,
        'xp_amount' => 100,
        'description' => 'قبل منتصف الليل',
        'created_at' => '2026-06-08 20:30:00',
        'updated_at' => '2026-06-08 20:30:00',
    ]);

    $component = Livewire::test('student.gamification-dashboard')
        ->assertViewHas('xpToday', 12)
        ->assertViewHas('coinsToday', 7);

    $days = collect($component->viewData('workingDays'))->keyBy('date');

    expect($days['2026-06-09']['is_today'])->toBeTrue()
        ->and($days['2026-06-09']['is_future'])->toBeFalse()
        ->and($days['2026-06-09']['status'])->toBe('orange')
        ->and($days['2026-06-08']['is_today'])->toBeFalse()
        ->and($days['2026-06-10']['is_future'])->toBeTrue();
});

it('leaves coins given to the team out of today\'s coins', function () {
    $leaderboard = makeDisplayLeaderboard($this->circle);
    $team = makeDisplayTeam($leaderboard, $this->student);

    GamificationTransaction::create([
        'leaderboard_id' => $leaderboard->id,
        'student_id' => $this->student->id,
        'type' => 'earn',
        'amount' => 50,
        'xp_amount' => 5,
        'description' => 'تسميع',
    ]);

    // A donation of 30, written as donateCoinsToTeam() writes it.
    GamificationTransaction::create([
        'leaderboard_id' => $leaderboard->id,
        'student_id' => $this->student->id,
        'type' => 'spend',
        'amount' => -30,
        'description' => 'تبرع لخزينة الفريق',
    ]);
    GamificationTransaction::create([
        'leaderboard_id' => $leaderboard->id,
        'team_id' => $team->id,
        'student_id' => $this->student->id,
        'type' => 'earn',
        'amount' => 30,
        'xp_amount' => 0,
        'description' => 'تبرع من الطالب',
    ]);
    GamificationService::recalculateStudentState($this->student->id, $leaderboard->id);

    Livewire::test('student.gamification-dashboard')
        ->assertViewHas('coinsToday', 50)
        ->assertViewHas('xpToday', 5)
        ->assertViewHas('gamificationState', fn ($state) => $state->coins === 20);
});

it('counts today\'s XP as the XP total above it does, leaving out what falls past the end date', function () {
    // Still on screen the day after it ended: nothing turns a competition off.
    $leaderboard = makeDisplayLeaderboard($this->circle, ['start_date' => '2026-06-01', 'end_date' => '2026-06-07']);

    // A badge claimed today, dated by when it was paid: past the end date.
    GamificationTransaction::create([
        'leaderboard_id' => $leaderboard->id,
        'student_id' => $this->student->id,
        'type' => 'earn',
        'amount' => 20,
        'xp_amount' => 50,
        'description' => 'وسام بعد نهاية المسابقة',
        'reference_type' => GamificationBadge::class,
        'reference_id' => 1,
    ]);

    // An attendance of the 6th recorded today: its work falls inside the dates.
    $attendance = Attendance::create([
        'student_id' => $this->student->id,
        'teacher_id' => Teacher::factory()->create()->id,
        'circle_id' => $this->circle->id,
        'date' => '2026-06-06',
        'status' => 'present',
    ]);
    GamificationTransaction::create([
        'leaderboard_id' => $leaderboard->id,
        'student_id' => $this->student->id,
        'type' => 'earn',
        'amount' => 3,
        'xp_amount' => 4,
        'description' => 'حضور متأخر التسجيل',
        'reference_type' => Attendance::class,
        'reference_id' => $attendance->id,
    ]);

    Livewire::test('student.gamification-dashboard')
        ->assertViewHas('studentXP', 4)
        ->assertViewHas('xpToday', 4)
        ->assertViewHas('coinsToday', 23);
});

it('keeps the stats bar\'s XP label short enough for a phone', function () {
    makeDisplayLeaderboard($this->circle);

    $component = Livewire::test('student.gamification-dashboard')->assertDontSee('نقاط المستوى');

    expect(displayTagWith($component->html(), 'data-stats-xp-label'))->toContain('truncate')
        ->and($component->html())->toMatch('/data-stats-xp-label[^>]*>.*?النقاط\s*<\/span>/su');
});

/* ------------------------------------------------------- the mission card */

function makeDisplayPendingPlan(Student $student): void
{
    $plan = StudentPlan::create([
        'student_id' => $student->id,
        'start_date' => '2026-06-08',
        'days_count' => 1,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'status' => 'active',
        'plan_type' => 'hifz_review',
        'is_approved' => 1,
        'created_by_role' => 'teacher',
    ]);

    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => '2026-06-08',
        'day_name' => 'الاثنين',
    ]);
}

it('promises on the mission card the XP an «ممتاز» actually pays, and nothing for a part not rewarded', function () {
    makeDisplayLeaderboard($this->circle, ['settings' => [
        'hifz_enabled' => true,
        'hifz_excellent_xp' => 20,
        'hifz_excellent_coins' => 3,
        'review_enabled' => false,
    ]]);
    makeDisplayPendingPlan($this->student);

    $component = Livewire::test('student.gamification-dashboard')
        ->assertSee('+20 XP')
        ->assertDontSee('+10 XP')
        ->assertDontSee('+5 XP');

    // Two cards (hifz and review), the XP shown on the hifz one only.
    expect($component->viewData('pendingMissions'))->toHaveCount(2)
        ->and(substr_count($component->html(), 'data-mission-xp'))->toBe(1);
});

it('reads the mission card\'s XP from the old setting keys the way grading does', function () {
    makeDisplayLeaderboard($this->circle, ['settings' => [
        'hifz_enabled' => true,
        'hifz_excellent' => 8,
        'review_enabled' => true,
    ]]);
    makeDisplayPendingPlan($this->student);

    Livewire::test('student.gamification-dashboard')
        ->assertSee('+8 XP')
        ->assertSee('+5 XP');

    expect(GamificationService::excellentGradePoints(['hifz_enabled' => true, 'hifz_excellent_xp' => 20, 'hifz_excellent_coins' => 3], 'hifz'))
        ->toBe(['xp' => 20, 'coins' => 3])
        ->and(GamificationService::excellentGradePoints(['review_enabled' => true], 'review'))->toBe(['xp' => 5, 'coins' => 5])
        ->and(GamificationService::excellentGradePoints(['hifz_excellent_xp' => 20], 'hifz'))->toBe(['xp' => 0, 'coins' => 0]);
});

/* -------------------------------------------------- team store products */

it('lets the leader of a one-member team buy a team product while team purchases go to a vote', function () {
    $leaderboard = makeDisplayLeaderboard($this->circle, ['settings' => ['team_purchase_voting_enabled' => true]]);
    $team = makeDisplayTeam($leaderboard, $this->student, 100);

    $item = GamificationStoreItem::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'نقاط للفريق',
        'price' => 40,
        'item_type' => 'team_points',
        'value' => 10,
        'is_team_product' => true,
        'is_active' => true,
    ]);

    $component = Livewire::test('student.gamification-dashboard');
    $button = displayTagWith($component->html(), 'data-store-buy="'.$item->id.'"');

    expect($button)->not->toBe('')
        ->and($button)->not->toMatch('/\sdisabled(\s|=|>)/')
        ->and($component->html())->not->toContain('data-team-vote-requirements')
        ->and($component->html())->not->toContain('بدء تصويت وشراء بـ');

    // The service has nobody to ask for a vote, so the purchase goes straight through.
    $component->call('buyItem', $item->id);

    expect(GamificationStorePurchase::where('store_item_id', $item->id)->value('status'))->toBe('approved')
        ->and($team->fresh()->coins)->toBe(60);
});

it('says a team product that asks for members\' approval starts a vote, with voting off for the competition', function () {
    $leaderboard = makeDisplayLeaderboard($this->circle);
    $team = makeDisplayTeam($leaderboard, $this->student, 100);
    $team->students()->attach(makeDisplayStudent('زميل العرض', 'display-mate@example.com', $this->circle)->id, ['role' => 'member']);

    $item = GamificationStoreItem::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'نقاط بموافقة الأعضاء',
        'price' => 40,
        'item_type' => 'team_points',
        'value' => 10,
        'is_team_product' => true,
        'is_active' => true,
        // The leader's own «موافق» is the first of the two.
        'require_member_approval_count' => 2,
    ]);

    // More than the treasury holds: its button stays disabled, as before.
    $tooDear = GamificationStoreItem::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'نقاط باهظة',
        'price' => 500,
        'item_type' => 'team_points',
        'value' => 10,
        'is_team_product' => true,
        'is_active' => true,
    ]);

    $component = Livewire::test('student.gamification-dashboard')
        ->assertSee('بدء تصويت وشراء بـ');

    expect($component->html())->toContain('data-team-vote-requirements')
        ->and(displayTagWith($component->html(), 'data-store-buy="'.$item->id.'"'))->not->toMatch('/\sdisabled(\s|=|>)/')
        ->and(displayTagWith($component->html(), 'data-store-buy="'.$tooDear->id.'"'))->toMatch('/\sdisabled(\s|=|>)/');

    $component->call('buyItem', $item->id);

    expect(GamificationStorePurchase::where('store_item_id', $item->id)->value('status'))->toBe('pending_approval');
});

/* ------------------------------------------------------- activity podium */

function makeDisplayActivityRound(Leaderboard $leaderboard): array
{
    $activity = GamificationActivity::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'مسابقة الإلقاء',
    ]);
    $round = GamificationActivityRound::create([
        'activity_id' => $activity->id,
        'name' => 'الجولة الأولى',
        'round_date' => '2026-06-07',
    ]);

    return [$activity, $round];
}

function displayPodiumName(string $html, int $place): ?string
{
    preg_match('/data-podium-place="'.$place.'">([^<]*)</u', $html, $match);

    return isset($match[1]) ? trim(html_entity_decode($match[1])) : null;
}

it('places a renamed rank\'s winner on the podium by the rank\'s order', function () {
    $leaderboard = makeDisplayLeaderboard($this->circle);
    makeDisplayTeam($leaderboard, $this->student);
    [$activity, $round] = makeDisplayActivityRound($leaderboard);

    foreach (['ذهبي' => 'فريق الذهب', 'فضي' => 'فريق الفضة', 'برونزي' => 'فريق البرونز'] as $rankName => $teamName) {
        $rank = $activity->ranks()->create(['name' => $rankName, 'team_xp' => 0, 'team_coins' => 0, 'member_xp' => 0, 'member_coins' => 0]);
        $team = GamificationTeam::create(['leaderboard_id' => $leaderboard->id, 'name' => $teamName, 'coins' => 0]);
        GamificationActivityWinner::create(['round_id' => $round->id, 'rank_id' => $rank->id, 'team_id' => $team->id]);
    }

    $html = Livewire::test('student.gamification-dashboard')->html();

    expect(displayPodiumName($html, 1))->toBe('فريق الذهب')
        ->and(displayPodiumName($html, 2))->toBe('فريق الفضة')
        ->and(displayPodiumName($html, 3))->toBe('فريق البرونز');
});

it('names every team tied on a podium place, and keeps the default names\' places', function () {
    $leaderboard = makeDisplayLeaderboard($this->circle);
    makeDisplayTeam($leaderboard, $this->student);
    [$activity, $round] = makeDisplayActivityRound($leaderboard);

    // Defaults, then a second «المركز الأول» added after a blank one: a tie for first.
    $ranks = collect(['المركز الأول', 'المركز الثاني', 'المركز الثالث', '...', 'المركز الأول'])
        ->map(fn (string $name) => $activity->ranks()->create(['name' => $name, 'team_xp' => 0, 'team_coins' => 0, 'member_xp' => 0, 'member_coins' => 0]));

    foreach ([0 => 'فريق أ', 4 => 'فريق ب', 1 => 'فريق ج'] as $rankIndex => $teamName) {
        $team = GamificationTeam::create(['leaderboard_id' => $leaderboard->id, 'name' => $teamName, 'coins' => 0]);
        GamificationActivityWinner::create(['round_id' => $round->id, 'rank_id' => $ranks[$rankIndex]->id, 'team_id' => $team->id]);
    }

    $html = Livewire::test('student.gamification-dashboard')->html();

    expect(displayPodiumName($html, 1))->toBe('فريق أ / فريق ب')
        ->and(displayPodiumName($html, 2))->toBe('فريق ج')
        ->and(displayPodiumName($html, 3))->toBe('-');
});
