<?php

use App\Livewire\Manager\Settings;
use App\Livewire\Shared\ExceededLimits;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Manager;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\GuardianNotificationService;
use App\Support\DisciplineWindow;
use App\Support\StudentDisciplineRecord;
use Livewire\Livewire;

/*
 * Wednesday 8 July 2026; the window is the default thirty days, 9 June to
 * 8 July, both counted.
 */
beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-07-08 10:00:00');

    $this->teacher = Teacher::factory()->create();
    $this->circle = Circle::factory()->create();
    $this->student = Student::factory()->create(['circle_id' => $this->circle->id, 'is_approved' => true]);

    foreach (['2026-06-08' => 'absent', '2026-06-09' => 'absent', '2026-06-20' => 'absent', '2026-07-01' => 'absent', '2026-06-25' => 'late', '2026-07-05' => 'late'] as $date => $status) {
        Attendance::create([
            'student_id' => $this->student->id, 'teacher_id' => $this->teacher->id,
            'circle_id' => $this->circle->id, 'date' => $date, 'status' => $status,
        ]);
    }
});

it('counts the last thirty days, today and the thirtieth day back included', function () {
    expect(DisciplineWindow::for())->toBe(['from' => '2026-06-09', 'to' => '2026-07-08'])
        // 8 June is the thirty-first day back: out.
        ->and($this->student->getAbsencesInPeriodCount())->toBe(3)
        ->and($this->student->getLatenessInPeriodCount())->toBe(2)
        ->and(DisciplineWindow::for('2026-06-20'))->toBe(['from' => '2026-05-22', 'to' => '2026-06-20']);
});

it('counts nothing before the day counting starts from', function () {
    Setting::setVal(DisciplineWindow::START_SETTING, '2026-06-21');

    expect(DisciplineWindow::for())->toBe(['from' => '2026-06-21', 'to' => '2026-07-08'])
        ->and($this->student->getAbsencesInPeriodCount())->toBe(1)
        ->and($this->student->getLatenessInPeriodCount())->toBe(2)
        // A start older than the window changes nothing.
        ->and(DisciplineWindow::for('2026-08-30'))->toBe(['from' => '2026-08-01', 'to' => '2026-08-30']);

    $limits = StudentDisciplineRecord::limits($this->student);

    expect($limits['absence']['used'])->toBe(1)
        ->and($limits['counts_from'])->toBe('2026-06-21');
});

it('leaves the over-the-limit list in step with the same window', function () {
    Setting::setVal('absence_limit', 3);
    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(ExceededLimits::class)->assertSee($this->student->name);

    Setting::setVal(DisciplineWindow::START_SETTING, '2026-06-21');

    Livewire::test(ExceededLimits::class)->assertDontSee($this->student->name);
});

it('numbers a guardian\'s absence message from the day counting starts', function () {
    expect(GuardianNotificationService::occurrenceNumber($this->student, 'absent', '2026-07-01'))->toBe(4);

    Setting::setVal(DisciplineWindow::START_SETTING, '2026-06-21');

    expect(GuardianNotificationService::occurrenceNumber($this->student, 'absent', '2026-07-01'))->toBe(1);
});

it('lets the manager set the day counting starts from, and clear it', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(Settings::class)
        ->assertSet('countFrom', '')
        ->set('countFrom', '2026-06-21')
        ->call('save')
        ->assertHasNoErrors();

    expect(DisciplineWindow::countsFrom())->toBe('2026-06-21');

    Livewire::test(Settings::class)
        ->assertSet('countFrom', '2026-06-21')
        ->assertSee('إزالة التاريخ')
        ->set('countFrom', '')
        ->call('save');

    expect(DisciplineWindow::countsFrom())->toBeNull();

    Livewire::test(Settings::class)
        ->set('countFrom', 'غداً')
        ->call('save')
        ->assertHasErrors('countFrom');
});
