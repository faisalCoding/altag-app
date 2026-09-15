<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Manager;
use App\Models\Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->keep = Stage::factory()->create(['name' => 'ثانوية']);
    $this->doomed = Stage::factory()->create(['name' => 'مرحلة ستُحذف']);

    $this->period = AcademicCalendarEvent::create([
        'event_name' => 'الفصل الأول',
        'start_date' => '2026-09-01',
        'end_date' => '2026-12-01',
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3],
        'stage_ids' => [$this->keep->id, $this->doomed->id],
    ]);

    $this->actingAs(Manager::factory()->create(), 'manager');
});

it('forgets a stage when it is deleted', function () {
    $this->doomed->delete();

    expect($this->period->fresh()->stage_ids)->toBe([$this->keep->id]);
});

it('drops a dangling id when the period is opened', function () {
    // Simulate a stage removed without the cleanup, as a migration once did.
    Stage::withoutEvents(fn () => $this->doomed->delete());

    expect($this->period->fresh()->stage_ids)->toContain($this->doomed->id);

    $component = Livewire::test('manager.⚡academic-calendar')
        ->call('editPeriod', $this->period->id);

    expect($component->get('periodStageIds'))->toBe([(string) $this->keep->id]);
});

it('saves a period that was pointing at a deleted stage', function () {
    Stage::withoutEvents(fn () => $this->doomed->delete());

    // Before, the dangling id loaded into the form and failed `exists` on save,
    // leaving the period uneditable over a stage it could not even display.
    Livewire::test('manager.⚡academic-calendar')
        ->call('editPeriod', $this->period->id)
        ->call('saveAttendancePeriod')
        ->assertHasNoErrors();

    expect($this->period->fresh()->stage_ids)->toBe([$this->keep->id]);
});

it('still refuses a stage id that never existed', function () {
    Livewire::test('manager.⚡academic-calendar')
        ->call('editPeriod', $this->period->id)
        ->set('periodStageIds', ['999999'])
        ->call('saveAttendancePeriod')
        ->assertHasErrors('periodStageIds.0');
});

it('keeps a period academy-wide when every stage it named is gone', function () {
    $this->period->update(['stage_ids' => [$this->doomed->id]]);

    $this->doomed->delete();

    expect($this->period->fresh()->stage_ids)->toBe([]);
});
