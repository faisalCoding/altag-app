<?php

use App\Models\Attendance;
use App\Models\Circle;
use App\Models\GamificationTrack;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\CompetitionWeekLeaders;
use App\Support\CompetitionWeeks;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Monday 20 July 2026. A competition from Sunday the 5th to the 25th: its
 * weeks are the 5th–11th and the 12th–18th, both over, and the 19th to today.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-07-20 10:00:00');

    $this->circle = Circle::factory()->create();
    $this->ahmad = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'أحمد']);
    $this->khalid = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'خالد']);
    $this->saad = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'سعد']);
});

/**
 * A gamified competition and the XP each student earned on a day.
 *
 * @param  list<array{0: Student, 1: int, 2: string}>  $earned
 */
function leadersCompetition(array $earned): Leaderboard
{
    $competition = Leaderboard::create([
        'circle_id' => test()->circle->id, 'title' => 'مسابقة الصيف', 'competition_type' => 'gamification',
        'start_date' => '2026-07-05', 'end_date' => '2026-07-25', 'is_active' => true, 'settings' => [],
    ]);
    $competition->circles()->attach(test()->circle->id);

    foreach ($earned as [$student, $xp, $date]) {
        GamificationTransaction::create([
            'leaderboard_id' => $competition->id, 'student_id' => $student->id, 'type' => 'earn',
            'amount' => 0, 'xp_amount' => $xp, 'description' => 'نقاط',
        ])->forceFill(['created_at' => "{$date} 09:00:00"])->saveQuietly();
    }

    return $competition;
}

/** Each week's leaders, by name. */
function leaderNames(Leaderboard $competition, int $viewerId): array
{
    return array_map(
        fn (array $leaders) => array_column($leaders, 'name'),
        CompetitionWeekLeaders::for($competition, CompetitionWeeks::of($competition), $viewerId),
    );
}

it('names the leader of each week that is over, and every student tied at the top', function () {
    $competition = leadersCompetition([
        [$this->ahmad, 30, '2026-07-06'], [$this->khalid, 10, '2026-07-07'],
        [$this->khalid, 20, '2026-07-13'], [$this->saad, 20, '2026-07-14'],
        // This week is still running: no leader yet, however far ahead.
        [$this->saad, 90, '2026-07-19'],
    ]);

    expect(leaderNames($competition, $this->ahmad->id))->toBe([
        1 => ['أحمد'],
        2 => ['خالد', 'سعد'],
    ]);
});

it('names no leader of a week nobody scored in', function () {
    $competition = leadersCompetition([[$this->ahmad, 15, '2026-07-13']]);

    expect(leaderNames($competition, $this->ahmad->id))->toBe([2 => ['أحمد']]);
});

it('names the leaders of the student\'s own track, as the leaderboard ranks them', function () {
    $competition = leadersCompetition([[$this->ahmad, 30, '2026-07-06'], [$this->khalid, 10, '2026-07-06'], [$this->saad, 5, '2026-07-07']]);
    $juniors = GamificationTrack::create(['leaderboard_id' => $competition->id, 'name' => 'الصغار']);
    $juniors->students()->attach([$this->khalid->id, $this->saad->id]);

    expect(leaderNames($competition, $this->saad->id))->toBe([1 => ['خالد']])
        // One in no track sees those of «عام».
        ->and(leaderNames($competition, $this->ahmad->id))->toBe([1 => ['أحمد']]);
});

it('shows the leaders in the week chips of the student\'s leaderboard', function () {
    $competition = leadersCompetition([
        [$this->ahmad, 30, '2026-07-06'],
        [$this->khalid, 20, '2026-07-13'], [$this->saad, 20, '2026-07-14'],
    ]);
    $this->actingAs($this->saad, 'student');

    $html = Livewire::test('student.gamification-leaderboard', ['competitionId' => $competition->id])->html();

    expect($html)->toMatch('/data-week="1".*?data-week-leaders="1".*?أحمد/s')
        ->toMatch('/data-week="2".*?data-week-leaders="2"[^>]*title="تصدّره بالتساوي: خالد، سعد"/s')
        ->and(substr_count($html, 'data-week-leaders='))->toBe(2);
});

it('shows the leaders in the week chips of a points competition too', function () {
    $competition = Leaderboard::create([
        'circle_id' => $this->circle->id, 'title' => 'مسابقة الحضور', 'competition_type' => 'normal',
        'start_date' => '2026-07-05', 'end_date' => '2026-07-25', 'is_active' => true,
        'settings' => ['attendance_enabled' => true, 'attendance_present' => 4],
    ]);
    $competition->circles()->attach($this->circle->id);
    $teacher = Teacher::factory()->create();
    Attendance::create(['student_id' => $this->khalid->id, 'teacher_id' => $teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-07', 'status' => 'present']);
    $this->actingAs($this->ahmad, 'student');

    Livewire::test('student.competition-leaderboard', ['competitionId' => $competition->id])
        ->assertSeeHtml('data-week-leaders="1" title="متصدّر الأسبوع: خالد"');
});
