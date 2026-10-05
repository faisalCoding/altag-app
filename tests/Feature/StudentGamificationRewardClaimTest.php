<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Circle;
use App\Models\GamificationLevel;
use App\Models\GamificationStudentState;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use Carbon\Carbon;
use Livewire\Livewire;

/*
 * Claiming a reward from the panel at once: the row hides and celebrates in
 * the browser before the server answers, so the server's answer must say
 * what happened (reward-claimed or gam-claim-failed, with the claim's key),
 * and the panel must carry its totals where every redraw refreshes them.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-08 10:00:00');

    $this->stage = Stage::create(['name' => 'مرحلة اختبار الاستلام']);
    $this->circle = Circle::create(['name' => 'حلقة اختبار الاستلام', 'stage_id' => $this->stage->id]);
    $this->student = claimTestStudent('طالب الاستلام', 'claim-student@example.com', $this->circle);

    AcademicCalendarEvent::create([
        'event_name' => 'دوام اختبار الاستلام',
        'start_date' => now()->subDays(30)->format('Y-m-d'),
        'end_date' => now()->addDays(30)->format('Y-m-d'),
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'is_visible' => true,
    ]);

    $this->leaderboard = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة اختبار الاستلام',
        'competition_type' => 'gamification',
        'start_date' => now()->subDays(3)->format('Y-m-d'),
        'end_date' => now()->addDays(5)->format('Y-m-d'),
        'is_active' => true,
        'settings' => ['manual_claim_enabled' => true],
    ]);
    $this->leaderboard->circles()->attach($this->circle->id);

    $this->actingAs($this->student, 'student');
});

function claimTestStudent(string $name, string $email, Circle $circle): Student
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

function pendingReward(Leaderboard $leaderboard, Student $student, int $xp, int $coins, string $description = 'حفظ ممتاز'): GamificationTransaction
{
    return GamificationTransaction::create([
        'leaderboard_id' => $leaderboard->id,
        'student_id' => $student->id,
        'type' => 'earn',
        'amount' => $coins,
        'xp_amount' => $xp,
        'description' => $description,
        'claimed_at' => null,
    ]);
}

function claimToastSaying(string $text): Closure
{
    return fn (string $event, array $params) => ($params['slots']['text'] ?? null) === $text;
}

function claimPanelXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

/* -------------------------------------------------------------- one claim */

it('answers a claim with what it gave and the key it gave it for, and pays the coins', function () {
    $reward = pendingReward($this->leaderboard, $this->student, 20, 5);

    Livewire::test('student.gamification-dashboard')
        ->call('claimReward', $reward->id)
        ->assertDispatched('reward-claimed', xp: 20, coins: 5, keys: ['tx-'.$reward->id])
        ->assertNotDispatched('gam-claim-failed');

    expect($reward->fresh()->claimed_at)->not->toBeNull()
        ->and(GamificationStudentState::where('student_id', $this->student->id)->value('coins'))->toBe(5);
});

it('refuses a reward already claimed, bringing its row back with a toast and changing nothing', function () {
    $reward = pendingReward($this->leaderboard, $this->student, 20, 5);
    $reward->update(['claimed_at' => now()->subMinute()]);
    $before = GamificationTransaction::orderBy('id')->get()->toArray();

    Livewire::test('student.gamification-dashboard')
        ->call('claimReward', $reward->id)
        ->assertDispatched('gam-claim-failed', keys: ['tx-'.$reward->id])
        ->assertDispatched('toast-show', claimToastSaying('تعذّر استلام هذه المكافأة، ربما استُلمت من قبل.'))
        ->assertNotDispatched('reward-claimed');

    expect(GamificationTransaction::orderBy('id')->get()->toArray())->toBe($before);
});

it('refuses another student\'s reward the same way', function () {
    $other = claimTestStudent('طالب آخر', 'claim-other@example.com', $this->circle);
    $reward = pendingReward($this->leaderboard, $other, 20, 5);

    Livewire::test('student.gamification-dashboard')
        ->call('claimReward', $reward->id)
        ->assertDispatched('gam-claim-failed', keys: ['tx-'.$reward->id])
        ->assertNotDispatched('reward-claimed');

    expect($reward->fresh()->claimed_at)->toBeNull();
});

it('answers «استلام الكل» with the panel\'s totals and the whole panel as its key', function () {
    pendingReward($this->leaderboard, $this->student, 20, 5);
    pendingReward($this->leaderboard, $this->student, 10, 0, 'مراجعة ممتازة');
    pendingReward($this->leaderboard, $this->student, 0, 30, 'مكافأة المستوى');

    Livewire::test('student.gamification-dashboard')
        ->call('claimAllRewards')
        ->assertDispatched('reward-claimed', xp: 30, coins: 35, keys: ['*panel'])
        ->assertDispatched('toast-show', claimToastSaying('تم استلام 3 مكافآت!'));

    expect(GamificationTransaction::whereNull('claimed_at')->count())->toBe(0);
});

it('counts two rewards as Arabic does in the «استلام الكل» toast', function () {
    pendingReward($this->leaderboard, $this->student, 20, 5);
    pendingReward($this->leaderboard, $this->student, 10, 0);

    Livewire::test('student.gamification-dashboard')
        ->call('claimAllRewards')
        ->assertDispatched('toast-show', claimToastSaying('تم استلام مكافأتين!'));
});

/* ------------------------------------------------------------- the panel */

it('draws each row to hide through the claim store, with its totals in attributes, not in x-data', function () {
    $first = pendingReward($this->leaderboard, $this->student, 20, 5, 'وصف طويل جداً لمكافأة تسميع الحفظ الممتاز في يوم الأحد');
    pendingReward($this->leaderboard, $this->student, 10, 0);

    $html = Livewire::test('student.gamification-dashboard')->html();
    $xpath = claimPanelXPath($html);

    $panel = $xpath->query('//*[@data-rewards-panel]')->item(0);
    expect($panel)->not->toBeNull()
        ->and($panel->getAttribute('data-total-count'))->toBe('2')
        ->and($panel->getAttribute('data-total-xp'))->toBe('30')
        ->and($panel->getAttribute('data-total-coins'))->toBe('5')
        ->and($panel->getAttribute('x-data'))->toStartWith('gamRewardsPanel(')
        ->and($panel->getAttribute('x-data'))->not->toContain('count:');

    $row = $xpath->query('//*[@data-reward-row="tx-'.$first->id.'"]')->item(0);
    expect($row)->not->toBeNull()
        ->and($row->getAttribute('wire:key'))->toBe('reward-tx-'.$first->id)
        ->and($row->getAttribute('data-claim-key'))->toBe('tx-'.$first->id)
        ->and($row->getAttribute('x-show'))->toBe("! \$store.gamClaims.isHidden('tx-{$first->id}')")
        ->and($row->hasAttribute('x-collapse'))->toBeTrue();

    // The button claims through the store, at once, with no wire:click to wait on.
    $button = $xpath->query('.//button', $row)->item(0);
    expect($button->getAttribute('x-on:click'))->toContain("\$store.gamClaims.claim('tx-{$first->id}', { xp: 20, coins: 5 }, () => \$wire.claimReward({$first->id}), \$el)")
        ->and($button->hasAttribute('wire:click'))->toBeFalse()
        ->and($button->getAttribute('aria-label'))->toBe('استلام مكافأة: وصف طويل جداً لمكافأة تسميع الحفظ الممتاز في يوم الأحد')
        ->and($button->getAttribute('class'))->toContain('min-h-11');

    // No row carries wire:loading, which a later live update would set off too.
    expect($xpath->query('//*[@data-reward-row]//*[@*[starts-with(name(), "wire:loading")]]')->length)->toBe(0)
        ->and($xpath->query('//*[@data-reward-row][@*[starts-with(name(), "wire:loading")]]')->length)->toBe(0);

    // The description wraps to two lines and the chips show at every width.
    $panelHtml = $panel->ownerDocument->saveHTML($panel);
    expect($panelHtml)->toContain('line-clamp-2')
        ->not->toContain('truncate')
        ->not->toContain('hidden sm:flex');

    // «استلام الكل» keeps its wire:click, so it gets data-loading while it runs.
    $claimAll = $xpath->query('//*[@data-claim-all]')->item(0);
    expect($claimAll->getAttribute('wire:click'))->toBe('claimAllRewards')
        ->and($claimAll->getAttribute('x-on:click'))->toBe('claimAll($el)')
        ->and($claimAll->getAttribute('class'))->toContain('data-loading:opacity-60')
        ->and($claimAll->getAttribute('class'))->toContain('data-loading:pointer-events-none');
});

it('guards every collapse in the panel, so a claim failing at once brings its row back and reduced motion hides it without sliding', function () {
    $reward = pendingReward($this->leaderboard, $this->student, 12, 0);

    $html = Livewire::test('student.gamification-dashboard')->html();
    $xpath = claimPanelXPath($html);

    $collapses = $xpath->query('//*[@data-rewards-panel]/descendant-or-self::*[@x-collapse]');
    expect($collapses->length)->toBeGreaterThanOrEqual(2);

    foreach ($collapses as $collapse) {
        expect($collapse->hasAttribute('x-gam-collapse'))->toBeTrue();
    }

    // x-gam-collapse wraps what x-collapse sets up, so it comes after it.
    expect($html)->toContain("isHidden('tx-{$reward->id}')\" x-collapse x-gam-collapse");

    // The award rows (badges, milestones) too, every one in the partial.
    $partial = file_get_contents(resource_path('views/components/student/partials/gam-rewards-panel.blade.php'));
    expect(preg_match_all('/"\s+x-collapse x-gam-collapse\b/', $partial))->toBe(3)
        ->and(preg_match_all('/"\s+x-collapse(?! x-gam-collapse)\b/', $partial))->toBe(0);

    $script = file_get_contents(resource_path('js/gamification/index.js'));
    expect($script)->toContain("Alpine.directive('gam-collapse'")
        ->toContain('guardCollapse(el, motion.reducedMotion)');
});

it('shows a coins-only row\'s coins and no points chip, and never a «+0»', function () {
    $coinsOnly = pendingReward($this->leaderboard, $this->student, 0, 50, 'مكافأة الوصول للمستوى 2');

    $xpath = claimPanelXPath(Livewire::test('student.gamification-dashboard')->html());
    $row = $xpath->query('//*[@data-reward-row="tx-'.$coinsOnly->id.'"]')->item(0);
    $rowText = preg_replace('/\s+/u', ' ', $row->textContent);

    expect($xpath->query('.//*[@data-reward-xp]', $row)->length)->toBe(0)
        ->and($xpath->query('.//*[@data-reward-coins]', $row)->length)->toBe(1)
        ->and($rowText)->toContain('+50')
        ->and($rowText)->not->toContain('+0')
        ->and($rowText)->not->toContain('نقطة');

    // The panel's own points chip starts hidden too.
    $xpChip = $xpath->query('//*[@data-rewards-total-xp]')->item(0);
    expect($xpChip->hasAttribute('x-cloak'))->toBeTrue();
});

it('counts the points on a row as Arabic does, and isolates the signed numbers', function () {
    $five = pendingReward($this->leaderboard, $this->student, 5, 0);
    $twelve = pendingReward($this->leaderboard, $this->student, 12, 0);

    $html = Livewire::test('student.gamification-dashboard')->html();
    $xpath = claimPanelXPath($html);

    $chip = fn (GamificationTransaction $tx) => preg_replace('/\s+/u', ' ', trim($xpath->query('//*[@data-reward-row="tx-'.$tx->id.'"]//*[@data-reward-xp]')->item(0)->textContent));

    expect($chip($five))->toBe('+5 نقاط')
        ->and($chip($twelve))->toBe('+12 نقطة')
        ->and($html)->toContain('<bdi dir="ltr">+5</bdi>')
        ->and($html)->toContain('<bdi dir="ltr">+12</bdi>');

    // The count under the title, as the browser will keep counting it down.
    expect(preg_replace('/\s+/u', ' ', $xpath->query('//*[@data-rewards-count]')->item(0)->textContent))
        ->toContain('مكافأتين')
        ->and($xpath->query('//*[@data-rewards-count]//*[@x-text="countLabel()"]')->length)->toBe(1);
});

it('prints the rewards\' totals in the footer beside «استلام الكل», not the last row\'s amounts', function () {
    pendingReward($this->leaderboard, $this->student, 20, 5);
    pendingReward($this->leaderboard, $this->student, 0, 10, 'مكافأة المستوى');

    $xpath = claimPanelXPath(Livewire::test('student.gamification-dashboard')->html());
    $text = fn (DOMNode $node): string => preg_replace('/\s+/u', ' ', trim($node->textContent));

    $footerXp = $xpath->query('//*[@x-text="signed(rewardsRemaining().xp)"]')->item(0);
    $footerUnit = $xpath->query('//*[@x-text="pointsUnit(rewardsRemaining().xp)"]')->item(0);
    $footerCoins = $xpath->query('//*[@x-text="signed(rewardsRemaining().coins)"]')->item(0);

    // Alpine rewrites these, but the server's numbers show on every render and redraw first.
    expect($text($footerXp))->toBe('+20')
        ->and($footerXp->parentNode->hasAttribute('x-cloak'))->toBeFalse()
        ->and($text($footerUnit))->toBe('نقطة')
        ->and($text($footerCoins))->toBe('+15')
        ->and($footerCoins->parentNode->hasAttribute('x-cloak'))->toBeFalse();
});

it('draws the theme coin on the panel\'s coin chips, and the stats bar\'s coins in a span of their own', function () {
    $this->leaderboard->update(['settings' => ['manual_claim_enabled' => true, 'theme' => ['coin_emoji' => '🦪']]]);
    pendingReward($this->leaderboard, $this->student, 0, 50);

    $html = Livewire::test('student.gamification-dashboard')->html();
    $xpath = claimPanelXPath($html);

    $coinsChip = $xpath->query('//*[@data-rewards-total-coins]')->item(0);
    // The pearl the supervisor picked, drawn as its SVG; it used to be a generic stack.
    expect($coinsChip->ownerDocument->saveHTML($coinsChip))->toContain('text-teal-600')
        ->not->toContain('circle-stack');

    expect($html)->toMatch('/<span id="gam-coins-value">\s*0\s*<\/span>/');
});

it('lists a level\'s coins, added by a claim that reaches the level, in the same answer', function () {
    GamificationLevel::create([
        'leaderboard_id' => $this->leaderboard->id,
        'level_number' => 1,
        'name' => 'البداية',
        'xp_required' => 0,
        'icon' => 'star',
        'settings' => ['reward_coins' => 0],
    ]);
    $second = GamificationLevel::create([
        'leaderboard_id' => $this->leaderboard->id,
        'level_number' => 2,
        'name' => 'المتقدم',
        'xp_required' => 100,
        'icon' => 'star',
        'settings' => ['reward_coins' => 40],
    ]);
    $reward = pendingReward($this->leaderboard, $this->student, 120, 0);

    $page = Livewire::test('student.gamification-dashboard')
        ->call('claimReward', $reward->id)
        ->assertDispatched('reward-claimed', xp: 120, coins: 0, keys: ['tx-'.$reward->id]);

    $levelReward = GamificationTransaction::where('reference_type', GamificationLevel::class)
        ->where('reference_id', $second->id)
        ->sole();

    // The new row is in the redraw, and the totals the browser reads moved with it.
    $xpath = claimPanelXPath($page->html());
    $panel = $xpath->query('//*[@data-rewards-panel]')->item(0);

    expect($xpath->query('//*[@data-reward-row="tx-'.$levelReward->id.'"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-reward-row="tx-'.$reward->id.'"]')->length)->toBe(0)
        ->and($panel->getAttribute('data-total-count'))->toBe('1')
        ->and($panel->getAttribute('data-total-xp'))->toBe('0')
        ->and($panel->getAttribute('data-total-coins'))->toBe('40');
});

/* ------------------------------------------------------- the page script */

it('hands the component to the claim store from the page\'s script, which keeps no claim logic of its own', function () {
    $source = file_get_contents(resource_path('views/components/student/⚡gamification-dashboard.blade.php'));
    preg_match('/@script(.*?)@endscript/s', $source, $script);

    // The answers, a failed «استلام الكل» and each redraw are handled in
    // claims.js (connectClaims), where the Node tests reach them.
    expect($script[1] ?? '')->toContain('window.gamConnectClaims?.($wire);')
        ->not->toContain('$wire.on(')
        ->not->toContain('$intercept(')
        ->not->toContain("\$hook('morphed'")
        ->and(file_get_contents(resource_path('js/gamification/index.js')))
        ->toContain('window.gamConnectClaims = (wire) => connectClaims(');
});
