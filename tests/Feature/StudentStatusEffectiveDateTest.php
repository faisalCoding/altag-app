<?php

use App\Livewire\Teacher\Attendance as TeacherAttendance;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Manager;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Services\StudentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-06-10 10:00:00');

    $this->stage = Stage::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::factory()->create([
        'name' => 'طالب الحالة',
        'circle_id' => $this->circle->id,
        'status' => 'registering',
        'is_approved' => true,
    ]);
    $this->student->statusHistories()->create([
        'status' => 'registering',
        'start_date' => '2026-06-01',
    ]);
});

it('backdates a status change to the chosen effective date', function () {
    StudentStatusService::changeStatus($this->student, 'active', '2026-06-05');

    expect($this->student->refresh()->status)->toBe('active');

    $latest = $this->student->statusHistories()->orderByDesc('start_date')->orderByDesc('id')->first();
    expect($latest->status)->toBe('active');
    expect($latest->start_date->format('Y-m-d'))->toBe('2026-06-05');

    // The previous registering row was closed at the effective date.
    $previous = $this->student->statusHistories()->where('status', 'registering')->first();
    expect($previous->end_date->format('Y-m-d'))->toBe('2026-06-05');
});

it('changes nothing when the status chosen is the one the student has', function () {
    StudentStatusService::changeStatus($this->student, 'active', '2026-06-08');

    // Re-saving a status used to move its period's start to the date given.
    expect(StudentStatusService::changeStatus($this->student, 'active', '2026-06-03'))->toBeFalse()
        ->and(StudentStatusService::changeStatus($this->student, 'active'))->toBeFalse();

    expect($this->student->statusHistories()->where('status', 'active')->count())->toBe(1)
        ->and($this->student->statusHistories()->where('status', 'active')->first()->start_date->format('Y-m-d'))->toBe('2026-06-08')
        ->and($this->student->statusHistories()->where('status', 'registering')->first()->end_date->format('Y-m-d'))->toBe('2026-06-08');
});

it('reflects a backdated activation on the teacher attendance page', function () {
    StudentStatusService::changeStatus($this->student, 'active', '2026-06-05');

    $this->actingAs($this->teacher, 'teacher');

    // Registering on 2026-06-03: hidden. Active from 2026-06-05: visible.
    $before = Livewire::test(TeacherAttendance::class)
        ->set('selectedCircle', $this->circle->id)
        ->set('date', '2026-06-03');
    expect(collect($before->get('students'))->pluck('id')->all())
        ->not->toContain($this->student->id);

    $after = Livewire::test(TeacherAttendance::class)
        ->set('selectedCircle', $this->circle->id)
        ->set('date', '2026-06-06');
    expect(collect($after->get('students'))->pluck('id')->all())
        ->toContain($this->student->id);
});

it('lets the manager change status with an effective date from the status manager', function () {
    $manager = Manager::factory()->create();
    $this->actingAs($manager, 'manager');

    Livewire::test('shared.⚡student-status-manager')
        ->call('open', $this->student->id)
        ->assertSet('showModal', true)
        ->set('newStatus', 'active')
        ->set('effectiveDate', '2026-06-04')
        ->set('reason', 'اكتمال إجراءات التسجيل')
        ->call('saveStatus')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect($this->student->refresh()->status)->toBe('active');
    $latest = $this->student->statusHistories()->orderByDesc('start_date')->orderByDesc('id')->first();
    expect($latest->start_date->format('Y-m-d'))->toBe('2026-06-04');
    expect($latest->notes)->toBe('اكتمال إجراءات التسجيل');
    expect($latest->changed_by_role)->toBe('manager');
    expect($latest->changed_by_name)->toBe($manager->name);
});

it('rejects a future effective date', function () {
    $manager = Manager::factory()->create();
    $this->actingAs($manager, 'manager');

    Livewire::test('shared.⚡student-status-manager')
        ->call('open', $this->student->id)
        ->set('newStatus', 'active')
        ->set('effectiveDate', '2026-06-15')
        ->set('reason', '')
        ->call('saveStatus')
        ->assertHasErrors('effectiveDate')
        // The reason is optional now: the change is attributed either way.
        ->assertHasNoErrors('reason');

    expect($this->student->refresh()->status)->toBe('registering');
});

it('saves a status change with no reason given', function () {
    $manager = Manager::factory()->create();
    $this->actingAs($manager, 'manager');

    Livewire::test('shared.⚡student-status-manager')
        ->call('open', $this->student->id)
        ->set('newStatus', 'active')
        ->set('effectiveDate', '2026-06-05')
        ->set('reason', '')
        ->call('saveStatus')
        ->assertHasNoErrors();

    expect($this->student->refresh()->status)->toBe('active');
});

it('suspends with an automatic return that the daily sync activates', function () {
    $this->student->update(['status' => 'active']);
    $this->student->statusHistories()->create(['status' => 'active', 'start_date' => '2026-06-03']);

    $manager = Manager::factory()->create();
    $this->actingAs($manager, 'manager');

    Livewire::test('shared.⚡student-status-manager')
        ->call('open', $this->student->id)
        ->set('newStatus', 'suspended')
        ->set('effectiveDate', '2026-06-10')
        ->set('returnDate', '2026-06-15')
        ->set('reason', 'إيقاف أسبوع بسبب الانقطاع')
        ->call('saveStatus')
        ->assertHasNoErrors();

    // Suspended today, with a scheduled active row at the return date.
    expect($this->student->refresh()->status)->toBe('suspended');
    expect(StudentStatusService::statusOn($this->student, '2026-06-12'))->toBe('suspended');
    expect(StudentStatusService::statusOn($this->student, '2026-06-15'))->toBe('active');

    // When the return day arrives, the daily sync flips the cached column.
    Carbon\Carbon::setTestNow('2026-06-15 00:20:00');
    $this->artisan('students:sync-current-status')->assertSuccessful();
    expect($this->student->refresh()->status)->toBe('active');
});

it('supersedes a scheduled return when a new manual decision is made', function () {
    $this->student->update(['status' => 'active']);
    $this->student->statusHistories()->create(['status' => 'active', 'start_date' => '2026-06-03']);

    StudentStatusService::suspendWithReturn($this->student, '2026-06-10', '2026-06-20', 'إيقاف مؤقت');
    expect($this->student->statusHistories()->whereDate('start_date', '>', '2026-06-10')->count())->toBe(1);

    // The manager reactivates manually today: the scheduled return row is removed.
    StudentStatusService::changeStatus($this->student, 'active', '2026-06-10', 'عفو مبكر');

    expect($this->student->refresh()->status)->toBe('active');
    expect($this->student->statusHistories()->whereDate('start_date', '>', '2026-06-10')->count())->toBe(0);
});

it('opens with nothing chosen, so saving untouched writes nothing', function () {
    StudentStatusService::changeStatus($this->student, 'active', '2026-06-03');
    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test('shared.⚡student-status-manager')
        ->call('open', $this->student->id)
        ->assertSet('newStatus', '')
        ->assertSee('data-current-status="active"', false)
        ->assertDontSee('data-action="active"', false)
        ->assertSee('data-action="suspended"', false)
        ->call('saveStatus')
        ->assertHasErrors('newStatus');

    expect($this->student->statusHistories()->where('status', 'active')->first()->start_date->format('Y-m-d'))->toBe('2026-06-03')
        ->and($this->student->statusHistories()->count())->toBe(2);
});

it('says before saving what a backdated change does to the reports', function () {
    StudentStatusService::changeStatus($this->student, 'active', '2026-06-02');

    foreach (['2026-06-04', '2026-06-08', '2026-06-09'] as $date) {
        Attendance::create([
            'student_id' => $this->student->id, 'teacher_id' => $this->teacher->id,
            'circle_id' => $this->circle->id, 'date' => $date, 'status' => 'present',
        ]);
    }

    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test('shared.⚡student-status-manager')
        ->call('open', $this->student->id)
        ->call('choose', 'suspended')
        ->set('effectiveDate', '2026-06-07')
        ->set('returnDate', '2026-06-21')
        ->assertSee('يُوقف من ٢١ ذو الحجة ١٤٤٧، ويعود مشاركاً تلقائياً في ٦ محرم ١٤٤٨.')
        // Two of the three records fall in the suspension and leave the reports.
        ->assertSee('data-report-shift="2"', false)
        ->assertSee('ما سُجّل له في يومين');
});

it('opens the record read-only for a teacher without the status permission', function () {
    $this->teacher->update(['permissions' => ['can_change_student_status' => false]]);
    $this->actingAs($this->teacher, 'teacher');

    Livewire::test('shared.⚡student-status-manager')
        ->call('open', $this->student->id)
        ->assertSet('showModal', true)
        ->assertSet('readOnly', true)
        ->assertDontSee('data-action=', false)
        ->call('choose', 'active')
        ->assertSet('newStatus', '')
        ->set('newStatus', 'active')
        ->call('saveStatus');

    expect($this->student->refresh()->status)->toBe('registering');
});

it('blocks a supervisor from managing students outside their stages', function () {
    $otherStage = Stage::factory()->create();
    $supervisor = Supervisor::factory()->create(['is_approved' => true]);
    $supervisor->stages()->attach($otherStage->id);

    $this->actingAs($supervisor, 'supervisor');

    Livewire::test('shared.⚡student-status-manager')
        ->call('open', $this->student->id)
        ->assertSet('showModal', false);
});

it('hides suspended students from attendance and tasmeeh like any non-active status', function () {
    StudentStatusService::changeStatus($this->student, 'active', '2026-06-02');
    StudentStatusService::changeStatus($this->student, 'suspended', '2026-06-07');

    $this->actingAs($this->teacher, 'teacher');

    // Active on 2026-06-05: visible. Suspended on 2026-06-09: hidden.
    $whileActive = Livewire::test(TeacherAttendance::class)
        ->set('selectedCircle', $this->circle->id)
        ->set('date', '2026-06-05');
    expect(collect($whileActive->get('students'))->pluck('id')->all())
        ->toContain($this->student->id);

    $whileSuspended = Livewire::test(TeacherAttendance::class)
        ->set('selectedCircle', $this->circle->id)
        ->set('date', '2026-06-09');
    expect(collect($whileSuspended->get('students'))->pluck('id')->all())
        ->not->toContain($this->student->id);

    // Hidden from the tasmeeh page too (current status is suspended).
    $tasmeeh = Livewire::test('teacher.⚡tasmeeh-manager');
    $shownIds = collect($tasmeeh->viewData('studentsWithPlansPresent'))
        ->merge($tasmeeh->viewData('studentsWithPlansAbsent'))
        ->merge($tasmeeh->viewData('studentsWithoutPlans'))
        ->pluck('id');
    expect($shownIds->all())->not->toContain($this->student->id);
});

it('excludes attendance recorded during a suspension from report counting', function () {
    StudentStatusService::changeStatus($this->student, 'active', '2026-06-02');

    foreach (['2026-06-04', '2026-06-09'] as $date) {
        Attendance::create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->teacher->id,
            'circle_id' => $this->circle->id,
            'date' => $date,
            'status' => 'present',
        ]);
    }

    StudentStatusService::changeStatus($this->student, 'suspended', '2026-06-07');

    // The record inside the suspension window is kept in the table but excluded
    // from counting; the record from the active period still counts.
    $counted = Attendance::query()
        ->whereRaw(Attendance::activeStatusOnDateSql())
        ->pluck('date')
        ->map(fn ($d) => Carbon\Carbon::parse($d)->format('Y-m-d'));

    expect($counted->all())->toContain('2026-06-04');
    expect($counted->all())->not->toContain('2026-06-09');
    expect(Attendance::count())->toBe(2);
});

it('rejects a new status starting before the current period (append-only timeline)', function () {
    StudentStatusService::changeStatus($this->student, 'active', '2026-06-05');

    expect(fn () => StudentStatusService::changeStatus($this->student, 'suspended', '2026-06-03'))
        ->toThrow(InvalidArgumentException::class);

    // Nothing was written: the student is still active with two history rows.
    expect($this->student->refresh()->status)->toBe('active');
    expect($this->student->statusHistories()->count())->toBe(2);
});

it('lets the period before run on when a wrong one is deleted', function () {
    StudentStatusService::changeStatus($this->student, 'active', '2026-06-05');
    StudentStatusService::changeStatus($this->student, 'suspended', '2026-06-08');

    $wrong = $this->student->statusHistories()->where('status', 'suspended')->first();
    StudentStatusService::deleteHistoryEntry($this->student, $wrong->id);

    expect($this->student->statusHistories()->where('status', 'active')->first()->end_date)->toBeNull()
        ->and($this->student->refresh()->status)->toBe('active');
});

it('keeps the previous period end date aligned when correcting the latest start date', function () {
    StudentStatusService::changeStatus($this->student, 'active', '2026-06-08');
    $active = $this->student->statusHistories()->where('status', 'active')->first();
    StudentStatusService::editHistoryEntry($this->student, $active->id, 'active', '2026-06-04');

    $registering = $this->student->statusHistories()->where('status', 'registering')->first();
    expect($registering->end_date->format('Y-m-d'))->toBe('2026-06-04');
});

it('backfills an opening history record for legacy students', function () {
    $legacy = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'status' => 'active',
        'joined_at' => '2026-05-15',
    ]);
    expect($legacy->statusHistories()->count())->toBe(0);

    $this->artisan('students:backfill-status-history')->assertSuccessful();

    $opening = $legacy->statusHistories()->first();
    expect($opening)->not->toBeNull();
    expect($opening->status)->toBe('active');
    expect($opening->start_date->format('Y-m-d'))->toBe('2026-05-15');

    // Students who already have history are untouched.
    expect($this->student->statusHistories()->count())->toBe(1);

    // Running it again is a no-op.
    $this->artisan('students:backfill-status-history')->assertSuccessful();
    expect($legacy->statusHistories()->count())->toBe(1);
});

it('deletes a wrong history entry and re-syncs the current status', function () {
    StudentStatusService::changeStatus($this->student, 'suspended', '2026-06-07');
    expect($this->student->refresh()->status)->toBe('suspended');

    $wrongEntry = $this->student->statusHistories()->where('status', 'suspended')->first();

    $manager = Manager::factory()->create();
    $this->actingAs($manager, 'manager');

    Livewire::test('shared.⚡student-status-manager')
        ->call('open', $this->student->id)
        ->call('deleteHistoryEntry', $wrongEntry->id);

    expect($this->student->statusHistories()->count())->toBe(1);
    expect($this->student->refresh()->status)->toBe('registering');
});

it('cancels a scheduled return when the teacher removes the student from the circle', function () {
    StudentStatusService::changeStatus($this->student, 'active', '2026-06-02');
    StudentStatusService::suspendWithReturn($this->student, '2026-06-09', '2026-06-20');

    $this->actingAs($this->teacher, 'teacher');

    Livewire::test('teacher.student-manager')
        ->call('viewStudent', $this->student->id)
        ->call('removeFromCircle');

    $student = $this->student->refresh();
    $suspension = $student->statusHistories()->where('status', 'suspended')->first();

    expect($student->circle_id)->toBeNull()
        ->and($student->status)->toBe('left')
        // The return is gone, so the daily sync cannot bring the student back.
        ->and($student->statusHistories()->whereDate('start_date', '>', '2026-06-10')->count())->toBe(0)
        ->and($suspension->end_date->format('Y-m-d'))->toBe('2026-06-10')
        ->and($student->statusHistories()->where('status', 'left')->first()->changed_by_role)->toBe('teacher');

    Carbon\Carbon::setTestNow('2026-06-20 00:20:00');
    $this->artisan('students:sync-current-status')->assertSuccessful();
    expect($student->refresh()->status)->toBe('left');
});

it('shows the status in Arabic in the student details, whatever the permission', function () {
    $this->teacher->update(['permissions' => ['can_change_student_status' => false]]);
    $this->actingAs($this->teacher, 'teacher');

    Livewire::test('teacher.student-manager')
        ->call('viewStudent', $this->student->id)
        ->assertSee('تحت التسجيل')
        ->assertSee('سجل الحالة')
        ->assertDontSee('>registering<', false);
});
