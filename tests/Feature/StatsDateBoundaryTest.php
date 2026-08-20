<?php

use App\Livewire\Manager\Dashboard;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Manager;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Teacher;
use App\Services\CircleReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Every statistic here is filtered by a date range, and every date column in
 * this schema carries a time component. A bare Y-m-d upper bound therefore
 * string-compares below that day's own rows and drops them silently — the
 * defect these tests exist to catch. Each one puts a record on the boundary
 * day and insists the figure counts it.
 */
beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-07-08 10:00:00');

    $this->stage = Stage::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);
    $this->student = Student::factory()->create(['circle_id' => $this->circle->id]);

    $this->plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'start_date' => '2026-07-01',
        'days_count' => 10,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'status' => 'active',
        'is_approved' => true,
        'created_by_role' => 'teacher',
    ]);
});

/** Grade a plan day, letting the model cast write the date exactly as the app does. */
function gradeDay(int $planId, string $date, ?int $hifz = null, ?int $review = null): StudentPlanDay
{
    return StudentPlanDay::create([
        'student_plan_id' => $planId,
        'date' => $date,
        'day_name' => 'اختبار',
        'hifz_achievement' => $hifz,
        'review_achievement' => $review,
    ]);
}

it('stores plan-day dates in one format only', function () {
    gradeDay($this->plan->id, '2026-07-08', 3);

    expect(DB::table('student_plan_days')->whereRaw('length(date) = 10')->count())->toBe(0);
});

it('counts a hifz session recorded on the last day of the range', function () {
    gradeDay($this->plan->id, '2026-07-08', 3);

    $this->actingAs(Manager::factory()->create(), 'manager');

    $dashboard = new Dashboard;
    $dashboard->quranPeriod = 'custom';
    $dashboard->quranFrom = '2026-07-01';
    $dashboard->quranTo = '2026-07-08';

    expect($dashboard->getQuranDataProperty()['hifzSessions'])->toBe(1);
});

it('counts a hifz session on a single-day range, the dashboard default', function () {
    gradeDay($this->plan->id, '2026-07-08', 3);

    $this->actingAs(Manager::factory()->create(), 'manager');

    $dashboard = new Dashboard;
    $dashboard->quranPeriod = 'custom';
    $dashboard->quranFrom = '2026-07-08';
    $dashboard->quranTo = '2026-07-08';

    $data = $dashboard->getQuranDataProperty();

    expect($data['hifzSessions'])->toBe(1);
    expect($data['hasData'])->toBeTrue();
});

it('counts a review session on the boundary day', function () {
    gradeDay($this->plan->id, '2026-07-08', null, 2);

    $this->actingAs(Manager::factory()->create(), 'manager');

    $dashboard = new Dashboard;
    $dashboard->quranPeriod = 'custom';
    $dashboard->quranFrom = '2026-07-08';
    $dashboard->quranTo = '2026-07-08';

    expect($dashboard->getQuranDataProperty()['reviewSessions'])->toBe(1);
});

it('breaks the boundary day down by stage', function () {
    gradeDay($this->plan->id, '2026-07-08', 3, 3);

    $this->actingAs(Manager::factory()->create(), 'manager');

    $dashboard = new Dashboard;
    $dashboard->quranPeriod = 'custom';
    $dashboard->quranFrom = '2026-07-08';
    $dashboard->quranTo = '2026-07-08';

    $rows = $dashboard->getQuranDataProperty()['stageRows'];

    expect($rows)->toHaveCount(1);
    expect($rows[0]['hifz'])->toBe(1);
    expect($rows[0]['review'])->toBe(1);
});

it('counts excellent per session, so it shares a unit with the session totals', function () {
    // One day graded excellent on both sides: two sessions, and two excellents.
    gradeDay($this->plan->id, '2026-07-08', 3, 3);

    $this->actingAs(Manager::factory()->create(), 'manager');

    $dashboard = new Dashboard;
    $dashboard->quranPeriod = 'custom';
    $dashboard->quranFrom = '2026-07-08';
    $dashboard->quranTo = '2026-07-08';

    $data = $dashboard->getQuranDataProperty();

    expect($data['hifzSessions'] + $data['reviewSessions'])->toBe(2);
    expect($data['excellentCount'])->toBe(2);
});

it('does not count excellent twice when only one side is excellent', function () {
    gradeDay($this->plan->id, '2026-07-08', 3, 1);

    $this->actingAs(Manager::factory()->create(), 'manager');

    $dashboard = new Dashboard;
    $dashboard->quranPeriod = 'custom';
    $dashboard->quranFrom = '2026-07-08';
    $dashboard->quranTo = '2026-07-08';

    expect($dashboard->getQuranDataProperty()['excellentCount'])->toBe(1);
});

it('leaves out a session that falls after the range', function () {
    gradeDay($this->plan->id, '2026-07-09', 3);

    $this->actingAs(Manager::factory()->create(), 'manager');

    $dashboard = new Dashboard;
    $dashboard->quranPeriod = 'custom';
    $dashboard->quranFrom = '2026-07-01';
    $dashboard->quranTo = '2026-07-08';

    expect($dashboard->getQuranDataProperty()['hifzSessions'])->toBe(0);
});

it('counts attendance recorded on the last day of the range', function () {
    Attendance::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'circle_id' => $this->circle->id,
        'date' => '2026-07-08',
        'status' => 'present',
    ]);

    $this->actingAs(Manager::factory()->create(), 'manager');

    $dashboard = new Dashboard;
    $dashboard->attendancePeriod = 'custom';
    $dashboard->attFrom = '2026-07-01';
    $dashboard->attTo = '2026-07-08';

    expect($dashboard->getAttendanceDataProperty()['present'])->toBe(1);
});

it('counts the boundary day in the supervisor report engine', function () {
    gradeDay($this->plan->id, '2026-07-08', 3, 2);

    Attendance::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'circle_id' => $this->circle->id,
        'date' => '2026-07-08',
        'status' => 'present',
    ]);

    $report = CircleReportService::build(
        CircleReportService::studentsForCircle($this->circle),
        Carbon\Carbon::parse('2026-07-01'),
        Carbon\Carbon::parse('2026-07-08'),
    );

    expect($report['totals']['hifz']['days'])->toBe(1);
    expect($report['totals']['review']['days'])->toBe(1);
    expect($report['totals']['attendance']['present'])->toBe(1);
});

it('agrees with the supervisor report on the same range', function () {
    gradeDay($this->plan->id, '2026-07-06', 3);
    gradeDay($this->plan->id, '2026-07-07', 2);
    gradeDay($this->plan->id, '2026-07-08', 3);

    $this->actingAs(Manager::factory()->create(), 'manager');

    $dashboard = new Dashboard;
    $dashboard->quranPeriod = 'custom';
    $dashboard->quranFrom = '2026-07-01';
    $dashboard->quranTo = '2026-07-08';

    $report = CircleReportService::build(
        CircleReportService::studentsForCircle($this->circle),
        Carbon\Carbon::parse('2026-07-01'),
        Carbon\Carbon::parse('2026-07-08'),
    );

    expect($dashboard->getQuranDataProperty()['hifzSessions'])
        ->toBe($report['totals']['hifz']['days']);
});
