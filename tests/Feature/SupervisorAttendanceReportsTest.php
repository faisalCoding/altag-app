<?php

use App\Livewire\Manager\AttendanceReports as ManagerAttendanceReports;
use App\Livewire\Supervisor\AttendanceReports;
use App\Livewire\Supervisor\StudentAttendanceList;
use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Manager;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Wednesday 8 July 2026. The supervisor oversees the middle stage; the
 * primary stage is another's. Both meet Sunday to Thursday.
 */
beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-07-08 10:00:00');

    $this->middle = Stage::factory()->create(['name' => 'المتوسطة', 'position' => 1]);
    $this->primary = Stage::factory()->create(['name' => 'الابتدائية', 'position' => 2]);

    AcademicCalendarEvent::create([
        'event_name' => 'الفصل', 'start_date' => '2026-06-01', 'end_date' => '2026-08-30',
        'is_attendance_period' => true, 'weekdays' => [0, 1, 2, 3, 4], 'is_visible' => true,
        'stage_ids' => [$this->middle->id, $this->primary->id],
    ]);
    AcademicCalendarEvent::forgetPeriodCache();

    $this->supervisor = Supervisor::factory()->create();
    $this->supervisor->stages()->attach($this->middle->id);

    $this->teacher = Teacher::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->middle->id, 'name' => 'حلقة الفاروق']);
    $this->other = Circle::factory()->create(['stage_id' => $this->primary->id, 'name' => 'حلقة النور']);
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'active', 'is_approved' => true, 'joined_at' => '2026-06-01', 'name' => 'أحمد الفاروق']);
    $this->student->statusHistories()->create(['status' => 'active', 'start_date' => '2026-06-01']);

    Attendance::create([
        'student_id' => $this->student->id, 'teacher_id' => $this->teacher->id,
        'circle_id' => $this->circle->id, 'date' => '2026-07-05', 'status' => 'present',
    ]);

    $this->actingAs($this->supervisor, 'supervisor');
});

function supervisorReport()
{
    return Livewire::test(AttendanceReports::class)
        ->set('fromDate', '2026-07-04')
        ->set('toDate', '2026-07-09');
}

it('reports the circles of the supervisor\'s stages alone, with their own day pages behind the cells', function () {
    supervisorReport()
        ->assertSee('حلقة الفاروق')
        ->assertDontSee('حلقة النور')
        ->assertSee('data-stage-chip="'.$this->middle->id.'"', false)
        ->assertDontSee('data-stage-chip="'.$this->primary->id.'"', false)
        ->assertSee('الإجمالي')
        ->assertSee(route('supervisor.attendance-list', ['circleId' => $this->circle->id, 'date' => '2026-07-05']), false)
        ->assertDontSee(route('manager.attendance-list', ['circleId' => $this->circle->id, 'date' => '2026-07-05']), false);
});

it('reads nothing for a supervisor with no stage, rather than every stage', function () {
    $this->actingAs(Supervisor::factory()->create(), 'supervisor');

    supervisorReport()
        ->assertDontSee('حلقة الفاروق')
        ->assertDontSee('حلقة النور');
});

it('prints the supervisor\'s stages alone, whatever is picked', function () {
    $reported = new ReflectionMethod(ManagerAttendanceReports::class, 'reportedStageIds');
    $names = new ReflectionMethod(ManagerAttendanceReports::class, 'chosenStageNames');

    $page = supervisorReport()->instance();
    expect($reported->invoke($page))->toBe([$this->middle->id])
        ->and($names->invoke($page))->toBe('المتوسطة');

    $page->stageIds = [(string) $this->primary->id];
    expect($reported->invoke($page))->toBe([$this->middle->id]);

    $pdf = supervisorReport()->instance()->downloadPDF();
    ob_start();
    $pdf->sendContent();
    expect(strlen(ob_get_clean()))->toBeGreaterThan(1000);
});

it('opens the roll of a circle the supervisor oversees, and of no other', function () {
    $this->get(route('supervisor.attendance-list', ['circleId' => $this->circle->id, 'date' => '2026-07-05']))
        ->assertOk()
        ->assertSee('أحمد الفاروق');

    $this->get(route('supervisor.attendance-list', ['circleId' => $this->other->id, 'date' => '2026-07-05']))->assertNotFound();

    Livewire::test(StudentAttendanceList::class, ['circleId' => $this->circle->id, 'date' => '2026-07-06'])
        ->call('updateStatus', $this->student->id, 'late');

    expect(Attendance::where('student_id', $this->student->id)->whereDate('date', '2026-07-06')->value('status'))->toBe('late');
});

it('opens from the supervisor\'s menu', function () {
    $this->get(route('supervisor.attendance-reports'))
        ->assertOk()
        ->assertSee('تقارير الحضور والغياب')
        ->assertSee(route('supervisor.attendance-reports'), false);
});

it('keeps the manager\'s report over the whole academy', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(ManagerAttendanceReports::class)
        ->set('fromDate', '2026-07-04')
        ->set('toDate', '2026-07-09')
        ->assertSee('حلقة الفاروق')
        ->assertSee('حلقة النور')
        ->assertSee('المجمع')
        ->assertSee(route('manager.attendance-list', ['circleId' => $this->circle->id, 'date' => '2026-07-05']), false);
});
