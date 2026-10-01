<?php

use App\Models\Attendance;
use App\Models\Circle;
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
