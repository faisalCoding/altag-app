<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Teacher;
use App\Services\StudentStatusService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Freeze "today" so the working-day roll is deterministic.
    $this->travelTo(Carbon::parse('2026-07-09 12:00:00'));

    $teacher = Teacher::factory()->create();
    $circle = Circle::factory()->create();
    $circle->teachers()->attach($teacher->id);
    $this->student = Student::factory()->create(['circle_id' => $circle->id]);
    $this->teacher = $teacher;
    $this->circle = $circle;
    $this->actingAs($teacher, 'teacher');

    $plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'teacher_id' => $teacher->id,
        'start_date' => '2026-07-05',
        'days_count' => 10,
        'active_days' => ['Sunday', 'Monday'],
        'plan_type' => 'hifz_review',
        'status' => 'active',
    ]);

    // One recited day (2026-07-06), graded ممتاز.
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => '2026-07-06',
        'day_name' => 'الإثنين',
        'hifz_achievement' => 3,
        'hifz_graded_at' => '2026-07-06 09:00:00',
    ]);

    // Attendance: present the day he recited, absent a day he did not.
    Attendance::create(['student_id' => $this->student->id, 'teacher_id' => $teacher->id, 'circle_id' => $circle->id, 'date' => '2026-07-06', 'status' => 'present']);
    Attendance::create(['student_id' => $this->student->id, 'teacher_id' => $teacher->id, 'circle_id' => $circle->id, 'date' => '2026-07-07', 'status' => 'absent']);
});

it('attaches attendance to each day and surfaces a day with no tasmee3', function () {
    Livewire::test('teacher.student-recitation-log', ['studentId' => $this->student->id])
        ->assertViewHas('days', function ($days) {
            $byDate = $days->keyBy('date');
            $recited = $byDate->get('2026-07-06');
            $absent = $byDate->get('2026-07-07');

            return $recited && $recited['attendance'] === 'present' && $recited['entries']->count() === 1
                && $absent && $absent['attendance'] === 'absent' && $absent['entries']->isEmpty();
        });
});

it('counts attendance statuses in the summary', function () {
    Livewire::test('teacher.student-recitation-log', ['studentId' => $this->student->id])
        ->assertViewHas('stats', fn ($stats) => $stats['attendance']['present'] === 1
            && $stats['attendance']['absent'] === 1
            && $stats['attendance']['late'] === 0
            && $stats['attendance']['excused'] === 0);
});

it('renders the no-tasmee3 marker and the absence on an empty day', function () {
    Livewire::test('teacher.student-recitation-log', ['studentId' => $this->student->id])
        ->assertSee('لا تسميع')
        ->assertSee('غائب');
});

it('keeps every day visible when a quality filter is active, narrowing only the gradings', function () {
    Livewire::test('teacher.student-recitation-log', ['studentId' => $this->student->id])
        ->call('toggleQuality', 3)
        ->assertViewHas('days', function ($days) {
            $byDate = $days->keyBy('date');

            return $byDate->has('2026-07-07')                              // empty day still visible under a filter
                && $byDate->get('2026-07-07')['entries']->isEmpty()
                && $byDate->get('2026-07-06')['entries']->count() === 1;   // only the matching grading shows
        });
});

it('excludes working days on which the student was not active (مشارك)', function () {
    // A calendar period that makes every day in the window a working day.
    $stageId = $this->student->fresh()->effective_stage_id;
    AcademicCalendarEvent::create([
        'event_name' => 'الفصل الدراسي',
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'is_attendance_period' => true,
        'weekdays' => [],
        'stage_ids' => $stageId ? [$stageId] : [],
    ]);
    AcademicCalendarEvent::forgetPeriodCache();

    // The student leaves on 2026-07-08, so 07-08 and 07-09 are no longer his days.
    StudentStatusService::changeStatus($this->student, 'left', '2026-07-08');

    Livewire::test('teacher.student-recitation-log', ['studentId' => $this->student->id])
        ->assertViewHas('days', function ($days) {
            $dates = $days->pluck('date');

            return $dates->contains('2026-07-05')   // active working day, shown
                && $dates->contains('2026-07-07')    // active, has attendance
                && ! $dates->contains('2026-07-08')  // left — excluded
                && ! $dates->contains('2026-07-09'); // left — excluded
        });
});
