<?php

use App\Models\AppNotification;
use App\Models\Ayah;
use App\Models\Circle;
use App\Models\FreeRecitation;
use App\Models\GamificationStudentState;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\PlanDayAttempt;
use App\Models\RoleScreenPermission;
use App\Models\Screen;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Surah;
use App\Models\Teacher;
use App\Services\GamificationService;
use App\Services\PlanDayAttempts;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Carbon::setTestNow('2026-07-08 10:00:00'); // 13:00 in Riyadh.

    // Two short surahs: ayah ids 1–7 are 1:1–1:7, and 8–12 are 2:1–2:5.
    foreach ([[1, 'الفاتحة', 7], [2, 'البقرة', 5]] as [$number, $name, $verses]) {
        Surah::create([
            'id' => $number, 'number' => $number, 'name_arabic' => $name, 'name_simple' => $name,
            'revelation_place' => 'makkah', 'revelation_order' => $number, 'verses_count' => $verses,
            'start_page' => 1, 'end_page' => 1,
        ]);

        foreach (range(1, $verses) as $verse) {
            Ayah::create([
                'surah_id' => $number, 'verse_number' => $verse, 'page_number' => 1,
                'line_number_start' => 1, 'line_number_end' => 1, 'verse_key' => "{$number}:{$verse}",
                'juz_number' => 1, 'hizb_number' => 1, 'rub_number' => 1, 'ruku_number' => 1,
                'manzil_number' => 1, 'text_uthmani' => 'آية',
            ]);
        }
    }

    $this->circle = Circle::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'active']);

    $this->plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'start_date' => '2026-07-07',
        'days_count' => 2,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'status' => 'active',
        'plan_type' => 'hifz_review',
        'direction' => 'forward',
        'is_approved' => true,
        'created_by_role' => 'teacher',
    ]);

    // The day sets 1:1–1:4 for hifz and 2:1–2:5 for review.
    $this->day = StudentPlanDay::create([
        'student_plan_id' => $this->plan->id,
        'date' => '2026-07-08',
        'day_name' => 'Wednesday',
        'from_ayah_id' => 1,
        'to_ayah_id' => 4,
        'review_from_ayah_id' => 8,
        'review_to_ayah_id' => 12,
    ]);

    $this->leaderboard = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة الحفظ',
        'competition_type' => 'gamification',
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-30',
        'is_active' => true,
        'settings' => ['hifz_enabled' => true, 'review_enabled' => true],
    ]);

    Sanctum::actingAs($this->teacher);
});

/**
 * One queued grade as the phone sends it: hifz of today's plan day graded
 * excellent, on a cell the server held nothing for.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tasmeehChange(array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid(),
        'kind' => 'plan',
        'day_id' => test()->day->id,
        'part' => 'hifz',
        'date' => '2026-07-08',
        'grade' => 3,
        'recited' => null,
        'base' => ['grade' => null, 'recited' => null],
    ], $overrides);
}

/**
 * @return array{from: array{surah: int, verse: int}, to: array{surah: int, verse: int}}
 */
function recited(int $fromSurah, int $fromVerse, int $toSurah, int $toVerse): array
{
    return ['from' => ['surah' => $fromSurah, 'verse' => $fromVerse], 'to' => ['surah' => $toSurah, 'verse' => $toVerse]];
}

function postTasmeeh(array ...$changes): TestResponse
{
    return test()->postJson('/api/v1/teacher/tasmeeh/changes', ['changes' => $changes]);
}

it('grades a plan day, pays its points and tells the student', function () {
    $change = tasmeehChange();

    postTasmeeh($change)
        ->assertSuccessful()
        ->assertJsonPath('data.results.0.id', $change['id'])
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.cell.day_id', $this->day->id)
        ->assertJsonPath('data.results.0.cell.part', 'hifz')
        ->assertJsonPath('data.results.0.cell.grade', 3)
        ->assertJsonPath('data.results.0.cell.recited', null)
        ->assertJsonPath('data.results.0.cell.graded_on', '2026-07-08')
        ->assertJsonPath('data.results.0.cell.updated_by', $this->teacher->name);

    $day = $this->day->fresh();

    expect($day->hifz_achievement)->toBe(3)
        ->and($day->hifz_graded_at->toDateTimeString())->toBe('2026-07-08 10:00:00')
        ->and($day->hifz_recorded_by)->toBe($this->teacher->id)
        ->and(GamificationTransaction::where('reference_type', StudentPlanDay::class)->sum('xp_amount'))->toEqual(10)
        ->and(AppNotification::where('recipient_id', $this->student->id)->where('type', 'grading')->count())->toBe(1);
});

it('stores «لم يسمع» as 0, dated but paying nothing and telling no one', function () {
    postTasmeeh(tasmeehChange(['grade' => 0]))
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.cell.grade', 0);

    $day = $this->day->fresh();

    expect($day->hifz_achievement)->toBe(0)
        ->and($day->hifz_graded_at)->not->toBeNull()
        ->and(GamificationTransaction::count())->toBe(0)
        ->and(AppNotification::count())->toBe(0);
});

it('takes the points back when an excellent day becomes «لم يسمع»', function () {
    postTasmeeh(tasmeehChange());
    expect(GamificationTransaction::count())->toBe(1);

    postTasmeeh(tasmeehChange(['grade' => 0, 'base' => ['grade' => 3, 'recited' => null]]))
        ->assertJsonPath('data.results.0.result', 'applied');

    expect(GamificationTransaction::count())->toBe(0);
});

it('keeps the plan and records what was actually recited beside it', function () {
    postTasmeeh(tasmeehChange(['recited' => recited(1, 1, 1, 6)]))
        ->assertJsonPath('data.results.0.cell.recited', recited(1, 1, 1, 6));

    $day = $this->day->fresh();

    expect([$day->from_ayah_id, $day->to_ayah_id])->toBe([1, 4])
        ->and([$day->hifz_recited_from_ayah_id, $day->hifz_recited_to_ayah_id])->toBe([1, 6]);
});

it('records reciting exactly the day\'s portion as no range', function () {
    postTasmeeh(tasmeehChange(['part' => 'review', 'recited' => recited(2, 1, 2, 5)]))
        ->assertJsonPath('data.results.0.cell.recited', null);

    expect($this->day->fresh()->review_recited_from_ayah_id)->toBeNull();
});

it('keeps the day a grade was given on when only the range is corrected', function () {
    postTasmeeh(tasmeehChange());
    Carbon::setTestNow('2026-07-08 11:30:00');

    postTasmeeh(tasmeehChange([
        'recited' => recited(1, 1, 1, 5),
        'base' => ['grade' => 3, 'recited' => null],
    ]))->assertJsonPath('data.results.0.result', 'applied');

    $day = $this->day->fresh();

    expect($day->hifz_graded_at->toDateTimeString())->toBe('2026-07-08 10:00:00')
        ->and($day->hifz_recited_to_ayah_id)->toBe(5)
        ->and(AppNotification::count())->toBe(1);
});

it('dates a grade for an earlier day at its midday, and never in the future', function () {
    postTasmeeh(tasmeehChange(['date' => '2026-07-06']));

    // 12:00 in Riyadh.
    expect($this->day->fresh()->hifz_graded_at->toDateTimeString())->toBe('2026-07-06 09:00:00');

    postTasmeeh(tasmeehChange(['part' => 'review', 'date' => '2026-07-20']))
        ->assertJsonPath('data.results.0.cell.graded_on', '2026-07-08');
});

it('holds back a grade when the server moved on since the phone last looked', function () {
    $this->day->update(['hifz_achievement' => 2, 'hifz_graded_at' => now(), 'hifz_recorded_by' => $this->teacher->id]);

    postTasmeeh(tasmeehChange())
        ->assertJsonPath('data.results.0.result', 'conflict')
        ->assertJsonPath('data.results.0.cell.grade', 2);

    expect($this->day->fresh()->hifz_achievement)->toBe(2);
});

it('treats a different recited range as a different value', function () {
    $this->day->update(['hifz_achievement' => 3, 'hifz_recited_from_ayah_id' => 1, 'hifz_recited_to_ayah_id' => 6]);

    postTasmeeh(tasmeehChange(['grade' => 2, 'base' => ['grade' => 3, 'recited' => null]]))
        ->assertJsonPath('data.results.0.result', 'conflict')
        ->assertJsonPath('data.results.0.cell.recited', recited(1, 1, 1, 6));
});

it('answers a resent grade as applied without writing it twice', function () {
    postTasmeeh(tasmeehChange());
    Carbon::setTestNow('2026-07-08 12:00:00');

    postTasmeeh(tasmeehChange())->assertJsonPath('data.results.0.result', 'applied');

    expect($this->day->fresh()->hifz_graded_at->toDateTimeString())->toBe('2026-07-08 10:00:00')
        ->and(AppNotification::count())->toBe(1);
});

it('clears a grade with its date and its points', function () {
    postTasmeeh(tasmeehChange());

    postTasmeeh(tasmeehChange(['grade' => null, 'base' => ['grade' => 3, 'recited' => null]]))
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.cell', null);

    $day = $this->day->fresh();

    expect($day->hifz_achievement)->toBeNull()
        ->and($day->hifz_graded_at)->toBeNull()
        ->and(GamificationTransaction::count())->toBe(0);
});

it('records a free recitation, paying it like a plan day', function () {
    $change = tasmeehChange([
        'kind' => 'free', 'day_id' => null, 'student_id' => $this->student->id,
        'grade' => 2, 'recited' => recited(2, 1, 2, 3),
    ]);

    postTasmeeh($change)
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.cell.uuid', $change['id'])
        ->assertJsonPath('data.results.0.cell.part', 'hifz')
        ->assertJsonPath('data.results.0.cell.date', '2026-07-08')
        ->assertJsonPath('data.results.0.cell.grade', 2)
        ->assertJsonPath('data.results.0.cell.recited', recited(2, 1, 2, 3));

    $row = FreeRecitation::sole();

    expect($row->recorded_by)->toBe($this->teacher->id)
        ->and([$row->from_ayah_id, $row->to_ayah_id])->toBe([8, 10])
        ->and(GamificationTransaction::where('reference_type', FreeRecitation::class)->sum('xp_amount'))->toEqual(7);
});

it('asks for the recited range before grading a free recitation', function () {
    postTasmeeh(tasmeehChange(['kind' => 'free', 'day_id' => null, 'student_id' => $this->student->id]))
        ->assertJsonPath('data.results.0.result', 'rejected')
        ->assertJsonPath('data.results.0.code', 'range_required');

    // «لم يسمع» needs no range: nothing was recited.
    postTasmeeh(tasmeehChange(['kind' => 'free', 'day_id' => null, 'student_id' => $this->student->id, 'grade' => 0]))
        ->assertJsonPath('data.results.0.result', 'applied');
});

it('removes an emptied free recitation with its points', function () {
    $free = ['kind' => 'free', 'day_id' => null, 'student_id' => $this->student->id];
    postTasmeeh(tasmeehChange($free + ['recited' => recited(2, 1, 2, 3)]));

    postTasmeeh(tasmeehChange($free + ['grade' => null, 'base' => ['grade' => 3, 'recited' => recited(2, 1, 2, 3)]]))
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.cell', null);

    expect(FreeRecitation::count())->toBe(0)
        ->and(GamificationTransaction::count())->toBe(0);
});

it('holds back a free recitation another teacher recorded first', function () {
    FreeRecitation::factory()->hifz()->create([
        'student_id' => $this->student->id,
        'recited_on' => '2026-07-08',
        'from_ayah_id' => 1,
        'to_ayah_id' => 2,
        'achievement' => 1,
    ]);

    postTasmeeh(tasmeehChange(['kind' => 'free', 'day_id' => null, 'student_id' => $this->student->id, 'recited' => recited(1, 1, 1, 7)]))
        ->assertJsonPath('data.results.0.result', 'conflict')
        ->assertJsonPath('data.results.0.cell.grade', 1);
});

it('refuses what it cannot apply, each change on its own', function () {
    $hifzOnly = StudentPlan::create([
        'student_id' => $this->student->id, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-07-07',
        'days_count' => 1, 'active_days' => [0, 1, 2, 3, 4, 5, 6], 'status' => 'active', 'plan_type' => 'hifz',
        'direction' => 'forward', 'is_approved' => true, 'created_by_role' => 'teacher',
    ]);
    $hifzDay = StudentPlanDay::create(['student_plan_id' => $hifzOnly->id, 'date' => '2026-07-08', 'day_name' => 'Wednesday', 'from_ayah_id' => 1, 'to_ayah_id' => 2]);
    $stranger = Student::factory()->create(['circle_id' => Circle::factory()->create()->id]);

    postTasmeeh(
        tasmeehChange(['day_id' => 999999]),
        tasmeehChange(['kind' => 'free', 'day_id' => null, 'student_id' => $stranger->id, 'grade' => 0]),
        tasmeehChange(['day_id' => $hifzDay->id, 'part' => 'review']),
        tasmeehChange(['recited' => recited(1, 1, 1, 99)]),
        tasmeehChange(),
    )
        ->assertJsonPath('data.results.0.code', 'day_unavailable')
        ->assertJsonPath('data.results.1.code', 'student_unavailable')
        ->assertJsonPath('data.results.2.code', 'invalid_part')
        ->assertJsonPath('data.results.3.code', 'invalid_range')
        ->assertJsonPath('data.results.4.result', 'applied');
});

it('refuses a grade that is not one', function (mixed $grade) {
    postTasmeeh(tasmeehChange(['grade' => $grade]))->assertUnprocessable();
})->with(['four' => 4, 'minus' => -1, 'text' => 'ممتاز']);

it('stops grading once the tasmeeh page is switched off for teachers', function () {
    RoleScreenPermission::where('screen_id', Screen::where('route_name', 'teacher.tasmeeh')->value('id'))->delete();

    postTasmeeh(tasmeehChange())
        ->assertForbidden()
        ->assertJsonPath('code', 'page_disabled');
});

it('lets a cell be edited again once a plan edit makes its recited range the day\'s portion', function () {
    $this->day->update(['hifz_achievement' => 3, 'hifz_graded_at' => now(), 'hifz_recited_from_ayah_id' => 1, 'hifz_recited_to_ayah_id' => 6]);

    // The teacher widens the plan to what the student recited.
    $this->day->update(['to_ayah_id' => 6]);

    $this->getJson('/api/v1/teacher/sync')
        ->assertJsonPath('data.tasmeeh_cells.0.recited', null);

    postTasmeeh(tasmeehChange(['grade' => 2, 'base' => ['grade' => 3, 'recited' => recited(1, 1, 1, 6)]]))
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.cell.grade', 2)
        ->assertJsonPath('data.results.0.cell.recited', null);

    expect($this->day->fresh()->hifz_recited_to_ayah_id)->toBeNull();
});

it('takes an excellent day\'s points back from the competition it was earned in when corrected to «لم يسمع» later', function () {
    $this->leaderboard->update(['end_date' => '2026-07-08']);
    $next = Leaderboard::create([
        'circle_id' => $this->circle->id, 'title' => 'المسابقة التالية', 'competition_type' => 'gamification',
        'start_date' => '2026-07-09', 'end_date' => '2026-07-30', 'is_active' => true,
        'settings' => ['hifz_enabled' => true, 'review_enabled' => true],
    ]);

    postTasmeeh(tasmeehChange());
    expect(GamificationTransaction::where('leaderboard_id', $this->leaderboard->id)->count())->toBe(1);

    Carbon::setTestNow('2026-07-09 10:00:00');

    postTasmeeh(tasmeehChange(['date' => '2026-07-09', 'grade' => 0, 'base' => ['grade' => 3, 'recited' => null]]))
        ->assertJsonPath('data.results.0.result', 'applied');

    expect(GamificationTransaction::count())->toBe(0)
        ->and(GamificationTransaction::where('leaderboard_id', $next->id)->count())->toBe(0);
});

it('takes a cleared free recitation out of the streak', function () {
    $this->leaderboard->update(['settings' => [
        'hifz_enabled' => true, 'enthusiasm_enabled' => true, 'attendance_enthusiasm_trigger' => false,
    ]]);
    $free = ['kind' => 'free', 'day_id' => null, 'student_id' => $this->student->id];

    postTasmeeh(tasmeehChange($free + ['recited' => recited(2, 1, 2, 3)]));
    expect(GamificationStudentState::where('student_id', $this->student->id)->value('current_streak'))->toBe(1);

    postTasmeeh(tasmeehChange($free + ['grade' => null, 'recited' => null, 'base' => ['grade' => 3, 'recited' => recited(2, 1, 2, 3)]]))
        ->assertJsonPath('data.results.0.result', 'applied');

    expect(GamificationStudentState::where('student_id', $this->student->id)->value('current_streak'))->toBe(0);
});

it('counts a free recitation toward the streak where its part earns no points', function () {
    $this->leaderboard->update(['settings' => [
        'hifz_enabled' => false, 'enthusiasm_enabled' => true, 'attendance_enthusiasm_trigger' => false,
    ]]);

    postTasmeeh(tasmeehChange(['kind' => 'free', 'day_id' => null, 'student_id' => $this->student->id, 'recited' => recited(2, 1, 2, 3)]))
        ->assertJsonPath('data.results.0.result', 'applied');

    expect(GamificationTransaction::count())->toBe(0)
        ->and(GamificationStudentState::where('student_id', $this->student->id)->value('current_streak'))->toBe(1);
});

it('does not count a day as enthusiastic for a «لم يسمع» given that day beside a part recited earlier', function () {
    $this->leaderboard->update(['settings' => [
        'hifz_enabled' => true, 'review_enabled' => true, 'enthusiasm_enabled' => true, 'attendance_enthusiasm_trigger' => false,
    ]]);
    $this->day->update([
        'hifz_achievement' => 3, 'hifz_graded_at' => '2026-07-07 07:00:00',
        'review_achievement' => 0, 'review_graded_at' => '2026-07-08 07:00:00',
    ]);

    expect(GamificationService::checkEnthusiasmForDate($this->student, '2026-07-08', $this->leaderboard->fresh()))->toBeFalse()
        ->and(GamificationService::checkEnthusiasmForDate($this->student, '2026-07-07', $this->leaderboard->fresh()))->toBeTrue();
});

it('keeps a grading date the web moved when the phone corrects only the range', function () {
    $this->day->update(['hifz_achievement' => 3, 'hifz_graded_at' => '2026-07-06 09:00:00']);

    postTasmeeh(tasmeehChange([
        'recited' => recited(1, 1, 1, 5),
        'base' => ['grade' => 3, 'recited' => null],
    ]))->assertJsonPath('data.results.0.result', 'applied');

    expect($this->day->fresh()->hifz_graded_at->toDateTimeString())->toBe('2026-07-06 09:00:00');
});

it('refreshes the streak where a cleared free recitation counted without paying', function () {
    $this->leaderboard->update(['settings' => [
        'hifz_enabled' => false, 'enthusiasm_enabled' => true, 'attendance_enthusiasm_trigger' => false,
    ]]);
    $free = ['kind' => 'free', 'day_id' => null, 'student_id' => $this->student->id];

    postTasmeeh(tasmeehChange($free + ['recited' => recited(2, 1, 2, 3)]));
    expect(GamificationStudentState::where('student_id', $this->student->id)->value('current_streak'))->toBe(1);

    postTasmeeh(tasmeehChange($free + ['grade' => null, 'recited' => null, 'base' => ['grade' => 3, 'recited' => recited(2, 1, 2, 3)]]));

    expect(GamificationStudentState::where('student_id', $this->student->id)->value('current_streak'))->toBe(0);
});

it('leaves a paused competition\'s points alone when the day is synced again', function () {
    $paused = Leaderboard::create([
        'circle_id' => $this->circle->id, 'title' => 'مسابقة موقوفة', 'competition_type' => 'gamification',
        'start_date' => '2026-07-01', 'end_date' => '2026-07-30', 'is_active' => true,
        'settings' => ['hifz_enabled' => true],
    ]);

    postTasmeeh(tasmeehChange());
    expect(GamificationTransaction::where('leaderboard_id', $paused->id)->count())->toBe(1);

    $paused->update(['is_active' => false]);
    GamificationService::syncStudentPlanDayXP($this->day->fresh());

    expect(GamificationTransaction::where('leaderboard_id', $paused->id)->count())->toBe(1);
});

it('records a second «لم يسمع» for the day being graded when asked to', function () {
    $this->day->update(['hifz_achievement' => 0, 'hifz_graded_at' => '2026-07-07 07:00:00']);

    postTasmeeh(tasmeehChange(['grade' => 0, 'redate' => true, 'base' => ['grade' => 0, 'recited' => null]]))
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.cell.graded_on', '2026-07-08');

    expect($this->day->fresh()->hifz_graded_at->toDateTimeString())->toBe('2026-07-08 10:00:00');
});

it('records the rest of a portion for the day being graded with the same grade', function () {
    $this->day->update(['hifz_achievement' => 3, 'hifz_graded_at' => '2026-07-07 07:00:00', 'hifz_recited_from_ayah_id' => 1, 'hifz_recited_to_ayah_id' => 2]);

    postTasmeeh(tasmeehChange([
        'recited' => recited(1, 3, 1, 4),
        'redate' => true,
        'base' => ['grade' => 3, 'recited' => recited(1, 1, 1, 2)],
    ]))->assertJsonPath('data.results.0.cell.graded_on', '2026-07-08');

    $day = $this->day->fresh();

    expect($day->hifz_recited_from_ayah_id)->toBe(3)
        ->and($day->hifz_graded_at->toDateTimeString())->toBe('2026-07-08 10:00:00');
});

it('records a carried grade for a day only once', function () {
    $this->day->update(['hifz_achievement' => 0, 'hifz_graded_at' => '2026-07-07 07:00:00']);
    $change = tasmeehChange(['grade' => 0, 'redate' => true, 'base' => ['grade' => 0, 'recited' => null]]);

    postTasmeeh($change);
    Carbon::setTestNow('2026-07-08 11:00:00');
    postTasmeeh($change)->assertJsonPath('data.results.0.result', 'applied');

    expect($this->day->fresh()->hifz_graded_at->toDateTimeString())->toBe('2026-07-08 10:00:00');
});

/**
 * The sessions a part of the test day holds, oldest first, as date => grade.
 *
 * @return array<string, ?int>
 */
function sessionsOf(string $part = 'hifz'): array
{
    return PlanDayAttempt::where('student_plan_day_id', test()->day->id)->where('part', $part)
        ->orderBy('recited_on')->pluck('grade', 'recited_on')->all();
}

it('keeps a «لم يسمع» on its own day when the portion is graded the next day', function () {
    Carbon::setTestNow('2026-07-07 10:00:00');
    postTasmeeh(tasmeehChange(['date' => '2026-07-07', 'grade' => 0, 'session' => true]))
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.attempt.date', '2026-07-07')
        ->assertJsonPath('data.results.0.attempt.grade', 0);

    // The next day starts ungraded: the phone grades that day's session.
    Carbon::setTestNow('2026-07-08 10:00:00');
    postTasmeeh(tasmeehChange(['session' => true]))
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.attempt.date', '2026-07-08')
        ->assertJsonPath('data.results.0.attempt.grade', 3)
        ->assertJsonPath('data.results.0.cell.grade', 3)
        ->assertJsonPath('data.results.0.cell.graded_on', '2026-07-08');

    expect(sessionsOf())->toBe(['2026-07-07' => 0, '2026-07-08' => 3])
        ->and($this->day->fresh()->hifz_achievement)->toBe(3)
        ->and(GamificationTransaction::where('reference_type', StudentPlanDay::class)->sum('xp_amount'))->toEqual(10)
        ->and(AppNotification::where('type', 'grading')->count())->toBe(1);
});

it('holds back a session grade when another teacher graded that session first', function () {
    PlanDayAttempts::record($this->day, 'hifz', '2026-07-08', '2026-07-08', 2, null, $this->teacher->id);

    postTasmeeh(tasmeehChange(['session' => true]))
        ->assertJsonPath('data.results.0.result', 'conflict')
        ->assertJsonPath('data.results.0.attempt.grade', 2);

    expect(sessionsOf())->toBe(['2026-07-08' => 2]);
});

it('compares a session grade with that session only, not with the day\'s latest grade', function () {
    PlanDayAttempts::record($this->day, 'hifz', '2026-07-07', '2026-07-08', 0, null, $this->teacher->id);

    // The phone saw nothing on the 8th, though the day holds a «لم يسمع».
    postTasmeeh(tasmeehChange(['session' => true, 'base' => ['grade' => null, 'recited' => null]]))
        ->assertJsonPath('data.results.0.result', 'applied');

    expect(sessionsOf())->toBe(['2026-07-07' => 0, '2026-07-08' => 3]);
});

it('answers a resent session grade as applied without writing it twice', function () {
    $change = tasmeehChange(['session' => true]);

    postTasmeeh($change);
    Carbon::setTestNow('2026-07-08 12:00:00');
    postTasmeeh($change)->assertJsonPath('data.results.0.result', 'applied');

    expect(PlanDayAttempt::count())->toBe(1)
        ->and(PlanDayAttempt::first()->graded_at->toDateTimeString())->toBe('2026-07-08 10:00:00')
        ->and(AppNotification::count())->toBe(1);
});

it('leaves the latest grade as the day\'s when an earlier session is corrected', function () {
    PlanDayAttempts::record($this->day, 'hifz', '2026-07-07', '2026-07-08', 0, null, $this->teacher->id);
    PlanDayAttempts::record($this->day, 'hifz', '2026-07-08', '2026-07-08', 3, null, $this->teacher->id);

    postTasmeeh(tasmeehChange(['date' => '2026-07-07', 'grade' => 1, 'session' => true, 'base' => ['grade' => 0, 'recited' => null]]))
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.cell.grade', 3);

    expect(sessionsOf())->toBe(['2026-07-07' => 1, '2026-07-08' => 3])
        ->and($this->day->fresh()->hifz_achievement)->toBe(3);
});

it('falls back to the earlier session when the latest is cleared, with its points', function () {
    PlanDayAttempts::record($this->day, 'hifz', '2026-07-07', '2026-07-08', 0, null, $this->teacher->id);
    PlanDayAttempts::record($this->day, 'hifz', '2026-07-08', '2026-07-08', 3, null, $this->teacher->id);
    expect(GamificationTransaction::count())->toBe(1);

    postTasmeeh(tasmeehChange(['grade' => null, 'session' => true, 'base' => ['grade' => 3, 'recited' => null]]))
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.attempt', null)
        ->assertJsonPath('data.results.0.cell.grade', 0)
        ->assertJsonPath('data.results.0.cell.graded_on', '2026-07-07');

    expect(sessionsOf())->toBe(['2026-07-07' => 0])
        ->and(GamificationTransaction::count())->toBe(0);
});

it('keeps the earlier «لم يسمع» when an app from before sessions grades the carried day', function () {
    PlanDayAttempts::record($this->day, 'hifz', '2026-07-07', '2026-07-08', 0, null, $this->teacher->id);

    // Such an app shows the day's latest grade and sends it as the base.
    postTasmeeh(tasmeehChange(['redate' => true, 'base' => ['grade' => 0, 'recited' => null]]))
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.cell.grade', 3);

    expect(sessionsOf())->toBe(['2026-07-07' => 0, '2026-07-08' => 3]);
});

it('sends every session with the snapshot, and a grade set straight on a day as one', function () {
    PlanDayAttempts::record($this->day, 'hifz', '2026-07-07', '2026-07-08', 0, null, $this->teacher->id);
    PlanDayAttempts::record($this->day, 'hifz', '2026-07-08', '2026-07-08', 2, [1, 6], $this->teacher->id);
    $this->day->update(['review_achievement' => 3, 'review_graded_at' => '2026-07-06 09:00:00']);

    $attempts = collect($this->getJson('/api/v1/teacher/sync')->assertSuccessful()->json('data.tasmeeh_attempts'));

    expect($attempts->map(fn (array $attempt) => [$attempt['part'], $attempt['date'], $attempt['grade']])->all())->toBe([
        ['hifz', '2026-07-07', 0],
        ['hifz', '2026-07-08', 2],
        ['review', '2026-07-06', 3],
    ])
        ->and($attempts[1]['recited'])->toBe(recited(1, 1, 1, 6))
        ->and($attempts[1]['day_id'])->toBe($this->day->id)
        ->and($attempts[1]['updated_by'])->toBe($this->teacher->name);
});
