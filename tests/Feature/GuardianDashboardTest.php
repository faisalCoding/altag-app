<?php

use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Guardian;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-06-10 10:00:00'); // Wednesday; week (Sat start) began 2026-06-06

    $this->stage = Stage::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $this->teacher = Teacher::factory()->create();
    $this->guardian = Guardian::factory()->create(['is_approved' => true]);

    $this->child = Student::factory()->create([
        'name' => 'الابن الأول',
        'guardian_id' => $this->guardian->id,
        'circle_id' => $this->circle->id,
    ]);

    $this->otherChild = Student::factory()->create([
        'name' => 'الابن الثاني',
        'guardian_id' => $this->guardian->id,
        'circle_id' => $this->circle->id,
    ]);
});

it('renders the guardian dashboard with all children listed', function () {
    $this->actingAs($this->guardian, 'guardian');

    $this->get(route('guardian.dashboard'))
        ->assertSuccessful()
        ->assertSee('لوحة تحكم ولي الأمر')
        ->assertSee('عدد الأبناء')
        ->assertSee('الابن الأول')
        ->assertSee('الابن الثاني');
});

it('shows the children before the notifications', function () {
    $this->actingAs($this->guardian, 'guardian');

    $this->get(route('guardian.dashboard'))
        ->assertSuccessful()
        ->assertSeeInOrder(['بيانات الأبناء', 'الابن الأول', 'آخر التنبيهات']);
});

it('renders the create-challenge page via the single-file component', function () {
    $this->actingAs($this->guardian, 'guardian');

    // Regression: a stale class component used to shadow the SFC and 500 with a
    // missing view. The page must resolve to the SFC and render successfully.
    $this->get(route('guardian.student.challenge.create', $this->child->id))
        ->assertSuccessful();
});

it('keeps a guardian\'s challenge on their own child', function () {
    $this->actingAs($this->guardian, 'guardian');
    $stranger = Student::factory()->create(['circle_id' => $this->circle->id]);

    Livewire::test('guardian.create-challenge', ['studentId' => $this->child->id])
        ->set('studentId', $stranger->id);
})->throws(CannotUpdateLockedPropertyException::class);

it('shows the latest hifz score and this week attendance for a child', function () {
    // Two attendance records this week: one present (today), one absent (yesterday).
    Attendance::create([
        'student_id' => $this->child->id,
        'circle_id' => $this->circle->id,
        'teacher_id' => $this->teacher->id,
        'date' => today(),
        'status' => 'present',
    ]);
    Attendance::create([
        'student_id' => $this->child->id,
        'circle_id' => $this->circle->id,
        'teacher_id' => $this->teacher->id,
        'date' => today()->subDay(),
        'status' => 'absent',
    ]);

    // A scored plan day → last evaluation should read "ممتاز".
    $plan = StudentPlan::create([
        'student_id' => $this->child->id,
        'plan_type' => 'hifz',
        'start_date' => now()->subDays(5),
        'is_approved' => true,
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => today(),
        'day_name' => 'الأربعاء',
        'hifz_achievement' => 3,
        'hifz_graded_at' => now(),
    ]);

    $this->actingAs($this->guardian, 'guardian');

    $this->get(route('guardian.dashboard'))
        ->assertSuccessful()
        ->assertSee('ممتاز')
        ->assertSee('1/2 أيام');
});

it('only exposes the authenticated guardian\'s own children', function () {
    $otherGuardian = Guardian::factory()->create(['is_approved' => true]);
    $foreignChild = Student::factory()->create([
        'name' => 'ابن ولي أمر آخر',
        'guardian_id' => $otherGuardian->id,
        'circle_id' => $this->circle->id,
    ]);

    $this->actingAs($this->guardian, 'guardian');

    $this->get(route('guardian.dashboard'))
        ->assertSuccessful()
        ->assertSee('الابن الأول')
        ->assertDontSee('ابن ولي أمر آخر');
});

it('batches child queries instead of running them per child (no N+1)', function () {
    // Add more children; query count must stay flat-ish, not scale per child for
    // the today-plan / last-scored / attendance lookups.
    Student::factory()->count(3)->create([
        'guardian_id' => $this->guardian->id,
        'circle_id' => $this->circle->id,
    ]);

    $this->actingAs($this->guardian, 'guardian');

    DB::enableQueryLog();

    Livewire::test('guardian.dashboard')->assertOk();

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // 5 children. The old per-child approach issued ~9 queries/child (~45+).
    expect($queryCount)->toBeLessThan(30);
});
