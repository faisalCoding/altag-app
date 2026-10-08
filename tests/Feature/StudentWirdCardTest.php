<?php

use App\Models\Attendance;
use App\Models\Ayah;
use App\Models\Circle;
use App\Models\Leaderboard;
use App\Models\LeaderboardCriterion;
use App\Models\LeaderboardScore;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Surah;
use App\Services\StudentNextWird;
use Carbon\Carbon;
use Livewire\Livewire;

/*
 * The top of the student's page: the portion due next as the teacher app
 * reads it, and what the student did last time.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-08 09:00:00'); // A Thursday, in Riyadh too.

    Surah::create([
        'id' => 78, 'number' => 78, 'name_arabic' => 'النبإ', 'name_simple' => 'An-Naba',
        'revelation_place' => 'makkah', 'revelation_order' => 80, 'verses_count' => 40,
        'start_page' => 582, 'end_page' => 583,
    ]);

    foreach (range(1, 40) as $verse) {
        Ayah::create([
            'id' => $verse, 'surah_id' => 78, 'verse_number' => $verse, 'page_number' => 582,
            'line_number_start' => 1, 'line_number_end' => 1, 'verse_key' => '78:'.$verse,
            'juz_number' => 30, 'hizb_number' => 59, 'rub_number' => 1, 'ruku_number' => 1,
            'manzil_number' => 7, 'text_uthmani' => 'آية '.$verse,
        ]);
    }

    $this->circle = Circle::factory()->create();
    $this->student = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'الطالب سعد']);

    $this->plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'start_date' => '2026-10-05',
        'days_count' => 3,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'status' => 'active',
        'plan_type' => 'hifz',
        'is_approved' => 1,
        'created_by_role' => 'teacher',
    ]);

    $this->days = collect([['2026-10-05', 1, 10], ['2026-10-06', 11, 20], ['2026-10-07', 21, 30]])
        ->map(fn ($row) => StudentPlanDay::create([
            'student_plan_id' => $this->plan->id,
            'date' => $row[0],
            'day_name' => 'يوم',
            'from_ayah_id' => $row[1],
            'to_ayah_id' => $row[2],
        ]));

    $this->actingAs($this->student, 'student');
});

/** The day graded yesterday, with what the student actually recited of it. */
function gradeYesterday(StudentPlanDay $day, int $grade, int $from, int $to): void
{
    $day->update([
        'hifz_achievement' => $grade,
        'hifz_graded_at' => '2026-10-07 07:00:00',
        'hifz_recited_from_ayah_id' => $from,
        'hifz_recited_to_ayah_id' => $to,
    ]);
}

it('names the next portion, carrying what the student fell short of', function () {
    gradeYesterday($this->days[0], 3, 1, 7);

    $due = StudentNextWird::due($this->student);

    expect($due)->toHaveCount(1);
    expect($due[0]['day']->id)->toBe($this->days[1]->id);
    expect($due[0]['range'])->toBe('النبإ 8-20');
    expect($due[0]['carried'])->toBeTrue();
});

it('keeps the next portion as it is after a long recitation, as the app does', function () {
    gradeYesterday($this->days[0], 3, 1, 14);

    $due = StudentNextWird::due($this->student);

    expect($due[0]['range'])->toBe('النبإ 11-20');
    expect($due[0]['carried'])->toBeFalse();
});

it('shows the due portion, the last session and the plan on the student page', function () {
    gradeYesterday($this->days[0], 3, 1, 7);
    Attendance::create(['student_id' => $this->student->id, 'circle_id' => $this->circle->id, 'date' => '2026-10-07', 'status' => 'late']);

    $competition = Leaderboard::create([
        'title' => 'مسابقة الحلقة', 'circle_id' => $this->circle->id, 'competition_type' => 'normal',
        'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'is_active' => true,
    ]);
    $criterion = LeaderboardCriterion::create(['leaderboard_id' => $competition->id, 'name' => 'الحضور المبكر', 'points' => 5]);
    LeaderboardScore::create(['leaderboard_id' => $competition->id, 'student_id' => $this->student->id, 'leaderboard_criterion_id' => $criterion->id, 'date' => '2026-10-07']);

    Livewire::test('student.wird-card')
        ->assertSee('واجبك القادم')
        ->assertSee('النبإ 8-20')
        ->assertSee('يشمل ما بقي عليك من الورد السابق')
        ->assertSee('آخر ما أنجزته')
        ->assertSee('النبإ 1-7')
        ->assertSee('ممتاز')
        ->assertSee('متأخر')
        ->assertSee('الحضور المبكر')
        ->assertSee(route('student.plan.print', ['kind' => 'quran', 'id' => $this->plan->id]), false);
});

it('opens the card on the student page', function () {
    $this->get(route('student.dashboard'))
        ->assertSuccessful()
        ->assertSee('واجبك القادم');
});

it('says so when the plan is done and nothing was ever graded elsewhere', function () {
    $this->days->each(fn (StudentPlanDay $day) => $day->update(['hifz_achievement' => 3, 'hifz_graded_at' => '2026-10-07 07:00:00']));

    expect(StudentNextWird::due($this->student))->toBeEmpty();

    Livewire::test('student.wird-card')->assertSee('لا واجب عليك الآن.');
});
