<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\GamificationBadge;
use App\Models\GamificationStudentState;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\GamificationService;
use App\Support\GamificationAwards;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Badges and streak milestones waiting to be taken: a card above the bottom
 * bar, one at a time, that leaves the page usable, and a row in the rewards
 * panel. Both answer to one key, stable across streak rebuilds.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-08 10:00:00');

    $this->stage = Stage::create(['name' => 'مرحلة بطاقات الاحتفال']);
    $this->circle = Circle::create(['name' => 'حلقة بطاقات الاحتفال', 'stage_id' => $this->stage->id]);
    $this->student = Student::create([
        'name' => 'طالب بطاقات الاحتفال',
        'email' => 'celebrations@example.com',
        'password' => bcrypt('password'),
        'circle_id' => $this->circle->id,
        'is_approved' => true,
        'status' => 'active',
    ]);
    $this->teacher = Teacher::create([
        'name' => 'معلم بطاقات الاحتفال',
        'email' => 'celebrations-teacher@example.com',
        'password' => bcrypt('password'),
        'status' => 'active',
        'is_approved' => true,
    ]);

    AcademicCalendarEvent::create([
        'event_name' => 'دوام اختبار بطاقات الاحتفال',
        'start_date' => now()->subDays(30)->format('Y-m-d'),
        'end_date' => now()->addDays(30)->format('Y-m-d'),
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'is_visible' => true,
    ]);

    $this->leaderboard = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة بطاقات الاحتفال',
        'competition_type' => 'gamification',
        'start_date' => now()->subDays(10)->format('Y-m-d'),
        'end_date' => now()->addDays(10)->format('Y-m-d'),
        'is_active' => true,
        'settings' => [
            'enthusiasm_enabled' => true,
            'enthusiasm_type' => 'attendance',
            'hifz_enthusiasm_trigger' => false,
            'review_enthusiasm_trigger' => false,
        ],
    ]);
    $this->leaderboard->circles()->attach($this->circle->id);

    $this->actingAs($this->student, 'student');
});

function celebrationBadge(Leaderboard $leaderboard, Student $student, string $name, int $xp, int $coins, string $status = 'approved'): GamificationBadge
{
    $badge = GamificationBadge::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => $name,
        'description' => 'تقديراً لثباتك',
        'icon' => 'star',
        'badge_type' => 'manual',
        'requirement_value' => 0,
        'reward_xp' => $xp,
        'reward_coins' => $coins,
    ]);

    DB::table('gamification_badge_student')->insert([
        'badge_id' => $badge->id,
        'student_id' => $student->id,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $badge;
}

function celebrationMilestone(Leaderboard $leaderboard, int $days, int $xp = 50, int $coins = 100): int
{
    return DB::table('gamification_streak_milestones')->insertGetId([
        'leaderboard_id' => $leaderboard->id,
        'days_required' => $days,
        'reward_xp' => $xp,
        'reward_coins' => $coins,
        'description' => 'حماسة متتالية',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * The student attends these days (days ago), and the streak is worked out.
 *
 * @param  list<int>  $daysAgo
 */
function celebrationAttend(Leaderboard $leaderboard, Student $student, Teacher $teacher, array $daysAgo): void
{
    foreach ($daysAgo as $ago) {
        Attendance::create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'circle_id' => $student->circle_id,
            'date' => now()->subDays($ago)->format('Y-m-d'),
            'status' => 'present',
        ]);
    }

    GamificationService::recalculateStudentStreak($student, $leaderboard);
}

function celebrationXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

/** @return list<string> */
function celebrationCardKeys(string $html): array
{
    $keys = [];
    foreach (celebrationXPath($html)->query('//*[@data-award-card]') as $card) {
        $keys[] = $card->getAttribute('data-claim-key');
    }

    return $keys;
}

function celebrationToast(string $text): Closure
{
    return fn (string $event, array $params) => ($params['slots']['text'] ?? null) === $text;
}

it('draws one card per award above the bottom bar, never a full-screen box', function () {
    $first = celebrationBadge($this->leaderboard, $this->student, 'وسام الثبات', 30, 10);
    $second = celebrationBadge($this->leaderboard, $this->student, 'وسام الإتقان', 40, 20);
    $milestoneId = celebrationMilestone($this->leaderboard, 2);
    celebrationAttend($this->leaderboard, $this->student, $this->teacher, [2, 1]);

    $html = $this->get(route('student.dashboard'))->assertSuccessful()->getContent();
    $xpath = celebrationXPath($html);
    $cards = $xpath->query('//*[@data-award-card]');

    expect(celebrationCardKeys($html))->toBe(['badge-'.$first->id, 'badge-'.$second->id, 'milestone-'.$milestoneId.'-2026-06-07'])
        ->and($xpath->query('//*[@data-claim-overlay]')->length)->toBe(0)
        ->and($xpath->query('//*[@data-gam-celebrations]//*[contains(concat(" ", normalize-space(@class), " "), " inset-0 ")]')->length)->toBe(0);

    foreach ($cards as $card) {
        $classes = preg_split('/\s+/', trim($card->getAttribute('class')));

        expect($classes)->toContain('gam-celebration', 'fixed', 'z-[10000]')
            ->and($classes)->not->toContain('inset-0')
            ->and($card->getAttribute('role'))->toBe('dialog')
            ->and($card->getAttribute('aria-modal'))->toBe('false')
            ->and($card->hasAttribute('data-gam-floating'))->toBeTrue()
            ->and($card->textContent)->toContain('استلام', 'لاحقاً', 'استلام الكل');
    }

    // The screen reader hears how many wait, counted as Arabic says it.
    expect($html)->toContain('لديك 3 جوائز بانتظار الاستلام');
});

it('shows only the chips an award gives, never «+0»', function () {
    $badge = celebrationBadge($this->leaderboard, $this->student, 'وسام النقاط وحدها', 30, 0);

    $html = Livewire::test('student.gamification-dashboard')->html();
    $xpath = celebrationXPath($html);
    $card = $xpath->query('//*[@data-award-card]')->item(0);

    expect($card->getAttribute('data-claim-key'))->toBe('badge-'.$badge->id)
        ->and($xpath->query('.//*[@data-award-xp]', $card)->length)->toBe(1)
        ->and($xpath->query('.//*[@data-award-coins]', $card)->length)->toBe(0)
        ->and(preg_replace('/\s+/u', ' ', $card->textContent))->toContain('+30 نقطة')
        ->and($card->textContent)->not->toContain('+0');
});

it('lists the awards in the rewards panel too, counted with the rewards', function () {
    $this->leaderboard->update(['settings' => $this->leaderboard->settings + ['manual_claim_enabled' => true]]);
    GamificationTransaction::create([
        'leaderboard_id' => $this->leaderboard->id,
        'student_id' => $this->student->id,
        'type' => 'earn',
        'amount' => 5,
        'xp_amount' => 20,
        'description' => 'حفظ ممتاز',
        'claimed_at' => null,
    ]);
    $badge = celebrationBadge($this->leaderboard, $this->student, 'وسام الثبات', 30, 10);
    $milestoneId = celebrationMilestone($this->leaderboard, 2);
    celebrationAttend($this->leaderboard, $this->student, $this->teacher, [2, 1]);

    $xpath = celebrationXPath(Livewire::test('student.gamification-dashboard')->html());
    $panel = $xpath->query('//*[@data-rewards-panel]')->item(0);
    $rows = [];
    foreach ($xpath->query('.//*[@data-award-row]', $panel) as $row) {
        $rows[$row->getAttribute('data-award-row')] = trim((string) preg_replace('/\s+/u', ' ', $row->textContent));
    }

    expect($panel->getAttribute('data-total-count'))->toBe('3')
        ->and($panel->getAttribute('data-total-xp'))->toBe('100')
        ->and($panel->getAttribute('data-total-coins'))->toBe('115')
        ->and($panel->getAttribute('data-reward-count'))->toBe('1')
        ->and(array_keys($rows))->toBe(['badge-'.$badge->id, 'milestone-'.$milestoneId.'-2026-06-07'])
        ->and($rows['badge-'.$badge->id])->toContain('وسام: وسام الثبات')
        ->and($rows['milestone-'.$milestoneId.'-2026-06-07'])->toContain('جائزة حماسة: يومان')
        ->and($panel->textContent)->toContain('3 مكافآت');
});

it('takes every award at once from the cards: each paid once, the streak rebuilt once, one toast', function () {
    $first = celebrationBadge($this->leaderboard, $this->student, 'وسام الثبات', 30, 10);
    $second = celebrationBadge($this->leaderboard, $this->student, 'وسام الإتقان', 40, 20);
    $milestoneId = celebrationMilestone($this->leaderboard, 2);
    celebrationAttend($this->leaderboard, $this->student, $this->teacher, [2, 1]);

    $page = Livewire::test('student.gamification-dashboard');

    // The rebuild's read of bought freezes, counted against a plain redraw's.
    $freezeReads = function (Closure $callback): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = collect(DB::getQueryLog())->filter(fn (array $q) => str_starts_with($q['query'], 'select "target_date" from "gamification_store_purchases"'))->count();
        DB::disableQueryLog();

        return $count;
    };
    $redraw = $freezeReads(fn () => $page->call('setNewsDate', '2026-06-08'));
    $claimAll = $freezeReads(fn () => $page->call('claimAllAwards'));

    $page->assertDispatched('toast-show', celebrationToast('استلمت 3 جوائز، مبارك!'))
        ->assertDispatched('reward-claimed', xp: 120, coins: 130, keys: ['*awards'])
        ->assertNotDispatched('gam-claim-failed');

    expect($claimAll - $redraw)->toBe(1)
        ->and(DB::table('gamification_badge_student')->where('student_id', $this->student->id)->pluck('status')->unique()->all())->toBe(['claimed'])
        ->and(DB::table('gamification_claimed_milestones')->where('milestone_id', $milestoneId)->pluck('status')->all())->toBe(['claimed'])
        ->and(GamificationTransaction::where('reference_type', GamificationBadge::class)->where('reference_id', $first->id)->count())->toBe(1)
        ->and(GamificationTransaction::where('reference_type', GamificationBadge::class)->where('reference_id', $second->id)->count())->toBe(1)
        ->and(GamificationTransaction::where('description', 'like', 'مكافأة أيام الحماسة لـ%')->count())->toBe(1)
        ->and(GamificationStudentState::where('student_id', $this->student->id)->value('coins'))->toBe(130)
        ->and(celebrationCardKeys($page->html()))->toBe([]);

    // Nothing left: the cards it hid come back, and a warning says so.
    $page->call('claimAllAwards')
        ->assertDispatched('gam-claim-failed', keys: ['*awards'])
        ->assertDispatched('toast-show', celebrationToast('لا توجد جوائز بانتظار الاستلام.'));
});

it('answers each card\'s «استلام» with its key, or refuses an award not approved', function () {
    $approved = celebrationBadge($this->leaderboard, $this->student, 'وسام معتمد', 30, 10);
    $waiting = celebrationBadge($this->leaderboard, $this->student, 'وسام بانتظار الاعتماد', 30, 10, 'pending_approval');

    Livewire::test('student.gamification-dashboard')
        ->call('claimBadge', $waiting->id)
        ->assertDispatched('gam-claim-failed', keys: ['badge-'.$waiting->id])
        ->assertNotDispatched('reward-claimed')
        ->call('claimBadge', $approved->id)
        ->assertDispatched('reward-claimed', xp: 30, coins: 10, keys: ['badge-'.$approved->id]);

    expect(DB::table('gamification_badge_student')->where('badge_id', $waiting->id)->value('status'))->toBe('pending_approval')
        ->and(GamificationTransaction::where('reference_type', GamificationBadge::class)->where('reference_id', $waiting->id)->exists())->toBeFalse();

    $milestoneId = celebrationMilestone($this->leaderboard, 5);

    Livewire::test('student.gamification-dashboard')
        ->call('claimMilestone', $milestoneId)
        ->assertDispatched('gam-claim-failed', keys: ['milestone-'.$milestoneId]);
});

it('counts the days in Arabic in a milestone claim\'s toast', function () {
    $milestoneId = celebrationMilestone($this->leaderboard, 3);
    celebrationAttend($this->leaderboard, $this->student, $this->teacher, [3, 2, 1]);

    Livewire::test('student.gamification-dashboard')
        ->call('claimMilestone', $milestoneId)
        ->assertDispatched('toast-show', celebrationToast('مبروك! لقد استلمت جائزة حماسة 3 أيام بنجاح'))
        ->assertDispatched('reward-claimed', xp: 50, coins: 100, keys: ['milestone-'.$milestoneId]);
});

it('keeps a milestone\'s key when a rebuild writes its claim row again', function () {
    $milestoneId = celebrationMilestone($this->leaderboard, 2);
    celebrationAttend($this->leaderboard, $this->student, $this->teacher, [2, 1]);

    $rowBefore = DB::table('gamification_claimed_milestones')->where('milestone_id', $milestoneId)->value('id');
    $keysBefore = celebrationCardKeys(Livewire::test('student.gamification-dashboard')->html());

    // A teacher marks today too and a three-day milestone is set: the rebuild
    // finds the awards changed and writes every row again, with new ids.
    celebrationMilestone($this->leaderboard, 3, 10, 5);
    celebrationAttend($this->leaderboard, $this->student, $this->teacher, [0]);

    $rowAfter = DB::table('gamification_claimed_milestones')->where('milestone_id', $milestoneId)->value('id');
    $keysAfter = celebrationCardKeys(Livewire::test('student.gamification-dashboard')->html());

    expect($rowAfter)->not->toBe($rowBefore)
        ->and($keysBefore)->toBe(['milestone-'.$milestoneId.'-2026-06-07'])
        ->and($keysAfter)->toContain('milestone-'.$milestoneId.'-2026-06-07');
});

it('gives each run of the same milestone its own key', function () {
    $milestoneId = celebrationMilestone($this->leaderboard, 2);

    // Two runs of two days, broken by a missed day between them.
    celebrationAttend($this->leaderboard, $this->student, $this->teacher, [5, 4, 2, 1]);

    expect(DB::table('gamification_claimed_milestones')->where('milestone_id', $milestoneId)->where('status', 'approved')->count())->toBe(2)
        ->and(celebrationCardKeys(Livewire::test('student.gamification-dashboard')->html()))
        ->toBe(['milestone-'.$milestoneId.'-2026-06-04', 'milestone-'.$milestoneId.'-2026-06-07'])
        ->and(GamificationAwards::milestoneKey($milestoneId, '2026-06-07 00:00:00'))->toBe('milestone-'.$milestoneId.'-2026-06-07');
});

it('puts a card off for the visit and shows one card at a time', function () {
    $badge = celebrationBadge($this->leaderboard, $this->student, 'وسام الثبات', 30, 10);

    $html = Livewire::test('student.gamification-dashboard')->html();
    $xpath = celebrationXPath($html);
    $root = $xpath->query('//*[@data-gam-celebrations]')->item(0);
    $later = $xpath->query('//*[@data-award-card]//button[normalize-space(.)="لاحقاً"]')->item(0);

    expect($root->getAttribute('x-data'))->toContain('gamCelebrations(', 'leaderboardId: '.$this->leaderboard->id)
        ->and($root->hasAttribute('x-cloak'))->toBeTrue()
        ->and($later->getAttribute('x-on:click'))->toBe("postpone('badge-{$badge->id}')")
        ->and($html)->toContain('.gam-celebration:not(.is-hidden) ~ .gam-celebration { display: none; }')
        ->and($html)->toContain('.gam-celebration.is-hidden { display: none; }')
        ->and(file_get_contents(resource_path('js/gamification/celebrations.js')))->toContain("'gam-later-' + leaderboardId")
        // The cards step aside with the bottom bar while the phone's side menu is open.
        ->and(file_get_contents(resource_path('css/app.css')))->toContain('[data-flux-sidebar-collapsed-mobile])) [data-gam-floating]');
});

it('lifts the card clear of a showing toast, on phones and on wide screens', function () {
    celebrationBadge($this->leaderboard, $this->student, 'وسام الثبات', 30, 10);

    $html = $this->get(route('student.dashboard'))->assertSuccessful()->getContent();
    $xpath = celebrationXPath($html);
    $cardClass = $xpath->query('//*[@data-award-card]')->item(0)->getAttribute('class');
    $toastClass = $xpath->query('//ui-toast//*[@data-flux-toast-dialog]')->item(0)?->getAttribute('class') ?? '';

    // Where the toast's box sits, in rem from the bottom edge: Flux's margin,
    // the padding that lifts it over the phone's bottom bar, and a box two
    // lines tall (p-2, py-1.5, two 1.25rem lines, a 1px border each side).
    preg_match('/ui-toast \{\s*margin: ([\d.]+)rem;/', file_get_contents(base_path('vendor/livewire/flux/dist/flux.css')), $margin);
    preg_match('/(?:^|\s)max-lg:pb-(\d+)(?:\s|$)/', $toastClass, $padding);
    $toastMargin = (float) $margin[1];
    $toastPadding = (int) $padding[1] / 4;
    $twoLineToast = 0.5 * 2 + 0.375 * 2 + 1.25 * 2 + 0.125;
    $phoneToastTop = $toastMargin + $toastPadding + $twoLineToast;
    $wideToastTop = $toastMargin + $twoLineToast;

    // At rest the card sits where a toast lands, so it has to move.
    preg_match('/bottom-\[calc\(env\(safe-area-inset-bottom,0px\)\+([\d.]+)rem\)\]/', $cardClass, $phoneRest);
    preg_match('/(?:^|\s)lg:bottom-(\d+)(?:\s|$)/', $cardClass, $wideRest);

    expect((float) $phoneRest[1])->toBeLessThan($phoneToastTop)
        ->and((int) $wideRest[1] / 4)->toBeLessThan($wideToastTop);

    // While Flux holds a toast's box in ui-toast, the card rises above it.
    $showing = preg_quote('body:has(ui-toast > [data-flux-toast-dialog]) .gam-celebration', '/');
    preg_match('/'.$showing.' \{ bottom: calc\(env\(safe-area-inset-bottom, 0px\) \+ ([\d.]+)rem\); \}/', $html, $phoneLifted);
    preg_match('/@media \(min-width: 64rem\) \{\s*'.$showing.' \{ bottom: ([\d.]+)rem; \}/', $html, $wideLifted);

    expect($phoneLifted)->not->toBeEmpty()
        ->and($wideLifted)->not->toBeEmpty()
        ->and((float) $phoneLifted[1])->toBeGreaterThanOrEqual($phoneToastTop)
        ->and((float) $wideLifted[1])->toBeGreaterThanOrEqual($wideToastTop)
        // It glides up, and simply moves when less motion is asked for.
        ->and($html)->toContain('transition: bottom 0.2s ease-out;')
        ->and($html)->toMatch('/@media \(prefers-reduced-motion: reduce\) \{\s*\.gam-celebration \{[^}]*transition: none; \}/');
});

it('leaves badges and milestones alone when the panel\'s «استلام الكل» takes its rewards', function () {
    $this->leaderboard->update(['settings' => $this->leaderboard->settings + ['manual_claim_enabled' => true]]);
    $reward = GamificationTransaction::create([
        'leaderboard_id' => $this->leaderboard->id,
        'student_id' => $this->student->id,
        'type' => 'earn',
        'amount' => 5,
        'xp_amount' => 20,
        'description' => 'حفظ ممتاز',
        'claimed_at' => null,
    ]);
    $badge = celebrationBadge($this->leaderboard, $this->student, 'وسام الثبات', 30, 10);
    $milestoneId = celebrationMilestone($this->leaderboard, 2);
    celebrationAttend($this->leaderboard, $this->student, $this->teacher, [2, 1]);

    Livewire::test('student.gamification-dashboard')
        ->call('claimAllRewards')
        ->assertDispatched('toast-show', celebrationToast('تم استلام مكافأة واحدة!'))
        ->assertDispatched('reward-claimed', xp: 20, coins: 5, keys: ['*panel']);

    expect($reward->fresh()->claimed_at)->not->toBeNull()
        ->and(DB::table('gamification_badge_student')->where('badge_id', $badge->id)->value('status'))->toBe('approved')
        ->and(DB::table('gamification_claimed_milestones')->where('milestone_id', $milestoneId)->value('status'))->toBe('approved');
});
