<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Guardian;
use App\Models\Setting;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\StudentDisciplineRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/*
 * Wednesday 8 July 2026, in Muharram 1448 (16 June – 14 July). The stage
 * meets Sunday to Thursday.
 */
beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-07-08 10:00:00');

    $this->circle = Circle::factory()->create(['stage_id' => Stage::factory()->create()->id]);
    $this->teacher = Teacher::factory()->create();
    $this->guardian = Guardian::factory()->create(['is_approved' => true]);
    $this->student = Student::factory()->create(['circle_id' => $this->circle->id, 'guardian_id' => $this->guardian->id]);

    AcademicCalendarEvent::create([
        'event_name' => 'الفصل الأول',
        'start_date' => '2026-06-01',
        'end_date' => '2026-08-30',
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5],
        'is_visible' => true,
    ]);
});

function markDay(Student $student, string $date, string $status, ?string $notes = null): Attendance
{
    return Attendance::create([
        'student_id' => $student->id,
        'teacher_id' => test()->teacher->id,
        'circle_id' => $student->circle_id,
        'date' => $date,
        'status' => $status,
        'notes' => $notes,
    ]);
}

/** A term with a run broken by an absence, late Sundays and an excused day. */
function markDisciplineTerm(Student $student): void
{
    foreach (['2026-06-14', '2026-06-15', '2026-06-16', '2026-06-17', '2026-06-18'] as $day) {
        markDay($student, $day, 'present');
    }

    markDay($student, '2026-05-31', 'absent');           // Outside the 30-day window.
    markDay($student, '2026-06-21', 'absent');           // Sunday.
    markDay($student, '2026-06-22', 'late');             // Monday.
    markDay($student, '2026-06-28', 'late');             // Sunday.
    markDay($student, '2026-07-01', 'absent', 'سفر');    // Wednesday.
    markDay($student, '2026-07-02', 'excused', 'موعد طبي');
    markDay($student, '2026-07-05', 'late');             // Sunday.

    foreach (['2026-07-06', '2026-07-07', '2026-07-08'] as $day) {
        markDay($student, $day, 'present');
    }
}

it('counts each limit over the rolling window and names the day the oldest leaves it', function () {
    markDisciplineTerm($this->student);

    $limits = StudentDisciplineRecord::limits($this->student);

    expect($limits['window'])->toBe(30)
        ->and($limits['absence'])->toBe(['used' => 2, 'limit' => 3, 'frees_on' => '2026-07-21'])
        ->and($limits['lateness'])->toBe(['used' => 3, 'limit' => 5, 'frees_on' => '2026-07-22'])
        ->and($limits['state'])->toBe('near')
        // The same count the teacher's roll call checks.
        ->and($limits['absence']['used'])->toBe($this->student->getAbsencesInPeriodCount());
});

it('says a student over a limit has reached it, and one clear of both is in good standing', function () {
    expect(StudentDisciplineRecord::limits($this->student)['state'])->toBe('good');

    Setting::setVal('absence_limit', 2);
    markDay($this->student, '2026-07-01', 'absent');
    markDay($this->student, '2026-07-02', 'absent');

    expect(StudentDisciplineRecord::limits($this->student)['state'])->toBe('over');
});

it('lays out the Hijri month a cell a day, from Saturday, with the working days marked', function () {
    markDisciplineTerm($this->student);

    $month = StudentDisciplineRecord::month($this->student, '2026-07-08');
    $cells = collect($month['cells'])->keyBy('date');

    expect($month['title'])->toBe('محرم ١٤٤٨')
        ->and($month['month'])->toBe('2026-06-16')
        ->and($month['cells'])->toHaveCount(29)
        ->and($month['offset'])->toBe(3)                // Tuesday: Saturday, Sunday and Monday come before it.
        ->and($month['next'])->toBeNull()
        ->and($month['previous'])->toBe('2026-05-18')
        ->and($cells['2026-06-16']['day'])->toBe(1)
        ->and($cells['2026-06-19']['working'])->toBeFalse()   // A Friday.
        ->and($cells['2026-06-21']['status'])->toBe('absent')
        ->and($cells['2026-07-08']['today'])->toBeTrue()
        ->and($cells['2026-07-09']['future'])->toBeTrue()
        ->and($month['counts'])->toBe(['present' => 6, 'late' => 3, 'excused' => 1, 'absent' => 2])
        ->and($month['rate'])->toBe(82)
        ->and($month['unrecorded'])->toBe(5)
        ->and($month['exceptions']->pluck('date')->map->format('Y-m-d')->first())->toBe('2026-07-05')
        ->and($month['exceptions'])->toHaveCount(6);
});

it('finds the weekday lateness gathers on, and the runs of days attended', function () {
    markDisciplineTerm($this->student);

    $habits = StudentDisciplineRecord::habits($this->student);

    expect(collect($habits['weekdays'])->pluck('label')->all())->toBe(['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس'])
        ->and($habits['weekdays'][0])->toBe(['label' => 'الأحد', 'late' => 2, 'absent' => 1])
        ->and($habits['worst'])->toBe(['label' => 'الأحد', 'status' => 'late'])
        // Late Sunday, then three days present: the excused Thursday breaks nothing.
        ->and($habits['streak'])->toBe(4)
        ->and($habits['best'])->toBe(5);
});

it('draws the record on the student page', function () {
    markDisciplineTerm($this->student);
    $this->actingAs($this->student, 'student');

    $this->get(route('student.attendance'))
        ->assertSuccessful()
        ->assertSee('data-discipline-state="near"', false)
        ->assertSee('قريب من الحد')
        ->assertSee('يتبقى غياب واحد قبل الحد')
        ->assertSee('يخرج أقدمها من الحساب في')
        ->assertSee('data-day="2026-06-21" data-status="absent"', false)
        ->assertSee('data-day="2026-06-19" data-status="off"', false)
        ->assertSee('data-day="2026-06-23" data-status="none"', false)
        ->assertSee('يتكرر التأخر يوم الأحد أكثر من غيره.')
        ->assertSee('موعد طبي');
});

it('moves between months and not past the current one', function () {
    $this->actingAs($this->student, 'student');

    Livewire::test('shared.discipline-record', ['role' => 'student'])
        ->assertSet('month', '2026-06-16')
        ->call('nextMonth')
        ->assertSet('month', '2026-06-16')
        ->call('previousMonth')
        ->assertSet('month', '2026-05-18')
        ->assertSee('ذو الحجة ١٤٤٧')
        ->call('nextMonth')
        ->assertSet('month', '2026-06-16');
});

it('shows a guardian the same record of their own child, and no other', function () {
    markDisciplineTerm($this->student);
    $this->actingAs($this->guardian, 'guardian');

    $this->get(route('guardian.student.attendance', $this->student->id))
        ->assertSuccessful()
        ->assertSee($this->student->name)
        ->assertSee('data-discipline-state="near"', false)
        ->assertSee('data-day="2026-06-21" data-status="absent"', false);

    $stranger = Student::factory()->create(['circle_id' => $this->circle->id]);

    $this->get(route('guardian.student.attendance', $stranger->id))->assertNotFound();

    expect(fn () => Livewire::test('shared.discipline-record', ['role' => 'guardian', 'studentId' => $stranger->id]))
        ->toThrow(ModelNotFoundException::class);
});

it('refuses a reader who is neither the student nor a guardian', function () {
    $this->actingAs($this->student, 'student');

    Livewire::test('shared.discipline-record', ['role' => 'teacher'])->assertForbidden();
});
