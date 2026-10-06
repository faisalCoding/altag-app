<?php

use App\Models\Circle;
use App\Models\PlanDayAttempt;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

/**
 * @return array{0: Teacher, 1: Student, 2: StudentPlanDay}
 */
function makeGradedHifzDay(): array
{
    $teacher = Teacher::factory()->create();
    $circle = Circle::factory()->create();
    $circle->teachers()->attach($teacher->id);
    $student = Student::factory()->create(['circle_id' => $circle->id]);

    $plan = StudentPlan::create([
        'student_id' => $student->id,
        'teacher_id' => $teacher->id,
        'start_date' => '2026-07-01',
        'days_count' => 10,
        'active_days' => ['Sunday', 'Monday'],
        'plan_type' => 'hifz_review',
        'status' => 'active',
    ]);

    $day = StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => '2026-07-05',
        'day_name' => 'الأحد',
        'hifz_achievement' => 3,
        'hifz_graded_at' => '2026-07-08 09:30:00',
    ]);

    return [$teacher, $student, $day];
}

it('lets the owning teacher move a grading date while keeping the time of day', function () {
    [$teacher, $student, $day] = makeGradedHifzDay();

    $this->actingAs($teacher, 'teacher');

    Livewire::test('teacher.student-recitation-log', ['studentId' => $student->id])
        ->set('editKey', "quran:{$day->id}:hifz")
        ->set('editDate', '2026-07-05')
        ->call('saveGradingDate')
        ->assertHasNoErrors();

    $fresh = $day->fresh();

    expect($fresh->hifz_graded_at->format('Y-m-d'))->toBe('2026-07-05')   // date moved
        ->and($fresh->hifz_graded_at->format('H:i:s'))->toBe('09:30:00'); // original time kept
});

/**
 * The academy reads dates by the Hijri calendar, and the app locale is "en",
 * so a plain translatedFormat('l') rendered the weekday in English.
 */
it('heads each log day with the Arabic weekday and the Hijri date', function () {
    [$teacher, $student, $day] = makeGradedHifzDay();

    $this->actingAs($teacher, 'teacher');

    Livewire::test('teacher.student-recitation-log', ['studentId' => $student->id])
        ->assertSee('الأربعاء')          // 2026-07-08 in Arabic
        ->assertSee('٢٣ محرم ١٤٤٨')      // the same day, Umm al-Qura
        ->assertSee('2026-07-08')        // Gregorian kept for cross-reference
        ->assertDontSee('Wednesday');
});

/**
 * The log groups by grading date, so the plan's own day has to be stated on
 * each rating — otherwise a grading moved to another day silently reads as if
 * the plan had scheduled it then.
 */
it('states the plan day in Hijri on each rating and flags a moved grading', function () {
    [$teacher, $student, $day] = makeGradedHifzDay(); // planned 2026-07-05, graded 2026-07-08

    $this->actingAs($teacher, 'teacher');

    Livewire::test('teacher.student-recitation-log', ['studentId' => $student->id])
        ->assertSee('يوم الخطة:')
        ->assertSee('الأحد')             // 2026-07-05, the planned day
        ->assertSee('٢٠ محرم ١٤٤٨')      // the same day, Umm al-Qura
        ->assertSee('قُيّم في يوم آخر');  // planned and graded days differ
});

it('does not flag a rating graded on its own plan day', function () {
    [$teacher, $student, $day] = makeGradedHifzDay();

    $day->update(['hifz_graded_at' => '2026-07-05 09:30:00']); // same day as the plan

    $this->actingAs($teacher, 'teacher');

    Livewire::test('teacher.student-recitation-log', ['studentId' => $student->id])
        ->assertSee('يوم الخطة:')
        ->assertDontSee('قُيّم في يوم آخر');
});

it('moves a grading back onto its plan day in one click', function () {
    [$teacher, $student, $day] = makeGradedHifzDay(); // planned 2026-07-05, graded 2026-07-08 09:30

    $this->actingAs($teacher, 'teacher');

    Livewire::test('teacher.student-recitation-log', ['studentId' => $student->id])
        ->assertSee('إرجاعه ليوم الخطة')
        ->call('matchPlanDate', "quran:{$day->id}:hifz")
        ->assertHasNoErrors();

    $fresh = $day->fresh();

    expect($fresh->hifz_graded_at->format('Y-m-d'))->toBe('2026-07-05')   // now on the plan day
        ->and($fresh->hifz_graded_at->format('H:i:s'))->toBe('09:30:00'); // original time kept
});

it('offers no plan-day shortcut once the grading already sits on it', function () {
    [$teacher, $student, $day] = makeGradedHifzDay();

    $day->update(['hifz_graded_at' => '2026-07-05 09:30:00']);

    $this->actingAs($teacher, 'teacher');

    Livewire::test('teacher.student-recitation-log', ['studentId' => $student->id])
        ->assertDontSee('إرجاعه ليوم الخطة');
});

it('forbids moving a grading to the plan day for a student not in the teacher circle', function () {
    [$owner, $student, $day] = makeGradedHifzDay();
    $intruder = Teacher::factory()->create();

    $this->actingAs($intruder, 'teacher');

    try {
        Livewire::test('teacher.student-recitation-log', ['studentId' => $student->id])
            ->call('matchPlanDate', "quran:{$day->id}:hifz");
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }

    expect($day->fresh()->hifz_graded_at->format('Y-m-d'))->toBe('2026-07-08');
});

it('falls back to the group label for undated entries', function () {
    [$teacher, $student, $day] = makeGradedHifzDay();

    $this->actingAs($teacher, 'teacher');

    $component = Livewire::test('teacher.student-recitation-log', ['studentId' => $student->id]);

    // The undated bucket is keyed by a label, not a date, so parsing must not throw.
    expect($component->instance()->formatLogDate('غير مؤرّخ'))->toBeNull();
});

it('validates that a grading date is required', function () {
    [$teacher, $student, $day] = makeGradedHifzDay();

    $this->actingAs($teacher, 'teacher');

    Livewire::test('teacher.student-recitation-log', ['studentId' => $student->id])
        ->set('editKey', "quran:{$day->id}:hifz")
        ->set('editDate', '')
        ->call('saveGradingDate')
        ->assertHasErrors('editDate');

    expect($day->fresh()->hifz_graded_at->format('Y-m-d'))->toBe('2026-07-08');
});

it('forbids a teacher who does not own the student from editing the grading date', function () {
    [$owner, $student, $day] = makeGradedHifzDay();
    $intruder = Teacher::factory()->create(); // not attached to the student's circle

    $this->actingAs($intruder, 'teacher');

    try {
        Livewire::test('teacher.student-recitation-log', ['studentId' => $student->id])
            ->set('editKey', "quran:{$day->id}:hifz")
            ->set('editDate', '2026-07-05')
            ->call('saveGradingDate');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }

    // Whatever the surfaced status, the grading date must stay untouched.
    expect($day->fresh()->hifz_graded_at->format('Y-m-d'))->toBe('2026-07-08');
});

it('moves the session a Quran grade stands for, so the teacher app sees the same day', function () {
    [$teacher, $student, $day] = makeGradedHifzDay();

    $this->actingAs($teacher, 'teacher');

    Livewire::test('teacher.student-recitation-log', ['studentId' => $student->id])
        ->set('editKey', "quran:{$day->id}:hifz")
        ->set('editDate', '2026-07-05')
        ->call('saveGradingDate');

    expect(PlanDayAttempt::where('student_plan_day_id', $day->id)->pluck('recited_on')->all())->toBe(['2026-07-05'])
        ->and($day->fresh()->hifz_graded_at->format('Y-m-d H:i:s'))->toBe('2026-07-05 09:30:00');
});

it('refuses to move a Quran grade onto a day the portion has another session on', function () {
    [$teacher, $student, $day] = makeGradedHifzDay();

    // «لم يسمع» on the 6th, then the grade on the 8th.
    PlanDayAttempt::create(['student_plan_day_id' => $day->id, 'part' => 'hifz', 'recited_on' => '2026-07-06', 'grade' => 0, 'graded_at' => '2026-07-06 09:00:00']);
    PlanDayAttempt::create(['student_plan_day_id' => $day->id, 'part' => 'hifz', 'recited_on' => '2026-07-08', 'grade' => 3, 'graded_at' => '2026-07-08 09:30:00']);

    $this->actingAs($teacher, 'teacher');

    Livewire::test('teacher.student-recitation-log', ['studentId' => $student->id])
        ->set('editKey', "quran:{$day->id}:hifz")
        ->set('editDate', '2026-07-06')
        ->call('saveGradingDate');

    expect(PlanDayAttempt::where('student_plan_day_id', $day->id)->orderBy('recited_on')->pluck('grade', 'recited_on')->all())
        ->toBe(['2026-07-06' => 0, '2026-07-08' => 3])
        ->and($day->fresh()->hifz_graded_at->format('Y-m-d'))->toBe('2026-07-08');
});
