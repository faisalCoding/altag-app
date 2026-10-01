<?php

use App\Models\Circle;
use App\Models\Guardian;
use App\Models\Manager;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Support\StudentStatus;
use Livewire\Livewire;

it('defaults to the students tab on the students route', function () {
    $manager = Manager::factory()->create();
    $this->actingAs($manager, 'manager');

    $this->get(route('manager.students'))
        ->assertSuccessful()
        ->assertSee('الطلاب')
        ->assertSee('المعلمون');
});

it('defaults to the teachers tab on the teachers route', function () {
    $manager = Manager::factory()->create();
    $teacher = Teacher::factory()->create(['is_approved' => true]);
    $this->actingAs($manager, 'manager');

    $this->get(route('manager.teachers'))
        ->assertSuccessful()
        ->assertSee($teacher->name);
});

it('defaults to the supervisors tab on the supervisors route', function () {
    $manager = Manager::factory()->create();
    $supervisor = Supervisor::factory()->create(['is_approved' => true]);
    $this->actingAs($manager, 'manager');

    $this->get(route('manager.supervisors'))
        ->assertSuccessful()
        ->assertSee($supervisor->name);
});

it('defaults to the guardians tab on the guardians route', function () {
    $manager = Manager::factory()->create();
    $guardian = Guardian::factory()->create(['is_approved' => true]);
    $this->actingAs($manager, 'manager');

    $this->get(route('manager.guardians'))
        ->assertSuccessful()
        ->assertSee($guardian->name);
});

it('switches tabs within the same page and shows the other role data', function () {
    $manager = Manager::factory()->create();
    $teacher = Teacher::factory()->create(['is_approved' => true]);
    $this->actingAs($manager, 'manager');

    Livewire::test('manager.user-directory', ['initialTab' => 'students'])
        ->assertSet('activeTab', 'students')
        ->call('setTab', 'teachers')
        ->assertSet('activeTab', 'teachers')
        ->assertSee($teacher->name);
});

it('ignores an unknown initial tab and falls back to students', function () {
    Livewire::test('manager.user-directory', ['initialTab' => 'not-a-real-tab'])
        ->assertSet('activeTab', 'students');
});

it('colours each student standing in the directory, the same way every page does', function (string $status, string $color) {
    $manager = Manager::factory()->create();
    $circle = Circle::factory()->create();
    Student::factory()->create(['circle_id' => $circle->id, 'status' => $status]);
    $this->actingAs($manager, 'manager');

    expect(StudentStatus::color($status))->toBe($color);

    $this->get(route('manager.students'))
        ->assertSuccessful()
        ->assertSee(StudentStatus::label($status))
        ->assertSee("text-{$color}-", false);
})->with([
    'participating' => ['active', 'green'],
    'registering' => ['registering', 'blue'],
    'suspended' => ['suspended', 'amber'],
    'left' => ['left', 'red'],
]);
