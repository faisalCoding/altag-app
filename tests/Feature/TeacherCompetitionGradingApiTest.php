<?php

use App\Models\Circle;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\LeaderboardCriterion;
use App\Models\LeaderboardScore;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Carbon::setTestNow('2026-07-08 10:00:00');

    $this->circle = Circle::factory()->create(['stage_id' => Stage::factory()]);
    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);
    $this->student = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'active']);

    $this->competition = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة التاج',
        'competition_type' => 'gamification',
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'is_active' => true,
        'is_active_for_grading' => true,
        'supervisor_id' => Supervisor::factory()->create()->id,
        'settings' => ['extra_points_enabled' => true],
    ]);
    $this->competition->circles()->attach($this->circle->id);

    $this->criterion = LeaderboardCriterion::create([
        'leaderboard_id' => $this->competition->id,
        'name' => 'الحفظ المتقن',
        'points' => 5,
        'coins' => 2,
    ]);

    Sanctum::actingAs($this->teacher);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scoreChange(array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid(),
        'competition_id' => test()->competition->id,
        'criterion_id' => test()->criterion->id,
        'student_id' => test()->student->id,
        'date' => '2026-07-08',
        'scored' => true,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function extraPointChange(array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid(),
        'action' => 'add',
        'competition_id' => test()->competition->id,
        'student_id' => test()->student->id,
        'date' => '2026-07-08',
        'points' => 3,
        'notes' => 'ساعد زميله في الحفظ',
    ], $overrides);
}

it('grants a criterion and earns its XP, as the web grading page does', function () {
    $change = scoreChange();

    $this->postJson('/api/v1/teacher/scores/changes', ['changes' => [$change]])
        ->assertSuccessful()
        ->assertJsonPath('data.results.0.id', $change['id'])
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.scored', true);

    $score = LeaderboardScore::sole();
    expect($score->student_id)->toBe($this->student->id)
        ->and($score->leaderboard_criterion_id)->toBe($this->criterion->id)
        ->and($score->date->toDateString())->toBe('2026-07-08');

    expect(GamificationTransaction::where('reference_type', LeaderboardScore::class)->where('reference_id', $score->id)->value('xp_amount'))
        ->toBe(5);
});

it('treats a resend, or a colleague who got there first, as already applied', function () {
    $this->postJson('/api/v1/teacher/scores/changes', ['changes' => [scoreChange()]]);

    $this->postJson('/api/v1/teacher/scores/changes', ['changes' => [scoreChange()]])
        ->assertJsonPath('data.results.0.result', 'applied');

    expect(LeaderboardScore::count())->toBe(1)
        ->and(GamificationTransaction::where('reference_type', LeaderboardScore::class)->count())->toBe(1);
});

it('withdraws a criterion and takes its XP back', function () {
    $this->postJson('/api/v1/teacher/scores/changes', ['changes' => [scoreChange()]]);

    $this->postJson('/api/v1/teacher/scores/changes', ['changes' => [scoreChange(['scored' => false])]])
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.scored', false);

    expect(LeaderboardScore::count())->toBe(0)
        ->and(GamificationTransaction::where('reference_type', LeaderboardScore::class)->count())->toBe(0);
});

it('refuses a score the grading page would not take', function (Closure $arrange, array $overrides, string $code) {
    $arrange($this);

    $this->postJson('/api/v1/teacher/scores/changes', ['changes' => [scoreChange($overrides)]])
        ->assertJsonPath('data.results.0.result', 'rejected')
        ->assertJsonPath('data.results.0.code', $code);

    expect(LeaderboardScore::count())->toBe(0);
})->with([
    'a day before the competition' => [fn () => null, ['date' => '2026-06-30'], 'outside_competition'],
    'a day still to come' => [fn () => null, ['date' => '2026-07-09'], 'future_date'],
    'a criterion of another competition' => [
        fn ($test) => $test->criterion->update(['leaderboard_id' => Leaderboard::create([
            'circle_id' => $test->circle->id, 'title' => 'أخرى', 'competition_type' => 'normal',
            'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'settings' => [],
        ])->id]),
        [],
        'criterion_unavailable',
    ],
    'a competition no longer primary' => [
        fn ($test) => $test->competition->update(['is_active_for_grading' => false]),
        [],
        'competition_unavailable',
    ],
    'a suspended student' => [fn ($test) => $test->student->update(['status' => 'suspended']), [], 'student_unavailable'],
    'a student of another circle' => [
        fn ($test) => $test->student->update(['circle_id' => Circle::factory()->create()->id]),
        [],
        'student_unavailable',
    ],
]);

it('adds extra points once, however many times the phone sends them', function () {
    $change = extraPointChange();

    $this->postJson('/api/v1/teacher/extra-points/changes', ['changes' => [$change]])
        ->assertSuccessful()
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.extra_point.uuid', $change['id'])
        ->assertJsonPath('data.results.0.extra_point.points', 3)
        ->assertJsonPath('data.results.0.extra_point.notes', 'ساعد زميله في الحفظ');

    $this->postJson('/api/v1/teacher/extra-points/changes', ['changes' => [$change]])
        ->assertJsonPath('data.results.0.result', 'applied');

    expect(DB::table('leaderboard_extra_points')->count())->toBe(1)
        ->and(GamificationTransaction::where('reference_type', 'leaderboard_extra_points')->count())->toBe(1);
});

it('removes extra points by the phone\'s id or the server\'s', function () {
    $added = extraPointChange();
    $this->postJson('/api/v1/teacher/extra-points/changes', ['changes' => [$added]]);
    $webRowId = DB::table('leaderboard_extra_points')->insertGetId([
        'leaderboard_id' => $this->competition->id,
        'student_id' => $this->student->id,
        'date' => '2026-07-07',
        'points' => 2,
        'notes' => 'من الويب',
    ]);

    $this->postJson('/api/v1/teacher/extra-points/changes', ['changes' => [
        ['id' => (string) Str::uuid(), 'action' => 'remove', 'extra_point_uuid' => $added['id']],
        ['id' => (string) Str::uuid(), 'action' => 'remove', 'extra_point_id' => $webRowId],
    ]])
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.1.result', 'applied');

    expect(DB::table('leaderboard_extra_points')->count())->toBe(0)
        ->and(GamificationTransaction::where('reference_type', 'leaderboard_extra_points')->count())->toBe(0);
});

it('counts removing points already gone as done', function () {
    $this->postJson('/api/v1/teacher/extra-points/changes', ['changes' => [
        ['id' => (string) Str::uuid(), 'action' => 'remove', 'extra_point_uuid' => (string) Str::uuid()],
    ]])
        ->assertJsonPath('data.results.0.result', 'applied');
});

it('refuses extra points where the competition has them switched off', function () {
    $this->competition->update(['settings' => ['extra_points_enabled' => false]]);

    $this->postJson('/api/v1/teacher/extra-points/changes', ['changes' => [extraPointChange()]])
        ->assertJsonPath('data.results.0.result', 'rejected')
        ->assertJsonPath('data.results.0.code', 'extra_points_disabled');

    expect(DB::table('leaderboard_extra_points')->count())->toBe(0);
});

it('validates extra points the way the web form does', function (array $overrides) {
    $this->postJson('/api/v1/teacher/extra-points/changes', ['changes' => [extraPointChange($overrides)]])
        ->assertUnprocessable();
})->with([
    'no points' => [['points' => 0]],
    'no reason' => [['notes' => '']],
    'an unknown action' => [['action' => 'edit']],
]);
