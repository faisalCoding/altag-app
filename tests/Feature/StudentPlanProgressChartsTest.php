<?php

use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('computes the completion percentage from graded days out of days_count', function () {
    $student = Student::factory()->create(['is_approved' => true]);

    $plan = StudentPlan::create([
        'student_id' => $student->id,
        'plan_type' => 'hifz',
        'start_date' => now()->subDays(5),
        'is_approved' => true,
        'days_count' => 4,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);

    StudentPlanDay::create(['student_plan_id' => $plan->id, 'date' => today(), 'day_name' => 'a', 'hifz_achievement' => 3]);
    StudentPlanDay::create(['student_plan_id' => $plan->id, 'date' => today()->addDay(), 'day_name' => 'b', 'review_achievement' => 2]);
    StudentPlanDay::create(['student_plan_id' => $plan->id, 'date' => today()->addDays(2), 'day_name' => 'c']);

    // 2 of 4 scheduled days have a rating (the third has none, and a fourth was never even created).
    expect($plan->completionPercentage())->toBe(50.0);
});

it('returns zero completion for a plan with no scheduled days', function () {
    $student = Student::factory()->create(['is_approved' => true]);

    $plan = StudentPlan::create([
        'student_id' => $student->id,
        'plan_type' => 'hifz',
        'start_date' => now(),
        'is_approved' => true,
        'days_count' => 0,
        'active_days' => [],
    ]);

    expect($plan->completionPercentage())->toBe(0.0);
});

it('buckets every recorded hifz and review rating into the achievement distribution', function () {
    $student = Student::factory()->create(['is_approved' => true]);

    $plan = StudentPlan::create([
        'student_id' => $student->id,
        'plan_type' => 'hifz_review',
        'start_date' => now()->subDays(5),
        'is_approved' => true,
        'days_count' => 3,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);

    // Day 1: excellent hifz + good review (contributes to both buckets).
    StudentPlanDay::create([
        'student_plan_id' => $plan->id, 'date' => today(), 'day_name' => 'a',
        'hifz_achievement' => 3, 'review_achievement' => 2,
    ]);
    // Day 2: weak hifz only.
    StudentPlanDay::create([
        'student_plan_id' => $plan->id, 'date' => today()->addDay(), 'day_name' => 'b',
        'hifz_achievement' => 1,
    ]);
    // Day 3: ungraded.
    StudentPlanDay::create(['student_plan_id' => $plan->id, 'date' => today()->addDays(2), 'day_name' => 'c']);

    expect($plan->achievementDistribution())->toBe([
        'excellent' => 1,
        'good' => 1,
        'weak' => 1,
        'not_heard' => 0,
    ]);
});

it('renders the overall and per-plan progress charts on the student plan page', function () {
    $student = Student::factory()->create(['is_approved' => true]);

    $plan = StudentPlan::create([
        'student_id' => $student->id,
        'plan_type' => 'hifz',
        'start_date' => now()->subDays(5),
        'is_approved' => true,
        'days_count' => 2,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);
    StudentPlanDay::create(['student_plan_id' => $plan->id, 'date' => today(), 'day_name' => 'a', 'hifz_achievement' => 3]);

    $this->actingAs($student, 'student')
        ->get(route('student.plan'))
        ->assertSuccessful()
        ->assertSee('إنجازك الكلي في كل خططك')
        ->assertSee('نسبة الإنجاز')
        ->assertSee('ممتاز');
});
