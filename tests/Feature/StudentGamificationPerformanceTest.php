<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\GamificationStoreItem;
use App\Models\GamificationTeam;
use App\Models\GamificationTeamTask;
use App\Models\GamificationTeamTaskAssignment;
use App\Models\GamificationTrack;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\GamificationService;
use App\Services\LeaderboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * How much the student's themed dashboard reads and writes per page and per
 * tap, and that the work it no longer repeats still lands where it did.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-08 10:00:00');

    $this->stage = Stage::create(['name' => 'مرحلة اختبار الأداء']);
    $this->circle = Circle::create(['name' => 'حلقة اختبار الأداء', 'stage_id' => $this->stage->id]);

    $this->teacher = Teacher::create([
        'name' => 'معلم الأداء',
        'email' => 'perf-teacher@example.com',
        'password' => bcrypt('password'),
        'status' => 'active',
        'is_approved' => true,
    ]);

    $this->students = collect(range(1, 4))->map(fn (int $i) => Student::create([
        'name' => "طالب الأداء {$i}",
        'email' => "perf-student-{$i}@example.com",
        'password' => bcrypt('password'),
        'circle_id' => $this->circle->id,
        'is_approved' => true,
        'status' => 'active',
    ]));
    $this->student = $this->students->first();

    AcademicCalendarEvent::create([
        'event_name' => 'دوام اختبار الأداء',
        'start_date' => now()->subDays(30)->format('Y-m-d'),
        'end_date' => now()->addDays(30)->format('Y-m-d'),
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'is_visible' => true,
    ]);

    $this->leaderboard = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة اختبار الأداء',
        'competition_type' => 'gamification',
        'start_date' => now()->subDays(5)->format('Y-m-d'),
        'end_date' => now()->addDays(5)->format('Y-m-d'),
        'is_active' => true,
        'settings' => [
            'enthusiasm_enabled' => true,
            'enthusiasm_type' => 'attendance',
            'hifz_enthusiasm_trigger' => false,
            'review_enthusiasm_trigger' => false,
        ],
    ]);
    $this->leaderboard->circles()->attach($this->circle->id);

    $this->milestoneId = DB::table('gamification_streak_milestones')->insertGetId([
        'leaderboard_id' => $this->leaderboard->id,
        'days_required' => 2,
        'reward_xp' => 50,
        'reward_coins' => 100,
        'description' => 'يومان متتاليان',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([2, 1] as $daysAgo) {
        Attendance::create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->teacher->id,
            'circle_id' => $this->circle->id,
            'date' => now()->subDays($daysAgo)->format('Y-m-d'),
            'status' => 'present',
        ]);
    }

    foreach ($this->students as $index => $student) {
        GamificationTransaction::create([
            'leaderboard_id' => $this->leaderboard->id,
            'student_id' => $student->id,
            'type' => 'earn',
            'amount' => 10 * ($index + 1),
            'xp_amount' => 10 * ($index + 1),
            'description' => 'كسب',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
    }

    $track = GamificationTrack::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'مسار الأداء',
        'sort_order' => 1,
    ]);
    $track->students()->sync([$this->students[0]->id, $this->students[1]->id]);

    $this->team = GamificationTeam::create(['leaderboard_id' => $this->leaderboard->id, 'name' => 'فريق السرعة', 'coins' => 0]);
    $this->team->students()->attach($this->students[0]->id, ['role' => 'leader']);
    $this->team->students()->attach($this->students[1]->id, ['role' => 'member']);

    $rivals = GamificationTeam::create(['leaderboard_id' => $this->leaderboard->id, 'name' => 'فريق المنافسين', 'coins' => 0]);
    $rivals->students()->attach($this->students[2]->id, ['role' => 'leader']);

    GamificationService::updateStudentStreak($this->student, now()->subDay()->format('Y-m-d'), $this->leaderboard);

    $this->actingAs($this->student, 'student');
});

/**
 * Run a callback with the query log on, and return each query it ran: its SQL
 * followed by its bindings.
 *
 * @return Collection<int, string>
 */
function queriesDuring(Closure $callback): Collection
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $queries = collect(DB::getQueryLog())
        ->map(fn (array $query) => $query['query'].' '.json_encode($query['bindings'], JSON_UNESCAPED_UNICODE));
    DB::disableQueryLog();

    return $queries;
}

function writesAmong(Collection $queries): Collection
{
    return $queries->filter(fn (string $sql) => preg_match('/^\s*(insert|update|delete)\b/i', $sql))->values();
}

/**
 * The rows the streak rebuild owns for the student, ids aside.
 *
 * @return array<int, array<int, mixed>>
 */
function streakRowsOf(Student $student): array
{
    return DB::table('gamification_claimed_milestones')
        ->where('student_id', $student->id)
        ->orderBy('milestone_id')->orderBy('created_at')
        ->get()
        ->map(fn ($row) => [$row->milestone_id, $row->streak_run_count, $row->status, $row->created_at, $row->updated_at])
        ->all();
}

/* ------------------------------------------------- cached team standings */

it('reads the cached team standings back as teams from a cache that refuses objects', function () {
    // The database store, as in production: it unserialises with no classes allowed.
    Cache::setDefaultDriver('database');

    $first = GamificationService::getTeamStandings($this->leaderboard, '2026-06-08');
    $cached = GamificationService::getTeamStandings($this->leaderboard, '2026-06-08');

    expect($cached)->toHaveCount(2)
        ->and($cached[0]['team'])->toBeInstanceOf(GamificationTeam::class)
        ->and(collect($cached)->map(fn ($row) => [$row['team']->id, $row['team']->name, $row['score'], $row['rank']])->all())
        ->toBe(collect($first)->map(fn ($row) => [$row['team']->id, $row['team']->name, $row['score'], $row['rank']])->all());

    // The team tab draws from the cached rows: the second render used to fail.
    Livewire::test('student.gamification-dashboard')
        ->call('$refresh')
        ->assertSuccessful()
        ->assertSee('فريق المنافسين');
});

/* -------------------------------------------------------- streak rebuild */

it('rebuilds the streak as the page opens, not on every tap, and writes nothing when it stands', function () {
    $rowsBefore = DB::table('gamification_claimed_milestones')->pluck('id')->all();

    $opening = queriesDuring(fn () => $this->page = Livewire::test('student.gamification-dashboard'));
    $tap = queriesDuring(fn () => $this->page->call('setNewsDate', '2026-06-08'));

    // The rebuild's last check, for the old freeze-consumption rows: run as the
    // page opens, not on the tap.
    $rebuilds = fn ($queries) => $queries->filter(fn ($sql) => str_contains($sql, 'استهلاك عدد (%'))->count();

    // Nothing changed since the last rebuild, so the rows keep their ids.
    expect($rebuilds($opening))->toBe(1)
        ->and($rebuilds($tap))->toBe(0)
        ->and(writesAmong($opening))->toBeEmpty()
        ->and(writesAmong($tap))->toBeEmpty()
        ->and(DB::table('gamification_claimed_milestones')->pluck('id')->all())->toBe($rowsBefore);
});

it('writes the streak again when it changed, exactly as a rebuild from nothing would', function () {
    // A teacher marks today as well: the streak runs three days.
    Attendance::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'circle_id' => $this->circle->id,
        'date' => now()->format('Y-m-d'),
        'status' => 'present',
    ]);
    // And a milestone at three days is set meanwhile.
    DB::table('gamification_streak_milestones')->insert([
        'leaderboard_id' => $this->leaderboard->id,
        'days_required' => 3,
        'reward_xp' => 10,
        'reward_coins' => 5,
        'description' => 'ثلاثة أيام',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test('student.gamification-dashboard');
    $rebuilt = streakRowsOf($this->student);

    // The same rows as a rebuild that starts with none.
    DB::table('gamification_claimed_milestones')->where('student_id', $this->student->id)->delete();
    GamificationService::recalculateStudentStreak($this->student, $this->leaderboard);

    expect($rebuilt)->toHaveCount(2)
        ->and($rebuilt)->toBe(streakRowsOf($this->student))
        ->and(DB::table('gamification_student_states')->where('student_id', $this->student->id)->value('current_streak'))->toBe(3);
});

it('leaves a claimed milestone\'s reward in the rebuild\'s own form, as the redraw after the claim used to', function () {
    $page = Livewire::test('student.gamification-dashboard');

    $page->call('claimMilestone', $this->milestoneId);

    $reward = GamificationTransaction::where('student_id', $this->student->id)
        ->where('description', 'like', 'مكافأة أيام الحماسة لـ%')
        ->sole();

    // Dated on the day the streak reached two days, with no claim reference.
    expect($reward->created_at->toDateString())->toBe('2026-06-07')
        ->and($reward->reference_type)->toBeNull()
        ->and((int) $reward->xp_amount)->toBe(50)
        ->and(DB::table('gamification_claimed_milestones')->where('milestone_id', $this->milestoneId)->value('status'))->toBe('claimed');

    // And the next opening finds it standing: nothing written.
    expect(writesAmong(queriesDuring(fn () => Livewire::test('student.gamification-dashboard'))))->toBeEmpty();
});

/* ------------------------------------------------------- standings reads */

it('groups the tracks from standings already worked out, to the same result', function () {
    $service = new LeaderboardService;
    $standings = $service->getDetailedStandings($this->leaderboard);

    $summary = fn ($groups) => $groups->map(fn ($group) => [
        $group['id'],
        collect($group['standings'])->map(fn ($row) => [$row['student']->id, $row['score'], $row['track_rank']])->all(),
    ])->all();

    $queries = queriesDuring(fn () => $this->grouped = $service->getStandingsByTrack($this->leaderboard, $standings));

    expect($summary($this->grouped))->toBe($summary($service->getStandingsByTrack($this->leaderboard)))
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'gamification_transactions'))->all())->toBeEmpty();
});

it('works the standings out once per render, with one read of the extra points', function () {
    DB::table('leaderboard_extra_points')->insert([
        'leaderboard_id' => $this->leaderboard->id,
        'student_id' => $this->students[2]->id,
        'points' => 5,
        'notes' => 'مشاركة',
        'date' => now()->format('Y-m-d'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $page = Livewire::test('student.gamification-dashboard');
    $tap = queriesDuring(fn () => $page->call('setNewsDate', '2026-06-08'));

    expect($tap->filter(fn ($sql) => str_contains($sql, 'leaderboard_extra_points'))->count())->toBe(1)
        ->and($tap->filter(fn ($sql) => str_starts_with($sql, 'select * from "gamification_transactions" where "leaderboard_id" = ? and "type" = ?'))->count())->toBe(1)
        // The level, once for the page rather than once per freeze and donation helper.
        ->and($tap->filter(fn ($sql) => str_contains($sql, 'sum("xp_amount")'))->count())->toBeLessThanOrEqual(2);
});

it('reads only the themed dashboard on the student page while a themed competition runs', function () {
    $page = queriesDuring(fn () => $this->get(route('student.dashboard'))
        ->assertSuccessful()
        ->assertSeeLivewire('student.gamification-dashboard'));

    // One standings pass in all, the themed dashboard's.
    expect($page->filter(fn ($sql) => str_contains($sql, 'leaderboard_extra_points'))->count())->toBe(1);

    // The everyday page's standings, plans and stats are no longer read and thrown away.
    Livewire::test('student.dashboard')
        ->assertViewHas('activeGamification', fn ($leaderboard) => $leaderboard->is($this->leaderboard))
        ->assertViewMissing('leaderboardStandings')
        ->assertViewMissing('pendingMissions');
});

it('still draws the everyday page, standings and all, for a points competition', function () {
    $this->leaderboard->update(['competition_type' => 'points']);

    $this->get(route('student.dashboard'))
        ->assertSuccessful()
        ->assertDontSeeLivewire('student.gamification-dashboard');

    Livewire::test('student.dashboard')
        ->assertViewHas('activeGamification', null)
        ->assertViewHas('leaderboardStandings', fn ($standings) => $standings->count() === 4);
});

/* --------------------------------------------------- taps that draw nothing */

it('opens the freeze modal without drawing the page again, naming the day from the browser state', function () {
    $page = Livewire::test('student.gamification-dashboard');

    $queries = queriesDuring(fn () => $page->call('openFreezeModal', '2026-06-07', 'الأحد', '٢١ ذو الحجة'));

    $page->assertSet('showFreezeModal', true)
        ->assertSet('freezeDayName', 'الأحد')
        ->assertSet('freezeHijriDate', '٢١ ذو الحجة')
        ->assertSet('freezePrice', 100);

    // Not drawn: the level for the price, and nothing of the page's reads.
    expect($page->effects)->not->toHaveKey('html')
        ->and($queries->count())->toBeLessThan(15);

    $html = Livewire::test('student.gamification-dashboard')->html();
    expect($html)->toContain('x-text="$wire.freezeDayName"')
        ->toContain('x-text="$wire.freezeHijriDate"')
        ->toContain('x-text="$wire.freezePrice"');
});

it('asks for news ids once a minute and only while the badge is on screen', function () {
    $html = Livewire::test('student.gamification-news-badge', ['leaderboardId' => $this->leaderboard->id])->html();

    expect($html)->toContain('wire:poll.60s.visible="refresh"')
        ->not->toContain('wire:poll.3s');
});

/* ------------------------------------------- values read once, unchanged */

it('shows the team score, the attack targets and a graded task\'s pay from the values read once', function () {
    $attack = GamificationStoreItem::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'خصم النقاط',
        'price' => 30,
        'item_type' => 'team_attack',
        'value' => 20,
        'is_team_product' => true,
        'is_active' => true,
    ]);

    $task = GamificationTeamTask::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'مهمة البحث',
        'xp_reward' => 100,
        'coins_reward' => 40,
    ]);
    $assignment = GamificationTeamTaskAssignment::create([
        'team_task_id' => $task->id,
        'team_id' => $this->team->id,
        'start_date' => now()->subDays(3)->format('Y-m-d'),
        'end_date' => now()->addDays(3)->format('Y-m-d'),
        'status' => 'graded',
        'grade' => 50,
    ]);
    GamificationTransaction::create([
        'leaderboard_id' => $this->leaderboard->id,
        'team_id' => $this->team->id,
        'type' => 'earn',
        'amount' => 17,
        'xp_amount' => 33,
        'description' => 'مكافأة مهمة',
        'reference_type' => GamificationTeamTaskAssignment::class,
        'reference_id' => $assignment->id,
    ]);

    $page = Livewire::test('student.gamification-dashboard');

    expect($page->viewData('teamScore'))->toBe(GamificationService::getTeamScore($this->team->fresh(), $this->leaderboard))
        ->and($page->viewData('otherTeams')->pluck('name')->all())->toBe(['فريق المنافسين']);

    // The task shows what its transaction paid, not the grade's share of the reward.
    $page->assertSee('+33 XP')
        ->assertSeeHtml('wire:model="targetTeams.'.$attack->id.'"');
});
