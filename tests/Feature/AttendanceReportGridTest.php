<?php

use App\Livewire\Manager\AttendanceReports;
use App\Livewire\Teacher\Attendance as TeacherAttendance;
use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Manager;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\AttendanceReportGrid;
use App\Services\StudentStatusService;
use App\Support\HijriDate;
use App\Support\RollCallReminder;
use Livewire\Livewire;

/*
 * Wednesday 8 July 2026. The middle stage meets Sunday to Thursday, the
 * primary stage Sunday and Monday only. The report runs Saturday 4 July to
 * Thursday 9 July.
 */
beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-07-08 10:00:00');

    $this->middle = Stage::factory()->create(['name' => 'المتوسطة', 'position' => 1]);
    $this->primary = Stage::factory()->create(['name' => 'الابتدائية', 'position' => 2]);

    foreach ([[$this->middle, [1, 2, 3, 4, 5]], [$this->primary, [1, 2]]] as [$stage, $weekdays]) {
        AcademicCalendarEvent::create([
            'event_name' => 'الفصل', 'start_date' => '2026-06-01', 'end_date' => '2026-08-30',
            'is_attendance_period' => true, 'weekdays' => $weekdays, 'is_visible' => true, 'stage_ids' => [$stage->id],
        ]);
    }
    AcademicCalendarEvent::forgetPeriodCache();

    $this->teacher = Teacher::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->middle->id, 'name' => 'حلقة الفاروق']);
    $this->other = Circle::factory()->create(['stage_id' => $this->primary->id, 'name' => 'حلقة النور']);
    $this->teacher->circles()->attach($this->circle->id);

    $this->students = collect(range(1, 4))->map(function (int $n) {
        $student = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'active', 'is_approved' => true, 'joined_at' => '2026-06-01']);
        $student->statusHistories()->create(['status' => 'active', 'start_date' => '2026-06-01']);

        return $student;
    });

    $mark = fn (Student $student, string $date, string $status) => Attendance::create([
        'student_id' => $student->id, 'teacher_id' => $this->teacher->id,
        'circle_id' => $this->circle->id, 'date' => $date, 'status' => $status,
    ]);

    // Sunday: every one marked, one each way.
    foreach (['present', 'late', 'absent', 'excused'] as $i => $status) {
        $mark($this->students[$i], '2026-07-05', $status);
    }
    // Monday: two marked, two left out. Tuesday: nobody took the roll.
    $mark($this->students[0], '2026-07-06', 'present');
    $mark($this->students[1], '2026-07-06', 'present');
});

function grid(): array
{
    return AttendanceReportGrid::build('2026-07-04', '2026-07-09');
}

function cellOf(array $grid, Circle $circle, string $date): array
{
    foreach ($grid['groups'] as $group) {
        foreach ($group['circles'] as $row) {
            if ($row['circle']->id === $circle->id) {
                return $row['cells'][$date];
            }
        }
    }

    throw new RuntimeException('No such circle in the grid.');
}

it('measures a day against the students on the roll, the excused left out of the rate', function () {
    $sunday = cellOf(grid(), $this->circle, '2026-07-05');

    expect($sunday)->toMatchArray([
        'state' => 'data', 'present' => 2, 'late' => 1, 'absent' => 1, 'excused' => 1,
        'expected' => 4, 'unmarked' => 0, 'rate' => 67,
    ]);
});

it('shows the students the teacher left unmarked instead of shrinking the day', function () {
    $monday = cellOf(grid(), $this->circle, '2026-07-06');

    expect($monday)->toMatchArray(['state' => 'data', 'present' => 2, 'expected' => 4, 'unmarked' => 2, 'rate' => 100]);
});

it('tells a working day with no roll call from a day the stage does not meet, and from today', function () {
    $grid = grid();

    expect(cellOf($grid, $this->circle, '2026-07-07')['state'])->toBe('missing')   // Tuesday, a working day.
        ->and(cellOf($grid, $this->circle, '2026-07-04')['state'])->toBe('off')    // Saturday.
        ->and(cellOf($grid, $this->circle, '2026-07-08')['state'])->toBe('pending') // Today.
        ->and(cellOf($grid, $this->circle, '2026-07-09')['state'])->toBe('future')
        // The primary stage does not meet on Tuesday, and its circle has nobody on its roll.
        ->and(cellOf($grid, $this->other, '2026-07-07')['state'])->toBe('off')
        ->and(cellOf($grid, $this->other, '2026-07-06')['state'])->toBe('empty');
});

it('sums each circle and the academy over the range', function () {
    $grid = grid();
    $row = $grid['groups'][0]['circles'][0];

    expect($row['totals'])->toMatchArray(['present' => 4, 'late' => 1, 'absent' => 1, 'excused' => 1, 'unmarked' => 2, 'missing' => 1, 'rate' => 80])
        ->and($grid['days']['2026-07-05']['rate'])->toBe(67)
        ->and($grid['days']['2026-07-07']['missing'])->toBe(1)
        ->and($grid['summary'])->toBe([
            'rate' => 80, 'missing' => 1, 'missing_circles' => 1, 'unmarked' => 2,
            'worst' => ['name' => 'حلقة الفاروق', 'rate' => 80],
        ]);
});

it('works the line and the summary out for the stages picked', function () {
    $grid = grid();

    expect(AttendanceReportGrid::select($grid, [$this->middle->id])['summary']['rate'])->toBe(80)
        ->and(AttendanceReportGrid::select($grid, [(string) $this->primary->id])['summary'])
        ->toBe(['rate' => null, 'missing' => 0, 'missing_circles' => 0, 'unmarked' => 0, 'worst' => null])
        ->and(AttendanceReportGrid::select($grid, [$this->primary->id])['days']['2026-07-07']['missing'])->toBe(0)
        ->and(AttendanceReportGrid::select($grid, [])['days']['2026-07-07']['missing'])->toBe(1);
});

it('draws the day cells as links to the names behind them', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(AttendanceReports::class)
        ->set('fromDate', '2026-07-04')
        ->set('toDate', '2026-07-09')
        ->assertSee('data-summary-missing="1"', false)
        ->assertSee('data-summary-unmarked="2"', false)
        ->assertSee('data-cell="'.$this->circle->id.'-2026-07-07" data-state="missing"', false)
        ->assertSee('لم يُحضَّر')
        ->assertSee('٢ / ٣')
        ->assertSee('٢ لم يُسجَّل')
        ->assertSee('data-circle-rate="80"', false)
        ->assertSee(route('manager.attendance-list', ['circleId' => $this->circle->id, 'date' => '2026-07-05']), false);
});

it('asks the circle\'s teacher over WhatsApp to take a missed roll, with a link onto that day', function () {
    $this->teacher->update(['name' => 'خالد عبدالله', 'phone' => '0501234567', 'access_token' => 'tok-123']);
    $this->actingAs(Manager::factory()->create(), 'manager');

    $html = Livewire::test(AttendanceReports::class)
        ->set('fromDate', '2026-07-04')
        ->set('toDate', '2026-07-09')
        ->html();

    expect(preg_match('/data-cell="'.$this->circle->id.'-2026-07-07"[^>]*>(.*?)<\/td>/s', $html, $cell))->toBe(1);

    $url = html_entity_decode(str($cell[1])->after('href="')->before('"')->toString());
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://wa.me/966501234567?')
        ->and($cell[1])->toContain('data-reminder')
        ->and($query['text'])->toContain('أ. خالد')
        ->toContain(HijriDate::dayMonth('2026-07-07'))
        ->toContain(route('teacher.magic-link', ['token' => 'tok-123', 'redirect' => route('teacher.attendance', ['date' => '2026-07-07'])]));
});

it('opens the day page instead when the teacher has no phone to remind', function () {
    $this->teacher->update(['phone' => null]);
    $this->actingAs(Manager::factory()->create(), 'manager');

    $html = Livewire::test(AttendanceReports::class)->set('fromDate', '2026-07-04')->set('toDate', '2026-07-09')->html();

    preg_match('/data-cell="'.$this->circle->id.'-2026-07-07"[^>]*>(.*?)<\/td>/s', $html, $cell);

    expect($cell[1])->not->toContain('wa.me')
        ->toContain(route('manager.attendance-list', ['circleId' => $this->circle->id, 'date' => '2026-07-07']));
});

it('says it is fetching while a new range is read', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    $html = Livewire::test(AttendanceReports::class)->html();

    expect($html)->toMatch('/wire:loading\.flex[^>]*wire:target="fromDate, toDate, clearFilters, downloadPDF"[^>]*data-report-loading/')
        ->toContain('جارٍ جلب بيانات الفترة')
        ->toContain('wire:loading.class="opacity-40 pointer-events-none"');
});

it('writes the reminder number the way wa.me reads it', function () {
    expect(RollCallReminder::phone('0501234567'))->toBe('966501234567')
        ->and(RollCallReminder::phone('+966 50 123 4567'))->toBe('966501234567')
        ->and(RollCallReminder::whatsappUrl(null, '2026-07-07'))->toBeNull();
});

it('says when the range runs backwards, rather than asking for one', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(AttendanceReports::class)
        ->set('fromDate', '2026-07-09')
        ->set('toDate', '2026-07-04')
        ->assertSee('تاريخ البداية بعد تاريخ النهاية');
});

it('opens on the last seven days in Riyadh, where it is already tomorrow after 21:00 UTC', function () {
    Carbon\Carbon::setTestNow('2026-07-08 22:30:00');
    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(AttendanceReports::class)
        ->assertSet('toDate', '2026-07-09')
        ->assertSet('fromDate', '2026-07-03');
});

it('puts a student on the roll call the very day they are made active', function () {
    $newcomer = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'registering', 'is_approved' => true]);
    $newcomer->statusHistories()->create(['status' => 'registering', 'start_date' => '2026-06-01']);
    StudentStatusService::changeStatus($newcomer, 'active', '2026-07-08');

    $this->actingAs($this->teacher, 'teacher');

    $roll = Livewire::test(TeacherAttendance::class)
        ->set('selectedCircle', $this->circle->id)
        ->set('date', '2026-07-08');

    expect(collect($roll->get('students'))->pluck('id'))->toContain($newcomer->id)
        ->and(AttendanceReportGrid::onRoll($newcomer->fresh('statusHistories'), '2026-07-08'))->toBeTrue()
        ->and(AttendanceReportGrid::onRoll($newcomer->fresh('statusHistories'), '2026-07-07'))->toBeFalse();
});

it('sends the day page back to the report it was opened from', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');
    $report = route('manager.attendance-reports');

    $this->from($report)
        ->get(route('manager.attendance-list', ['circleId' => $this->circle->id, 'date' => '2026-07-05']))
        ->assertSuccessful()
        ->assertSee('href="'.$report.'"', false);
});
