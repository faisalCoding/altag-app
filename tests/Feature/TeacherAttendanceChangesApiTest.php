<?php

use App\Models\Attendance;
use App\Models\AttendanceRevision;
use App\Models\Circle;
use App\Models\GamificationTransaction;
use App\Models\Guardian;
use App\Models\GuardianNotification;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentStatusHistory;
use App\Models\Teacher;
use App\Services\GamificationService;
use App\Support\HijriDate;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Carbon::setTestNow('2026-07-08 10:00:00'); // Wednesday, a working day.

    $this->stage = Stage::factory()->create(['require_edit_reason' => true]);
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);

    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::factory()->create(['circle_id' => $this->circle->id]);

    Sanctum::actingAs($this->teacher);
});

/**
 * One queued edit as the phone sends it: marking the student present today,
 * on a cell the server held nothing for.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function attendanceChange(array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid(),
        'student_id' => test()->student->id,
        'date' => '2026-07-08',
        'status' => 'present',
        'base_status' => null,
        'edited_on' => '2026-07-08',
    ], $overrides);
}

it('applies a same-day mark and records it like a sheet edit', function () {
    $change = attendanceChange();

    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [$change]])
        ->assertSuccessful()
        ->assertJsonPath('data.results.0.id', $change['id'])
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.attendance.status', 'present')
        ->assertJsonPath('data.results.0.attendance.date', '2026-07-08');

    $attendance = Attendance::where('student_id', $this->student->id)->sole();
    expect($attendance->status)->toBe('present')
        ->and($attendance->teacher_id)->toBe($this->teacher->id)
        ->and($attendance->circle_id)->toBe($this->circle->id);

    $revision = AttendanceRevision::sole();
    expect($revision->attendance_id)->toBe($attendance->id)
        ->and($revision->old_status)->toBeNull()
        ->and($revision->new_status)->toBe('present')
        ->and($revision->is_off_day_edit)->toBeFalse()
        ->and($revision->reason)->toBeNull()
        ->and($revision->edited_on->toDateString())->toBe('2026-07-08')
        ->and($revision->edited_by_id)->toBe($this->teacher->id)
        ->and($revision->edited_by_type)->toBe('teacher');
});

it('answers every change in a batch on its own', function () {
    $other = Student::factory()->create(['circle_id' => $this->circle->id]);

    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [
        attendanceChange(['date' => '2026-07-09']),
        attendanceChange(['student_id' => $other->id, 'status' => 'absent']),
    ]])
        ->assertSuccessful()
        ->assertJsonPath('data.results.0.result', 'rejected')
        ->assertJsonPath('data.results.0.code', 'future_date')
        ->assertJsonPath('data.results.1.result', 'applied');

    expect(Attendance::where('student_id', $other->id)->value('status'))->toBe('absent');
});

it('refuses an off-day edit without a reason when the stage asks for one', function () {
    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [
        attendanceChange(['date' => '2026-07-06', 'reason' => '   ']),
    ]])
        ->assertJsonPath('data.results.0.result', 'rejected')
        ->assertJsonPath('data.results.0.code', 'reason_required');

    expect(Attendance::count())->toBe(0)
        ->and(AttendanceRevision::count())->toBe(0);
});

it('applies an off-day edit with its reason on the revision', function () {
    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [
        attendanceChange(['date' => '2026-07-06', 'reason' => 'نسيت التحضير في يومه']),
    ]])
        ->assertJsonPath('data.results.0.result', 'applied');

    $revision = AttendanceRevision::sole();
    expect($revision->is_off_day_edit)->toBeTrue()
        ->and($revision->reason)->toBe('نسيت التحضير في يومه')
        ->and($revision->edited_on->toDateString())->toBe('2026-07-08');
});

it('applies an off-day edit without a reason when the supervisor turned the requirement off', function () {
    $this->stage->update(['require_edit_reason' => false]);

    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [
        attendanceChange(['date' => '2026-07-06']),
    ]])
        ->assertJsonPath('data.results.0.result', 'applied');

    expect(AttendanceRevision::sole()->is_off_day_edit)->toBeTrue();
});

it('does not treat a register taken offline on the day as an off-day edit when it syncs later', function () {
    Carbon::setTestNow('2026-07-09 10:00:00');

    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [
        attendanceChange(['date' => '2026-07-08', 'edited_on' => '2026-07-08']),
    ]])
        ->assertJsonPath('data.results.0.result', 'applied');

    $revision = AttendanceRevision::sole();
    expect($revision->is_off_day_edit)->toBeFalse()
        ->and($revision->edited_on->toDateString())->toBe('2026-07-08');
});

it('never dates an edit after today, whatever the phone clock says', function () {
    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [
        attendanceChange(['date' => '2026-07-07', 'edited_on' => '2026-07-12']),
    ]])
        ->assertJsonPath('data.results.0.code', 'reason_required');

    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [
        attendanceChange(['date' => '2026-07-07', 'edited_on' => '2026-07-12', 'reason' => 'تصحيح']),
    ]])
        ->assertJsonPath('data.results.0.result', 'applied');

    expect(AttendanceRevision::sole()->edited_on->toDateString())->toBe('2026-07-08');
});

it('holds a change back as a conflict when the server moved since the phone last saw it', function () {
    $colleague = Teacher::factory()->create(['name' => 'خالد']);
    $colleague->circles()->attach($this->circle->id);
    Attendance::create([
        'student_id' => $this->student->id,
        'circle_id' => $this->circle->id,
        'teacher_id' => $colleague->id,
        'date' => '2026-07-08',
        'status' => 'absent',
    ]);

    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [attendanceChange()]])
        ->assertSuccessful()
        ->assertJsonPath('data.results.0.result', 'conflict')
        ->assertJsonPath('data.results.0.attendance.status', 'absent')
        ->assertJsonPath('data.results.0.attendance.updated_by', 'خالد');

    expect(Attendance::sole()->status)->toBe('absent')
        ->and(AttendanceRevision::count())->toBe(0);
});

it('applies the teacher\'s value once the conflict is settled in its favour', function () {
    Attendance::create([
        'student_id' => $this->student->id,
        'circle_id' => $this->circle->id,
        'teacher_id' => $this->teacher->id,
        'date' => '2026-07-08',
        'status' => 'absent',
    ]);

    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [
        attendanceChange(['base_status' => 'absent']),
    ]])
        ->assertJsonPath('data.results.0.result', 'applied');

    expect(Attendance::sole()->status)->toBe('present');

    $revision = AttendanceRevision::sole();
    expect($revision->old_status)->toBe('absent')
        ->and($revision->new_status)->toBe('present');
});

it('reports a resent change as applied without writing it twice', function () {
    $change = attendanceChange();

    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [$change]])
        ->assertJsonPath('data.results.0.result', 'applied');

    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [$change]])
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.attendance.status', 'present');

    expect(Attendance::count())->toBe(1)
        ->and(AttendanceRevision::count())->toBe(1);
});

it('awards attendance XP, and takes it back when the cell is cleared', function () {
    $leaderboard = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة التلعيب',
        'competition_type' => 'gamification',
        'start_date' => now()->subDays(5),
        'end_date' => now()->addDays(5),
        'is_active' => true,
        'settings' => [
            'attendance_enabled' => true,
            'attendance_present_xp' => 10,
            'attendance_present_coins' => 15,
        ],
    ]);
    $leaderboard->circles()->attach($this->circle->id);

    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [attendanceChange()]]);

    expect(GamificationService::getStudentXP($this->student->id, $leaderboard->id))->toBe(10);

    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [
        attendanceChange(['status' => null, 'base_status' => 'present']),
    ]])
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.attendance', null);

    expect(Attendance::count())->toBe(0)
        ->and(GamificationTransaction::where('reference_type', Attendance::class)->count())->toBe(0)
        ->and(GamificationService::getStudentXP($this->student->id, $leaderboard->id))->toBe(0);

    $cleared = AttendanceRevision::latest('id')->first();
    expect($cleared->old_status)->toBe('present')
        ->and($cleared->new_status)->toBeNull()
        ->and($cleared->attendance_id)->toBeNull();
});

it('tells the guardian about an absence once it is written', function () {
    $guardian = Guardian::factory()->create(['is_approved' => true]);
    $this->student->update(['guardian_id' => $guardian->id]);

    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [
        attendanceChange(['status' => 'absent']),
    ]]);

    expect(GuardianNotification::where('guardian_id', $guardian->id)->where('type', 'absence')->count())->toBe(1);
});

it('refuses a day the sheet would not let the teacher mark', function (Closure $arrange, array $overrides, string $code) {
    $arrange($this);

    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [
        attendanceChange($overrides + ['reason' => 'سبب']),
    ]])
        ->assertJsonPath('data.results.0.result', 'rejected')
        ->assertJsonPath('data.results.0.code', $code);

    expect(Attendance::count())->toBe(0);
})->with([
    'a day still to come' => [fn () => null, ['date' => '2026-07-09'], 'future_date'],
    'a day the stage does not meet' => [fn () => null, ['date' => '2026-07-03'], 'not_working_day'],
    'a day before the student joined' => [
        fn ($test) => $test->student->update(['joined_at' => '2026-07-07']),
        ['date' => '2026-07-06'],
        'not_enrolled',
    ],
    'a day the student was suspended' => [
        fn ($test) => StudentStatusHistory::create(['student_id' => $test->student->id, 'status' => 'suspended', 'start_date' => '2026-07-01']),
        [],
        'inactive_on_date',
    ],
    'a student of another circle' => [
        fn ($test) => $test->student->update(['circle_id' => Circle::factory()->create()->id]),
        [],
        'student_unavailable',
    ],
    'a student not approved' => [
        fn ($test) => $test->student->update(['is_approved' => false]),
        [],
        'student_unavailable',
    ],
]);

it('words a closed day the way the sheet does', function () {
    $this->student->update(['joined_at' => '2026-07-07']);

    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [
        attendanceChange(['date' => '2026-07-06', 'reason' => 'سبب']),
    ]])
        ->assertJsonPath('data.results.0.message', 'لم يكن الطالب قد التحق بالمجمع بعد — التحق في '.HijriDate::full('2026-07-07').'.');
});

it('validates the shape of every change', function (array $overrides) {
    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [attendanceChange($overrides)]])
        ->assertUnprocessable();
})->with([
    'an unknown status' => [['status' => 'sleeping']],
    'a malformed date' => [['date' => '08/07/2026']],
    'an id that is not a uuid' => [['id' => '42']],
]);

it('requires the status the phone last saw, even when it was nothing', function () {
    $change = attendanceChange();
    unset($change['base_status']);

    $this->postJson('/api/v1/teacher/attendance/changes', ['changes' => [$change]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('changes.0.base_status');
});
