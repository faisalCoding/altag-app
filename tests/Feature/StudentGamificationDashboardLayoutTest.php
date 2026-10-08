<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\GamificationActivity;
use App\Models\GamificationActivityRound;
use App\Models\GamificationActivityWinner;
use App\Models\GamificationBadge;
use App\Models\GamificationLevel;
use App\Models\GamificationPurchaseVote;
use App\Models\GamificationStoreItem;
use App\Models\GamificationStorePurchase;
use App\Models\GamificationStudentState;
use App\Models\GamificationTeam;
use App\Models\GamificationTrack;
use App\Models\GamificationTransaction;
use App\Models\Hadith;
use App\Models\HadithChapter;
use App\Models\HadithLine;
use App\Models\HadithPath;
use App\Models\HadithPathDay;
use App\Models\HadithText;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentHadithPlan;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Teacher;
use App\Services\GamificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Livewire\Livewire;

/*
 * The themed dashboard on a phone: what stacks over what, what may shrink,
 * and what must stay put. A browser is not at hand, so these read the classes
 * and structure that make each piece of the layout work.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-06-08 10:00:00');

    $this->stage = Stage::create(['name' => 'مرحلة تخطيط اللوحة']);
    $this->circle = Circle::create(['name' => 'حلقة تخطيط اللوحة', 'stage_id' => $this->stage->id]);

    $this->student = Student::create([
        'name' => 'طالب تخطيط اللوحة',
        'email' => 'gam-layout@example.com',
        'password' => bcrypt('password'),
        'circle_id' => $this->circle->id,
        'is_approved' => true,
        'status' => 'active',
    ]);

    AcademicCalendarEvent::create([
        'event_name' => 'دوام كامل لاختبار التخطيط',
        'start_date' => now()->subDays(30)->format('Y-m-d'),
        'end_date' => now()->addDays(30)->format('Y-m-d'),
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'is_visible' => true,
    ]);

    $this->leaderboard = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة تخطيط اللوحة',
        'competition_type' => 'gamification',
        'start_date' => now()->subDays(5)->format('Y-m-d'),
        'end_date' => now()->addDays(5)->format('Y-m-d'),
        'is_active' => true,
        'settings' => [],
    ]);
    $this->leaderboard->circles()->attach($this->circle->id);

    $this->actingAs($this->student, 'student');
});

/**
 * Parse rendered HTML into something that can be asked about elements.
 */
function gamLayoutXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

/**
 * The first element an XPath expression finds, failing the test if none.
 */
function gamLayoutElement(DOMXPath $xpath, string $expression, ?DOMNode $context = null): DOMElement
{
    $element = $xpath->query($expression, $context)->item(0);

    expect($element)->toBeInstanceOf(DOMElement::class, "Nothing matched {$expression}");

    return $element;
}

/**
 * @return list<string>
 */
function gamLayoutClasses(DOMElement $element): array
{
    return preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
}

/**
 * The z-index an element's Tailwind classes give it: z-10, z-[15], z-20!.
 * An important one wins over a plain one, as Flux's side menu has both.
 */
function gamLayoutZIndex(DOMElement $element): int
{
    $plain = $important = null;

    foreach (gamLayoutClasses($element) as $class) {
        if (! preg_match('/^z-\[?(\d+)\]?(!?)$/', $class, $match)) {
            continue;
        }

        if ($match[2] === '!') {
            $important = (int) $match[1];
        } else {
            $plain = (int) $match[1];
        }
    }

    return $important ?? $plain
        ?? throw new RuntimeException('No z-index class on <'.$element->tagName.' class="'.$element->getAttribute('class').'">');
}

/*
 * A badge awaiting its claim used to cover the whole screen until taken. It is
 * a card now, above the phone's bottom bar, that leaves the page usable: no
 * full-screen backdrop, and «لاحقاً» beside «استلام».
 */
it('shows a pending badge as a card above the bottom bar that leaves the page usable', function () {
    $badge = GamificationBadge::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'وسام الثبات على الطريق',
        'description' => 'يمنح لمن ثبت على الحضور والتسميع طوال المسابقة',
        'icon' => 'star',
        'badge_type' => 'manual',
        'requirement_value' => 0,
        'reward_xp' => 50,
        'reward_coins' => 20,
    ]);
    DB::table('gamification_badge_student')->insert([
        'badge_id' => $badge->id,
        'student_id' => $this->student->id,
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $xpath = gamLayoutXPath($this->get(route('student.dashboard'))->assertSuccessful()->getContent());

    $card = gamLayoutElement($xpath, '//*[@data-award-card]');
    $bottomBar = gamLayoutElement($xpath, '//*[@data-bottom-nav]');

    expect($xpath->query('//*[@data-claim-overlay]')->length)->toBe(0)
        ->and(gamLayoutZIndex($card))->toBeGreaterThan(gamLayoutZIndex($bottomBar))
        ->and(gamLayoutClasses($card))->toContain('fixed', 'bottom-[calc(env(safe-area-inset-bottom,0px)+5.75rem)]', 'lg:bottom-6', 'lg:w-96')
        ->and(gamLayoutClasses($card))->not->toContain('inset-0')
        ->and($card->getAttribute('role'))->toBe('dialog')
        ->and($card->getAttribute('aria-modal'))->toBe('false')
        ->and($card->hasAttribute('data-gam-floating'))->toBeTrue()
        ->and($card->textContent)->toContain('وسام الثبات على الطريق', 'استلام', 'لاحقاً');
});

it('shows a reached milestone as a card like the badge\'s, above the bottom bar', function () {
    $this->leaderboard->update(['settings' => [
        'enthusiasm_enabled' => true,
        'enthusiasm_type' => 'attendance',
        'hifz_enthusiasm_trigger' => false,
        'review_enthusiasm_trigger' => false,
    ]]);

    DB::table('gamification_streak_milestones')->insert([
        'leaderboard_id' => $this->leaderboard->id,
        'days_required' => 2,
        'reward_xp' => 50,
        'reward_coins' => 100,
        'description' => 'يومان متتاليان',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $teacher = Teacher::factory()->create();
    foreach ([now()->subDays(2), now()->subDay()] as $day) {
        Attendance::create([
            'student_id' => $this->student->id,
            'teacher_id' => $teacher->id,
            'circle_id' => $this->circle->id,
            'date' => $day->format('Y-m-d'),
            'status' => 'present',
        ]);
    }
    GamificationService::updateStudentStreak($this->student, now()->subDay()->format('Y-m-d'), $this->leaderboard);

    $xpath = gamLayoutXPath($this->get(route('student.dashboard'))->assertSuccessful()->getContent());

    $card = gamLayoutElement($xpath, '//*[@data-award-card]');
    $bottomBar = gamLayoutElement($xpath, '//*[@data-bottom-nav]');

    expect($card->textContent)->toContain('جائزة الحماسة', 'يومان من الحماسة المتتالية', 'استلام', 'لاحقاً')
        ->and($xpath->query('//*[@data-claim-overlay]')->length)->toBe(0)
        ->and(gamLayoutZIndex($card))->toBeGreaterThan(gamLayoutZIndex($bottomBar))
        ->and(gamLayoutClasses($card))->toContain('fixed')
        ->and(gamLayoutClasses($card))->not->toContain('inset-0');
});

it('stacks the pinned stats bar over the scrolling cards but under the phone side menu and its backdrop, which hides the bottom bar', function () {
    $xpath = gamLayoutXPath($this->get(route('student.dashboard'))->assertSuccessful()->getContent());

    $pinned = gamLayoutElement($xpath, '//*[@id="gam-stats-bar"]/parent::div');
    $sideMenu = gamLayoutElement($xpath, '//ui-sidebar');
    $cards = gamLayoutElement($xpath, '//input[@id="profile-avatar-upload"]/ancestor::div[contains(concat(" ", @class, " "), " z-10 ")][1]');

    // The open menu's backdrop takes a tap beside the drawer to close it, so
    // it covers the pinned bar too while staying under the menu.
    $css = file_get_contents(resource_path('css/app.css'));
    expect(preg_match('/ui-sidebar-toggle\[data-flux-sidebar-backdrop\]\[data-flux-sidebar-on-mobile\]\s*\{\s*z-index:\s*(\d+)\s*!important/', $css, $backdrop))->toBe(1);

    expect(gamLayoutClasses($pinned))->toContain('sticky')
        ->and(gamLayoutZIndex($pinned))->toBeGreaterThan(gamLayoutZIndex($cards))
        ->and(gamLayoutZIndex($pinned))->toBeLessThan((int) $backdrop[1])
        ->and((int) $backdrop[1])->toBeLessThan(gamLayoutZIndex($sideMenu));

    // The themed bottom bar carries the hook app.css hides while the side menu is open.
    expect(gamLayoutElement($xpath, '//*[@data-bottom-nav]')->textContent)->toContain('المتجر')
        ->and($css)
        ->toContain('body:has(ui-sidebar[data-flux-sidebar-on-mobile]:not([data-flux-sidebar-collapsed-mobile])) [data-bottom-nav]');
});

it('keeps the profile coins whole beside a long name and level: the name block wraps, the level truncates', function () {
    $this->student->update(['name' => 'عبدالرحمن بن عبدالعزيز بن محمد العبدالكريم']);
    GamificationStudentState::create([
        'leaderboard_id' => $this->leaderboard->id,
        'student_id' => $this->student->id,
        'coins' => 1250,
        'current_streak' => 0,
        'max_streak' => 0,
    ]);
    GamificationLevel::create([
        'leaderboard_id' => $this->leaderboard->id,
        'level_number' => 1,
        'name' => 'الحافظ المتقن لكتاب الله',
        'xp_required' => 0,
        'icon' => 'sparkles',
        'settings' => [],
    ]);

    $xpath = gamLayoutXPath(Livewire::test('student.gamification-dashboard')->html());

    $avatar = gamLayoutElement($xpath, '//input[@id="profile-avatar-upload"]/parent::div');
    $row = gamLayoutElement($xpath, './parent::div', $avatar);
    $nameBlock = gamLayoutElement($xpath, './div[h3]', $row);
    $coins = gamLayoutElement($xpath, './h2', $row);

    expect(gamLayoutClasses($avatar))->toContain('shrink-0')
        ->and(gamLayoutClasses($nameBlock))->toContain('min-w-0', 'flex-1')
        ->and(gamLayoutClasses(gamLayoutElement($xpath, './h3', $nameBlock)))->toContain('break-words')
        ->and(trim(gamLayoutElement($xpath, './/span[contains(concat(" ", @class, " "), " truncate ")]', $nameBlock)->textContent))
        ->toBe('الحافظ المتقن لكتاب الله')
        ->and(gamLayoutClasses($coins))->toContain('shrink-0', 'text-3xl', 'sm:text-4xl')
        ->and(trim($coins->textContent))->toStartWith('1250')
        // The old spacer pushed the coins out; the name block takes that room now.
        ->and($xpath->query('./div[normalize-space(@class)="flex-1"]', $row)->length)->toBe(0);
});

it('centres today on the enthusiasm strip by scrolling the strip alone, never the page', function () {
    $xpath = gamLayoutXPath(Livewire::test('student.gamification-dashboard')->html());

    $today = gamLayoutElement($xpath, '//*[contains(concat(" ", @class, " "), " is-today-day ")]');
    $strip = gamLayoutElement($xpath, './ancestor::div[@x-init][1]', $today);

    expect($strip->getAttribute('x-init'))->toContain('$el.scrollBy(')
        ->not->toContain('scrollIntoView')
        ->and(gamLayoutClasses($strip))->toContain('overflow-x-auto');
});

it('keeps every initials avatar round beside a long name: standings, team members and purchase voters', function () {
    $this->leaderboard->update(['settings' => ['team_purchase_voting_enabled' => true]]);

    $longName = 'عبدالرحمن بن عبدالعزيز بن محمد العبدالكريم';
    $member = Student::create([
        'name' => $longName,
        'email' => 'gam-layout-long@example.com',
        'password' => bcrypt('password'),
        'circle_id' => $this->circle->id,
        'is_approved' => true,
        'status' => 'active',
    ]);

    $team = GamificationTeam::create(['leaderboard_id' => $this->leaderboard->id, 'name' => 'فريق الهمة', 'coins' => 200]);
    $team->students()->attach($this->student->id, ['role' => 'leader']);
    $team->students()->attach($member->id, ['role' => 'member']);

    $item = GamificationStoreItem::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'درع الفريق',
        'description' => 'حماية',
        'price' => 40,
        'item_type' => 'shield',
        'is_team_product' => true,
    ]);
    $purchase = GamificationStorePurchase::create([
        'store_item_id' => $item->id,
        'student_id' => $this->student->id,
        'team_id' => $team->id,
        'price_paid' => 40,
        'target_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'pending_approval',
    ]);
    GamificationPurchaseVote::create(['store_purchase_id' => $purchase->id, 'student_id' => $member->id, 'vote' => true]);

    $xpath = gamLayoutXPath(Livewire::test('student.gamification-dashboard')->html());

    $homeTab = '//*[@x-show="currentTab === \'leaderboard\'"]';
    $teamTab = '//*[@x-show="currentTab === \'team\'"]';
    $places = [
        'standings' => $homeTab.'//table//span[normalize-space(text())="'.$longName.'"]',
        'team members' => $teamTab.'//table//span[normalize-space(text())="'.$longName.'"]',
        'purchase voters' => $teamTab.'//span[contains(concat(" ", @class, " "), " font-medium ")][normalize-space(text())="'.$longName.'"]',
    ];

    foreach ($places as $place => $expression) {
        $names = $xpath->query($expression);
        expect($names->length)->toBeGreaterThan(0, "No {$place} row for the long name");

        foreach ($names as $name) {
            $row = gamLayoutElement($xpath, './ancestor::div[contains(concat(" ", @class, " "), " items-center ")][1]', $name);
            $avatar = gamLayoutElement($xpath, './*[contains(concat(" ", @class, " "), " rounded-full ")][1]', $row);
            $nameColumn = gamLayoutElement($xpath, './*[descendant-or-self::span[normalize-space(text())="'.$longName.'"]]', $row);

            expect(gamLayoutClasses($avatar))->toContain('shrink-0')
                ->and(gamLayoutClasses($nameColumn))->toContain('min-w-0');
        }
    }
});

it('stacks the full-screen hadith text above the bottom bar so its «إغلاق» footer shows', function () {
    $text = HadithText::create(['name' => 'متن تجريبي', 'description' => 'متن تجريبي']);
    $chapter = HadithChapter::create(['hadith_text_id' => $text->id, 'name' => 'كتاب الإيمان']);
    $hadith = Hadith::create(['hadith_chapter_id' => $chapter->id, 'name' => 'الأعمال بالنيات', 'sanad' => 'عمر', 'ruling' => 'صحيح']);
    foreach (range(1, 5) as $line) {
        HadithLine::create(['hadith_id' => $hadith->id, 'line_number' => $line, 'text' => "نص السطر {$line}"]);
    }
    $path = HadithPath::create([
        'name' => 'مسار المتن التجريبي',
        'hadith_text_id' => $text->id,
        'memorize_type' => 'hadiths',
        'memorize_amount' => 1,
        'start_date' => now()->format('Y-m-d'),
    ]);
    StudentHadithPlan::create([
        'student_id' => $this->student->id,
        'hadith_path_id' => $path->id,
        'start_date' => now()->format('Y-m-d'),
        'status' => 'active',
        'created_by_role' => 'teacher',
    ]);
    HadithPathDay::create([
        'hadith_path_id' => $path->id,
        'day_number' => 1,
        'date' => now()->format('Y-m-d'),
        'day_name' => 'الاثنين',
        'memorize_type' => 'hadiths',
        'memorize_amount' => 1,
        'from_hadith_id' => $hadith->id,
        'to_hadith_id' => $hadith->id,
    ]);

    $xpath = gamLayoutXPath($this->get(route('student.dashboard'))->assertSuccessful()->getContent());

    $modal = gamLayoutElement($xpath, '//*[@data-hadith-text-modal]');

    expect(gamLayoutZIndex($modal))->toBeGreaterThan(gamLayoutZIndex(gamLayoutElement($xpath, '//*[@data-bottom-nav]')))
        ->and(gamLayoutClasses($modal))->toContain('fixed', 'inset-0')
        ->and($modal->textContent)->toContain('إغلاق');
});

it('cloaks every tab panel and collapsed section, so none paints before Alpine picks what shows', function () {
    $team = GamificationTeam::create(['leaderboard_id' => $this->leaderboard->id, 'name' => 'فريق الإخفاء', 'coins' => 200]);
    $team->students()->attach($this->student->id, ['role' => 'leader']);

    // A donation box and the team's purchases, both collapsed behind a toggle.
    GamificationLevel::create([
        'leaderboard_id' => $this->leaderboard->id,
        'level_number' => 1,
        'name' => 'مبتدئ',
        'xp_required' => 0,
        'icon' => 'sparkles',
        'settings' => ['has_donation' => true, 'donation_max_limit' => 100],
    ]);
    GamificationStudentState::create([
        'leaderboard_id' => $this->leaderboard->id,
        'student_id' => $this->student->id,
        'coins' => 100,
        'current_streak' => 0,
        'max_streak' => 0,
    ]);
    GamificationTransaction::create([
        'leaderboard_id' => $this->leaderboard->id,
        'student_id' => $this->student->id,
        'type' => 'earn',
        'amount' => 100,
        'xp_amount' => 10,
        'description' => 'رصيد أمس',
        'claimed_at' => now()->subDay(),
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);
    $item = GamificationStoreItem::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'درع الفريق',
        'description' => 'حماية',
        'price' => 40,
        'item_type' => 'shield',
        'is_team_product' => true,
    ]);
    GamificationStorePurchase::create([
        'store_item_id' => $item->id,
        'student_id' => $this->student->id,
        'team_id' => $team->id,
        'price_paid' => 40,
        'target_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'approved',
    ]);

    // Standings divided into tracks, each collapsed behind its header.
    GamificationTrack::create(['leaderboard_id' => $this->leaderboard->id, 'name' => 'مسار التخطيط', 'sort_order' => 1])
        ->students()->attach($this->student->id);

    $xpath = gamLayoutXPath(Livewire::test('student.gamification-dashboard')->html());

    $panels = $xpath->query('//*[starts-with(@x-show, "currentTab ===")]');
    $tabs = collect(iterator_to_array($panels))->map(fn (DOMElement $panel) => $panel->getAttribute('x-show'))->unique()->values();

    expect($tabs->all())->toEqualCanonicalizing([
        "currentTab === 'leaderboard'",
        "currentTab === 'store'",
        "currentTab === 'badges'",
        "currentTab === 'news'",
        "currentTab === 'team'",
    ]);

    foreach ($panels as $panel) {
        expect($panel->hasAttribute('x-cloak'))->toBeTrue('No x-cloak on the '.$panel->getAttribute('x-show').' panel');
    }

    $collapsed = $xpath->query('//*[@x-collapse]');
    expect($collapsed->length)->toBeGreaterThanOrEqual(3);

    foreach ($collapsed as $section) {
        expect($section->hasAttribute('x-cloak'))->toBeTrue('No x-cloak on a collapsed section: '.trim(substr($section->textContent, 0, 80)));
    }
});

it('lets the long «اكتمل —» badge pills wrap inside their card instead of running into the next one', function () {
    $awaitingClaim = GamificationBadge::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'وسام المواظبة',
        'icon' => 'bolt',
        'badge_type' => 'streak_attendance',
        'requirement_value' => 3,
    ]);
    DB::table('gamification_badge_student')->insert([
        'badge_id' => $awaitingClaim->id,
        'student_id' => $this->student->id,
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $awaitingApproval = GamificationBadge::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'وسام الانطلاق',
        'icon' => 'bolt',
        'badge_type' => 'manual',
        'requirement_value' => 0,
    ]);
    DB::table('gamification_badge_student')->insert([
        'badge_id' => $awaitingApproval->id,
        'student_id' => $this->student->id,
        'status' => 'pending_approval',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $xpath = gamLayoutXPath(Livewire::test('student.gamification-dashboard')->html());

    foreach (['اكتمل — بانتظار الاستلام', 'اكتمل — بانتظار الاعتماد'] as $label) {
        $pill = gamLayoutElement($xpath, '//*[@data-flux-badge][contains(normalize-space(.), "'.$label.'")]');

        // The ! outranks Flux's own whitespace-nowrap whatever order the CSS lands in.
        expect(gamLayoutClasses($pill))->toContain('max-w-full', 'whitespace-normal!', 'text-center');
    }
});

it('stacks the activity podium over its date when the card is too narrow to hold both side by side', function () {
    $team = GamificationTeam::create(['leaderboard_id' => $this->leaderboard->id, 'name' => 'أسرة الهمة العالية', 'coins' => 100]);
    $team->students()->attach($this->student->id, ['role' => 'leader']);

    $activity = GamificationActivity::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'دوري المسابقات الثقافية',
        'description' => 'أسئلة ثقافية أسبوعية',
    ]);
    $rank = $activity->ranks()->create(['name' => 'المركز الأول', 'team_xp' => 10, 'team_coins' => 10, 'member_xp' => 5, 'member_coins' => 5]);
    $round = GamificationActivityRound::create(['activity_id' => $activity->id, 'name' => 'الجولة الأولى', 'round_date' => now()->format('Y-m-d')]);
    GamificationActivityWinner::create(['round_id' => $round->id, 'rank_id' => $rank->id, 'team_id' => $team->id]);

    $xpath = gamLayoutXPath(Livewire::test('student.gamification-dashboard')->html());

    $roundName = gamLayoutElement($xpath, '//div[normalize-space(text())="الجولة الأولى"]');
    $card = gamLayoutElement($xpath, './ancestor::div[contains(concat(" ", @class, " "), " rounded-3xl ")][1]', $roundName);
    $dateBlock = gamLayoutElement($xpath, './parent::div', $roundName);
    $row = gamLayoutElement($xpath, './parent::div', $dateBlock);
    $podium = gamLayoutElement($xpath, './div[2]', $row);

    // The row reads the card's own width, not the screen's.
    expect(gamLayoutClasses($card))->toContain('@container')
        ->and(gamLayoutClasses($row))->toContain('flex-col-reverse', '@sm:flex-row')
        ->and(gamLayoutClasses($dateBlock))->toContain('min-w-0')
        ->and(gamLayoutClasses($podium))->toContain('shrink-0')
        ->and($podium->textContent)->toContain('أسرة الهمة العالية');
});

it('keeps the rank in the pinned stats bar on one line', function () {
    GamificationTransaction::create([
        'leaderboard_id' => $this->leaderboard->id,
        'student_id' => $this->student->id,
        'type' => 'earn',
        'amount' => 10,
        'xp_amount' => 10,
        'description' => 'تجربة',
        'claimed_at' => now(),
    ]);
    GamificationService::recalculateStudentState($this->student->id, $this->leaderboard->id);

    $xpath = gamLayoutXPath(Livewire::test('student.gamification-dashboard')->html());

    $rank = gamLayoutElement($xpath, '//*[@id="gam-rank-value"]');

    expect(gamLayoutClasses($rank))->toContain('whitespace-nowrap')
        ->and(preg_replace('/\s+/', ' ', trim($rank->textContent)))->toBe('1 / 1');
});

/**
 * The Quran's portion and the plan links are in the wird card under the
 * student's own card; listing them again under the enthusiasm card said the
 * same thing twice.
 */
it('lists no Quran missions under the enthusiasm card, the wird card says what is due', function () {
    $plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'start_date' => now()->format('Y-m-d'),
        'days_count' => 1,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'status' => 'active',
        'plan_type' => 'hifz_review',
        'is_approved' => 1,
        'created_by_role' => 'teacher',
    ]);
    StudentPlanDay::create(['student_plan_id' => $plan->id, 'date' => now()->format('Y-m-d'), 'day_name' => 'الأحد', ...planDayPortion()]);

    Livewire::test('student.gamification-dashboard')
        ->assertDontSee('المهام والخطط')
        ->assertDontSee('مهمة اليوم')
        ->assertDontSee('عرض وطباعة');
});

it('shows the hadith missions only to a stage that memorises hadith', function () {
    $this->stage->update(['hadith_enabled' => false]);

    Livewire::test('student.gamification-dashboard')
        ->assertDontSee('مهام حفظ الحديث المجدولة')
        ->assertDontSee('data-hadith-missions', false);

    $this->stage->update(['hadith_enabled' => true]);
    $this->student->refresh();
    $this->actingAs($this->student, 'student');

    Livewire::test('student.gamification-dashboard')
        ->assertSee('مهام حفظ الحديث المجدولة');
});

/*
 * Tabs and navigation: which switchers reach which tabs, which tab opens, and
 * where the rank tile leads.
 */

/**
 * The root of the dashboard's Alpine state, where the open tab is kept.
 */
function gamTabsRoot(DOMXPath $xpath): DOMElement
{
    return gamLayoutElement($xpath, '//*[contains(@x-data, "currentTab:")]');
}

/**
 * The tab each button of a switcher opens, read from what its click sends.
 *
 * @return list<string>
 */
function gamSwitcherTabs(DOMXPath $xpath, DOMElement $switcher): array
{
    $tabs = [];

    foreach ($xpath->query('.//button', $switcher) as $button) {
        expect(preg_match("/'gamnav-changed', \\{ detail: \\{ tab: '(\\w+)' \\} \\}/", $button->getAttribute('x-on:click'), $match))
            ->toBe(1, 'A switcher button that sends no tab: '.trim($button->textContent));

        $tabs[] = $match[1];
    }

    return $tabs;
}

/**
 * The bottom bar hides from lg up, where it would sit over the side menu, and
 * it was the only way into the news and the team tab. A strip pinned with the
 * stats bar stands in for it there, with the same tabs.
 */
it('offers every tab on a wide screen in a strip pinned with the stats bar, the bottom bar’s tabs exactly', function () {
    $team = GamificationTeam::create(['leaderboard_id' => $this->leaderboard->id, 'name' => 'فريق الشاشة العريضة', 'coins' => 0]);
    $team->students()->attach($this->student->id, ['role' => 'member']);

    $xpath = gamLayoutXPath($this->get(route('student.dashboard'))->assertSuccessful()->getContent());

    $strip = gamLayoutElement($xpath, '//*[@data-desktop-tabs]');
    $pinned = gamLayoutElement($xpath, '//*[@id="gam-stats-bar"]/parent::div');
    $bottomBar = gamLayoutElement($xpath, '//*[@data-bottom-nav]');

    expect(gamLayoutClasses($strip))->toContain('hidden', 'lg:flex')
        ->and(gamLayoutClasses($bottomBar))->toContain('lg:hidden')
        ->and($strip->parentNode->isSameNode($pinned))->toBeTrue('The strip is not pinned with the stats bar')
        ->and(gamSwitcherTabs($xpath, $strip))->toBe(['leaderboard', 'store', 'badges', 'news', 'team'])
        ->and(gamSwitcherTabs($xpath, $strip))->toBe(gamSwitcherTabs($xpath, $bottomBar))
        // The team tab goes by the theme's word for «my team», as on the bottom bar.
        ->and(trim(gamLayoutElement($xpath, './/button[@data-tab="team"]', $strip)->textContent))->toBe('كتيبتي');

    // Opening the news clears its unread badge, from either switcher.
    expect(gamLayoutElement($xpath, './/button[@data-tab="news"]', $strip)->getAttribute('x-on:click'))->toContain('news-opened');
});

/**
 * A remembered tab is reopened only if the student still has it: a student
 * moved off their team kept «team» in the browser and reopened onto a blank
 * page, the team panel not drawn and every other one hidden.
 */
it('checks a remembered tab against the tabs the student has, in the dashboard and the bottom bar alike', function () {
    $xpath = gamLayoutXPath($this->get(route('student.dashboard'))->assertSuccessful()->getContent());

    $root = gamTabsRoot($xpath);
    $bottomBar = gamLayoutElement($xpath, '//*[@data-bottom-nav]');
    $offered = ['leaderboard', 'store', 'badges', 'news'];

    expect($xpath->query('//*[@x-show="currentTab === \'team\'"]')->length)->toBe(0)
        ->and(gamSwitcherTabs($xpath, gamLayoutElement($xpath, '//*[@data-desktop-tabs]')))->toBe($offered)
        ->and(gamSwitcherTabs($xpath, $bottomBar))->toBe($offered);

    foreach ([$root, $bottomBar] as $switcher) {
        $state = $switcher->getAttribute('x-data');

        expect($state)->toContain('tabs: '.Js::from($offered)->toHtml())
            ->toContain('this.tabs.includes(')
            ->toContain(": 'leaderboard'")
            // A browser that refuses storage still opens a tab.
            ->toContain("try { remembered = localStorage.getItem('student-gam-tab'); } catch (e) {}")
            ->and($switcher->getAttribute('x-on:gamnav-changed.window'))->toContain('tabs.includes($event.detail.tab)');
    }
});

/**
 * The bottom bar read only the browser's memory, so after the sidebar's
 * «الإنجازات» opened the badges it went on lighting another tab, and the
 * fragment, left in the address, reopened the badges on every reload. The
 * dashboard now drops the fragment once read, remembers the tab it opened and
 * tells the bar.
 */
it('drops the sidebar’s fragment once read and tells the bottom bar which tab it opened', function () {
    $xpath = gamLayoutXPath($this->get(route('student.dashboard'))->assertSuccessful()->getContent());

    $root = gamTabsRoot($xpath);
    $state = $root->getAttribute('x-data');

    expect($state)->toContain("fragments: { '#achievements': 'badges', '#leaderboard-standings': 'leaderboard' }")
        ->toContain('history.replaceState(history.state, \'\', window.location.pathname + window.location.search)')
        ->toContain("localStorage.setItem('student-gam-tab', this.currentTab)")
        ->toContain('this.$nextTick(() => this.announce())')
        ->toContain("new CustomEvent('gamnav-changed', { detail: { tab: this.currentTab } })")
        ->and($root->getAttribute('x-on:hashchange.window'))->toBe('followFragment(); announce();');
});

/**
 * «مركزك» promised the standings and scrolled to the top of the page, while
 * the table sits below the missions and plans.
 */
it('takes the rank tile to the standings table, which carries the sidebar’s anchor', function () {
    GamificationTransaction::create([
        'leaderboard_id' => $this->leaderboard->id,
        'student_id' => $this->student->id,
        'type' => 'earn',
        'amount' => 10,
        'xp_amount' => 10,
        'description' => 'تجربة',
        'claimed_at' => now(),
    ]);
    GamificationService::recalculateStudentState($this->student->id, $this->leaderboard->id);

    $xpath = gamLayoutXPath($this->get(route('student.dashboard'))->assertSuccessful()->getContent());

    $tile = gamLayoutElement($xpath, '//*[@id="gam-rank-value"]/ancestor::button[1]');
    $standings = $xpath->query('//*[@id="leaderboard-standings"]');

    expect($tile->getAttribute('x-on:click'))->toContain("tab: 'leaderboard'")
        ->toContain('$nextTick(() => scrollToStandings())')
        ->not->toContain('window.scrollTo')
        ->and(gamTabsRoot($xpath)->getAttribute('x-data'))
        ->toContain("document.getElementById('leaderboard-standings')?.scrollIntoView({ behavior, block: 'start' })")
        ->and($standings->length)->toBe(1);

    $table = $standings->item(0);

    // On the home tab, clear of the pinned bar once scrolled to.
    expect($table->textContent)->toContain('لوحة المتصدرين')
        ->and($xpath->query('ancestor::*[@x-show="currentTab === \'leaderboard\'"]', $table)->length)->toBeGreaterThan(0)
        ->and(gamLayoutClasses($table))->toContain('scroll-mt-24', 'lg:scroll-mt-40');
});

/**
 * Flux draws an icon on the server, so binding its variant in the browser only
 * set a stray attribute on an outline already drawn: the open tab never filled.
 * Both shapes are drawn now, and Alpine shows one.
 */
it('fills the open tab’s icon on the bottom bar and the wide-screen strip', function () {
    $xpath = gamLayoutXPath($this->get(route('student.dashboard'))->assertSuccessful()->getContent());

    $switchers = [
        'activeTab' => gamLayoutElement($xpath, '//*[@data-bottom-nav]'),
        'currentTab' => gamLayoutElement($xpath, '//*[@data-desktop-tabs]'),
    ];

    foreach ($switchers as $state => $switcher) {
        foreach ($xpath->query('.//button', $switcher) as $button) {
            preg_match("/tab: '(\\w+)'/", $button->getAttribute('x-on:click'), $match);
            $tab = $match[1];

            $solid = gamLayoutElement($xpath, ".//svg[@x-show=\"{$state} === '{$tab}'\"]", $button);
            $outline = gamLayoutElement($xpath, ".//svg[@x-show=\"{$state} !== '{$tab}'\"]", $button);

            expect($solid->getAttribute('fill'))->toBe('currentColor', "The open {$tab} icon is not solid")
                ->and($solid->hasAttribute('x-cloak'))->toBeTrue()
                ->and($outline->getAttribute('fill'))->toBe('none')
                ->and($outline->getAttribute('stroke'))->toBe('currentColor');

            foreach ($xpath->query('.//svg', $button) as $icon) {
                expect($icon->hasAttribute('variant') || $icon->hasAttribute('x-bind:variant'))->toBeFalse("A stray variant on the {$tab} icon");
            }
        }
    }
});

it('places what is due under the student\'s own card and above the enthusiasm card, once', function () {
    $html = $this->get(route('student.dashboard'))->assertSuccessful()->getContent();

    $profile = strpos($html, '<!-- Student Profile & Level Card -->');
    $card = strpos($html, 'data-wird-card');
    $enthusiasm = strpos($html, 'أيام الحماسة المتتالية');

    expect(substr_count($html, 'data-wird-card'))->toBe(1)
        ->and($profile)->toBeLessThan($card)
        ->and($card)->toBeLessThan($enthusiasm);
});
