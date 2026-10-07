<?php

use App\Models\Ayah;
use App\Models\Circle;
use App\Models\PlanDayAttempt;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Surah;
use App\Models\Teacher;
use App\Services\PlanDayAttempts;
use App\Support\TasmeehPayload;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-08 10:00:00'); // 13:00 in Riyadh.

    Surah::create([
        'id' => 1, 'number' => 1, 'name_arabic' => 'الفاتحة', 'name_simple' => 'Al-Fatihah',
        'revelation_place' => 'makkah', 'revelation_order' => 1, 'verses_count' => 7,
        'start_page' => 1, 'end_page' => 1,
    ]);

    foreach (range(1, 7) as $verse) {
        Ayah::create([
            'id' => $verse, 'surah_id' => 1, 'verse_number' => $verse, 'page_number' => 1,
            'line_number_start' => 1, 'line_number_end' => 1, 'verse_key' => "1:{$verse}",
            'juz_number' => 1, 'hizb_number' => 1, 'rub_number' => 1, 'ruku_number' => 1,
            'manzil_number' => 1, 'text_uthmani' => 'آية',
        ]);
    }

    $this->teacher = Teacher::factory()->create();
    $this->circle = Circle::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'active', 'is_approved' => true]);

    $this->plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'start_date' => '2026-07-07',
        'days_count' => 1,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'status' => 'active',
        'plan_type' => 'hifz',
        'direction' => 'forward',
        'is_approved' => true,
        'created_by_role' => 'teacher',
    ]);

    $this->day = StudentPlanDay::create([
        'student_plan_id' => $this->plan->id,
        'date' => '2026-07-07',
        'day_name' => 'Tuesday',
        'from_ayah_id' => 1,
        'to_ayah_id' => 4,
    ]);

    $this->actingAs($this->teacher, 'teacher');
});

function sessionCard(?string $gradedAtDate)
{
    return Livewire::test('teacher.⚡student-tasmeeh-card', [
        'student' => test()->student,
        'sPlans' => StudentPlan::where('student_id', test()->student->id)->get(),
        'activePlanId' => test()->plan->id,
        'gradedAtDate' => $gradedAtDate,
    ]);
}

it('keeps a «لم يسمع» on its own day when the portion is graded the next day', function () {
    sessionCard('2026-07-07')->call('saveAchievement', $this->day->id, 'hifz', 0);
    sessionCard('2026-07-08')->call('saveAchievement', $this->day->id, 'hifz', 3);

    expect(PlanDayAttempt::orderBy('recited_on')->pluck('grade', 'recited_on')->all())
        ->toBe(['2026-07-07' => 0, '2026-07-08' => 3])
        ->and($this->day->fresh()->hifz_achievement)->toBe(3);
});

it('clears only the session of the picked day', function () {
    sessionCard('2026-07-07')->call('saveAchievement', $this->day->id, 'hifz', 0);
    sessionCard('2026-07-08')->call('saveAchievement', $this->day->id, 'hifz', 3);
    sessionCard('2026-07-08')->call('saveAchievement', $this->day->id, 'hifz', null);

    $day = $this->day->fresh();

    expect(PlanDayAttempt::pluck('grade', 'recited_on')->all())->toBe(['2026-07-07' => 0])
        ->and($day->hifz_achievement)->toBe(0)
        ->and($day->hifz_graded_at->toDateTimeString())->toBe('2026-07-07 09:00:00');
});

it('never grades a session after today, whatever date the browser sends', function () {
    sessionCard('2026-07-20')->call('saveAchievement', $this->day->id, 'hifz', 2);
    sessionCard('not-a-date')->call('saveAchievement', $this->day->id, 'hifz', 3);

    expect(PlanDayAttempt::pluck('grade', 'recited_on')->all())->toBe(['2026-07-08' => 3]);
});

it('keeps the range the app recorded for a session when the web regrades it', function () {
    PlanDayAttempt::create([
        'student_plan_day_id' => $this->day->id, 'part' => 'hifz', 'recited_on' => '2026-07-08',
        'grade' => 2, 'recited_from_ayah_id' => 1, 'recited_to_ayah_id' => 6, 'graded_at' => now(),
    ]);

    sessionCard('2026-07-08')->call('saveAchievement', $this->day->id, 'hifz', 3);

    $session = PlanDayAttempt::sole();

    expect($session->grade)->toBe(3)
        ->and($session->recitedAyahIds())->toBe([1, 6])
        ->and($this->day->fresh()->hifz_recited_to_ayah_id)->toBe(6);
});

it('gives the card every session of a part to show beside the picked one', function () {
    sessionCard('2026-07-07')->call('saveAchievement', $this->day->id, 'hifz', 0);
    sessionCard('2026-07-08')->call('saveAchievement', $this->day->id, 'hifz', 3);

    $days = TasmeehPayload::quranDays(StudentPlanDay::with('attempts.recitedFromAyah.surah', 'attempts.recitedToAyah.surah')->get());

    expect($days[0]['hifz']['sessions'])->toBe([
        ['date' => '2026-07-07', 'grade' => 0, 'recited_range' => null],
        ['date' => '2026-07-08', 'grade' => 3, 'recited_range' => null],
    ])
        ->and($days[0]['hifz']['achievement'])->toBe(3);
});

it('picks the academy\'s day for grading, not the server\'s', function () {
    Carbon::setTestNow('2026-07-07 22:00:00'); // 01:00 on the 8th in Riyadh.

    expect(Livewire::test('teacher.⚡tasmeeh-manager')->get('gradedAtDate'))->toBe('2026-07-08');
});

/**
 * Three days of the plan, the first two graded excellent: the first on the
 * 6th, the second today — a student behind the calendar.
 *
 * @return array{0: StudentPlanDay, 1: StudentPlanDay, 2: StudentPlanDay}
 */
function gradedBehind(): array
{
    $make = fn (string $date, int $from) => StudentPlanDay::create([
        'student_plan_id' => test()->plan->id, 'date' => $date, 'day_name' => 'يوم',
        'from_ayah_id' => $from, 'to_ayah_id' => $from + 1,
    ]);

    $first = test()->day;
    $second = $make('2026-07-08', 3);
    $third = $make('2026-07-09', 5);

    PlanDayAttempts::record($first, 'hifz', '2026-07-06', '2026-07-08', 3, null, test()->teacher->id);
    PlanDayAttempts::record($second, 'hifz', '2026-07-08', '2026-07-08', 3, null, test()->teacher->id);

    return [$first, $second, $third];
}

it('opens on the day graded on the date picked, wherever it was graded', function () {
    [$first, $second, $third] = gradedBehind();

    // The oldest day still owed is the third; the one graded today is the second.
    sessionCard('2026-07-08')->assertViewHas('defaultDayId', $second->id);
    sessionCard('2026-07-06')->assertViewHas('defaultDayId', $first->id);
    sessionCard('2026-07-07')->assertViewHas('defaultDayId', $third->id);
});

it('colours a student graded today on an earlier day of the plan as graded', function () {
    // The plan's day for today is left; its first day is graded today instead.
    StudentPlanDay::create([
        'student_plan_id' => $this->plan->id, 'date' => '2026-07-08', 'day_name' => 'الأربعاء',
        'from_ayah_id' => 5, 'to_ayah_id' => 6,
    ]);
    $colour = fn () => Livewire::test('teacher.⚡tasmeeh-manager')->viewData('studentsWithPlansPresent')
        ->firstWhere('id', $this->student->id)->tasmeeh_color;

    expect($colour())->toBe('rose');

    PlanDayAttempts::record($this->day, 'hifz', '2026-07-08', '2026-07-08', 3, null, $this->teacher->id);

    expect($colour())->toBe('emerald');
});
