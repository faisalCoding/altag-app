<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Guardian;
use App\Models\Leaderboard;
use App\Models\LeaderboardCriterion;
use App\Models\LeaderboardScore;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentStatusHistory;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Support\HijriDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-07-08 10:00:00'); // Wednesday, a working day.

    $this->stage = Stage::factory()->create(['require_edit_reason' => false]);
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);

    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->token = $this->teacher->createToken('phone')->plainTextToken;
});

it('sends only the circles the teacher takes attendance for, with their calendar', function () {
    Circle::factory()->create();

    $response = $this->withToken($this->token)->getJson('/api/v1/teacher/sync')->assertSuccessful();

    expect($response->json('data.circles'))->toHaveCount(1);

    $response->assertJsonPath('data.circles.0.id', $this->circle->id)
        ->assertJsonPath('data.circles.0.stage.id', $this->stage->id)
        ->assertJsonPath('data.circles.0.require_edit_reason', false);

    expect($response->json('data.circles.0.working_days'))
        ->toContain('2026-07-08')
        ->not->toContain('2026-07-03') // A Friday.
        ->toContain('2026-07-19'); // A Sunday the phone may reach offline.
});

it('reads today on the academy clock rather than UTC', function () {
    Carbon::setTestNow(Carbon::create(2026, 7, 8, 22, 0, 0, 'UTC')); // 01:00 on the 9th in Riyadh.

    $this->withToken($this->token)->getJson('/api/v1/teacher/sync')
        ->assertJsonPath('data.today', '2026-07-09')
        ->assertJsonPath('data.window.to', '2026-07-23');
});

it('starts the window on the first day of the previous Hijri month', function () {
    Carbon::setTestNow('2026-03-25 10:00:00'); // ٦ شوال ١٤٤٧.

    $this->withToken($this->token)->getJson('/api/v1/teacher/sync')
        ->assertJsonPath('data.window.from', '2026-02-18'); // ١ رمضان ١٤٤٧.
});

it('labels every day of the window in Hijri', function () {
    $response = $this->withToken($this->token)->getJson('/api/v1/teacher/sync');
    $days = $response->json('data.days');

    expect(array_key_first($days))->toBe($response->json('data.window.from'))
        ->and(array_key_last($days))->toBe('2026-07-22')
        ->and($days['2026-07-08'])->toBe([
            'weekday' => HijriDate::weekday('2026-07-08'),
            'day' => HijriDate::format('2026-07-08', 'd'),
            'month' => HijriDate::monthYear('2026-07-08'),
            'full' => HijriDate::withWeekday('2026-07-08'),
        ]);
});

it('sends the approved students of the teacher\'s circles with their status history', function () {
    $student = Student::factory()->create(['circle_id' => $this->circle->id, 'joined_at' => '2026-06-01']);
    StudentStatusHistory::create(['student_id' => $student->id, 'status' => 'suspended', 'start_date' => '2026-06-20']);

    Student::factory()->create(['circle_id' => $this->circle->id, 'is_approved' => false]);
    Student::factory()->create(['circle_id' => Circle::factory()->create()->id]);

    $response = $this->withToken($this->token)->getJson('/api/v1/teacher/sync');

    expect($response->json('data.students'))->toHaveCount(1);

    $response->assertJsonPath('data.students.0.id', $student->id)
        ->assertJsonPath('data.students.0.joined_at', '2026-06-01')
        ->assertJsonPath('data.students.0.joined_at_hijri', HijriDate::full('2026-06-01'))
        ->assertJsonPath('data.students.0.status_histories.0.status', 'suspended')
        ->assertJsonPath('data.students.0.status_histories.0.start_date', '2026-06-20');
});

/**
 * An attendance period, with the request's cached periods forgotten so the
 * next read sees it.
 */
function syncPeriod(array $attributes): AcademicCalendarEvent
{
    $period = AcademicCalendarEvent::create(array_merge([
        'event_name' => 'فترة دوام الحلقات',
        'start_date' => '2026-07-01',
        'end_date' => null,
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5], // Sunday to Thursday.
        'is_visible' => true,
    ], $attributes));

    AcademicCalendarEvent::forgetPeriodCache();

    return $period;
}

it('sends each student\'s phone and their guardian\'s name and phone', function () {
    $guardian = Guardian::factory()->create(['name' => 'أبو محمد', 'phone' => '0501234567']);
    $withGuardian = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'أحمد', 'phone' => '0559876543', 'guardian_id' => $guardian->id]);
    $withoutGuardian = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'بدر', 'phone' => null]);

    $students = collect($this->withToken($this->token)->getJson('/api/v1/teacher/sync')->assertSuccessful()->json('data.students'))->keyBy('id');

    expect($students[$withGuardian->id])->toMatchArray([
        'phone' => '0559876543',
        'guardian_name' => 'أبو محمد',
        'guardian_phone' => '0501234567',
    ])
        ->and($students[$withoutGuardian->id])->toMatchArray([
            'phone' => null,
            'guardian_name' => null,
            'guardian_phone' => null,
        ]);
});

it('reads the guardians of all the students in one query', function () {
    foreach (range(1, 3) as $i) {
        Student::factory()->create([
            'circle_id' => $this->circle->id,
            'guardian_id' => Guardian::factory()->create(['phone' => "050000000{$i}"])->id,
        ]);
    }

    DB::enableQueryLog();
    $this->withToken($this->token)->getJson('/api/v1/teacher/sync')->assertSuccessful();
    $guardianQueries = collect(DB::getQueryLog())->filter(
        fn (array $query) => str_contains($query['query'], 'from "users"') && in_array('guardian', $query['bindings'], true),
    );
    DB::disableQueryLog();

    expect($guardianQueries)->toHaveCount(1);
});

it('sends each circle the attendance period covering today, with the days it has met so far', function () {
    syncPeriod(['start_date' => '2026-01-04', 'end_date' => '2026-06-30']);
    syncPeriod(['start_date' => '2026-07-01', 'end_date' => '2026-08-31']);

    $this->withToken($this->token)->getJson('/api/v1/teacher/sync')
        ->assertJsonPath('data.circles.0.period', [
            'start' => '2026-07-01',
            'end' => '2026-08-31',
            // Sunday to Thursday up to today, a Wednesday.
            'working_days' => ['2026-07-01', '2026-07-02', '2026-07-05', '2026-07-06', '2026-07-07', '2026-07-08'],
        ])
        // The period starts inside the window, whose days already travel.
        ->assertJsonPath('data.attendance_history', []);
});

it('falls back to the last period that started before today when none covers it', function () {
    syncPeriod(['start_date' => '2026-06-01', 'end_date' => '2026-06-30']);
    syncPeriod(['start_date' => '2026-07-12', 'end_date' => '2026-08-31']); // Not started yet.

    $period = $this->withToken($this->token)->getJson('/api/v1/teacher/sync')->json('data.circles.0.period');

    expect($period['start'])->toBe('2026-06-01')
        ->and($period['end'])->toBe('2026-06-30')
        ->and($period['working_days'][0])->toBe('2026-06-01')
        ->and(end($period['working_days']))->toBe('2026-06-30'); // A Tuesday, its last day.
});

it('reads each circle\'s period from its own stage, else the academy-wide one', function () {
    $otherStage = Stage::factory()->create();
    $otherCircle = Circle::factory()->create(['stage_id' => $otherStage->id]);
    $this->teacher->circles()->attach($otherCircle->id);

    syncPeriod(['start_date' => '2026-06-21', 'stage_ids' => [$this->stage->id]]);
    syncPeriod(['start_date' => '2026-06-28', 'stage_ids' => []]); // Academy-wide, and started later.
    syncPeriod(['start_date' => '2026-07-05', 'stage_ids' => [Stage::factory()->create()->id]]); // Neither circle's.

    $periods = collect($this->withToken($this->token)->getJson('/api/v1/teacher/sync')->json('data.circles'))
        ->mapWithKeys(fn (array $circle) => [$circle['id'] => $circle['period']['start']]);

    expect($periods[$this->circle->id])->toBe('2026-06-21')
        ->and($periods[$otherCircle->id])->toBe('2026-06-28');
});

it('sends the attendance from the start of the period up to the window', function () {
    syncPeriod(['start_date' => '2026-04-05']); // A Sunday in Shawwal, before the window.

    $student = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'أحمد']);
    $classmate = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'بدر']);
    $stranger = Student::factory()->create(['circle_id' => Circle::factory()->create()->id]);

    foreach ([
        [$student, '2026-04-01', 'present'], // Before the period.
        [$student, '2026-05-17', 'late'],    // The day before the window.
        [$student, '2026-04-05', 'absent'],
        [$student, '2026-05-18', 'present'], // The window's first day.
        [$classmate, '2026-04-06', 'excused'],
        [$stranger, '2026-04-06', 'absent'],
    ] as [$owner, $date, $status]) {
        Attendance::create(['student_id' => $owner->id, 'circle_id' => $this->circle->id, 'teacher_id' => $this->teacher->id, 'date' => $date, 'status' => $status]);
    }

    $response = $this->withToken($this->token)->getJson('/api/v1/teacher/sync')
        ->assertJsonPath('data.window.from', '2026-05-18')
        ->assertJsonPath('data.circles.0.period.start', '2026-04-05')
        ->assertJsonPath('data.circles.0.period.end', null);

    $expected = collect([
        ['student_id' => $student->id, 'date' => '2026-04-05', 'status' => 'absent'],
        ['student_id' => $student->id, 'date' => '2026-05-17', 'status' => 'late'],
        ['student_id' => $classmate->id, 'date' => '2026-04-06', 'status' => 'excused'],
    ])->sortBy([['student_id', 'asc'], ['date', 'asc']])->values()->all();

    expect($response->json('data.attendance_history'))->toBe($expected)
        ->and(collect($response->json('data.attendances'))->pluck('date')->all())->toBe(['2026-05-18']);

    expect($response->json('data.circles.0.period.working_days'))
        ->toContain('2026-04-05', '2026-05-17', '2026-07-08')
        ->not->toContain('2026-04-10'); // A Friday.
});

it('sends no period and no earlier attendance when the calendar holds none', function () {
    $student = Student::factory()->create(['circle_id' => $this->circle->id]);
    Attendance::create(['student_id' => $student->id, 'circle_id' => $this->circle->id, 'teacher_id' => $this->teacher->id, 'date' => '2026-04-05', 'status' => 'present']);

    $this->withToken($this->token)->getJson('/api/v1/teacher/sync')
        ->assertJsonPath('data.circles.0.period', null)
        ->assertJsonPath('data.attendance_history', []);
});

it('starts the hijri months at the month holding the earliest date the app shows', function () {
    $months = fn () => collect($this->withToken($this->token)->getJson('/api/v1/teacher/sync')->json('data.hijri_months'));

    // The window's first month, ذو الحجة, through five months after محرم, today's.
    expect($months()->pluck('key')->all())->toBe(['1447-12', '1448-01', '1448-02', '1448-03', '1448-04', '1448-05', '1448-06']);

    syncPeriod(['start_date' => '2026-04-05']); // In شوال.

    $withPeriod = $months();

    expect($withPeriod->first())->toMatchArray(['key' => '1447-10', 'first_day' => '2026-03-20'])
        ->and($withPeriod->pluck('title'))->toContain(HijriDate::monthYear('2026-07-08'))
        ->and($withPeriod->last()['key'])->toBe('1448-06')
        ->and($withPeriod)->toHaveCount(9);
});

it('sends the competition each circle grades in: the supervisor\'s primary one, else the teacher\'s own', function () {
    $ownCircle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $this->teacher->circles()->attach($ownCircle->id);

    $supervisorCompetition = Leaderboard::create([
        'circle_id' => $this->circle->id, 'title' => 'مسابقة المرحلة', 'competition_type' => 'gamification',
        'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'is_active_for_grading' => true,
        'supervisor_id' => Supervisor::factory()->create()->id, 'settings' => ['extra_points_enabled' => true],
    ]);
    $supervisorCompetition->circles()->attach($this->circle->id);
    LeaderboardCriterion::create(['leaderboard_id' => $supervisorCompetition->id, 'name' => 'الحفظ المتقن', 'points' => 5, 'is_enthusiasm_trigger' => true]);

    $teacherCompetition = Leaderboard::create([
        'circle_id' => $ownCircle->id, 'title' => 'مسابقة الحلقة', 'competition_type' => 'normal',
        'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'is_active_for_grading' => true, 'settings' => [],
    ]);
    Leaderboard::create([
        'circle_id' => $this->circle->id, 'title' => 'ليست أساسية', 'competition_type' => 'normal',
        'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'is_active_for_grading' => false, 'settings' => [],
    ]);

    $competitions = collect($this->withToken($this->token)->getJson('/api/v1/teacher/sync')->json('data.competitions'))->keyBy('id');

    expect($competitions->keys()->all())->toEqualCanonicalizing([$supervisorCompetition->id, $teacherCompetition->id])
        ->and($competitions[$supervisorCompetition->id])->toMatchArray([
            'title' => 'مسابقة المرحلة',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'extra_points_enabled' => true,
            'circle_ids' => [$this->circle->id],
        ])
        ->and($competitions[$supervisorCompetition->id]['criteria'][0])->toMatchArray([
            'name' => 'الحفظ المتقن',
            'points' => 5,
            'is_enthusiasm_trigger' => true,
        ])
        ->and($competitions[$teacherCompetition->id]['circle_ids'])->toBe([$ownCircle->id]);
});

it('sends the window\'s scores and extra points in those competitions', function () {
    $student = Student::factory()->create(['circle_id' => $this->circle->id]);
    $competition = Leaderboard::create([
        'circle_id' => $this->circle->id, 'title' => 'مسابقة الحلقة', 'competition_type' => 'normal',
        'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'is_active_for_grading' => true,
        'settings' => ['extra_points_enabled' => true],
    ]);
    $criterion = LeaderboardCriterion::create(['leaderboard_id' => $competition->id, 'name' => 'المراجعة', 'points' => 3]);

    LeaderboardScore::create(['leaderboard_id' => $competition->id, 'leaderboard_criterion_id' => $criterion->id, 'student_id' => $student->id, 'date' => '2026-07-07']);
    DB::table('leaderboard_extra_points')->insert([
        'leaderboard_id' => $competition->id, 'student_id' => $student->id, 'date' => '2026-07-07',
        'points' => 2, 'notes' => 'انضباط', 'uuid' => null,
    ]);

    $response = $this->withToken($this->token)->getJson('/api/v1/teacher/sync');

    expect($response->json('data.scores'))->toBe([[
        'competition_id' => $competition->id,
        'criterion_id' => $criterion->id,
        'student_id' => $student->id,
        'date' => '2026-07-07',
    ]])
        ->and($response->json('data.extra_points.0'))->toMatchArray([
            'competition_id' => $competition->id,
            'student_id' => $student->id,
            'date' => '2026-07-07',
            'points' => 2,
            'notes' => 'انضباط',
        ]);
});

it('sends the window\'s attendance of those students, whichever circle took it', function () {
    $student = Student::factory()->create(['circle_id' => $this->circle->id]);
    $formerCircle = Circle::factory()->create();
    $colleague = Teacher::factory()->create(['name' => 'خالد']);

    Attendance::create(['student_id' => $student->id, 'circle_id' => $formerCircle->id, 'teacher_id' => $colleague->id, 'date' => '2026-07-06', 'status' => 'absent']);
    Attendance::create(['student_id' => $student->id, 'circle_id' => $this->circle->id, 'teacher_id' => $this->teacher->id, 'date' => '2026-07-08', 'status' => 'present']);
    Attendance::create(['student_id' => $student->id, 'circle_id' => $this->circle->id, 'teacher_id' => $this->teacher->id, 'date' => '2026-01-04', 'status' => 'present']);

    $stranger = Student::factory()->create(['circle_id' => $formerCircle->id]);
    Attendance::create(['student_id' => $stranger->id, 'circle_id' => $formerCircle->id, 'teacher_id' => $colleague->id, 'date' => '2026-07-08', 'status' => 'late']);

    $attendances = collect($this->withToken($this->token)->getJson('/api/v1/teacher/sync')->json('data.attendances'))
        ->sortBy('date')
        ->values();

    expect($attendances)->toHaveCount(2)
        ->and($attendances[0])->toMatchArray([
            'student_id' => $student->id,
            'circle_id' => $formerCircle->id,
            'date' => '2026-07-06',
            'status' => 'absent',
            'updated_by' => 'خالد',
        ])
        ->and($attendances[1])->toMatchArray(['date' => '2026-07-08', 'status' => 'present']);
});

it('sends each circle the WhatsApp group its absence message opens: its own, else its stage\'s', function () {
    $this->stage->update(['whatsapp_group_url' => 'https://chat.whatsapp.com/StageGroup1234567890']);
    $ownGroup = Circle::factory()->create([
        'stage_id' => $this->stage->id,
        'whatsapp_group_url' => 'https://chat.whatsapp.com/CircleGroup123456789',
    ]);
    $noGroup = Circle::factory()->create(['stage_id' => Stage::factory()->create()->id]);
    $this->teacher->circles()->attach([$ownGroup->id, $noGroup->id]);

    $groups = collect($this->withToken($this->token)->getJson('/api/v1/teacher/sync')->assertSuccessful()->json('data.circles'))
        ->pluck('whatsapp_group_url', 'id');

    expect($groups[$this->circle->id])->toBe('https://chat.whatsapp.com/StageGroup1234567890')
        ->and($groups[$ownGroup->id])->toBe('https://chat.whatsapp.com/CircleGroup123456789')
        ->and($groups[$noGroup->id])->toBeNull();
});
