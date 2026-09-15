<?php

use App\Livewire\Supervisor\Students as BulkScreen;
use App\Models\Circle;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentStatusHistory;
use App\Models\Supervisor;
use App\Services\StudentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-09-15 09:00:00');

    $this->stage = Stage::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);

    $this->supervisor = Supervisor::factory()->create();
    $this->supervisor->stages()->attach($this->stage->id);

    $this->student = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'name' => 'طالب',
        'status' => 'active',
    ]);

    $this->actingAs($this->supervisor, 'supervisor');
});

it('changes a status without being given a reason', function () {
    StudentStatusService::changeStatus($this->student, 'left', '2026-09-10', null);

    expect($this->student->fresh()->status)->toBe('left');
    expect($this->student->statusHistories()->where('status', 'left')->exists())->toBeTrue();
});

it('says plainly when a bulk change moved nobody', function () {
    StudentStatusHistory::create([
        'student_id' => $this->student->id,
        'status' => 'active',
        'start_date' => '2026-09-12',
    ]);

    Livewire::test(BulkScreen::class)
        ->set('selectAll', true)
        ->set('bulkStatus', 'left')
        // Before the record already on file, which the timeline refuses.
        ->set('bulkStatusDate', '2026-09-05')
        ->call('applyBulkStatus');

    expect($this->student->fresh()->status)->toBe('active');
});

it('keeps the selection when a bulk change fails, so it can be retried', function () {
    StudentStatusHistory::create([
        'student_id' => $this->student->id,
        'status' => 'active',
        'start_date' => '2026-09-12',
    ]);

    $component = Livewire::test(BulkScreen::class)
        ->set('selectAll', true)
        ->set('bulkStatus', 'left')
        ->set('bulkStatusDate', '2026-09-05')
        ->call('applyBulkStatus');

    expect($component->get('selectedStudentIds'))->toContain((string) $this->student->id);
    expect($component->get('bulkStatusDate'))->toBe('2026-09-05');
});

it('still applies a bulk change when the date is allowed', function () {
    StudentStatusHistory::create([
        'student_id' => $this->student->id,
        'status' => 'active',
        'start_date' => '2026-09-01',
    ]);

    Livewire::test(BulkScreen::class)
        ->set('selectAll', true)
        ->set('bulkStatus', 'left')
        ->set('bulkStatusDate', '2026-09-10')
        ->call('applyBulkStatus');

    expect($this->student->fresh()->status)->toBe('left');
});

it('corrects a mistyped date on a recorded period', function () {
    $entry = StudentStatusHistory::create([
        'student_id' => $this->student->id,
        'status' => 'left',
        'start_date' => '2026-09-12',
    ]);

    StudentStatusService::editHistoryEntry($this->student, $entry->id, 'left', '2026-09-02');

    expect($entry->fresh()->start_date->format('Y-m-d'))->toBe('2026-09-02');
});

it('unblocks a backdated change once the wrong record is corrected', function () {
    $entry = StudentStatusHistory::create([
        'student_id' => $this->student->id,
        'status' => 'active',
        'start_date' => '2026-09-12',
    ]);

    // Refused while the record sits after the date being asked for.
    expect(fn () => StudentStatusService::changeStatus($this->student, 'left', '2026-09-05'))
        ->toThrow(InvalidArgumentException::class);

    StudentStatusService::editHistoryEntry($this->student, $entry->id, 'active', '2026-09-01');

    StudentStatusService::changeStatus($this->student, 'left', '2026-09-05');

    expect($this->student->fresh()->status)->toBe('left');
});

it('re-cuts the neighbouring period when one is moved', function () {
    $first = StudentStatusHistory::create([
        'student_id' => $this->student->id,
        'status' => 'active',
        'start_date' => '2026-09-01',
    ]);
    $second = StudentStatusHistory::create([
        'student_id' => $this->student->id,
        'status' => 'left',
        'start_date' => '2026-09-10',
    ]);

    StudentStatusService::editHistoryEntry($this->student, $second->id, 'left', '2026-09-06');

    // The first period must now end where the second begins, not overlap it.
    expect($first->fresh()->end_date->format('Y-m-d'))->toBe('2026-09-06');
    expect($second->fresh()->end_date)->toBeNull();
});

it('re-reads the current status from the corrected timeline', function () {
    $entry = StudentStatusHistory::create([
        'student_id' => $this->student->id,
        'status' => 'left',
        'start_date' => '2026-09-10',
    ]);
    $this->student->update(['status' => 'left']);

    StudentStatusService::editHistoryEntry($this->student, $entry->id, 'suspended', '2026-09-10');

    expect($this->student->fresh()->status)->toBe('suspended');
});

it('refuses to move a period into the future', function () {
    $entry = StudentStatusHistory::create([
        'student_id' => $this->student->id,
        'status' => 'left',
        'start_date' => '2026-09-10',
    ]);

    expect(fn () => StudentStatusService::editHistoryEntry($this->student, $entry->id, 'left', '2026-12-01'))
        ->toThrow(InvalidArgumentException::class);

    expect($entry->fresh()->start_date->format('Y-m-d'))->toBe('2026-09-10');
});

it('refuses to edit a record belonging to another student', function () {
    $other = Student::factory()->create(['circle_id' => $this->circle->id]);
    $entry = StudentStatusHistory::create([
        'student_id' => $other->id,
        'status' => 'left',
        'start_date' => '2026-09-10',
    ]);

    expect(fn () => StudentStatusService::editHistoryEntry($this->student, $entry->id, 'active', '2026-09-01'))
        ->toThrow(InvalidArgumentException::class);

    expect($entry->fresh()->status)->toBe('left');
});
