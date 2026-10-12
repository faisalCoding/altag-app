<?php

use App\Livewire\Supervisor\CompetitionStandings;
use App\Livewire\Supervisor\ManageGamification;
use App\Livewire\Teacher\LeaderboardReport;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\LeaderboardCriterion;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Services\LeaderboardService;
use App\Support\CompetitionWeeks;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Monday 20 July 2026. A competition from Sunday the 5th to the 25th: its
 * weeks are the 5th–11th, the 12th–18th, and the 19th to today.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-07-20 10:00:00');

    $this->circle = Circle::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->first = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'أحمد']);
    $this->second = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'خالد']);
});

function weeklyCompetition(string $type, array $settings = []): Leaderboard
{
    return Leaderboard::create([
        'circle_id' => test()->circle->id, 'title' => 'مسابقة الصيف', 'competition_type' => $type,
        'start_date' => '2026-07-05', 'end_date' => '2026-07-25', 'is_active' => true, 'settings' => $settings,
    ]);
}

/** Each student's score in the standings, by name. */
function weeklyScores(Leaderboard $competition, ?array $week = null): array
{
    return (new LeaderboardService)->getDetailedStandings($competition, $week)
        ->mapWithKeys(fn (array $row) => [$row['student']->name => $row['score']])
        ->sortKeys()
        ->all();
}

it('lays the competition out in weeks from Sunday to Saturday, up to today', function () {
    $weeks = CompetitionWeeks::of(weeklyCompetition('normal'));

    expect(array_map(fn (array $week) => [$week['number'], $week['from'], $week['to']], $weeks))->toBe([
        [1, '2026-07-05', '2026-07-11'],
        [2, '2026-07-12', '2026-07-18'],
        [3, '2026-07-19', '2026-07-20'],
    ])->and($weeks[0]['label'])->toStartWith('الأسبوع ١ · ');
});

it('starts the first week on the day the competition does, mid-week', function () {
    $competition = weeklyCompetition('normal');
    $competition->update(['start_date' => '2026-07-08']);

    expect(CompetitionWeeks::of($competition)[0])->toMatchArray(['from' => '2026-07-08', 'to' => '2026-07-11'])
        ->and(CompetitionWeeks::find($competition, 9))->toBeNull()
        ->and(CompetitionWeeks::find($competition, null))->toBeNull();
});

it('ranks a points competition over one week: attendance, criteria and extra points alike', function () {
    $competition = weeklyCompetition('normal', ['attendance_enabled' => true, 'attendance_present' => 4]);
    $criterion = LeaderboardCriterion::create(['leaderboard_id' => $competition->id, 'name' => 'الانضباط', 'points' => 5]);

    foreach ([[$this->first, '2026-07-06'], [$this->first, '2026-07-07'], [$this->second, '2026-07-13']] as [$student, $date]) {
        Attendance::create(['student_id' => $student->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => $date, 'status' => 'present']);
    }
    DB::table('leaderboard_scores')->insert(['leaderboard_id' => $competition->id, 'student_id' => $this->first->id, 'leaderboard_criterion_id' => $criterion->id, 'date' => '2026-07-08']);
    DB::table('leaderboard_extra_points')->insert(['leaderboard_id' => $competition->id, 'student_id' => $this->second->id, 'date' => '2026-07-14', 'points' => 10]);

    [$week1, $week2] = CompetitionWeeks::of($competition);

    expect(weeklyScores($competition))->toBe(['أحمد' => 13, 'خالد' => 14])
        ->and(weeklyScores($competition, $week1))->toBe(['أحمد' => 13, 'خالد' => 0])
        ->and(weeklyScores($competition, $week2))->toBe(['أحمد' => 0, 'خالد' => 14]);
});

/** A gamified competition: أحمد earned 30 in the first week, خالد 20 in the second. */
function weeklyGamified(): Leaderboard
{
    $competition = weeklyCompetition('gamification');
    $competition->circles()->attach(test()->circle->id);

    foreach ([[test()->first, 30, '2026-07-06'], [test()->second, 20, '2026-07-13']] as [$student, $xp, $date]) {
        $transaction = GamificationTransaction::create([
            'leaderboard_id' => $competition->id, 'student_id' => $student->id, 'type' => 'earn',
            'amount' => 0, 'xp_amount' => $xp, 'description' => 'نقاط',
        ]);
        $transaction->forceFill(['created_at' => "{$date} 09:00:00"])->saveQuietly();
    }

    return $competition;
}

/** The names in the order a set of standings ranks them. */
function weeklyOrder($standings): array
{
    return collect($standings)->map(fn (array $row) => $row['student']->name.':'.$row['score'])->all();
}

it('ranks a gamified competition over one week, by the day each point was earned', function () {
    $competition = weeklyGamified();

    [$week1, $week2, $week3] = CompetitionWeeks::of($competition);

    expect(weeklyScores($competition))->toBe(['أحمد' => 30, 'خالد' => 20])
        ->and(weeklyScores($competition, $week1))->toBe(['أحمد' => 30, 'خالد' => 0])
        ->and(weeklyScores($competition, $week2))->toBe(['أحمد' => 0, 'خالد' => 20])
        ->and(weeklyScores($competition, $week3))->toBe(['أحمد' => 0, 'خالد' => 0]);
});

it('filters the teacher\'s report by week', function () {
    $competition = weeklyGamified();
    $this->actingAs($this->teacher, 'teacher');

    Livewire::test(LeaderboardReport::class, ['leaderboardId' => $competition->id])
        ->assertSee('كل أيام المسابقة')
        ->assertSee('الأسبوع ٢ · ')
        ->assertViewHas('standings', fn ($standings) => weeklyOrder($standings) === ['أحمد:30', 'خالد:20'])
        ->set('week', '2')
        ->assertViewHas('standings', fn ($standings) => weeklyOrder($standings) === ['خالد:20', 'أحمد:0']);
});

it('filters the supervisor\'s standings and the gamified competition\'s standings tab by week', function () {
    $competition = weeklyGamified();
    $supervisor = Supervisor::factory()->create();
    $competition->update(['supervisor_id' => $supervisor->id]);
    $this->actingAs($supervisor, 'supervisor');

    Livewire::test(CompetitionStandings::class, ['competitionId' => $competition->id])
        ->set('week', '2')
        ->assertViewHas('topByTrack', fn ($groups) => weeklyOrder($groups->first()['standings']) === ['خالد:20', 'أحمد:0']);

    Livewire::test(ManageGamification::class, ['competitionId' => $competition->id])
        ->set('activeTab', 'standings')
        ->set('standingsWeek', '2')
        ->assertViewHas('standingsGroups', fn ($groups) => weeklyOrder($groups[0]['standings']) === ['خالد:20', 'أحمد:0']);
});

it('shows a student one week of the leaderboard, their own rank staying the whole competition\'s', function () {
    weeklyGamified();
    $this->actingAs($this->second, 'student');

    Livewire::test('student.gamification-dashboard')
        ->set('standingsWeek', '2')
        ->assertViewHas('shownStandings', fn ($standings) => weeklyOrder($standings) === ['خالد:20', 'أحمد:0'])
        ->assertViewHas('leaderboardStandings', fn ($standings) => weeklyOrder($standings) === ['أحمد:30', 'خالد:20']);
});

it('shows a student one week of a points competition', function () {
    $competition = weeklyCompetition('normal', ['attendance_enabled' => true, 'attendance_present' => 4]);
    $competition->circles()->attach($this->circle->id);
    Attendance::create(['student_id' => $this->second->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-13', 'status' => 'present']);
    Attendance::create(['student_id' => $this->first->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-06', 'status' => 'present']);
    Attendance::create(['student_id' => $this->first->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-07', 'status' => 'present']);
    $this->actingAs($this->second, 'student');

    Livewire::test('student.dashboard')
        ->assertViewHas('leaderboardStandings', fn ($standings) => weeklyOrder($standings) === ['أحمد:8', 'خالد:4'])
        ->set('standingsWeek', '2')
        ->assertViewHas('leaderboardStandings', fn ($standings) => weeklyOrder($standings) === ['خالد:4', 'أحمد:0']);
});
