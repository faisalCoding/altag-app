<?php

use App\Models\Manager;
use App\Models\Student;
use App\Models\Task;
use App\Models\Teacher;
use Livewire\Livewire;

beforeEach(function () {
    $this->manager = Manager::factory()->create();
    $this->actingAs($this->manager, 'manager');

    $this->task = Task::create([
        'title' => 'مهمة',
        'status' => 'pending',
        'created_by_id' => $this->manager->id,
        'created_by_type' => Manager::class,
    ]);
});

it('hands a task to a teacher the picker lists', function () {
    $teacher = Teacher::factory()->create();

    Livewire::test('manager.tasks-manager')
        ->call('updateTaskAssignee', $this->task->id, Teacher::class, $teacher->id);

    expect($this->task->fresh())
        ->assigned_to_type->toBe(Teacher::class)
        ->assigned_to_id->toBe($teacher->id);
});

it('refuses an assignee type the picker never offers', function (string $type) {
    $student = Student::factory()->create();

    Livewire::test('manager.tasks-manager')
        ->call('updateTaskAssignee', $this->task->id, $type, $student->id);

    expect($this->task->fresh()->assigned_to_type)->toBeNull();
})->with([
    'a student' => [Student::class],
    'any class at all' => ['Illuminate\\Support\\Facades\\Artisan'],
]);
