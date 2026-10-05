<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Circle;
use App\Models\GamificationLevel;
use App\Models\GamificationStudentState;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use App\Services\GamificationNewsService;
use App\Services\GamificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * The level-up card on the student's gamification page: shown once per level,
 * whichever device the student opens the page on (celebrated_level), with what
 * the new level unlocked and its coin reward.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-08 10:00:00');

    $this->stage = Stage::create(['name' => 'مرحلة احتفال المستوى']);
    $this->circle = Circle::create(['name' => 'حلقة احتفال المستوى', 'stage_id' => $this->stage->id]);
    $this->student = Student::create([
        'name' => 'طالب احتفال المستوى',
        'email' => 'level-celebration@example.com',
        'password' => bcrypt('password'),
        'circle_id' => $this->circle->id,
        'is_approved' => true,
        'status' => 'active',
    ]);

    AcademicCalendarEvent::create([
        'event_name' => 'دوام اختبار احتفال المستوى',
        'start_date' => now()->subDays(30)->format('Y-m-d'),
        'end_date' => now()->addDays(30)->format('Y-m-d'),
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'is_visible' => true,
    ]);

    $this->actingAs($this->student, 'student');
});

/**
 * @param  list<array{n: int, xp: int, settings?: array<string, mixed>}>  $levels
 */
function levelCelebrationLeaderboard(Circle $circle, array $levels, bool $manualClaim = false): Leaderboard
{
    $leaderboard = Leaderboard::create([
        'circle_id' => $circle->id,
        'title' => 'مسابقة احتفال المستوى',
        'competition_type' => 'gamification',
        'start_date' => now()->subDays(5)->format('Y-m-d'),
        'end_date' => now()->addDays(5)->format('Y-m-d'),
        'is_active' => true,
        'settings' => ['manual_claim_enabled' => $manualClaim],
    ]);
    $leaderboard->circles()->attach($circle->id);

    foreach ($levels as $level) {
        GamificationLevel::create([
            'leaderboard_id' => $leaderboard->id,
            'level_number' => $level['n'],
            'name' => 'رتبة '.$level['n'],
            'xp_required' => $level['xp'],
            'icon' => 'star',
            'settings' => $level['settings'] ?? [],
        ]);
    }

    return $leaderboard;
}

/** XP the student earned, already taken unless said otherwise; then the state is worked out again, as a teacher's grading does. */
function levelCelebrationEarn(Leaderboard $leaderboard, Student $student, int $xp, bool $claimed = true): GamificationTransaction
{
    $tx = GamificationTransaction::create([
        'leaderboard_id' => $leaderboard->id,
        'student_id' => $student->id,
        'type' => 'earn',
        'amount' => 0,
        'xp_amount' => $xp,
        'description' => 'حفظ ممتاز',
        'claimed_at' => $claimed ? now() : null,
    ]);

    GamificationService::recalculateStudentState($student->id, $leaderboard->id);

    return $tx;
}

function levelCelebrationState(Leaderboard $leaderboard, Student $student): GamificationStudentState
{
    return GamificationStudentState::where('leaderboard_id', $leaderboard->id)
        ->where('student_id', $student->id)
        ->sole();
}

function levelCelebrationXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

/** The level card the page draws, or null. */
function levelCelebrationCard(string $html): ?DOMElement
{
    $card = levelCelebrationXPath($html)->query('//*[@data-level-card]')->item(0);

    return $card instanceof DOMElement ? $card : null;
}

/** @return list<string> */
function levelCelebrationPerks(DOMElement $card): array
{
    $perks = [];
    foreach ((new DOMXPath($card->ownerDocument))->query('.//*[@data-level-perk]', $card) as $perk) {
        $perks[] = $perk->getAttribute('data-level-perk');
    }

    return $perks;
}

function levelCelebrationText(DOMElement $element): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $element->textContent));
}

it('starts every existing student\'s celebrations where their announced level stands', function () {
    $leaderboard = levelCelebrationLeaderboard($this->circle, [['n' => 1, 'xp' => 0]]);
    $other = Student::create([
        'name' => 'طالب بلا خط أساس',
        'email' => 'level-celebration-2@example.com',
        'password' => bcrypt('password'),
        'circle_id' => $this->circle->id,
        'is_approved' => true,
        'status' => 'active',
    ]);

    $migration = require database_path('migrations/2026_10_05_143034_add_celebrated_level_to_gamification_student_states_table.php');
    $migration->down();
    expect(Schema::hasColumn('gamification_student_states', 'celebrated_level'))->toBeFalse();

    DB::table('gamification_student_states')->insert([
        ['leaderboard_id' => $leaderboard->id, 'student_id' => $this->student->id, 'coins' => 0, 'notified_level' => 3, 'created_at' => now(), 'updated_at' => now()],
        ['leaderboard_id' => $leaderboard->id, 'student_id' => $other->id, 'coins' => 0, 'notified_level' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration->up();

    expect(DB::table('gamification_student_states')->where('student_id', $this->student->id)->value('celebrated_level'))->toBe(3)
        ->and(DB::table('gamification_student_states')->where('student_id', $other->id)->value('celebrated_level'))->toBeNull();
});

it('sets the celebration baseline with the news baseline, and leaves it for the page to move', function () {
    $leaderboard = levelCelebrationLeaderboard($this->circle, [
        ['n' => 1, 'xp' => 0],
        ['n' => 2, 'xp' => 100],
        ['n' => 3, 'xp' => 200],
    ]);

    // The first state worked out for a student at level 2: the baseline, silently.
    levelCelebrationEarn($leaderboard, $this->student, 150);
    $state = levelCelebrationState($leaderboard, $this->student);
    expect($state->notified_level)->toBe(2)
        ->and($state->celebrated_level)->toBe(2);

    // Level 3 is announced; the celebration waits for the student to see it.
    levelCelebrationEarn($leaderboard, $this->student, 100);
    $state->refresh();
    expect($state->notified_level)->toBe(3)
        ->and($state->celebrated_level)->toBe(2);

    // A state the news feed already had a baseline for keeps its celebration baseline too.
    GamificationNewsService::syncStudentLevel($this->student->id, $leaderboard->id);
    expect($state->fresh()->celebrated_level)->toBe(2);
});

it('lists what reaching a level unlocked, with its reward taken from the card when it waits for a claim', function (bool $manualClaim) {
    $leaderboard = levelCelebrationLeaderboard($this->circle, [
        ['n' => 1, 'xp' => 0],
        ['n' => 2, 'xp' => 100, 'settings' => ['has_individual_multiplier' => false, 'freeze_max_days' => 2]],
        ['n' => 3, 'xp' => 200, 'settings' => ['has_individual_multiplier' => true, 'freeze_max_days' => 3, 'reward_coins' => 30]],
    ], $manualClaim);

    levelCelebrationEarn($leaderboard, $this->student, 150);
    expect(levelCelebrationCard(Livewire::test('student.gamification-dashboard')->html()))->toBeNull();

    levelCelebrationEarn($leaderboard, $this->student, 100);

    $card = levelCelebrationCard(Livewire::test('student.gamification-dashboard')->html());
    $reward = GamificationTransaction::where('reference_type', GamificationLevel::class)->sole();

    expect($card)->not->toBeNull()
        ->and($card->getAttribute('data-level'))->toBe('3')
        ->and($card->getAttribute('role'))->toBe('dialog')
        ->and($card->getAttribute('aria-modal'))->toBe('false')
        ->and($card->hasAttribute('data-gam-floating'))->toBeTrue()
        ->and(levelCelebrationText($card))->toContain('مستوى جديد!', 'المستوى 3: رتبة 3', 'ما فُتح لك:', 'مضاعف النقاط الفردي', 'تجميد الأيام الفائتة، حتى 3 أيام', 'مكافأة المستوى:', '+30', 'رائع')
        ->and(levelCelebrationPerks($card))->toBe(['individual_multiplier', 'freeze_days'])
        ->and(preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B50}]/u', $card->textContent))->toBe(0);

    $claim = (new DOMXPath($card->ownerDocument))->query('.//*[@data-level-reward-claim]', $card)->item(0);

    if ($manualClaim) {
        expect($claim)->not->toBeNull()
            ->and($claim->getAttribute('data-level-reward-claim'))->toBe('tx-'.$reward->id)
            ->and($claim->getAttribute('x-on:click'))->toContain('claimLevelReward(')
            ->and(levelCelebrationText($card))->toContain('استلام المكافأة');
    } else {
        expect($claim)->toBeNull()
            ->and($reward->claimed_at)->not->toBeNull()
            ->and(levelCelebrationText($card))->toContain('أُضيفت إلى رصيدك')
            ->and(levelCelebrationText($card))->not->toContain('استلام المكافأة');
    }
})->with([
    'manual claim' => true,
    'rewards taken at once' => false,
]);

it('celebrates a jump over several levels in one card, the unlocks together and the coins summed', function () {
    $leaderboard = levelCelebrationLeaderboard($this->circle, [
        ['n' => 1, 'xp' => 0, 'settings' => ['has_individual_multiplier' => false, 'has_freeze' => false]],
        ['n' => 2, 'xp' => 100, 'settings' => ['has_individual_multiplier' => true, 'has_freeze' => false, 'reward_coins' => 20]],
        ['n' => 3, 'xp' => 200, 'settings' => ['has_individual_multiplier' => true, 'has_freeze' => true, 'reward_coins' => 30]],
    ]);

    levelCelebrationEarn($leaderboard, $this->student, 10);
    levelCelebrationEarn($leaderboard, $this->student, 300);

    $card = levelCelebrationCard(Livewire::test('student.gamification-dashboard')->html());

    expect($card->getAttribute('data-level'))->toBe('3')
        ->and(levelCelebrationPerks($card))->toBe(['individual_multiplier', 'freeze'])
        ->and(levelCelebrationText($card))->toContain('+50');
});

it('reaching the first level unlocks its coins only, the perks having applied below it already', function () {
    $leaderboard = levelCelebrationLeaderboard($this->circle, [
        ['n' => 1, 'xp' => 100, 'settings' => ['has_individual_multiplier' => true, 'has_freeze' => true, 'freeze_max_days' => 2, 'reward_coins' => 10]],
        ['n' => 2, 'xp' => 500],
    ]);

    // No level yet: the baseline is 0.
    GamificationService::recalculateStudentState($this->student->id, $leaderboard->id);
    expect(levelCelebrationState($leaderboard, $this->student)->celebrated_level)->toBe(0);

    levelCelebrationEarn($leaderboard, $this->student, 150);

    $card = levelCelebrationCard(Livewire::test('student.gamification-dashboard')->html());

    expect($card->getAttribute('data-level'))->toBe('1')
        ->and(levelCelebrationPerks($card))->toBe([])
        ->and(levelCelebrationText($card))->toContain('واصل التقدّم نحو المستوى 2', '+10');
});

it('is seen once: acknowledging moves the baseline, held to the announced level and never lowered', function () {
    $leaderboard = levelCelebrationLeaderboard($this->circle, [
        ['n' => 1, 'xp' => 0],
        ['n' => 2, 'xp' => 100],
        ['n' => 3, 'xp' => 200],
    ]);
    levelCelebrationEarn($leaderboard, $this->student, 10);
    levelCelebrationEarn($leaderboard, $this->student, 250);

    $state = levelCelebrationState($leaderboard, $this->student);
    expect($state->celebrated_level)->toBe(1)
        ->and($state->notified_level)->toBe(3);
    $updatedAt = $state->updated_at->toDateTimeString();

    Carbon::setTestNow('2026-06-08 11:00:00');

    $page = Livewire::test('student.gamification-dashboard');
    expect(levelCelebrationCard($page->html()))->not->toBeNull();

    // A hand-typed level 5 is held to the level announced (3), and draws nothing.
    $page->call('acknowledgeLevelUp', 5);
    expect($page->effects['html'] ?? null)->toBeNull();

    $state->refresh();
    expect($state->celebrated_level)->toBe(3)
        ->and($state->updated_at->toDateTimeString())->toBe($updatedAt);

    // A second device answering late keeps the higher.
    $page->call('acknowledgeLevelUp', 2);
    expect($state->fresh()->celebrated_level)->toBe(3);

    // And the card is gone, on this device or any other.
    expect(levelCelebrationCard(Livewire::test('student.gamification-dashboard')->html()))->toBeNull();
});

it('writes nothing as the page opens with a level to celebrate', function () {
    $leaderboard = levelCelebrationLeaderboard($this->circle, [
        ['n' => 1, 'xp' => 0],
        ['n' => 2, 'xp' => 100],
    ]);
    levelCelebrationEarn($leaderboard, $this->student, 10);
    levelCelebrationEarn($leaderboard, $this->student, 150);

    // The first opening settles the streak rebuild; the second must write nothing.
    Livewire::test('student.gamification-dashboard');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $html = Livewire::test('student.gamification-dashboard')->html();
    $writes = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => preg_match('/^\s*(insert|update|delete)\b/i', $sql))->values();
    DB::disableQueryLog();

    expect(levelCelebrationCard($html))->not->toBeNull()
        ->and($writes->all())->toBe([])
        ->and(levelCelebrationState($leaderboard, $this->student)->celebrated_level)->toBe(1);
});

it('names a level of the theme\'s default levels when the competition has none of its own', function () {
    $leaderboard = levelCelebrationLeaderboard($this->circle, []);

    GamificationService::recalculateStudentState($this->student->id, $leaderboard->id);
    expect(levelCelebrationState($leaderboard, $this->student)->celebrated_level)->toBe(1);

    levelCelebrationEarn($leaderboard, $this->student, 150);

    $card = levelCelebrationCard(Livewire::test('student.gamification-dashboard')->html());

    expect($card->getAttribute('data-level'))->toBe('2')
        ->and(levelCelebrationText($card))->toContain('المستوى 2: مشارك', 'واصل التقدّم نحو المستوى 3')
        ->and(levelCelebrationPerks($card))->toBe([])
        ->and((new DOMXPath($card->ownerDocument))->query('.//*[@data-level-reward]', $card)->length)->toBe(0);
});

it('brings the card in the same answer as the claim that crossed the level', function () {
    $leaderboard = levelCelebrationLeaderboard($this->circle, [
        ['n' => 1, 'xp' => 0],
        ['n' => 2, 'xp' => 100, 'settings' => ['reward_coins' => 25]],
    ], manualClaim: true);

    GamificationService::recalculateStudentState($this->student->id, $leaderboard->id);
    $pending = levelCelebrationEarn($leaderboard, $this->student, 150, claimed: false);

    $page = Livewire::test('student.gamification-dashboard');
    expect(levelCelebrationCard($page->html()))->toBeNull();

    $page->call('claimReward', $pending->id);

    $card = levelCelebrationCard($page->html());
    $levelReward = GamificationTransaction::where('reference_type', GamificationLevel::class)->sole();

    expect($card)->not->toBeNull()
        ->and($card->getAttribute('data-level'))->toBe('2')
        ->and($levelReward->claimed_at)->toBeNull()
        ->and((new DOMXPath($card->ownerDocument))->query('.//*[@data-level-reward-claim="tx-'.$levelReward->id.'"]', $card)->length)->toBe(1);
});
