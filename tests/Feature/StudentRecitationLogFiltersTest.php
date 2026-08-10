<?php

use App\Models\Circle;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $teacher = Teacher::factory()->create();
    $circle = Circle::factory()->create();
    $circle->teachers()->attach($teacher->id);
    $this->student = Student::factory()->create(['circle_id' => $circle->id]);
    $this->actingAs($teacher, 'teacher');

    $plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'teacher_id' => $teacher->id,
        'start_date' => '2026-07-01',
        'days_count' => 10,
        'active_days' => ['Sunday', 'Monday'],
        'plan_type' => 'hifz_review',
        'status' => 'active',
    ]);

    // hifz ممتاز (3) + review جيد (2)
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => '2026-07-05',
        'day_name' => 'الأحد',
        'hifz_achievement' => 3,
        'hifz_graded_at' => '2026-07-08 09:00:00',
        'review_achievement' => 2,
        'review_graded_at' => '2026-07-08 09:05:00',
    ]);

    // hifz مقبول (1) only
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => '2026-07-06',
        'day_name' => 'الإثنين',
        'hifz_achievement' => 1,
        'hifz_graded_at' => '2026-07-09 09:00:00',
    ]);

    // review ممتاز (3) only
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => '2026-07-07',
        'day_name' => 'الثلاثاء',
        'review_achievement' => 3,
        'review_graded_at' => '2026-07-10 09:00:00',
    ]);

    // ungraded — must never surface
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => '2026-07-12',
        'day_name' => 'الأحد',
    ]);
});

it('summarises the gradings across parts and quality', function () {
    Livewire::test('teacher.student-recitation-log', ['studentId' => $this->student->id])
        ->assertViewHas('stats', fn ($stats) => $stats['total'] === 4
            && $stats['hifz'] === 2
            && $stats['review'] === 2
            && $stats['distribution'][3] === 2
            && $stats['distribution'][2] === 1
            && $stats['distribution'][1] === 1
            && $stats['distribution'][0] === 0)
        ->assertViewHas('days', fn ($days) => $days->flatMap(fn ($d) => $d['entries'])->count() === 4);
});

it('filters the list and the tiles down to review gradings', function () {
    Livewire::test('teacher.student-recitation-log', ['studentId' => $this->student->id])
        ->call('setPart', 'review')
        ->assertViewHas('stats', fn ($stats) => $stats['total'] === 2 && $stats['hifz'] === 0 && $stats['review'] === 2)
        ->assertViewHas('days', fn ($days) => $days->flatMap(fn ($d) => $d['entries'])->count() === 2
            && $days->flatMap(fn ($d) => $d['entries'])->every(fn ($entry) => $entry['part'] === 'review'));
});

it('filters the list by quality and toggles the filter off again', function () {
    Livewire::test('teacher.student-recitation-log', ['studentId' => $this->student->id])
        ->call('toggleQuality', 3)
        ->assertViewHas('days', fn ($days) => $days->flatMap(fn ($d) => $d['entries'])->count() === 2
            && $days->flatMap(fn ($d) => $d['entries'])->every(fn ($entry) => $entry['achievement'] === 3))
        ->call('toggleQuality', 3)
        ->assertViewHas('days', fn ($days) => $days->flatMap(fn ($d) => $d['entries'])->count() === 4);
});

it('keeps every day visible when a filter matches no grading', function () {
    Livewire::test('teacher.student-recitation-log', ['studentId' => $this->student->id])
        ->call('toggleQuality', 0) // no "لم يُسمع" gradings exist
        ->assertViewHas('days', fn ($days) => $days->isNotEmpty()                        // days still shown
            && $days->flatMap(fn ($d) => $d['entries'])->isEmpty())                      // but no grading matches
        ->assertSee('لا يوجد ضمن الفلتر');                                               // days that had gradings read as filtered-out
});

it('shows the empty state for a student with no gradings', function () {
    $circle = Circle::factory()->create();
    $freshStudent = Student::factory()->create(['circle_id' => $circle->id]);

    Livewire::test('teacher.student-recitation-log', ['studentId' => $freshStudent->id])
        ->assertViewHas('stats', fn ($stats) => $stats['total'] === 0)
        ->assertSee('لم يقم الطالب بأي تسميع حتى الآن.');
});
