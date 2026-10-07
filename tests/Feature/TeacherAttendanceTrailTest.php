<?php

use App\Livewire\Shared\TeacherAttendance as Screen;
use App\Models\AcademicCalendarEvent;
use App\Models\Circle;
use App\Models\Stage;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use App\Models\TeacherAttendanceRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-09-14 09:00:00'); // A Monday.

    $this->stage = Stage::factory()->create();
    $circle = Circle::factory()->create(['stage_id' => $this->stage->id]);

    $this->supervisor = Supervisor::factory()->create(['name' => 'المشرف سالم']);
    $this->supervisor->stages()->attach($this->stage->id);

    $this->teacher = Teacher::factory()->create(['name' => 'أستاذ أحمد']);
    $this->teacher->circles()->attach($circle->id);

    $this->actingAs($this->supervisor, 'supervisor');
});

it('leaves a line in the trail for the first mark and for every change', function () {
    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'absent')
        ->call('mark', $this->teacher->id, 'absent') // Unchanged: nothing written.
        ->call('mark', $this->teacher->id, 'present');

    $trail = TeacherAttendanceRevision::orderBy('id')->get();

    expect($trail)->toHaveCount(2);
    expect($trail[0]->only('old_status', 'new_status'))->toBe(['old_status' => null, 'new_status' => 'absent']);
    expect($trail[1]->summary())->toBe('غائب ← حاضر');
    expect($trail[1]->edited_by_id)->toBe($this->supervisor->id);
    expect($trail[1]->edited_by_role)->toBe('supervisor');
    expect($trail[1]->stage_id)->toBe($this->stage->id);
});

it('keeps the trail of a cleared day', function () {
    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'late')
        ->call('clearDay');

    expect(TeacherAttendance::count())->toBe(0);

    $cleared = TeacherAttendanceRevision::latest('id')->first();
    expect($cleared->old_status)->toBe('late');
    expect($cleared->new_status)->toBeNull();
    expect($cleared->summary())->toBe('متأخر ← حُذف التسجيل');
});

it('saves a reason and the arrival time of a late teacher', function () {
    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'late')
        ->call('saveNote', $this->teacher->id, '  زحمة الطريق ', '16:20')
        ->assertSee('زحمة الطريق')
        ->assertSee('16:20');

    $record = TeacherAttendance::first();
    expect($record->notes)->toBe('زحمة الطريق');
    expect($record->arrivalLabel())->toBe('16:20');

    $line = TeacherAttendanceRevision::latest('id')->first();
    expect($line->notesChanged())->toBeTrue();
    expect($line->arrivalChanged())->toBeTrue();
    expect($line->summary())->toBeNull();
});

it('drops the arrival time once the teacher is no longer late, but keeps the reason', function () {
    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'late')
        ->call('saveNote', $this->teacher->id, 'موعد طبي', '16:45')
        ->call('mark', $this->teacher->id, 'excused');

    $record = TeacherAttendance::first();
    expect($record->arrived_at)->toBeNull();
    expect($record->notes)->toBe('موعد طبي');
});

it('ignores an arrival time on a teacher who is not late', function () {
    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'absent')
        ->call('saveNote', $this->teacher->id, 'مريض', '16:20');

    expect(TeacherAttendance::first()->arrived_at)->toBeNull();
});

it('refuses a reason for a day not yet marked, and a malformed time', function () {
    Livewire::test(Screen::class)
        ->call('saveNote', $this->teacher->id, 'مريض')
        ->call('mark', $this->teacher->id, 'late')
        ->call('saveNote', $this->teacher->id, 'زحمة', '25:99');

    expect(TeacherAttendance::first()->notes)->toBeNull();
    expect(TeacherAttendanceRevision::count())->toBe(1);
});

it('shows the day trail with who made each change', function () {
    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'absent')
        ->call('mark', $this->teacher->id, 'present')
        ->assertSee('سجل تعديلات هذا اليوم')
        ->assertSee('غائب ← حاضر')
        ->assertSee('المشرف المشرف سالم');
});

it('reads how late a teacher was against the first session of the day', function () {
    AcademicCalendarEvent::create([
        'event_name' => 'فترة دوام الحلقات',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5],
        'sessions' => [['from' => '16:00', 'to' => '18:00', 'label' => null]],
        'is_visible' => true,
    ]);
    AcademicCalendarEvent::forgetPeriodCache();

    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'late')
        ->call('saveNote', $this->teacher->id, '', '16:25')
        ->assertSee('متأخراً ٢٥ دقيقة');
});
