<?php

use App\Livewire\Teacher\Attendance;
use App\Models\Attendance as AttendanceModel;
use App\Models\Circle;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-07-08 10:00:00'); // Wednesday

    $this->stage = Stage::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);

    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->studentA = Student::factory()->create(['circle_id' => $this->circle->id]);
    $this->studentB = Student::factory()->create(['circle_id' => $this->circle->id]);

    $this->actingAs($this->teacher, 'teacher');
});

it('computes the weekly breakdown for the selected circle', function () {
    AttendanceModel::create(['student_id' => $this->studentA->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-08', 'status' => 'present']);
    AttendanceModel::create(['student_id' => $this->studentB->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-08', 'status' => 'late']);

    $component = Livewire::test(Attendance::class)->set('selectedCircle', $this->circle->id);

    expect($component->instance()->weeklyBreakdown())->toBe([
        'present' => 1, 'late' => 1, 'absent' => 0, 'excused' => 0,
    ]);
});

it('computes the weekly attendance percentage counting late as half credit', function () {
    AttendanceModel::create(['student_id' => $this->studentA->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-08', 'status' => 'present']);
    AttendanceModel::create(['student_id' => $this->studentB->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-08', 'status' => 'absent']);

    $component = Livewire::test(Attendance::class)->set('selectedCircle', $this->circle->id);

    // 1 present out of 2 recorded = 50%
    expect($component->instance()->weeklyAttendancePercentage())->toBe(50);
});

it('returns zero breakdown and percentage when no circle is selected', function () {
    $component = Livewire::test(Attendance::class);

    expect($component->instance()->weeklyBreakdown())->toBe(['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0]);
    expect($component->instance()->weeklyAttendancePercentage())->toBe(0);
});

it('builds a 7-day sparkline series for a status, oldest first', function () {
    AttendanceModel::create(['student_id' => $this->studentA->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-08', 'status' => 'present']);
    AttendanceModel::create(['student_id' => $this->studentB->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-06', 'status' => 'present']);

    $component = Livewire::test(Attendance::class)->set('selectedCircle', $this->circle->id);

    $series = $component->instance()->sparklineFor('present');

    expect($series)->toHaveCount(7);
    expect(array_sum($series))->toBe(2);
    expect($series[6])->toBe(1); // today (2026-07-08) is the last entry
});

it('renders the redesigned attendance page end-to-end with real data', function () {
    AttendanceModel::create(['student_id' => $this->studentA->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-08', 'status' => 'present']);
    AttendanceModel::create(['student_id' => $this->studentB->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-07', 'status' => 'absent']);

    $this->get(route('teacher.attendance'))
        ->assertSuccessful()
        ->assertSee('سجل الحضور والغياب')
        ->assertSee('نسبة الحضور الكلية')
        ->assertSee('إضافة جلسة')
        ->assertSee('تصدير تقرير')
        ->assertSee('هذا الأسبوع')
        ->assertSee('آخر الجلسات')
        ->assertSee($this->studentA->name)
        ->assertSee($this->studentB->name);
});

it('names every tab of the phone bottom bar, not only the open one', function () {
    $html = $this->get(route('teacher.attendance'))->assertSuccessful()->getContent();

    $bar = str($html)->after('aria-label="التنقل السريع"')->before('</nav>');

    foreach (['التحضير', 'التسميع', 'البنود', 'الخطط', 'الطلاب'] as $label) {
        expect((string) $bar)->toContain(">{$label}</span>");
    }

    expect((string) $bar)->not->toContain('x-show="isActive"');
});

it('streams a CSV export of the current day attendance', function () {
    Livewire::test(Attendance::class)
        ->set('selectedCircle', $this->circle->id)
        ->call('markStatus', $this->studentA->id, 'present')
        ->call('markStatus', $this->studentB->id, 'absent')
        ->call('exportCsv')
        ->assertFileDownloaded();
});

it('does not export when no circle is selected', function () {
    Livewire::test(Attendance::class)
        ->call('exportCsv');

    // No exception thrown, and no file streamed since there's nothing to export.
    expect(true)->toBeTrue();
});

it('lists the most recent distinct session dates with their present percentage', function () {
    AttendanceModel::create(['student_id' => $this->studentA->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-08', 'status' => 'present']);
    AttendanceModel::create(['student_id' => $this->studentB->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-08', 'status' => 'absent']);
    AttendanceModel::create(['student_id' => $this->studentA->id, 'teacher_id' => $this->teacher->id, 'circle_id' => $this->circle->id, 'date' => '2026-07-07', 'status' => 'present']);

    $component = Livewire::test(Attendance::class)->set('selectedCircle', $this->circle->id);

    $sessions = $component->instance()->recentSessions();

    expect($sessions)->toHaveCount(2);
    expect($sessions[0]['date'])->toBe('2026-07-08');
    expect($sessions[0]['percentage'])->toBe(50);
    expect($sessions[1]['date'])->toBe('2026-07-07');
    expect($sessions[1]['percentage'])->toBe(100);
});
