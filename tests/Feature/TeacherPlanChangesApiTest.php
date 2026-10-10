<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Ayah;
use App\Models\Circle;
use App\Models\RoleScreenPermission;
use App\Models\Screen;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\Surah;
use App\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
 * Wednesday 8 July 2026. الفاتحة and a surah of ten verses after it.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-07-08 10:00:00');

    foreach ([1 => 7, 2 => 10] as $surahId => $verses) {
        Surah::create([
            'id' => $surahId, 'number' => $surahId, 'name_arabic' => 'سورة '.$surahId, 'name_simple' => 'Surah '.$surahId,
            'revelation_place' => 'makkah', 'revelation_order' => $surahId, 'verses_count' => $verses,
            'start_page' => 1, 'end_page' => 1,
        ]);

        foreach (range(1, $verses) as $verse) {
            Ayah::create([
                'surah_id' => $surahId, 'verse_number' => $verse, 'page_number' => 1,
                'line_number_start' => 1, 'line_number_end' => 1, 'verse_key' => "{$surahId}:{$verse}",
                'juz_number' => 1, 'hizb_number' => 1, 'rub_number' => 1, 'ruku_number' => 1,
                'manzil_number' => 1, 'text_uthmani' => 'آية',
            ]);
        }
    }

    $this->circle = Circle::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);
    $this->student = Student::factory()->create(['circle_id' => $this->circle->id]);

    Sanctum::actingAs($this->teacher);
});

/**
 * A plan as the phone sends it: hifz and review on Sunday and Monday, built
 * on a sync an hour ago.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function planChange(array $overrides = []): array
{
    $range = fn (int $fromSurah, int $fromVerse, int $toSurah, int $toVerse) => [
        'from' => ['surah' => $fromSurah, 'verse' => $fromVerse],
        'to' => ['surah' => $toSurah, 'verse' => $toVerse],
    ];

    return array_merge([
        'id' => (string) Str::uuid(),
        'student_id' => test()->student->id,
        'plan_type' => 'hifz_review',
        'direction' => 'forward',
        'review_direction' => 'forward',
        'start_date' => '2026-07-11',
        'active_days' => ['Sunday', 'Monday'],
        'description' => 'خطة الصيف',
        'deactivate_previous' => false,
        'synced_at' => '2026-07-08T09:00:00.000Z',
        'days' => [
            ['date' => '2026-07-12', 'hifz' => $range(2, 1, 2, 5), 'review' => $range(1, 1, 1, 7)],
            ['date' => '2026-07-13', 'hifz' => $range(2, 6, 2, 10), 'review' => $range(1, 1, 1, 7)],
        ],
    ], $overrides);
}

function postPlans(array ...$changes): TestResponse
{
    return test()->postJson('/api/v1/teacher/plans/changes', ['changes' => $changes]);
}

function ayahIdOf(int $surah, int $verse): int
{
    return Ayah::where('surah_id', $surah)->where('verse_number', $verse)->value('id');
}

it('saves the plan as the phone built it, approved as a teacher\'s plan is', function () {
    $change = planChange();

    postPlans($change)
        ->assertSuccessful()
        ->assertJsonPath('data.results.0.id', $change['id'])
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.created_meanwhile', []);

    $plan = StudentPlan::with('days')->sole();

    expect($plan->only(['uuid', 'student_id', 'teacher_id', 'days_count', 'active_days', 'description', 'plan_type', 'direction', 'review_direction', 'status', 'is_approved', 'created_by_role']))
        ->toEqual([
            'uuid' => $change['id'], 'student_id' => $this->student->id, 'teacher_id' => $this->teacher->id,
            'days_count' => 2, 'active_days' => ['Sunday', 'Monday'], 'description' => 'خطة الصيف',
            'plan_type' => 'hifz_review', 'direction' => 'forward', 'review_direction' => 'forward',
            'status' => 'active', 'is_approved' => true, 'created_by_role' => 'teacher',
        ])
        ->and($plan->start_date->toDateString())->toBe('2026-07-11')
        ->and($plan->days->map(fn ($day) => [$day->date->toDateString(), $day->day_name, $day->from_ayah_id, $day->to_ayah_id, $day->review_from_ayah_id, $day->review_to_ayah_id])->all())
        ->toBe([
            ['2026-07-12', 'الأحد', ayahIdOf(2, 1), ayahIdOf(2, 5), ayahIdOf(1, 1), ayahIdOf(1, 7)],
            ['2026-07-13', 'الاثنين', ayahIdOf(2, 6), ayahIdOf(2, 10), ayahIdOf(1, 1), ayahIdOf(1, 7)],
        ]);
});

it('answers a resent plan as applied without saving it twice', function () {
    $change = planChange();

    $first = postPlans($change)->json('data.results.0.plan_id');

    postPlans($change)
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.plan_id', $first);

    expect(StudentPlan::count())->toBe(1);
});

it('names the plans made for the student since the phone last synced', function () {
    $before = StudentPlan::create(['student_id' => $this->student->id, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-07-01', 'days_count' => 5, 'active_days' => ['Sunday'], 'plan_type' => 'hifz', 'status' => 'active', 'is_approved' => true, 'created_by_role' => 'teacher']);
    $before->forceFill(['created_at' => '2026-07-08 08:00:00'])->save();
    $meanwhile = StudentPlan::create(['student_id' => $this->student->id, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-07-09', 'days_count' => 5, 'active_days' => ['Sunday'], 'plan_type' => 'review', 'status' => 'active', 'is_approved' => false, 'created_by_role' => 'student']);

    postPlans(planChange())
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.created_meanwhile', [[
            'id' => $meanwhile->id, 'plan_type' => 'review', 'start_date' => '2026-07-09', 'created_by_role' => 'student', 'status' => 'active',
        ]]);

    expect(StudentPlan::where('status', 'active')->count())->toBe(3);
});

it('switches the student\'s other plans off when the teacher asked, the one made meanwhile too', function () {
    $other = StudentPlan::create(['student_id' => $this->student->id, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-07-09', 'days_count' => 5, 'active_days' => ['Sunday'], 'plan_type' => 'hifz', 'status' => 'active', 'is_approved' => true, 'created_by_role' => 'teacher']);
    $change = planChange(['deactivate_previous' => true]);

    postPlans($change)->assertJsonPath('data.results.0.created_meanwhile.0.status', 'inactive');

    expect($other->fresh()->status)->toBe('inactive')
        ->and(StudentPlan::where('uuid', $change['id'])->value('status'))->toBe('active');

    // A resend does not switch off what was turned back on since.
    $other->fresh()->update(['status' => 'active']);
    postPlans($change)->assertJsonPath('data.results.0.result', 'applied');

    expect($other->fresh()->status)->toBe('active');
});

it('keeps only the parts the kind of plan schedules', function () {
    postPlans(planChange(['plan_type' => 'review']))->assertJsonPath('data.results.0.result', 'applied');

    $day = StudentPlan::sole()->days()->orderBy('date')->first();

    expect($day->from_ayah_id)->toBeNull()
        ->and($day->to_ayah_id)->toBeNull()
        ->and($day->review_to_ayah_id)->toBe(ayahIdOf(1, 7));
});

it('refuses what it cannot save, each plan on its own', function () {
    $stranger = Student::factory()->create();
    $day = fn (string $date, int $toVerse = 5) => ['date' => $date, 'hifz' => ['from' => ['surah' => 2, 'verse' => 1], 'to' => ['surah' => 2, 'verse' => $toVerse]], 'review' => null];

    $results = postPlans(
        planChange(['student_id' => $stranger->id]),
        planChange(['plan_type' => 'hifz', 'days' => [$day('2026-07-12', 11)]]),
        planChange(['plan_type' => 'hifz', 'days' => [$day('2026-07-13'), $day('2026-07-12')]]),
        planChange(['plan_type' => 'hifz', 'days' => [$day('2026-07-10')]]),
        planChange(['plan_type' => 'hifz', 'days' => [['date' => '2026-07-12', 'hifz' => null]]]),
        planChange(['plan_type' => 'hifz', 'days' => [$day('2026-07-12')]]),
    )->assertSuccessful()->json('data.results');

    expect(array_column($results, 'result'))->toBe(['rejected', 'rejected', 'rejected', 'rejected', 'rejected', 'applied'])
        ->and(array_column($results, 'code'))->toBe(['student_unavailable', 'invalid_plan', 'invalid_plan', 'invalid_plan', 'invalid_plan'])
        ->and($results[1]['message'])->toContain('اليوم 1')
        ->and(StudentPlan::count())->toBe(1);
});

it('stops taking plans once the plan page is switched off for teachers', function () {
    RoleScreenPermission::where('screen_id', Screen::where('route_name', 'teacher.plan-creator')->value('id'))->delete();

    postPlans(planChange())
        ->assertForbidden()
        ->assertJsonPath('code', 'page_disabled');
});

it('sends the attendance periods plans are laid out on, and the plans each student has running', function () {
    $other = Stage::factory()->create();
    $period = fn (string $name, array $stageIds) => AcademicCalendarEvent::create([
        'event_name' => $name, 'start_date' => '2026-06-01', 'end_date' => '2026-08-30', 'is_attendance_period' => true,
        'weekdays' => [1, 2, '3'], 'stage_ids' => $stageIds, 'excluded_dates' => ['2026-07-15'], 'extra_dates' => ['2026-07-18'], 'is_visible' => true,
    ]);
    $period('الفصل الصيفي', [$this->circle->stage_id]);
    $period('للأكاديمية كلها', []);
    $period('لمرحلة أخرى', [$other->id]);
    StudentPlan::create(['student_id' => $this->student->id, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-07-01', 'days_count' => 5, 'active_days' => ['Sunday'], 'plan_type' => 'hifz', 'status' => 'active', 'is_approved' => true, 'created_by_role' => 'teacher']);

    $data = $this->getJson('/api/v1/teacher/sync')->assertSuccessful()->json('data');

    expect($data['pages']['plan_creator'])->toBeTrue()
        ->and(array_column($data['calendar_periods'], 'name'))->toBe(['الفصل الصيفي', 'للأكاديمية كلها'])
        ->and($data['calendar_periods'][0])->toBe([
            'name' => 'الفصل الصيفي', 'start' => '2026-06-01', 'end' => '2026-08-30', 'weekdays' => [1, 2, 3],
            'stage_ids' => [$this->circle->stage_id], 'extra_dates' => ['2026-07-18'], 'excluded_dates' => ['2026-07-15'],
        ])
        ->and($data['active_plan_counts'])->toBe([(string) $this->student->id => 1]);
});

it('sends none of it while the plan page is switched off for teachers', function () {
    AcademicCalendarEvent::create(['event_name' => 'الفصل', 'start_date' => '2026-06-01', 'end_date' => '2026-08-30', 'is_attendance_period' => true, 'weekdays' => [1], 'is_visible' => true]);
    RoleScreenPermission::where('screen_id', Screen::where('route_name', 'teacher.plan-creator')->value('id'))->delete();

    $data = $this->getJson('/api/v1/teacher/sync')->assertSuccessful()->json('data');

    expect($data['pages']['plan_creator'])->toBeFalse()
        ->and($data['calendar_periods'])->toBe([])
        ->and($data['active_plan_counts'])->toBe([]);
});
