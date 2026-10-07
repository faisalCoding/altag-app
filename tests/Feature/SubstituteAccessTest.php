<?php

use App\Livewire\Shared\SubstituteAssignments;
use App\Livewire\Shared\TeacherAttendance as RollCall;
use App\Livewire\Teacher\Attendance;
use App\Livewire\Teacher\AttendanceSheet;
use App\Livewire\Teacher\LeaderboardGrade;
use App\Models\Attendance as StudentAttendance;
use App\Models\Circle;
use App\Models\ExamLevel;
use App\Models\Leaderboard;
use App\Models\LeaderboardCriterion;
use App\Models\LeaderboardScore;
use App\Models\Manager;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\SubstituteAssignment;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-14 09:00:00'); // A Monday.

    // The absent teacher's circle, in the supervisor's stage.
    $this->stage = Stage::factory()->create(['name' => 'المرحلة الأولى', 'require_edit_reason' => false]);
    $this->circle = Circle::factory()->create(['name' => 'حلقة النور', 'stage_id' => $this->stage->id]);
    $this->supervisor = Supervisor::factory()->create();
    $this->supervisor->stages()->attach($this->stage->id);

    $this->absent = Teacher::factory()->create(['name' => 'أستاذ أحمد']);
    $this->absent->circles()->attach($this->circle->id);
    $this->student = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'الطالب سعد']);

    // The substitute teaches in another stage altogether.
    $this->otherStage = Stage::factory()->create(['name' => 'المرحلة الثانوية']);
    $this->ownCircle = Circle::factory()->create(['name' => 'حلقة الفجر', 'stage_id' => $this->otherStage->id]);
    $this->substitute = Teacher::factory()->create(['name' => 'أستاذ خالد']);
    $this->substitute->circles()->attach($this->ownCircle->id);
});

function grantToday(array $overrides = []): SubstituteAssignment
{
    return SubstituteAssignment::create(array_merge([
        'circle_id' => test()->circle->id,
        'teacher_id' => test()->substitute->id,
        'date' => '2026-09-14',
        'absent_teacher_id' => test()->absent->id,
    ], $overrides));
}

// ─────────── Granting from the roll call ───────────

it('grants the absent teacher\'s circles to a substitute of another stage named on the roll call', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(RollCall::class)
        ->assertSee('أستاذ خالد — المرحلة الثانوية')
        ->call('mark', $this->absent->id, 'absent')
        ->call('saveNote', $this->absent->id, 'مريض', null, $this->substitute->id);

    $grant = SubstituteAssignment::sole();
    expect($grant->circle_id)->toBe($this->circle->id);
    expect($grant->teacher_id)->toBe($this->substitute->id);
    expect($grant->absent_teacher_id)->toBe($this->absent->id);
    expect($grant->teacher_attendance_id)->toBe(TeacherAttendance::sole()->id);
    expect($this->substitute->substituteCircleIds())->toBe([$this->circle->id]);
});

it('moves the grant with the substitute, and takes it back when the teacher is present or the day cleared', function () {
    $this->actingAs($this->supervisor, 'supervisor');
    $another = Teacher::factory()->create();

    $page = Livewire::test(RollCall::class)
        ->call('mark', $this->absent->id, 'absent')
        ->call('saveNote', $this->absent->id, '', null, $this->substitute->id)
        ->call('saveNote', $this->absent->id, '', null, $another->id);

    expect(SubstituteAssignment::pluck('teacher_id')->all())->toBe([$another->id]);

    $page->call('mark', $this->absent->id, 'present');
    expect(SubstituteAssignment::count())->toBe(0);

    $page->call('mark', $this->absent->id, 'excused')
        ->call('saveNote', $this->absent->id, '', null, $this->substitute->id)
        ->call('clearDay');
    expect(SubstituteAssignment::count())->toBe(0);
});

// ─────────── Granting directly ───────────

it('grants a circle for today or a day ahead from the substitutes page', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(SubstituteAssignments::class, ['role' => 'supervisor'])
        ->set('circleId', $this->circle->id)
        ->assertSet('absentTeacherId', $this->absent->id)
        ->set('teacherId', $this->substitute->id)
        ->call('grant')
        ->assertSee('أستاذ خالد')
        ->set('date', '2026-09-16')
        ->set('circleId', $this->circle->id)
        ->set('teacherId', $this->substitute->id)
        ->call('grant');

    expect(SubstituteAssignment::orderBy('date')->get()->map(fn ($grant) => $grant->date->format('Y-m-d'))->all())
        ->toBe(['2026-09-14', '2026-09-16']);
});

it('refuses a past day, a circle outside the stages, and the circle\'s own teacher', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(SubstituteAssignments::class, ['role' => 'supervisor'])
        ->set('circleId', $this->ownCircle->id)
        ->set('teacherId', $this->substitute->id)
        ->call('grant')
        ->set('circleId', $this->circle->id)
        ->set('teacherId', $this->absent->id)
        ->call('grant')
        ->set('date', '2026-09-10')
        ->assertSee('مضى هذا اليوم')
        ->set('circleId', $this->circle->id)
        ->set('teacherId', $this->substitute->id)
        ->call('grant');

    expect(SubstituteAssignment::count())->toBe(0);
});

it('revokes a grant, but keeps a past one as the record of who covered', function () {
    $this->actingAs($this->supervisor, 'supervisor');
    $today = grantToday();
    $past = grantToday(['date' => '2026-09-10']);

    Livewire::test(SubstituteAssignments::class, ['role' => 'supervisor'])
        ->call('revoke', $today->id)
        ->call('revoke', $past->id);

    expect(SubstituteAssignment::pluck('id')->all())->toBe([$past->id]);
});

it('lets the manager grant any circle', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(SubstituteAssignments::class, ['role' => 'manager'])
        ->set('circleId', $this->ownCircle->id)
        ->set('teacherId', $this->absent->id)
        ->call('grant');

    expect(SubstituteAssignment::sole()->circle_id)->toBe($this->ownCircle->id);
});

// ─────────── What the grant opens, and only on its day ───────────

it('opens the circle on its day alone', function () {
    grantToday(['date' => '2026-09-15']);

    expect($this->substitute->workingCircleIds())->toBe([$this->ownCircle->id]);

    Carbon::setTestNow('2026-09-15 09:00:00');
    expect($this->substitute->workingCircleIds())->toEqualCanonicalizing([$this->ownCircle->id, $this->circle->id]);
    expect($this->substitute->mayWorkOn($this->circle->id, '2026-09-15'))->toBeTrue();
    expect($this->substitute->mayWorkOn($this->circle->id, '2026-09-14'))->toBeFalse();
    expect($this->substitute->mayWorkOn($this->ownCircle->id, '2026-09-01'))->toBeTrue();

    Carbon::setTestNow('2026-09-16 09:00:00');
    expect($this->substitute->workingCircleIds())->toBe([$this->ownCircle->id]);
});

it('lets the substitute take the circle\'s register for today only', function () {
    grantToday();
    $this->actingAs($this->substitute, 'teacher');

    $page = Livewire::test(Attendance::class)
        ->set('selectedCircle', $this->circle->id)
        ->assertSee('تنوب اليوم في هذه الحلقة')
        ->set('date', '2026-09-10')
        ->assertSet('date', '2026-09-14')
        ->call('markStatus', $this->student->id, 'present');

    expect(StudentAttendance::where('student_id', $this->student->id)->value('status'))->toBe('present');

    // The month's sheet opens today's column alone.
    Livewire::test(AttendanceSheet::class, ['circleId' => $this->circle->id])
        ->call('saveChanges', [
            ['student_id' => $this->student->id, 'date' => '2026-09-10', 'status' => 'absent'],
        ]);

    expect(StudentAttendance::where('student_id', $this->student->id)->whereDate('date', '2026-09-10')->exists())->toBeFalse();
});

it('keeps a teacher with no grant out of the circle\'s register', function () {
    $this->actingAs($this->substitute, 'teacher');

    Livewire::test(Attendance::class)
        ->set('selectedCircle', $this->circle->id)
        ->call('markStatus', $this->student->id, 'present');

    expect(StudentAttendance::count())->toBe(0);
});

it('sends the circle to the substitute\'s phone with today as its only day', function () {
    grantToday();
    Sanctum::actingAs($this->substitute);

    $circles = collect($this->getJson('/api/v1/teacher/sync')->assertSuccessful()->json('data.circles'));
    $stoodIn = $circles->firstWhere('id', $this->circle->id);

    expect($stoodIn['standing_in'])->toBeTrue();
    expect($stoodIn['working_days'])->toBe(['2026-09-14']);
    expect($circles->firstWhere('id', $this->ownCircle->id)['standing_in'])->toBeFalse();
});

it('takes the substitute\'s marks from the phone for today only', function () {
    grantToday();
    Sanctum::actingAs($this->substitute);

    $change = fn (string $date) => [
        'id' => (string) Str::uuid(),
        'student_id' => $this->student->id,
        'date' => $date,
        'status' => 'present',
        'base_status' => null,
        'edited_on' => '2026-09-14',
    ];

    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [$change('2026-09-14'), $change('2026-09-10')]])
        ->assertSuccessful()
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.1.result', 'rejected')
        ->assertJsonPath('data.results.1.code', 'substitute_day_only');
});

it('lets the substitute pair the circle today, not another day', function () {
    grantToday();
    Sanctum::actingAs($this->substitute);

    $this->postJson('/api/v1/teacher/pairs/generate', ['circle_id' => $this->circle->id, 'date' => '2026-09-14'])
        ->assertSuccessful();

    $this->postJson('/api/v1/teacher/pairs/generate', ['circle_id' => $this->circle->id, 'date' => '2026-09-10'])
        ->assertForbidden()
        ->assertJsonPath('code', 'substitute_day_only');
});

it('tells the substitute where they stand in today', function () {
    grantToday();

    $this->actingAs($this->substitute, 'teacher')
        ->get(route('teacher.dashboard'))
        ->assertSuccessful()
        ->assertSee('تنوب اليوم في:')
        ->assertSee('حلقة النور عن أستاذ أحمد');
});

it('opens the substitutes page from both sidebars', function () {
    $this->actingAs($this->supervisor, 'supervisor')
        ->get(route('supervisor.substitutes'))
        ->assertSuccessful()
        ->assertSee('صلاحيات البدلاء');

    $this->actingAs(Manager::factory()->create(), 'manager')
        ->get(route('manager.substitutes'))
        ->assertSuccessful();
});

it('grades the circle\'s tasmeeh on today alone, and leaves its plans to its teacher', function () {
    grantToday();
    $this->actingAs($this->substitute, 'teacher');

    $card = Livewire::test('teacher.student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => collect(),
        'activePlanId' => null,
        'gradedAtDate' => '2026-09-10',
    ]);

    expect($card->viewData('gradingDate'))->toBe('2026-09-14');
    expect($card->viewData('gradingDays'))->toBe(['2026-09-14']);
    expect($card->viewData('standingIn'))->toBeTrue();
    $managing = collect($card->viewData('perms'))->filter(fn ($allowed, $key) => str_starts_with($key, 'can_manage'));
    expect($managing)->not->toBeEmpty();
    expect($managing->filter())->toBeEmpty();
});

it('scores the circle\'s competition for today only', function () {
    grantToday();
    $competition = Leaderboard::create([
        'title' => 'مسابقة الحلقة',
        'circle_id' => $this->circle->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'is_active' => true,
        'competition_type' => 'normal',
    ]);
    $criterion = LeaderboardCriterion::create(['leaderboard_id' => $competition->id, 'name' => 'الحضور المبكر', 'points' => 5]);

    $this->actingAs($this->substitute, 'teacher');

    Livewire::test(LeaderboardGrade::class, ['leaderboardId' => $competition->id])
        ->call('toggleScore', $this->student->id, $criterion->id, 5)
        ->set('date', '2026-09-10')
        ->call('toggleScore', $this->student->id, $criterion->id, 5);

    expect(LeaderboardScore::get()->map(fn ($score) => Carbon::parse($score->date)->toDateString())->all())
        ->toBe(['2026-09-14']);
});

it('shows the substitute today\'s exams of the circle, and no others', function () {
    grantToday();
    $level = ExamLevel::create(['name' => 'المستوى الأول']);
    StudentExam::create(['student_id' => $this->student->id, 'exam_level_id' => $level->id, 'status' => 'pending', 'date_time' => '2026-09-14 16:00:00', 'location' => 'قاعة اليوم']);
    StudentExam::create(['student_id' => $this->student->id, 'exam_level_id' => $level->id, 'status' => 'pending', 'date_time' => '2026-09-20 16:00:00', 'location' => 'قاعة الأسبوع القادم']);

    $this->actingAs($this->substitute, 'teacher');

    Livewire::test('teacher.student-exams')
        ->assertSee('الطالب سعد')
        ->assertSee('قاعة اليوم')
        ->assertDontSee('قاعة الأسبوع القادم');
});
