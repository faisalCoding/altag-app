<?php

use App\Livewire\Manager\Circles as ManagerCircles;
use App\Livewire\Supervisor\Circles as SupervisorCircles;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Manager;
use App\Models\PromotionRun;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Supervisor;
use App\Services\CircleMergeService;
use App\Services\StudentPromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stage = Stage::factory()->create();
    $this->source = Circle::factory()->create(['name' => 'حلقة أ', 'stage_id' => $this->stage->id]);
    $this->target = Circle::factory()->create(['name' => 'حلقة ب', 'stage_id' => $this->stage->id]);
    $this->merges = new CircleMergeService;
});

it('moves every student of one circle into another', function () {
    $students = Student::factory()->count(3)->create(['circle_id' => $this->source->id]);
    $bystander = Student::factory()->create(['circle_id' => $this->target->id]);

    $this->merges->merge($this->source, $this->target);

    foreach ($students as $student) {
        expect($student->fresh()->circle_id)->toBe($this->target->id);
    }

    expect($bystander->fresh()->circle_id)->toBe($this->target->id);
    expect($this->source->students()->count())->toBe(0);
});

it('leaves the emptied circle standing, with its register intact', function () {
    Student::factory()->create(['circle_id' => $this->source->id]);
    $attendee = Student::factory()->create(['circle_id' => $this->source->id]);
    foreach (['2026-09-01', '2026-09-02'] as $date) {
        Attendance::create([
            'student_id' => $attendee->id,
            'circle_id' => $this->source->id,
            'date' => $date,
            'status' => 'present',
        ]);
    }

    $this->merges->merge($this->source, $this->target);

    expect(Circle::find($this->source->id))->not->toBeNull();
    expect($this->source->attendances()->count())->toBe(2);
});

it('records the merge so it can be undone', function () {
    $student = Student::factory()->create(['circle_id' => $this->source->id]);

    $run = $this->merges->merge($this->source, $this->target);

    expect($run->type)->toBe(PromotionRun::MERGE);
    expect($run->status)->toBe(PromotionRun::APPLIED);

    (new StudentPromotionService)->revert($run->fresh());

    expect($student->fresh()->circle_id)->toBe($this->source->id);
});

it('refuses to merge a circle into itself', function () {
    expect(fn () => $this->merges->merge($this->source, $this->source))->toThrow(RuntimeException::class);
});

it('refuses a circle outside the caller\'s scope', function () {
    $outsider = Circle::factory()->create(['stage_id' => Stage::factory()->create()->id]);
    Student::factory()->create(['circle_id' => $this->source->id]);

    expect(fn () => $this->merges->merge($this->source, $outsider, null, [$this->source->id, $this->target->id]))
        ->toThrow(RuntimeException::class);

    expect($this->source->students()->count())->toBe(1);
});

it('merges an empty circle without complaint', function () {
    $run = $this->merges->merge($this->source, $this->target);

    expect($run->items()->count())->toBe(0);
});

it('lets a manager merge any two circles from the screen', function () {
    $student = Student::factory()->create(['circle_id' => $this->source->id]);
    $elsewhere = Circle::factory()->create(['stage_id' => Stage::factory()->create()->id]);

    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(ManagerCircles::class)
        ->call('openMerge', $this->source->id)
        ->set('mergeTargetId', $elsewhere->id)
        ->call('mergeCircles')
        ->assertHasNoErrors();

    expect($student->fresh()->circle_id)->toBe($elsewhere->id);
});

it('keeps a supervisor inside their own stages', function () {
    $supervisor = Supervisor::factory()->create();
    $supervisor->stages()->attach($this->stage->id);

    $otherStage = Stage::factory()->create();
    $forbidden = Circle::factory()->create(['stage_id' => $otherStage->id]);
    $student = Student::factory()->create(['circle_id' => $this->source->id]);

    $this->actingAs($supervisor, 'supervisor');

    Livewire::test(SupervisorCircles::class)
        ->call('openMerge', $this->source->id)
        ->set('mergeTargetId', $forbidden->id)
        ->call('mergeCircles');

    expect($student->fresh()->circle_id)->toBe($this->source->id);
});

it('lets a supervisor merge within their own stage', function () {
    $supervisor = Supervisor::factory()->create();
    $supervisor->stages()->attach($this->stage->id);

    $student = Student::factory()->create(['circle_id' => $this->source->id]);

    $this->actingAs($supervisor, 'supervisor');

    Livewire::test(SupervisorCircles::class)
        ->call('openMerge', $this->source->id)
        ->set('mergeTargetId', $this->target->id)
        ->call('mergeCircles')
        ->assertHasNoErrors();

    expect($student->fresh()->circle_id)->toBe($this->target->id);
});

it('will not delete a circle that still holds a register', function () {
    $attendee = Student::factory()->create(['circle_id' => $this->source->id]);
    Attendance::create([
        'student_id' => $attendee->id,
        'circle_id' => $this->source->id,
        'date' => '2026-09-01',
        'status' => 'present',
    ]);
    $attendee->update(['circle_id' => $this->target->id]);

    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(ManagerCircles::class)->call('delete', $this->source->id);

    expect(Circle::find($this->source->id))->not->toBeNull();
});

it('still deletes a circle with nothing behind it', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(ManagerCircles::class)->call('delete', $this->target->id);

    expect(Circle::find($this->target->id))->toBeNull();
});
