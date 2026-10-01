<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Manager;
use App\Models\Supervisor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->supervisor = Supervisor::factory()->create();
    $this->actingAs($this->supervisor, 'supervisor');

    $this->period = fn ($creator) => AcademicCalendarEvent::create([
        'event_name' => 'فترة دوام الحلقات',
        'start_date' => '2026-09-01',
        'end_date' => '2026-12-30',
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5],
        'created_by_id' => $creator->id,
        'created_by_type' => $creator::class,
        'is_visible' => true,
    ]);
});

it('lets a supervisor remove a period they set', function () {
    $own = ($this->period)($this->supervisor);

    Livewire::test('supervisor.academic-calendar')->call('deletePeriod', $own->id);

    expect(AcademicCalendarEvent::whereKey($own->id)->exists())->toBeFalse();
});

it('keeps the manager\'s attendance periods out of a supervisor\'s reach', function (string $action) {
    $managers = ($this->period)(Manager::factory()->create());

    expect(fn () => Livewire::test('supervisor.academic-calendar')->call($action, $managers->id))
        ->toThrow(ModelNotFoundException::class);

    expect(AcademicCalendarEvent::whereKey($managers->id)->exists())->toBeTrue();
})->with(['deletePeriod', 'deleteEvent', 'editEvent']);
