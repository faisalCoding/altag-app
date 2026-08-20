<?php

use App\Livewire\Teacher\AttendanceSheet;
use App\Models\Attendance as AttendanceModel;
use App\Models\AttendanceRevision;
use App\Models\Circle;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-07-08 10:00:00'); // Wednesday, a working day.

    $this->stage = Stage::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);

    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->studentA = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'أحمد']);
    $this->studentB = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'بلال']);

    $this->actingAs($this->teacher, 'teacher');
});

it('lays the month out as working-day columns and student rows', function () {
    $component = Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id]);

    $days = $component->instance()->days();
    $students = $component->instance()->students();

    expect($days)->not->toBeEmpty();
    expect($students->pluck('name')->all())->toBe(['أحمد', 'بلال']);
    expect(collect($days)->pluck('date')->all())->toContain('2026-07-08');
    expect(collect($days)->firstWhere('date', '2026-07-08')['is_today'])->toBeTrue();
});

it('saves a batch of same-day cells in one call without asking for a reason', function () {
    $component = Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id])
        ->call('saveChanges', [
            ['student_id' => $this->studentA->id, 'date' => '2026-07-08', 'status' => 'present'],
            ['student_id' => $this->studentB->id, 'date' => '2026-07-08', 'status' => 'absent'],
        ], '');

    $component->assertHasNoErrors();

    expect(AttendanceModel::where('student_id', $this->studentA->id)->value('status'))->toBe('present');
    expect(AttendanceModel::where('student_id', $this->studentB->id)->value('status'))->toBe('absent');
});

it('refuses an off-day edit until a reason is given', function () {
    $component = Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id])
        ->call('saveChanges', [
            ['student_id' => $this->studentA->id, 'date' => '2026-07-06', 'status' => 'present'],
        ], '');

    $component->assertHasErrors('reason');

    expect(AttendanceModel::count())->toBe(0);
    expect(AttendanceRevision::count())->toBe(0);
});

it('treats whitespace as no reason at all', function () {
    Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id])
        ->call('saveChanges', [
            ['student_id' => $this->studentA->id, 'date' => '2026-07-06', 'status' => 'present'],
        ], "   \n  ")
        ->assertHasErrors('reason');

    expect(AttendanceModel::count())->toBe(0);
});

it('saves the off-day edit once a reason is given and stores it on the revision', function () {
    Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id])
        ->call('saveChanges', [
            ['student_id' => $this->studentA->id, 'date' => '2026-07-06', 'status' => 'present'],
        ], 'انقطاع الشبكة يوم الجلسة')
        ->assertHasNoErrors();

    $revision = AttendanceRevision::sole();

    expect($revision->reason)->toBe('انقطاع الشبكة يوم الجلسة');
    expect($revision->is_off_day_edit)->toBeTrue();
    expect($revision->date->toDateString())->toBe('2026-07-06');
    expect($revision->edited_on->toDateString())->toBe('2026-07-08');
    expect($revision->old_status)->toBeNull();
    expect($revision->new_status)->toBe('present');
    expect($revision->edited_by_id)->toBe($this->teacher->id);
});

it('records who changed a status, and from what to what', function () {
    AttendanceModel::create([
        'student_id' => $this->studentA->id,
        'teacher_id' => $this->teacher->id,
        'circle_id' => $this->circle->id,
        'date' => '2026-07-08',
        'status' => 'absent',
    ]);

    Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id])
        ->call('saveChanges', [
            ['student_id' => $this->studentA->id, 'date' => '2026-07-08', 'status' => 'present'],
        ], '');

    $revision = AttendanceRevision::sole();

    expect($revision->old_status)->toBe('absent');
    expect($revision->new_status)->toBe('present');
    expect($revision->is_off_day_edit)->toBeFalse();
    expect($revision->reason)->toBeNull();
    expect($revision->summary())->toBe('غائب ← حاضر');
});

it('keeps no reason on same-day changes even when one is supplied for the batch', function () {
    Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id])
        ->call('saveChanges', [
            ['student_id' => $this->studentA->id, 'date' => '2026-07-08', 'status' => 'present'],
            ['student_id' => $this->studentB->id, 'date' => '2026-07-06', 'status' => 'late'],
        ], 'تأخر التسجيل');

    $sameDay = AttendanceRevision::whereDate('date', '2026-07-08')->sole();
    $offDay = AttendanceRevision::whereDate('date', '2026-07-06')->sole();

    expect($sameDay->reason)->toBeNull();
    expect($sameDay->is_off_day_edit)->toBeFalse();
    expect($offDay->reason)->toBe('تأخر التسجيل');
    expect($offDay->is_off_day_edit)->toBeTrue();
});

it('clears a cell, removing the record and logging the removal', function () {
    AttendanceModel::create([
        'student_id' => $this->studentA->id,
        'teacher_id' => $this->teacher->id,
        'circle_id' => $this->circle->id,
        'date' => '2026-07-08',
        'status' => 'present',
    ]);

    Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id])
        ->call('saveChanges', [
            ['student_id' => $this->studentA->id, 'date' => '2026-07-08', 'status' => ''],
        ], '');

    expect(AttendanceModel::count())->toBe(0);

    $revision = AttendanceRevision::sole();
    expect($revision->old_status)->toBe('present');
    expect($revision->new_status)->toBeNull();
    expect($revision->summary())->toBe('حاضر ← حُذف التسجيل');
});

it('writes nothing when a cell is set to the status it already holds', function () {
    AttendanceModel::create([
        'student_id' => $this->studentA->id,
        'teacher_id' => $this->teacher->id,
        'circle_id' => $this->circle->id,
        'date' => '2026-07-08',
        'status' => 'present',
    ]);

    Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id])
        ->call('saveChanges', [
            ['student_id' => $this->studentA->id, 'date' => '2026-07-08', 'status' => 'present'],
        ], '');

    expect(AttendanceRevision::count())->toBe(0);
});

it('refuses to mark a day that has not arrived yet', function () {
    Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id])
        ->call('saveChanges', [
            ['student_id' => $this->studentA->id, 'date' => '2026-07-09', 'status' => 'present'],
        ], 'محاولة استباقية');

    expect(AttendanceModel::count())->toBe(0);
});

it('refuses an unknown status', function () {
    Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id])
        ->call('saveChanges', [
            ['student_id' => $this->studentA->id, 'date' => '2026-07-08', 'status' => 'vacationing'],
        ], '');

    expect(AttendanceModel::count())->toBe(0);
});

it('refuses to write into a circle the teacher does not hold', function () {
    $otherCircle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $otherStudent = Student::factory()->create(['circle_id' => $otherCircle->id]);

    Livewire::test(AttendanceSheet::class, ['circleId' => $otherCircle->id])
        ->call('saveChanges', [
            ['student_id' => $otherStudent->id, 'date' => '2026-07-08', 'status' => 'present'],
        ], '');

    expect(AttendanceModel::count())->toBe(0);
});

it('refuses a student who belongs to another circle', function () {
    $otherCircle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $outsider = Student::factory()->create(['circle_id' => $otherCircle->id]);

    Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id])
        ->call('saveChanges', [
            ['student_id' => $outsider->id, 'date' => '2026-07-08', 'status' => 'present'],
        ], '');

    expect(AttendanceModel::count())->toBe(0);
});

it('counts a month per student, giving lateness half credit', function () {
    AttendanceModel::create(['student_id' => $this->studentA->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-08', 'status' => 'present']);
    AttendanceModel::create(['student_id' => $this->studentA->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-07', 'status' => 'late']);

    $totals = Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id])
        ->instance()->studentTotals();

    expect($totals[$this->studentA->id]['present'])->toBe(1);
    expect($totals[$this->studentA->id]['late'])->toBe(1);
    expect($totals[$this->studentA->id]['marked'])->toBe(2);
    expect($totals[$this->studentA->id]['percentage'])->toBe(75);
});

it('counts how far each day column got', function () {
    AttendanceModel::create(['student_id' => $this->studentA->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-08', 'status' => 'present']);

    $totals = Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id])
        ->instance()->dayTotals();

    expect($totals['2026-07-08'])->toBe(['marked' => 1, 'total' => 2]);
});

it('shows the month edit log, including its last day', function () {
    $component = Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id]);

    $lastDay = collect($component->instance()->days())->last()['date'];

    $component->call('saveChanges', [
        ['student_id' => $this->studentA->id, 'date' => '2026-07-08', 'status' => 'present'],
    ], '');

    AttendanceRevision::create([
        'student_id' => $this->studentB->id,
        'circle_id' => $this->circle->id,
        'date' => $lastDay,
        'new_status' => 'present',
        'edited_on' => '2026-07-08',
        'edited_by_id' => $this->teacher->id,
        'edited_by_type' => $this->teacher->getMorphClass(),
    ]);

    $dates = Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id])
        ->instance()->revisions()->pluck('date')->map->toDateString();

    expect($dates)->toContain('2026-07-08');
    expect($dates)->toContain($lastDay);
});

it('renders the sheet inside the attendance page', function () {
    $this->get(route('teacher.attendance'))
        ->assertSuccessful()
        ->assertSee('جدول الشهر');
});

it('streams the month as a CSV', function () {
    Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id])
        ->call('exportCsv')
        ->assertFileDownloaded();
});

it('moves between Hijri months and drops the cached grid', function () {
    $component = Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id]);

    $opening = $component->instance()->monthLabel();

    $component->call('previousMonth');
    expect($component->instance()->monthLabel())->not->toBe($opening);

    $component->call('goToCurrentMonth');
    expect($component->instance()->monthLabel())->toBe($opening);
});
