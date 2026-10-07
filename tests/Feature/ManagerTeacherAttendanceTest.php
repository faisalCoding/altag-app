<?php

use App\Livewire\Manager\HijriDatepicker;
use App\Livewire\Shared\TeacherAttendance as Screen;
use App\Models\Circle;
use App\Models\Manager;
use App\Models\Stage;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-09-14 09:00:00'); // A Monday.

    $this->manager = Manager::factory()->create();
    $this->actingAs($this->manager, 'manager');
});

it('reaches the teachers of a stage no supervisor covers, and those with no circle', function () {
    // A stage with a supervisor, and one with none.
    $covered = Stage::factory()->create();
    Supervisor::factory()->create()->stages()->attach($covered->id);
    $uncovered = Stage::factory()->create();

    $supervised = Teacher::factory()->create(['name' => 'أستاذ المرحلة المشرفة']);
    $supervised->circles()->attach(Circle::factory()->create(['stage_id' => $covered->id])->id);
    $unsupervised = Teacher::factory()->create(['name' => 'أستاذ الثانوية']);
    $unsupervised->circles()->attach(Circle::factory()->create(['stage_id' => $uncovered->id])->id);
    Teacher::factory()->create(['name' => 'أستاذ بلا حلقة']);

    Livewire::test(Screen::class, ['role' => 'manager'])
        ->assertSee('أستاذ المرحلة المشرفة')
        ->assertSee('أستاذ الثانوية')
        ->assertSee('أستاذ بلا حلقة');
});

it('leaves out teachers still awaiting approval', function () {
    Stage::factory()->create();
    Teacher::factory()->create(['name' => 'أستاذ معلّق', 'is_approved' => false]);

    Livewire::test(Screen::class, ['role' => 'manager'])->assertDontSee('أستاذ معلّق');
});

it('files a mark under the manager, and a teacher with no circle under no stage', function () {
    Stage::factory()->create();
    $teacher = Teacher::factory()->create();

    Livewire::test(Screen::class, ['role' => 'manager'])
        ->call('mark', $teacher->id, 'excused');

    $record = TeacherAttendance::first();
    expect($record->status)->toBe('excused');
    expect($record->recorded_by_id)->toBe($this->manager->id);
    expect($record->stage_id)->toBeNull();
});

it('opens from the manager sidebar', function () {
    $this->get(route('manager.teacher-attendance'))
        ->assertSuccessful()
        ->assertSee('تحضير المعلمين');
});

it('will not open as the supervisor roll call for a manager', function () {
    Livewire::test(Screen::class, ['role' => 'supervisor'])->assertForbidden();
});

it('keeps the picker from choosing a day after its last', function () {
    Livewire::test(HijriDatepicker::class, ['maxDate' => '2026-09-14'])
        ->call('selectDate', '2026-09-15')
        ->assertNotSet('date', '2026-09-15')
        ->call('selectDate', '2026-09-13')
        ->assertSet('date', '2026-09-13');
});
