<?php

use App\Livewire\Shared\TeacherAttendanceReport as Report;
use App\Models\Circle;
use App\Models\Manager;
use App\Models\Stage;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use App\Support\HijriDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-09-17 09:00:00'); // A Thursday.

    $this->stage = Stage::factory()->create(['name' => 'المرحلة الأولى']);
    $this->supervisor = Supervisor::factory()->create(['name' => 'المشرف سالم']);
    $this->supervisor->stages()->attach($this->stage->id);

    $this->teacher = Teacher::factory()->create(['name' => 'أستاذ أحمد']);
    $this->teacher->circles()->attach(Circle::factory()->create(['stage_id' => $this->stage->id])->id);

    $this->otherStage = Stage::factory()->create(['name' => 'المرحلة البعيدة']);
    $this->outsider = Teacher::factory()->create(['name' => 'أستاذ بعيد']);
    $this->outsider->circles()->attach(Circle::factory()->create(['stage_id' => $this->otherStage->id])->id);

    // Sunday to Thursday: one of each status, and Thursday left unmarked.
    foreach (['2026-09-13' => 'present', '2026-09-14' => 'late', '2026-09-15' => 'excused', '2026-09-16' => 'absent'] as $date => $status) {
        TeacherAttendance::create([
            'teacher_id' => $this->teacher->id,
            'stage_id' => $this->stage->id,
            'date' => $date,
            'status' => $status,
            'notes' => $status === 'absent' ? 'مريض' : null,
        ]);
    }

    TeacherAttendance::create([
        'teacher_id' => $this->outsider->id,
        'stage_id' => $this->otherStage->id,
        'date' => '2026-09-13',
        'status' => 'absent',
    ]);
});

function teacherReport(string $role = 'supervisor'): Testable
{
    return Livewire::test(Report::class, ['role' => $role])
        ->set('fromDate', '2026-09-13')
        ->set('toDate', '2026-09-17');
}

function rowOf(Testable $page, Teacher $teacher): array
{
    return $page->viewData('rows')->firstWhere('teacher.id', $teacher->id);
}

it('counts each teacher against the working days of their stage', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $row = rowOf(teacherReport(), $this->teacher);

    expect($row['working'])->toBe(5);
    expect([$row['present'], $row['late'], $row['excused'], $row['absent']])->toBe([1, 1, 1, 1]);
    // Thursday is today: its roll may still be called, so it is not owed yet.
    expect($row['unrecorded'])->toBe(0);
    // Present and late over the days counted, leaving out the excused one.
    expect($row['rate'])->toBe(67);
});

it('owes a day the roll was not called on once the day is over', function () {
    Carbon\Carbon::setTestNow('2026-09-18 08:00:00');
    $this->actingAs($this->supervisor, 'supervisor');

    expect(rowOf(teacherReport(), $this->teacher)['unrecorded'])->toBe(1);
});

it('owes a teacher who has moved on only the days they were marked', function () {
    $moved = Teacher::factory()->create(['name' => 'أستاذ منقول']);
    TeacherAttendance::create(['teacher_id' => $moved->id, 'stage_id' => $this->stage->id, 'date' => '2026-09-13', 'status' => 'present']);
    $this->actingAs($this->supervisor, 'supervisor');

    $page = teacherReport();
    $row = rowOf($page, $moved);

    expect($row['working'])->toBe(0)
        ->and($row['unrecorded'])->toBe(0)
        ->and($row['present'])->toBe(1)
        // Nor does their record make the stage's day look complete.
        ->and($page->viewData('followUp')['stages'][0]['cells']['2026-09-13'])->toBe(['marked' => 1, 'expected' => 1]);

    TeacherAttendance::where('teacher_id', $this->teacher->id)->whereDate('date', '2026-09-13')->delete();

    expect(teacherReport()->viewData('followUp')['stages'][0]['cells']['2026-09-13'])->toBe(['marked' => 0, 'expected' => 1]);
});

it('lists the days that need a second look, with their reason', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $row = rowOf(teacherReport(), $this->teacher);

    expect($row['away']->pluck('status')->all())->toBe(['absent', 'excused', 'late']);
    // Sent as data and laid out when the teacher's line is opened.
    teacherReport()
        ->assertSee('"n":"مريض"')
        ->assertSee('x-html="daysHtml(days)"', false);
});

it('keeps a supervisor to their own stages', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    teacherReport()
        ->assertSee('أستاذ أحمد')
        ->assertDontSee('أستاذ بعيد');
});

it('gives the manager the whole academy, and narrows by stage', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');
    $loner = Teacher::factory()->create(['name' => 'أستاذ بلا حلقة']);

    teacherReport('manager')
        ->assertSee('أستاذ أحمد')
        ->assertSee('أستاذ بعيد')
        ->assertSee('أستاذ بلا حلقة')
        ->set('stageIds', [(string) $this->otherStage->id])
        ->assertSee('أستاذ بعيد')
        ->assertDontSee('أستاذ أحمد')
        ->assertDontSee('أستاذ بلا حلقة');
});

it('opens on the Hijri month so far and never counts past today', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $page = Livewire::test(Report::class, ['role' => 'supervisor'])
        ->assertSet('fromDate', HijriDate::months('2026-09-17', 1)[0]['first_day'])
        ->assertSet('toDate', '2026-09-17')
        ->set('toDate', '2026-09-30');

    expect($page->viewData('to'))->toBe('2026-09-17');
});

it('shows day by day whether each stage was marked', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $followUp = teacherReport()->viewData('followUp');
    $cells = $followUp['stages'][0]['cells'];

    expect($followUp['days'])->toBe(['2026-09-13', '2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17']);
    expect($cells['2026-09-13'])->toBe(['marked' => 1, 'expected' => 1]);
    expect($cells['2026-09-17'])->toBe(['marked' => 0, 'expected' => 1]);
});

it('drops the day-by-day grid for a period too long to lay out', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $page = Livewire::test(Report::class, ['role' => 'supervisor'])->set('fromDate', '2026-06-01');

    expect($page->viewData('followUp'))->toBeNull();
    $page->assertSee('اختر فترة لا تزيد على');
});

it('prints the report', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    teacherReport()->call('downloadPdf')->assertFileDownloaded('teacher_attendance_report.pdf');
});

it('opens from both sidebars', function () {
    $this->actingAs($this->supervisor, 'supervisor')
        ->get(route('supervisor.teacher-attendance-report'))
        ->assertSuccessful()
        ->assertSee('تقرير حضور المعلمين');

    $this->actingAs(Manager::factory()->create(), 'manager')
        ->get(route('manager.teacher-attendance-report'))
        ->assertSuccessful();
});

it('will not open as the manager report for a supervisor', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(Report::class, ['role' => 'manager'])->assertForbidden();
});

it('shows today on the supervisor dashboard', function () {
    TeacherAttendance::create([
        'teacher_id' => $this->teacher->id,
        'stage_id' => $this->stage->id,
        'date' => '2026-09-17',
        'status' => 'absent',
    ]);

    $this->actingAs($this->supervisor, 'supervisor')
        ->get(route('supervisor.dashboard'))
        ->assertSuccessful()
        ->assertSee('تحضير المعلمين اليوم')
        ->assertSee('مكتمل')
        ->assertSee('غائب ١');
});

it('shows the manager which stages are still to be marked, and by whom', function () {
    $this->actingAs(Manager::factory()->create(), 'manager')
        ->get(route('manager.dashboard'))
        ->assertSuccessful()
        ->assertSee('تحضير المعلمين اليوم')
        ->assertSee('المشرف سالم')
        ->assertSee('بلا مشرف')
        ->assertSee('لم يُحضَّر بعد');
});
