<?php

use App\Livewire\Teacher\Attendance;
use App\Livewire\Teacher\AttendanceSheet;
use App\Models\Circle;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Riyadh is three hours ahead of the app's UTC clock, so a moment just after
    // midnight there is still yesterday in UTC — the hour that used to date a
    // student's enrolment to the day before they were enrolled.
    Carbon\Carbon::setTestNow('2026-09-21 01:00:00'); // 04:00 in Riyadh

    $this->stage = Stage::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);

    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->actingAs($this->teacher, 'teacher');
});

function manager()
{
    return Livewire::test('teacher.student-manager');
}

it('lets a student added today be marked present today', function () {
    manager()
        ->set('names', 'محمد أحمد الغامدي')
        ->call('createStudent')
        ->assertHasNoErrors();

    $student = Student::where('name', 'محمد أحمد الغامدي')->first();

    expect($student->status)->toBe('active');
    expect($student->joined_at->format('Y-m-d'))->toBe('2026-09-21');

    // The complaint itself: the roll call shows only students active on the day,
    // so a student created "تحت التسجيل" was invisible on the day they were added.
    $roll = Livewire::test(Attendance::class)
        ->set('selectedCircle', $this->circle->id)
        ->set('date', '2026-09-21');

    expect($roll->get('students')->pluck('name'))->toContain('محمد أحمد الغامدي');
});

it('dates the enrolment in Riyadh rather than in UTC', function () {
    manager()->set('names', 'سعد')->call('createStudent');

    $student = Student::where('name', 'سعد')->first();

    // 01:00 UTC is already the 21st in Riyadh; now() alone would have said the 20th.
    expect($student->joined_at->format('Y-m-d'))->toBe('2026-09-21');
    expect($student->statusHistories()->first()->start_date->format('Y-m-d'))->toBe('2026-09-21');
});

it('creates everyone on a pasted list at once', function () {
    manager()
        ->set('names', "محمد أحمد\nعبدالله سعد، 0555123456\n\n  خالد فهد  \n")
        ->call('createStudent')
        ->assertHasNoErrors();

    expect(Student::where('circle_id', $this->circle->id)->count())->toBe(3);
    expect(Student::where('name', 'عبدالله سعد')->value('phone'))->toBe('0555123456');
    // Blank lines and stray spaces are not students.
    expect(Student::where('name', 'خالد فهد')->exists())->toBeTrue();
});

it('passes over a name already in the circle instead of duplicating it', function () {
    manager()->set('names', 'محمد أحمد')->call('createStudent');
    manager()->set('names', "محمد أحمد\nعبدالله سعد")->call('createStudent');

    expect(Student::where('name', 'محمد أحمد')->count())->toBe(1);
    expect(Student::where('circle_id', $this->circle->id)->count())->toBe(2);
});

it('takes one join date for the whole batch', function () {
    manager()
        ->set('names', "محمد\nعبدالله")
        ->set('joinedAt', '2026-09-06')
        ->call('createStudent')
        ->assertHasNoErrors();

    expect(Student::pluck('joined_at')->map->format('Y-m-d')->unique()->all())->toBe(['2026-09-06']);
});

it('refuses a join date that has not come', function () {
    manager()
        ->set('names', 'محمد')
        ->set('joinedAt', '2026-10-01')
        ->call('createStudent')
        ->assertHasErrors('joinedAt');

    expect(Student::count())->toBe(0);
});

it('shows a student in the register on the very day they joined', function () {
    // Independent of the status: joined_at is stored with a midnight time, and a
    // bare string comparison against "2026-09-21" excluded that same day.
    $student = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'joined_at' => '2026-09-21',
        'status' => 'active',
    ]);

    $roll = Livewire::test(Attendance::class)
        ->set('selectedCircle', $this->circle->id)
        ->call('loadStudents');

    expect($roll->get('students')->pluck('id'))->toContain($student->id);
});

it('gives a row in the month sheet to a student who joined on its last day', function () {
    $sheet = Livewire::test(AttendanceSheet::class)
        ->set('circleId', $this->circle->id);

    // The sheet works in Hijri months, so the boundary is asked of the sheet
    // itself rather than guessed at from a Gregorian calendar.
    $days = $sheet->instance()->days;
    $lastDay = end($days)['date'];

    $student = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'joined_at' => $lastDay,
        'status' => 'active',
    ]);

    $sheet->set('circleId', null)->set('circleId', $this->circle->id);

    expect($sheet->instance()->students->pluck('id'))->toContain($student->id);
});

it('changes the status of everyone selected in one go', function () {
    $students = Student::factory()->count(3)->create([
        'circle_id' => $this->circle->id,
        'status' => 'registering',
    ]);

    Livewire::test('teacher.student-manager')
        ->set('selected', $students->pluck('id')->map(fn ($id) => (string) $id)->all())
        ->set('bulkStatus', 'active')
        ->set('bulkStatusDate', '2026-09-21')
        ->call('applyBulkStatus')
        ->assertHasNoErrors()
        ->assertSet('selected', []);

    expect(Student::where('status', 'active')->count())->toBe(3);

    foreach ($students as $student) {
        expect($student->statusHistories()->where('status', 'active')->exists())->toBeTrue();
    }
});

it('changes the join date of everyone selected in one go', function () {
    $students = Student::factory()->count(3)->create(['circle_id' => $this->circle->id]);

    Livewire::test('teacher.student-manager')
        ->set('selected', $students->pluck('id')->map(fn ($id) => (string) $id)->all())
        ->set('bulkJoinedAt', '2026-09-06')
        ->call('applyBulkJoinedAt')
        ->assertHasNoErrors();

    expect(Student::pluck('joined_at')->map->format('Y-m-d')->unique()->all())->toBe(['2026-09-06']);
});

it('will not touch a student in somebody elses circle', function () {
    $elsewhere = Student::factory()->create([
        'circle_id' => Circle::factory()->create()->id,
        'status' => 'registering',
    ]);

    Livewire::test('teacher.student-manager')
        ->set('selected', [(string) $elsewhere->id])
        ->set('bulkStatus', 'active')
        ->call('applyBulkStatus');

    expect($elsewhere->fresh()->status)->toBe('registering');
});

it('refuses a bulk status date that has not come', function () {
    $student = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'registering']);

    Livewire::test('teacher.student-manager')
        ->set('selected', [(string) $student->id])
        ->set('bulkStatusDate', '2026-10-05')
        ->call('applyBulkStatus')
        ->assertHasErrors('bulkStatusDate');

    expect($student->fresh()->status)->toBe('registering');
});

it('keeps the selection when a teacher may not change statuses', function () {
    $this->teacher->update(['permissions' => ['can_change_student_status' => false]]);
    $student = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'registering']);

    Livewire::test('teacher.student-manager')
        ->set('selected', [(string) $student->id])
        ->set('bulkStatus', 'active')
        ->call('applyBulkStatus');

    expect($student->fresh()->status)->toBe('registering');
});

it('selects and clears the whole page with one tick', function () {
    $students = Student::factory()->count(4)->create(['circle_id' => $this->circle->id]);

    $page = Livewire::test('teacher.student-manager')->call('toggleSelectAll');

    expect($page->get('selected'))->toHaveCount(4);
    expect($page->get('selected'))->toContain((string) $students->first()->id);

    $page->call('toggleSelectAll');

    expect($page->get('selected'))->toBe([]);
});
