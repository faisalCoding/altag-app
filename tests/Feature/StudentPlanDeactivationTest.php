<?php

use App\Models\Circle;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->teacher = Teacher::factory()->create();
    $this->circle = Circle::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'status' => 'active',
        'is_approved' => true,
    ]);

    $this->plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'start_date' => now()->subDays(2),
        'days_count' => 5,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'description' => 'خطة الحفظ',
        'status' => 'active',
        'plan_type' => 'hifz',
        'direction' => 'forward',
        'is_approved' => true,
        'created_by_role' => 'teacher',
    ]);

    $this->actingAs($this->teacher, 'teacher');
});

it('lets the teacher deactivate and reactivate a plan from the plans list', function () {
    Livewire::test('teacher.⚡student-plans-list')
        ->call('togglePlanStatus', $this->plan->id);

    expect($this->plan->refresh()->status)->toBe('inactive');

    Livewire::test('teacher.⚡student-plans-list')
        ->call('togglePlanStatus', $this->plan->id);

    expect($this->plan->refresh()->status)->toBe('active');
});

it('does not let a teacher toggle plans of students outside their circles', function () {
    $otherCircle = Circle::factory()->create();
    $otherStudent = Student::factory()->create([
        'circle_id' => $otherCircle->id,
        'status' => 'active',
        'is_approved' => true,
    ]);
    $otherPlan = StudentPlan::create([
        'student_id' => $otherStudent->id,
        'teacher_id' => $this->teacher->id,
        'start_date' => now(),
        'days_count' => 5,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'status' => 'active',
        'plan_type' => 'hifz',
        'direction' => 'forward',
        'is_approved' => true,
        'created_by_role' => 'teacher',
    ]);

    expect(fn () => Livewire::test('teacher.⚡student-plans-list')->call('togglePlanStatus', $otherPlan->id))
        ->toThrow(ModelNotFoundException::class);

    expect($otherPlan->refresh()->status)->toBe('active');
});

it('hides inactive plans from the teacher tasmeeh manager', function () {
    $component = Livewire::test('teacher.⚡tasmeeh-manager');
    expect($component->viewData('studentsWithPlansPresent')->pluck('id')->all())
        ->toContain($this->student->id);

    $this->plan->update(['status' => 'inactive']);

    $component = Livewire::test('teacher.⚡tasmeeh-manager');
    expect($component->viewData('studentsWithPlansPresent')->pluck('id')->all())
        ->not->toContain($this->student->id);
    expect($component->viewData('studentsWithoutPlans')->pluck('id')->all())
        ->toContain($this->student->id);
});

it('bulk activates and deactivates the selected plans', function () {
    $secondPlan = StudentPlan::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'start_date' => now(),
        'days_count' => 3,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'description' => 'خطة المراجعة',
        'status' => 'active',
        'plan_type' => 'review',
        'direction' => 'forward',
        'is_approved' => true,
        'created_by_role' => 'teacher',
    ]);

    Livewire::test('teacher.⚡student-plans-list')
        ->set('selectedPlans', [$this->plan->id, $secondPlan->id])
        ->call('bulkDeactivate')
        ->assertSet('selectedPlans', []);

    expect($this->plan->refresh()->status)->toBe('inactive');
    expect($secondPlan->refresh()->status)->toBe('inactive');

    Livewire::test('teacher.⚡student-plans-list')
        ->set('selectedPlans', [$this->plan->id])
        ->call('bulkActivate')
        ->assertSet('selectedPlans', []);

    expect($this->plan->refresh()->status)->toBe('active');
    expect($secondPlan->refresh()->status)->toBe('inactive');
});

it('requires typing the exact confirmation word before bulk deleting plans', function () {
    Livewire::test('teacher.⚡student-plans-list')
        ->set('selectedPlans', [$this->plan->id])
        ->call('openBulkDeleteModal')
        ->assertSet('showBulkDeleteModal', true)
        ->set('bulkDeleteConfirmation', 'نعم')
        ->call('bulkDelete')
        ->assertHasErrors(['bulkDeleteConfirmation']);

    expect(StudentPlan::find($this->plan->id))->not->toBeNull();

    Livewire::test('teacher.⚡student-plans-list')
        ->set('selectedPlans', [$this->plan->id])
        ->set('bulkDeleteConfirmation', 'حذف')
        ->call('bulkDelete')
        ->assertHasNoErrors()
        ->assertSet('showBulkDeleteModal', false)
        ->assertSet('selectedPlans', []);

    expect(StudentPlan::find($this->plan->id))->toBeNull();
});

it('never bulk deletes or toggles plans of students outside the teacher circles', function () {
    $otherCircle = Circle::factory()->create();
    $otherStudent = Student::factory()->create([
        'circle_id' => $otherCircle->id,
        'status' => 'active',
        'is_approved' => true,
    ]);
    $otherPlan = StudentPlan::create([
        'student_id' => $otherStudent->id,
        'teacher_id' => $this->teacher->id,
        'start_date' => now(),
        'days_count' => 5,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'status' => 'active',
        'plan_type' => 'hifz',
        'direction' => 'forward',
        'is_approved' => true,
        'created_by_role' => 'teacher',
    ]);

    Livewire::test('teacher.⚡student-plans-list')
        ->set('selectedPlans', [$this->plan->id, $otherPlan->id])
        ->set('bulkDeleteConfirmation', 'حذف')
        ->call('bulkDelete');

    expect(StudentPlan::find($this->plan->id))->toBeNull();
    expect(StudentPlan::find($otherPlan->id))->not->toBeNull();

    Livewire::test('teacher.⚡student-plans-list')
        ->set('selectedPlans', [$otherPlan->id])
        ->call('bulkDeactivate');

    expect($otherPlan->refresh()->status)->toBe('active');
});

it('never selects an inactive plan in the student tasmeeh card', function () {
    $this->plan->update(['status' => 'inactive']);

    $sPlans = StudentPlan::where('student_id', $this->student->id)->latest()->get();

    Livewire::test('teacher.⚡student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => $sPlans,
        'activePlanId' => $this->plan->id,
    ])
        ->assertOk()
        ->assertViewHas('activePlan', null);
});

it('grades only a day of the student on the card, and only with a real grade', function () {
    $day = StudentPlanDay::create(['student_plan_id' => $this->plan->id, 'date' => now()->toDateString(), 'day_name' => 'الأحد']);

    $stranger = Student::factory()->create(['circle_id' => Circle::factory()->create()->id]);
    $strangerPlan = StudentPlan::create([
        'student_id' => $stranger->id, 'start_date' => now(), 'days_count' => 1, 'active_days' => [0],
        'description' => 'خطة غيره', 'status' => 'active', 'plan_type' => 'hifz', 'direction' => 'forward',
        'is_approved' => true, 'created_by_role' => 'teacher',
    ]);
    $strangerDay = StudentPlanDay::create(['student_plan_id' => $strangerPlan->id, 'date' => now()->toDateString(), 'day_name' => 'الأحد']);

    $card = fn () => Livewire::test('teacher.⚡student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => StudentPlan::where('student_id', $this->student->id)->get(),
        'activePlanId' => $this->plan->id,
    ]);

    $card()->call('saveAchievement', $strangerDay->id, 'hifz', 3)->assertNotFound();
    $card()->call('saveAchievement', $day->id, 'hifz', 99)->assertStatus(422);
    $card()->call('saveAchievement', $day->id, 'hifz', 3)->assertSuccessful();

    expect($strangerDay->fresh()->hifz_achievement)->toBeNull()
        ->and($day->fresh()->hifz_achievement)->toBe(3);
});

it('opens for editing only a plan of a student the user may plan for', function () {
    $stranger = Student::factory()->create(['circle_id' => Circle::factory()->create()->id]);
    $strangersPlan = StudentPlan::create([
        'student_id' => $stranger->id, 'start_date' => now(), 'days_count' => 1, 'active_days' => [0],
        'description' => 'خطة طالب آخر', 'status' => 'active', 'plan_type' => 'hifz', 'direction' => 'forward',
        'is_approved' => true, 'created_by_role' => 'student',
    ]);

    // A student naming another student's plan in ?edit=.
    $this->actingAs($this->student, 'student');
    expect(fn () => Livewire::withQueryParams(['edit' => $strangersPlan->id])->test('shared.plan-creator'))
        ->toThrow(ModelNotFoundException::class);

    // …and trying to pass themselves off as a teacher.
    expect(fn () => Livewire::withQueryParams([])->test('shared.plan-creator')->set('userLevel', 'teacher'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('moves a plan only to one of the teacher\'s own students', function () {
    $stranger = Student::factory()->create(['circle_id' => Circle::factory()->create()->id]);

    Livewire::test('teacher.⚡student-plans-list')
        ->call('openStudentModal', $this->plan->id, 'change')
        ->set('selectedNewStudentId', $stranger->id)
        ->call('executeStudentAction')
        ->assertHasErrors('selectedNewStudentId');

    expect($this->plan->fresh()->student_id)->toBe($this->student->id);
});
