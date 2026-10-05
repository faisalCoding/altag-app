<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\GamificationBadge;
use App\Models\GamificationStoreItem;
use App\Models\GamificationStorePurchase;
use App\Models\GamificationTeam;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\GamificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The student gamification page draws its icons as SVG, not as emojis that
 * every phone draws in its own style, and keeps still for readers who asked
 * for less motion. Only what a supervisor typed (a coin or team emoji the
 * theme does not know) is printed as it is.
 */

/** Pictographs, dingbats, the star, and the tick and cross. */
const GAM_EMOJI_PATTERN = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B50}\x{2713}\x{2717}]/u';

beforeEach(function () {
    Carbon::setTestNow('2026-06-08 10:00:00');

    $this->stage = Stage::create(['name' => 'مرحلة اختبار الأيقونات']);
    $this->circle = Circle::create(['name' => 'حلقة اختبار الأيقونات', 'stage_id' => $this->stage->id]);
    $this->teacher = Teacher::create([
        'name' => 'معلم الأيقونات',
        'email' => 'icons-teacher@example.com',
        'password' => bcrypt('password'),
        'status' => 'active',
        'is_approved' => true,
    ]);
    $this->student = iconsStudent('طالب الأيقونات', 'icons-student@example.com', $this->circle);
    $this->teammate = iconsStudent('زميل الأيقونات', 'icons-mate@example.com', $this->circle);
    $this->rival = iconsStudent('منافس الأيقونات', 'icons-rival@example.com', $this->circle);

    AcademicCalendarEvent::create([
        'event_name' => 'دوام اختبار الأيقونات',
        'start_date' => now()->subDays(30)->format('Y-m-d'),
        'end_date' => now()->addDays(30)->format('Y-m-d'),
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'is_visible' => true,
    ]);

    $this->leaderboard = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة اختبار الأيقونات',
        'competition_type' => 'gamification',
        'start_date' => now()->subDays(5)->format('Y-m-d'),
        'end_date' => now()->addDays(5)->format('Y-m-d'),
        'is_active' => true,
        'settings' => [
            'manual_claim_enabled' => true,
            'enthusiasm_enabled' => true,
            'enthusiasm_type' => 'attendance',
            'hifz_enthusiasm_trigger' => false,
            'review_enthusiasm_trigger' => false,
        ],
    ]);
    $this->leaderboard->circles()->attach($this->circle->id);

    $this->actingAs($this->student, 'student');
});

function iconsStudent(string $name, string $email, Circle $circle): Student
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
 * Everything the page can draw at once: a team with a vote running, a team
 * product that needs one, badges of every keyword (one awaiting its claim),
 * a reward to claim and a milestone already claimed. No emoji in any of it.
 */
function seedEverythingTheIconsPageDraws(object $test): void
{
    $leaderboard = $test->leaderboard;

    $team = GamificationTeam::create(['leaderboard_id' => $leaderboard->id, 'name' => 'فريق النور', 'coins' => 500]);
    $team->students()->attach($test->student->id, ['role' => 'leader']);
    $team->students()->attach($test->teammate->id, ['role' => 'member']);
    $rivals = GamificationTeam::create(['leaderboard_id' => $leaderboard->id, 'name' => 'فريق الفجر', 'coins' => 0]);
    $rivals->students()->attach($test->rival->id, ['role' => 'leader']);

    $voteItem = GamificationStoreItem::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'نقاط للفريق',
        'price' => 100,
        'item_type' => 'team_points',
        'value' => 10,
        'is_team_product' => true,
        'require_member_approval_count' => 1,
        'is_active' => true,
    ]);
    $purchase = GamificationStorePurchase::create([
        'store_item_id' => $voteItem->id,
        'student_id' => $test->student->id,
        'team_id' => $team->id,
        'price_paid' => 100,
        'status' => 'pending_approval',
    ]);
    DB::table('gamification_purchase_votes')->insert([
        'store_purchase_id' => $purchase->id,
        'student_id' => $test->teammate->id,
        'vote' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach (['sparkles', 'sparkle', 'fire', 'rocket', 'crown', 'bolt', 'trophy', 'star', 'shield', 'heart', 'medal'] as $index => $keyword) {
        $badge = GamificationBadge::create([
            'leaderboard_id' => $leaderboard->id,
            'name' => 'وسام '.$keyword,
            'description' => 'وصف الوسام',
            'icon' => $keyword,
            'badge_type' => 'manual',
            'requirement_value' => 0,
            'reward_xp' => 10,
            'reward_coins' => 5,
        ]);

        if ($index < 2) {
            DB::table('gamification_badge_student')->insert([
                'badge_id' => $badge->id,
                'student_id' => $test->student->id,
                'status' => $index === 0 ? 'claimed' : 'approved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    GamificationTransaction::create([
        'leaderboard_id' => $leaderboard->id,
        'student_id' => $test->student->id,
        'type' => 'earn',
        'amount' => 5,
        'xp_amount' => 20,
        'description' => 'حفظ ممتاز',
        'claimed_at' => null,
    ]);

    // A two-day streak, its milestone claimed: the circle draws a tick.
    $milestoneId = DB::table('gamification_streak_milestones')->insertGetId([
        'leaderboard_id' => $leaderboard->id,
        'days_required' => 2,
        'reward_xp' => 50,
        'reward_coins' => 100,
        'description' => 'يومان متتاليان',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    foreach ([2, 1] as $daysAgo) {
        Attendance::create([
            'student_id' => $test->student->id,
            'teacher_id' => $test->teacher->id,
            'circle_id' => $test->circle->id,
            'date' => now()->subDays($daysAgo)->format('Y-m-d'),
            'status' => 'present',
        ]);
    }
    GamificationService::updateStudentStreak($test->student, now()->subDay()->format('Y-m-d'), $leaderboard);
    Livewire::test('student.gamification-dashboard')->call('claimMilestone', $milestoneId);

    expect(DB::table('gamification_claimed_milestones')->where('milestone_id', $milestoneId)->value('status'))->toBe('claimed');
}

it('draws the whole page, bottom bar and all, without a single emoji', function () {
    seedEverythingTheIconsPageDraws($this);

    $html = $this->get(route('student.dashboard'))->assertSuccessful()->getContent();

    preg_match_all(GAM_EMOJI_PATTERN, $html, $found);

    expect($html)->toContain('data-bottom-nav')
        ->and($html)->toContain('data-team-vote-requirements')
        // The approved badge's celebration card, which replaced the full-screen box.
        ->and($html)->toContain('data-award-card')
        ->and($html)->not->toContain('data-claim-overlay')
        ->and($html)->toContain('data-gam-icon="check"')
        ->and($html)->toContain('data-gam-icon="streak"')
        ->and($found[0])->toBe([]);
});

it('tells screen readers which team-vote requirements are met and that a milestone was claimed', function () {
    seedEverythingTheIconsPageDraws($this);
    // The treasury cannot cover the 100-coin product: that requirement is unmet.
    GamificationTeam::where('name', 'فريق النور')->update(['coins' => 50]);

    $html = $this->get(route('student.dashboard'))->assertSuccessful()->getContent();

    preg_match('/<div data-team-vote-requirements.*?<\/div>\s*<\/div>\s*<\/div>/su', $html, $requirements);
    preg_match_all('/<span class="sr-only" data-gam-icon-label>\s*(.*?)\s*<\/span>/su', $requirements[0] ?? '', $labels);

    expect($labels[1])->toBe(['متحقق', 'متحقق', 'غير متحقق', 'متحقق'])
        ->and($html)->toMatch('/<span class="sr-only" data-gam-icon-label>\s*تم الاستلام\s*<\/span>/u');
});

it('reads an icon\'s label out beside it, and adds nothing when it has none', function () {
    $labelled = Blade::render('<x-student.partials.gam-icon name="x" label="غير متحقق" />');
    $freeze = Blade::render('<x-student.partials.gam-icon name="freeze" label="تجميد" />');
    $plain = Blade::render('<x-student.partials.gam-icon name="check" />');

    expect($labelled)->toContain('aria-hidden="true"')
        ->toMatch('/<span class="sr-only" data-gam-icon-label>\s*غير متحقق\s*<\/span>/u')
        ->not->toContain('label="')
        ->and($freeze)->toMatch('/<span class="sr-only" data-gam-icon-label>\s*تجميد\s*<\/span>/u')
        ->and($plain)->not->toContain('sr-only');
});

it('still prints a coin emoji the supervisor typed that the theme does not know', function () {
    $this->leaderboard->update(['settings' => array_merge($this->leaderboard->settings, ['theme' => ['coin_emoji' => '🐪']])]);

    $html = $this->get(route('student.dashboard'))->assertSuccessful()->getContent();

    expect($html)->toContain('🐪');
});

it('draws each badge keyword as its icon, and an unknown one as a plain badge', function (string $keyword, string $drawn) {
    $html = Blade::render('<x-student.partials.gam-badge-icon :icon="$icon" class="size-8" />', ['icon' => $keyword]);

    expect($html)->toContain('data-badge-icon="'.$drawn.'"')
        ->and($html)->toContain('<svg')
        ->and($html)->not->toMatch(GAM_EMOJI_PATTERN);
})->with([
    ['sparkles', 'sparkles'],
    ['sparkle', 'sparkles'],
    ['fire', 'fire'],
    ['rocket', 'rocket'],
    ['crown', 'crown'],
    ['bolt', 'bolt'],
    ['trophy', 'trophy'],
    ['star', 'star'],
    ['shield', 'shield-check'],
    ['heart', 'heart'],
    ['medal', 'check-badge'],
    ['', 'check-badge'],
]);

it('draws an uploaded badge image, greyed while the badge is locked', function () {
    $locked = Blade::render('<x-student.partials.gam-badge-icon icon="badges/a.webp" :locked="true" alt="وسام" />');
    $earned = Blade::render('<x-student.partials.gam-badge-icon icon="badges/a.webp" alt="وسام" />');

    expect($locked)->toContain('data-badge-icon="image"')
        ->toContain('storage/badges/a.webp')
        ->toContain('grayscale')
        ->and($earned)->not->toContain('grayscale');
});

it('draws the freeze snowflake inline and the rest as Heroicons', function () {
    expect(Blade::render('<x-student.partials.gam-icon name="freeze" class="size-4" />'))->toContain('data-gam-icon="freeze"')->toContain('<svg')
        ->and(Blade::render('<x-student.partials.gam-icon name="streak" />'))->toContain('data-gam-icon="streak"')->toContain('<svg')
        ->and(Blade::render('<x-student.partials.gam-icon name="x" />'))->toContain('data-gam-icon="x"');
});

it('keeps still for readers who asked for less motion', function () {
    seedEverythingTheIconsPageDraws($this);

    $html = $this->get(route('student.dashboard'))->assertSuccessful()->getContent();

    expect($html)->toContain('@media (prefers-reduced-motion: reduce)')
        ->and($html)->toContain('.gam-pop { animation: gam-pop-color 0.5s !important; }')
        ->and($html)->toContain('motion-safe:scroll-smooth')
        ->and($html)->not->toMatch('/(?<![\w:-])animate-(pulse|bounce)\b/')
        ->and($html)->not->toMatch('/(?<![\w:-])scroll-smooth\b/');

    // The page's own source, overlays and partials included.
    $sources = collect([
        resource_path('views/components/student/⚡gamification-dashboard.blade.php'),
        resource_path('views/components/student-gamification-nav.blade.php'),
    ])->merge(glob(resource_path('views/components/student/partials/gam-*.blade.php')));

    foreach ($sources as $source) {
        expect(file_get_contents($source))
            ->not->toMatch('/(?<![\w:-])animate-(pulse|bounce)\b/', basename($source))
            ->not->toMatch('/(?<![\w:-])hover:(scale|-?translate)/', basename($source));
    }
});

it('writes a new badge reward\'s description without an emoji', function () {
    $badge = GamificationBadge::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'وسام المثابرة',
        'description' => 'للمثابرين',
        'icon' => 'star',
        'badge_type' => 'manual',
        'requirement_value' => 0,
        'reward_xp' => 30,
        'reward_coins' => 10,
    ]);
    DB::table('gamification_badge_student')->insert([
        'badge_id' => $badge->id,
        'student_id' => $this->student->id,
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test('student.gamification-dashboard')->call('claimBadge', $badge->id);

    $description = GamificationTransaction::where('reference_type', GamificationBadge::class)
        ->where('reference_id', $badge->id)
        ->value('description');

    expect($description)->toBe('مكافأة الحصول على وسام: وسام المثابرة (+30 XP)')
        ->and($description)->not->toMatch(GAM_EMOJI_PATTERN);
});

it('names the day a freeze is bought for, and confirms a multiplier without an emoji', function () {
    $html = Livewire::test('student.gamification-dashboard')->html();

    expect($html)->toContain('data-freeze-confirm')
        ->and(preg_replace('/\s+/u', ' ', $html))->toContain('تجميد يوم <span x-text="$wire.freezeDayName">')
        ->and($html)->toContain('تأكيد الشراء')
        ->not->toContain('شراء وتجميد اليوم')
        ->not->toContain('ويتمم');
});

it('reads the theme coin classes from the class that draws it', function () {
    expect(file_get_contents(resource_path('css/app.css')))
        ->toContain("@source '../../app/Support/GamificationEmoji.php';");
});
