<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\GamificationLevel;
use App\Models\GamificationStoreItem;
use App\Models\GamificationStorePurchase;
use App\Models\GamificationStudentState;
use App\Models\GamificationTeam;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TurnReservation;
use App\Models\TurnReservationSession;
use App\Services\GamificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * The student gamification dashboard's actions: what each one refuses, what it
 * says when it does nothing, and how it holds up when two requests land at once.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-08 10:00:00');

    $this->stage = Stage::create(['name' => 'مرحلة اختبار أفعال اللوحة']);
    $this->circle = Circle::create(['name' => 'حلقة اختبار أفعال اللوحة', 'stage_id' => $this->stage->id]);
    $this->student = makeActionsStudent('طالب الأفعال', 'actions-student@example.com', $this->circle);

    AcademicCalendarEvent::create([
        'event_name' => 'دوام كامل لاختبار الأفعال',
        'start_date' => now()->subDays(30)->format('Y-m-d'),
        'end_date' => now()->addDays(30)->format('Y-m-d'),
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'is_visible' => true,
    ]);

    $this->actingAs($this->student, 'student');
});

function makeActionsStudent(string $name, string $email, Circle $circle): Student
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

function makeActionsLeaderboard(Circle $circle, array $attributes = []): Leaderboard
{
    $leaderboard = Leaderboard::create(array_merge([
        'circle_id' => $circle->id,
        'title' => 'مسابقة اختبار الأفعال',
        'competition_type' => 'gamification',
        'start_date' => now()->subDays(2)->format('Y-m-d'),
        'end_date' => now()->addDays(5)->format('Y-m-d'),
        'is_active' => true,
        'settings' => [],
    ], $attributes));
    $leaderboard->circles()->attach($circle->id);

    return $leaderboard;
}

function giveActionsCoins(Student $student, Leaderboard $leaderboard, int $coins): GamificationStudentState
{
    GamificationTransaction::create([
        'leaderboard_id' => $leaderboard->id,
        'student_id' => $student->id,
        'type' => 'earn',
        'amount' => $coins,
        'xp_amount' => 0,
        'description' => 'رصيد مبدئي',
        'created_at' => now()->subDays(1),
        'updated_at' => now()->subDays(1),
    ]);

    return GamificationStudentState::updateOrCreate(
        ['leaderboard_id' => $leaderboard->id, 'student_id' => $student->id],
        ['coins' => $coins],
    );
}

function toastSaying(string $text): Closure
{
    return fn (string $event, array $params) => ($params['slots']['text'] ?? null) === $text;
}

/* ------------------------------------------------------------------ buyItem */

it('refuses a store product the supervisor turned off', function () {
    $leaderboard = makeActionsLeaderboard($this->circle);
    $state = giveActionsCoins($this->student, $leaderboard, 200);

    $item = GamificationStoreItem::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'هدية معطلة',
        'price' => 50,
        'item_type' => 'custom',
        'is_team_product' => false,
        'is_active' => false,
    ]);

    Livewire::test('student.gamification-dashboard')
        ->call('buyItem', $item->id)
        ->assertDispatched('toast-show', toastSaying('هذا المنتج غير متوفر حالياً في المتجر.'));

    expect(GamificationStorePurchase::count())->toBe(0)
        ->and($state->fresh()->coins)->toBe(200)
        // Every caller of the service is covered, not only this button.
        ->and(GamificationService::requestStorePurchase($this->student->id, $item->id))->toBe('item_inactive');
});

it('refuses the multiplier and the freeze through the store button, whatever the level allows', function () {
    $leaderboard = makeActionsLeaderboard($this->circle);
    $state = giveActionsCoins($this->student, $leaderboard, 1000);

    // The level forbids the individual multiplier and would charge 900 for it.
    GamificationLevel::create([
        'leaderboard_id' => $leaderboard->id,
        'level_number' => 1,
        'name' => 'مبتدئ',
        'xp_required' => 0,
        'icon' => 'sparkles',
        'settings' => ['has_individual_multiplier' => false, 'individual_multiplier_price' => 900],
    ]);

    $multiplier = GamificationStoreItem::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'مضاعف النقاط الفردي',
        'price' => 150,
        'item_type' => 'multiplier',
        'value' => 2,
        'is_team_product' => false,
        'is_active' => true,
    ]);

    $freeze = GamificationStoreItem::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'تجميد الحماسة',
        'price' => 10,
        'item_type' => 'freeze',
        'is_streak_freeze' => true,
        'is_team_product' => false,
        'is_active' => true,
    ]);

    $tomorrow = now('Asia/Riyadh')->addDay()->toDateString();

    Livewire::test('student.gamification-dashboard')
        ->set('targetDates.'.$multiplier->id, $tomorrow)
        ->call('buyItem', $multiplier->id)
        ->assertDispatched('toast-show', toastSaying('هذا المنتج غير متوفر حالياً في المتجر.'))
        ->set('targetDates.'.$freeze->id, now('Asia/Riyadh')->toDateString())
        ->call('buyItem', $freeze->id);

    expect(GamificationStorePurchase::count())->toBe(0)
        ->and($state->fresh()->coins)->toBe(1000);
});

it('refuses a product from a competition other than the one on screen', function () {
    $leaderboard = makeActionsLeaderboard($this->circle);
    $state = giveActionsCoins($this->student, $leaderboard, 200);

    $otherCircle = Circle::create(['name' => 'حلقة أخرى', 'stage_id' => $this->stage->id]);
    $otherLeaderboard = makeActionsLeaderboard($otherCircle, ['title' => 'مسابقة حلقة أخرى']);

    $foreignItem = GamificationStoreItem::create([
        'leaderboard_id' => $otherLeaderboard->id,
        'name' => 'هدية حلقة أخرى',
        'price' => 50,
        'item_type' => 'custom',
        'is_team_product' => false,
        'is_active' => true,
    ]);

    Livewire::test('student.gamification-dashboard')
        ->call('buyItem', $foreignItem->id)
        ->assertDispatched('toast-show', toastSaying('هذا المنتج غير متوفر حالياً في المتجر.'));

    expect(GamificationStorePurchase::count())->toBe(0)
        ->and($state->fresh()->coins)->toBe(200);
});

it('still sells an active store product at its price', function () {
    $leaderboard = makeActionsLeaderboard($this->circle);
    $state = giveActionsCoins($this->student, $leaderboard, 200);

    $item = GamificationStoreItem::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'هدية متاحة',
        'price' => 50,
        'item_type' => 'custom',
        'is_team_product' => false,
        'is_active' => true,
    ]);

    Livewire::test('student.gamification-dashboard')
        ->call('buyItem', $item->id)
        ->assertDispatched('toast-show', toastSaying('تم الشراء وتفعيل ميزة المتجر بنجاح!'));

    expect(GamificationStorePurchase::where('store_item_id', $item->id)->value('status'))->toBe('approved')
        ->and($state->fresh()->coins)->toBe(150);
});

/* ---------------------------------------------- claim all & donate, past end */

it('claims every pending reward with «استلام الكل» on a competition past its end date but still shown', function () {
    $leaderboard = makeActionsLeaderboard($this->circle, [
        'start_date' => now()->subDays(10)->format('Y-m-d'),
        'end_date' => now()->subDay()->format('Y-m-d'),
    ]);

    $reward = GamificationTransaction::create([
        'leaderboard_id' => $leaderboard->id,
        'student_id' => $this->student->id,
        'type' => 'earn',
        'amount' => 5,
        'xp_amount' => 20,
        'description' => 'مكافأة بانتظار الاستلام',
        'claimed_at' => null,
    ]);

    // Counted as Arabic says it: «مكافأة واحدة», not «1 مكافأة».
    Livewire::test('student.gamification-dashboard')
        ->assertSee('استلام الكل')
        ->call('claimAllRewards')
        ->assertDispatched('toast-show', toastSaying('تم استلام مكافأة واحدة!'))
        ->assertDispatched('reward-claimed', xp: 20, coins: 5, keys: ['*panel']);

    expect($reward->fresh()->claimed_at)->not->toBeNull();
});

it('says so when «استلام الكل» finds nothing left to claim', function () {
    makeActionsLeaderboard($this->circle);

    // The rows it hid come back, and only those: not award cards or badges
    // still waiting on claims of their own.
    Livewire::test('student.gamification-dashboard')
        ->call('claimAllRewards')
        ->assertDispatched('toast-show', toastSaying('لا توجد مكافآت بانتظار الاستلام.'))
        ->assertDispatched('gam-claim-failed', keys: ['*panel'])
        ->assertNotDispatched('reward-claimed');
});

it('closes donations after the end date with a message, and shows the note instead of the donate box', function () {
    $leaderboard = makeActionsLeaderboard($this->circle, [
        'start_date' => now()->subDays(10)->format('Y-m-d'),
        'end_date' => now()->subDay()->format('Y-m-d'),
    ]);
    $state = giveActionsCoins($this->student, $leaderboard, 100);

    $team = GamificationTeam::create(['leaderboard_id' => $leaderboard->id, 'name' => 'فريق منتهٍ', 'coins' => 0]);
    $team->students()->attach($this->student->id, ['role' => 'leader']);

    Livewire::test('student.gamification-dashboard')
        ->assertSeeHtml('data-donation-closed')
        ->assertSee('انتهت المسابقة، فلم يعد التبرع للفريق متاحاً.')
        ->assertDontSee('تبرع الآن')
        ->call('donateToTeam', 10)
        ->assertDispatched('toast-show', toastSaying('انتهت المسابقة، فلم يعد التبرع للفريق متاحاً.'))
        ->assertDispatched('donation-finished');

    expect($team->fresh()->coins)->toBe(0)
        ->and($state->fresh()->coins)->toBe(100);
});

it('opens donations on the first day by the academy clock, before the UTC date catches up', function () {
    // 22:00 UTC on 7 June is 01:00 on 8 June in Riyadh, the competition's first day.
    Carbon::setTestNow('2026-06-07 22:00:00');

    $leaderboard = makeActionsLeaderboard($this->circle, [
        'start_date' => '2026-06-08',
        'end_date' => '2026-06-20',
    ]);

    GamificationLevel::create([
        'leaderboard_id' => $leaderboard->id,
        'level_number' => 1,
        'name' => 'مبتدئ',
        'xp_required' => 0,
        'icon' => 'sparkles',
        'settings' => ['has_donation' => true, 'donation_max_limit' => 100],
    ]);

    $state = giveActionsCoins($this->student, $leaderboard, 100);

    $team = GamificationTeam::create(['leaderboard_id' => $leaderboard->id, 'name' => 'فريق البداية', 'coins' => 0]);
    $team->students()->attach($this->student->id, ['role' => 'leader']);

    Livewire::test('student.gamification-dashboard')
        ->assertDontSeeHtml('data-donation-closed')
        ->call('donateToTeam', 30)
        ->assertDispatched('toast-show', toastSaying('تم التبرع بنجاح للفريق!'));

    expect($team->fresh()->coins)->toBe(30)
        ->and($state->fresh()->coins)->toBe(70);
});

/* --------------------------------------------------------------- reserveTurn */

function makeOpenTurnSession(Circle $circle): TurnReservationSession
{
    $teacher = Teacher::create([
        'name' => 'معلم الطابور المزدحم',
        'email' => 'busy-queue-teacher@example.com',
        'password' => bcrypt('password'),
        'status' => 'active',
        'is_approved' => true,
    ]);
    $teacher->circles()->attach($circle->id);

    $now = Carbon::now('Asia/Riyadh');

    return TurnReservationSession::create([
        'teacher_id' => $teacher->id,
        'start_date' => $now->copy()->subDay()->format('Y-m-d'),
        'end_date' => $now->copy()->addDay()->format('Y-m-d'),
        'days_of_week' => [0, 1, 2, 3, 4, 5, 6],
        'start_time' => $now->copy()->subHour()->format('H:i:s'),
        'end_time' => $now->copy()->addHour()->format('H:i:s'),
    ]);
}

/**
 * Make the next booking lose the race once: just before it is written, another
 * student is written with the very number it read, as when both tapped together.
 */
function makeNextTurnLoseTheRace(TurnReservationSession $session, Student $rival): void
{
    $raced = false;

    TurnReservation::creating(function (TurnReservation $reservation) use (&$raced, $session, $rival) {
        if ($raced || $reservation->student_id === $rival->id) {
            return;
        }

        $raced = true;

        DB::table('turn_reservations')->insert([
            'turn_reservation_session_id' => $session->id,
            'student_id' => $rival->id,
            'date' => $reservation->date,
            'turn_number' => $reservation->turn_number,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}

it('gives students booking at the same moment their own turn numbers instead of an error', function () {
    makeActionsLeaderboard($this->circle);
    $session = makeOpenTurnSession($this->circle);
    $rival = makeActionsStudent('طالب سبق بالحجز', 'rival-booker@example.com', $this->circle);

    makeNextTurnLoseTheRace($session, $rival);

    Livewire::test('student.gamification-dashboard')
        ->call('reserveTurn', $session->id)
        ->assertDispatched('toast-show', toastSaying('تم حجز دورك بنجاح!'));

    $turns = TurnReservation::where('turn_reservation_session_id', $session->id)
        ->orderBy('turn_number')
        ->pluck('turn_number', 'student_id');

    expect($turns->all())->toBe([$rival->id => 1, $this->student->id => 2]);
});

it('books the same way from the plain student dashboard', function () {
    $session = makeOpenTurnSession($this->circle);
    $rival = makeActionsStudent('طالب سبق بالحجز', 'rival-booker@example.com', $this->circle);

    makeNextTurnLoseTheRace($session, $rival);

    Livewire::test('student.dashboard')
        ->call('reserveTurn', $session->id)
        ->assertDispatched('toast-show', toastSaying('تم حجز دورك بنجاح!'));

    expect(TurnReservation::where('student_id', $this->student->id)->value('turn_number'))->toBe(2);
});

it('returns the booking a student already holds rather than a second one', function () {
    $session = makeOpenTurnSession($this->circle);
    $today = now('Asia/Riyadh')->toDateString();

    $first = TurnReservation::reserveNext($session->id, $this->student->id, $today);
    $again = TurnReservation::reserveNext($session->id, $this->student->id, $today);

    expect($first->wasRecentlyCreated)->toBeTrue()
        ->and($again->wasRecentlyCreated)->toBeFalse()
        ->and($again->id)->toBe($first->id)
        ->and(TurnReservation::count())->toBe(1);
});

/* ------------------------------------------------------------ team treasury */

function makeActionsTeamWithLeader(Leaderboard $leaderboard, Student $leader, int $coins): GamificationTeam
{
    $team = GamificationTeam::create(['leaderboard_id' => $leaderboard->id, 'name' => 'فريق الخزينة', 'coins' => $coins]);
    $team->students()->attach($leader->id, ['role' => 'leader']);

    return $team;
}

it('keeps a teammate\'s spend that lands while a donation is being made', function () {
    $leaderboard = makeActionsLeaderboard($this->circle);
    GamificationLevel::create([
        'leaderboard_id' => $leaderboard->id,
        'level_number' => 1,
        'name' => 'مبتدئ',
        'xp_required' => 0,
        'icon' => 'sparkles',
        'settings' => ['has_donation' => true, 'donation_max_limit' => 100],
    ]);
    giveActionsCoins($this->student, $leaderboard, 100);
    $team = makeActionsTeamWithLeader($leaderboard, $this->student, 200);

    // Between the donation reading the team and adding to it, a purchase of 150
    // leaves the treasury.
    $spent = false;
    GamificationTransaction::creating(function () use (&$spent, $team) {
        if (! $spent) {
            $spent = true;
            GamificationTeam::whereKey($team->id)->decrement('coins', 150);
        }
    });

    expect(GamificationService::donateCoinsToTeam($this->student->id, $team->id, 50))->toBeTrue()
        // 200 - 150 + 50. Saved from the copy read first, it came out 250.
        ->and($team->fresh()->coins)->toBe(100);
});

it('refuses a team purchase when the treasury was spent in the meantime', function () {
    $leaderboard = makeActionsLeaderboard($this->circle, ['settings' => ['team_purchase_voting_enabled' => false]]);
    $team = makeActionsTeamWithLeader($leaderboard, $this->student, 100);

    $item = GamificationStoreItem::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'دعم الفريق',
        'price' => 80,
        'item_type' => 'team_points',
        'value' => 10,
        'is_team_product' => true,
        'is_active' => true,
    ]);

    // After the balance check passed against 100, another purchase takes 60.
    $spent = false;
    Leaderboard::retrieved(function () use (&$spent, $team) {
        if (! $spent) {
            $spent = true;
            GamificationTeam::whereKey($team->id)->decrement('coins', 60);
        }
    });

    expect(GamificationService::requestStorePurchase($this->student->id, $item->id))->toBe('insufficient_team_coins')
        ->and($team->fresh()->coins)->toBe(40)
        ->and(GamificationStorePurchase::count())->toBe(0);
});

it('lets team coins go below zero from an attack, taken in the update itself', function () {
    $leaderboard = makeActionsLeaderboard($this->circle, ['settings' => ['team_purchase_voting_enabled' => false]]);
    $myTeam = makeActionsTeamWithLeader($leaderboard, $this->student, 200);
    $target = GamificationTeam::create(['leaderboard_id' => $leaderboard->id, 'name' => 'الفريق المستهدف', 'coins' => 10]);

    $attack = GamificationStoreItem::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'هجوم',
        'price' => 80,
        'item_type' => 'team_attack',
        'value' => 50,
        'is_team_product' => true,
        'is_active' => true,
    ]);

    expect(GamificationService::requestStorePurchase($this->student->id, $attack->id, $target->id))->toBe('success')
        ->and($myTeam->fresh()->coins)->toBe(120)
        ->and($target->fresh()->coins)->toBe(-40);

    // Cancelling gives back both the price and the attack, once each.
    $purchase = GamificationStorePurchase::sole();
    expect(GamificationService::cancelPurchase($purchase->id))->toBe('success')
        ->and($myTeam->fresh()->coins)->toBe(200)
        ->and($target->fresh()->coins)->toBe(10);
});

/* --------------------------------------------------------- double final vote */

/**
 * A pending team-points purchase in a team of two: the leader asked (and so
 * voted yes), and the member's vote, given as $memberVote, is the deciding one.
 *
 * @return array{0: GamificationStorePurchase, 1: GamificationTeam}
 */
function makeDecidedTeamPurchase(Circle $circle, Student $leader, bool $memberVote): array
{
    $leaderboard = makeActionsLeaderboard($circle, ['settings' => ['team_purchase_voting_enabled' => true]]);
    $member = makeActionsStudent('عضو مصوت', 'deciding-voter@example.com', $circle);

    $team = makeActionsTeamWithLeader($leaderboard, $leader, 100);
    $team->students()->attach($member->id, ['role' => 'member']);

    $item = GamificationStoreItem::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'دعم الفريق بالتصويت',
        'price' => 40,
        'item_type' => 'team_points',
        'value' => 10,
        'is_team_product' => true,
        'is_active' => true,
    ]);

    expect(GamificationService::requestStorePurchase($leader->id, $item->id))->toBe('pending_voting');

    $purchase = GamificationStorePurchase::sole();
    DB::table('gamification_purchase_votes')->insert([
        'store_purchase_id' => $purchase->id,
        'student_id' => $member->id,
        'vote' => $memberVote,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$purchase, $team];
}

/**
 * Decide the purchase as two requests landing together: the second one reads
 * the purchase (still pending), and before it goes on, the first one decides it
 * from start to finish.
 */
function decideTwiceAtOnce(GamificationStorePurchase $purchase): void
{
    $overlapped = false;

    GamificationStorePurchase::retrieved(function (GamificationStorePurchase $read) use (&$overlapped, $purchase) {
        if ($overlapped || $read->id !== $purchase->id) {
            return;
        }

        $overlapped = true;
        GamificationService::checkPurchaseVotingStatus($purchase->id);
    });

    GamificationService::checkPurchaseVotingStatus($purchase->id);
}

it('carries out a voted team purchase once when two final votes land together', function () {
    [$purchase, $team] = makeDecidedTeamPurchase($this->circle, $this->student, memberVote: true);

    decideTwiceAtOnce($purchase);

    $teamPointGrants = GamificationTransaction::where('team_id', $team->id)
        ->where('description', 'like', 'تفعيل قوة ميزة دعم الفريق%')
        ->count();

    expect($purchase->fresh()->status)->toBe('approved')
        ->and($teamPointGrants)->toBe(1)
        ->and($team->fresh()->coins)->toBe(60);
});

it('refunds a rejected team purchase once when two final votes land together', function () {
    [$purchase, $team] = makeDecidedTeamPurchase($this->circle, $this->student, memberVote: false);

    decideTwiceAtOnce($purchase);

    $refunds = GamificationTransaction::where('team_id', $team->id)
        ->where('description', 'like', 'استرداد رصيد شراء مرفوض بالتصويت%')
        ->count();

    expect($purchase->fresh()->status)->toBe('rejected')
        ->and($refunds)->toBe(1)
        ->and($team->fresh()->coins)->toBe(100);
});

/* ------------------------------------------------------- milestone claiming */

/**
 * A two-day attendance streak with a two-day milestone, so the student has one
 * milestone reward awaiting their claim.
 */
function makeReachedMilestone(Circle $circle, Student $student): array
{
    $leaderboard = makeActionsLeaderboard($circle, [
        'settings' => [
            'enthusiasm_enabled' => true,
            'enthusiasm_type' => 'attendance',
            'hifz_enthusiasm_trigger' => false,
            'review_enthusiasm_trigger' => false,
        ],
    ]);

    $milestoneId = DB::table('gamification_streak_milestones')->insertGetId([
        'leaderboard_id' => $leaderboard->id,
        'days_required' => 2,
        'reward_xp' => 50,
        'reward_coins' => 100,
        'description' => 'يومان متتاليان',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $teacher = Teacher::create([
        'name' => 'معلم الحماسة',
        'email' => 'streak-teacher@example.com',
        'password' => bcrypt('password'),
        'status' => 'active',
        'is_approved' => true,
    ]);

    foreach ([2, 1] as $daysAgo) {
        Attendance::create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'circle_id' => $circle->id,
            'date' => now()->subDays($daysAgo)->format('Y-m-d'),
            'status' => 'present',
        ]);
    }

    GamificationService::updateStudentStreak($student, now()->subDay()->format('Y-m-d'), $leaderboard);

    return [$leaderboard, $milestoneId];
}

it('claims a milestone on the first tap even after a teacher rebuilt the streak while the modal was open', function () {
    [$leaderboard, $milestoneId] = makeReachedMilestone($this->circle, $this->student);

    // The card's «استلام» names the milestone, through the claim store.
    $page = Livewire::test('student.gamification-dashboard')
        ->assertSeeHtml('() => $wire.claimMilestone('.$milestoneId.')');

    $rowShownOnScreen = DB::table('gamification_claimed_milestones')->where('milestone_id', $milestoneId)->value('id');

    // A rebuild that found something changed meanwhile (a teacher's marking)
    // writes the claim rows again: they come back with new ids. A rebuild that
    // changes nothing leaves them be, so the rewrite is done by hand here.
    $row = (array) DB::table('gamification_claimed_milestones')->where('id', $rowShownOnScreen)->first();
    DB::table('gamification_claimed_milestones')->where('id', $rowShownOnScreen)->delete();
    unset($row['id']);
    DB::table('gamification_claimed_milestones')->insert($row);
    expect(DB::table('gamification_claimed_milestones')->where('milestone_id', $milestoneId)->value('id'))
        ->not->toBe($rowShownOnScreen);

    $page->call('claimMilestone', $milestoneId)
        ->assertDispatched('toast-show', toastSaying('مبروك! لقد استلمت جائزة حماسة يومين بنجاح'))
        ->assertDispatched('reward-claimed', xp: 50, coins: 100, keys: ['milestone-'.$milestoneId]);

    expect(DB::table('gamification_claimed_milestones')->where('milestone_id', $milestoneId)->value('status'))->toBe('claimed')
        ->and(GamificationStudentState::where('student_id', $this->student->id)->value('coins'))->toBe(100);
});

it('never congratulates on a milestone claim whose row was replaced under it', function () {
    [, $milestoneId] = makeReachedMilestone($this->circle, $this->student);
    $page = Livewire::test('student.gamification-dashboard');

    // Just after the claim reads the row, a rebuild swaps it for a new one.
    $replaced = false;
    DB::listen(function ($query) use (&$replaced, $milestoneId) {
        if ($replaced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'gamification_claimed_milestones') || ! str_contains($query->sql, 'order by')) {
            return;
        }

        $replaced = true;
        $row = (array) DB::table('gamification_claimed_milestones')->where('milestone_id', $milestoneId)->first();
        DB::table('gamification_claimed_milestones')->where('id', $row['id'])->delete();
        unset($row['id']);
        DB::table('gamification_claimed_milestones')->insert($row);
    });

    $page->call('claimMilestone', $milestoneId)
        ->assertDispatched('toast-show', toastSaying('لا يوجد جائزة معتمدة بانتظار الاستلام.'))
        ->assertNotDispatched('toast-show', toastSaying('مبروك! لقد استلمت جائزة حماسة يومين بنجاح'));

    // Nothing paid for a claim that marked nothing; the reward still awaits.
    expect($replaced)->toBeTrue()
        ->and(GamificationTransaction::where('student_id', $this->student->id)->where('description', 'like', 'مكافأة أيام الحماسة لـ%')->count())->toBe(0)
        ->and(DB::table('gamification_claimed_milestones')->where('milestone_id', $milestoneId)->value('status'))->toBe('approved');
});

it('leaves the milestone rows and their rewards in place when a streak rebuild fails halfway', function () {
    [$leaderboard, $milestoneId] = makeReachedMilestone($this->circle, $this->student);

    Livewire::test('student.gamification-dashboard')->call('claimMilestone', $milestoneId);

    $claimedRows = fn () => DB::table('gamification_claimed_milestones')->where('milestone_id', $milestoneId)->where('status', 'claimed')->count();
    $rewards = fn () => GamificationTransaction::where('student_id', $this->student->id)->where('description', 'like', 'مكافأة أيام الحماسة لـ%')->count();

    expect($claimedRows())->toBe(1)->and($rewards())->toBe(1);

    // The supervisor raises the milestone's reward, so the rebuild has rows to
    // write again (one that finds them standing writes nothing).
    DB::table('gamification_streak_milestones')->where('id', $milestoneId)->update(['reward_xp' => 60]);

    // The rebuild deletes everything, then fails while writing it back.
    GamificationTransaction::creating(function (GamificationTransaction $transaction) {
        if (str_starts_with((string) $transaction->description, 'مكافأة أيام الحماسة لـ')) {
            throw new RuntimeException('rebuild interrupted');
        }
    });

    expect(fn () => GamificationService::recalculateStudentStreak($this->student, $leaderboard))
        ->toThrow(RuntimeException::class, 'rebuild interrupted');

    // Rolled back as one: nothing deleted, nothing doubled.
    expect($claimedRows())->toBe(1)->and($rewards())->toBe(1);
});

/* ------------------------------------------------------------- avatar upload */

it('tells the student why a picked file was refused as their avatar', function () {
    Storage::fake('public');
    makeActionsLeaderboard($this->circle);

    Livewire::test('student.gamification-dashboard')
        ->set('profile_image_file', UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'))
        ->assertHasErrors(['profile_image_file' => 'image'])
        ->assertDispatched('toast-show', toastSaying('الملف المختار ليس صورة. اختر صورة بصيغة JPG أو PNG أو WebP.'))
        ->assertSeeHtml('data-avatar-error')
        ->assertSee('الملف المختار ليس صورة. اختر صورة بصيغة JPG أو PNG أو WebP.')
        ->assertSet('profile_image_file', null);

    expect($this->student->fresh()->avatar_path)->toBeNull();
});

it('refuses a photo over 10 MB with the limit in the message', function () {
    Storage::fake('public');
    makeActionsLeaderboard($this->circle);

    Livewire::test('student.gamification-dashboard')
        ->set('profile_image_file', UploadedFile::fake()->image('big.jpg')->size(11000))
        ->assertHasErrors(['profile_image_file' => 'max'])
        ->assertDispatched('toast-show', toastSaying('حجم الصورة أكبر من 10 ميجابايت. اختر صورة أصغر.'));

    expect($this->student->fresh()->avatar_path)->toBeNull();
});

it('says in Arabic why a photo was refused on its way up, before the avatar rules see it', function () {
    makeActionsLeaderboard($this->circle);

    $message = 'تعذر رفع الصورة. اختر صورة أصغر من 10 ميجابايت وحاول مرة أخرى.';

    // Over Livewire's 12 MB upload limit: refused by the temporary store.
    Livewire::test('student.gamification-dashboard')
        ->call('_uploadErrored', 'profile_image_file', json_encode(['errors' => ['files.0' => ['The files.0 field must not be greater than 12288 kilobytes.']]]), false)
        ->assertHasErrors('profile_image_file')
        ->assertDispatched('toast-show', toastSaying($message))
        ->assertSee($message)
        ->assertDontSee('12288 kilobytes');

    // The connection dropped mid-upload: no reason given.
    Livewire::test('student.gamification-dashboard')
        ->call('_uploadErrored', 'profile_image_file', null, false)
        ->assertDispatched('toast-show', toastSaying($message))
        ->assertDontSee('failed to upload');
});

it('says so when a picked photo passes as an image but cannot be read', function () {
    Storage::fake('public');
    makeActionsLeaderboard($this->circle);

    $message = 'تعذرت قراءة الصورة. اختر صورة أخرى بصيغة JPG أو PNG أو WebP.';

    Livewire::test('student.gamification-dashboard')
        ->set('profile_image_file', UploadedFile::fake()->create('damaged.jpg', 50, 'image/jpeg'))
        ->assertHasErrors('profile_image_file')
        ->assertDispatched('toast-show', toastSaying($message))
        ->assertSee($message)
        ->assertSet('profile_image_file', null);

    expect($this->student->fresh()->avatar_path)->toBeNull();
});

it('covers the avatar with a spinner while the photo uploads', function () {
    makeActionsLeaderboard($this->circle);

    Livewire::test('student.gamification-dashboard')
        ->assertSeeHtml('data-avatar-uploading')
        ->assertSeeHtml('wire:loading.flex wire:target="profile_image_file"');
});

/* ------------------------------------------------ deleted requester or voter */

it('keeps the team page up when the student who asked for, or voted on, a team purchase is deleted', function () {
    $leaderboard = makeActionsLeaderboard($this->circle, ['settings' => ['team_purchase_voting_enabled' => true]]);
    $leader = makeActionsStudent('قائد سيحذف', 'deleted-leader@example.com', $this->circle);
    $voter = makeActionsStudent('مصوت سيحذف', 'deleted-voter@example.com', $this->circle);

    $team = GamificationTeam::create(['leaderboard_id' => $leaderboard->id, 'name' => 'فريق الحذف', 'coins' => 100]);
    $team->students()->attach($leader->id, ['role' => 'leader']);
    $team->students()->attach($voter->id, ['role' => 'member']);
    $team->students()->attach($this->student->id, ['role' => 'member']);

    $item = GamificationStoreItem::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'منتج قيد التصويت',
        'price' => 40,
        'item_type' => 'team_points',
        'value' => 10,
        'is_team_product' => true,
        'is_active' => true,
    ]);

    // The leader asks (and votes yes); one member says no. With three members
    // and two needed, it stays pending.
    expect(GamificationService::requestStorePurchase($leader->id, $item->id))->toBe('pending_voting');
    expect(GamificationService::voteForPurchase($voter->id, GamificationStorePurchase::sole()->id, false))->toBeTrue();
    expect(GamificationStorePurchase::sole()->status)->toBe('pending_approval');

    $leader->delete();
    $voter->delete();

    Livewire::test('student.gamification-dashboard')
        ->assertSee('منتج قيد التصويت')
        ->assertSee('طالب محذوف');
});
