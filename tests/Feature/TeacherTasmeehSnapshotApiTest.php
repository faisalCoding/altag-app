<?php

use App\Models\Ayah;
use App\Models\Circle;
use App\Models\ExamLevel;
use App\Models\FreeRecitation;
use App\Models\RoleScreenPermission;
use App\Models\Screen;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Surah;
use App\Models\Teacher;
use App\Support\HijriDate;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Carbon::setTestNow('2026-07-08 10:00:00');

    Surah::create([
        'id' => 1, 'number' => 1, 'name_arabic' => 'الفاتحة', 'name_simple' => 'Al-Fatihah',
        'revelation_place' => 'makkah', 'revelation_order' => 1, 'verses_count' => 7,
        'start_page' => 1, 'end_page' => 1,
    ]);

    // Verse n sits in juz 31 - n, so a level ending on it is n juz long.
    foreach (range(1, 7) as $verse) {
        Ayah::create([
            'id' => $verse, 'surah_id' => 1, 'verse_number' => $verse, 'page_number' => 1,
            'line_number_start' => 1, 'line_number_end' => 1, 'verse_key' => "1:{$verse}",
            'juz_number' => 31 - $verse, 'hizb_number' => 1, 'rub_number' => 1, 'ruku_number' => 1,
            'manzil_number' => 1, 'text_uthmani' => 'آية',
        ]);
    }

    $this->circle = Circle::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::factory()->create(['circle_id' => $this->circle->id]);

    $this->token = $this->teacher->createToken('phone')->plainTextToken;
});

function snapshotPlan(int $studentId, string $type, string $createdAt, array $attributes = []): StudentPlan
{
    $plan = StudentPlan::create(array_merge([
        'student_id' => $studentId,
        'teacher_id' => test()->teacher->id,
        'start_date' => '2026-07-07',
        'days_count' => 2,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'status' => 'active',
        'plan_type' => $type,
        'direction' => 'forward',
        'is_approved' => true,
        'created_by_role' => 'teacher',
    ], $attributes));

    $plan->forceFill(['created_at' => $createdAt])->save();

    return $plan;
}

function snapshot(): TestResponse
{
    return test()->withToken(test()->token)->getJson('/api/v1/teacher/sync')->assertSuccessful();
}

it('tells the app which screens it may show', function () {
    snapshot()->assertJsonPath('data.pages', ['tasmeeh' => true, 'student_exams' => true, 'pairs' => true, 'plan_creator' => true]);
});

it('sends the active quran plans of the teacher\'s students, newest first per student', function () {
    $older = snapshotPlan($this->student->id, 'hifz', '2026-06-01');
    $newer = snapshotPlan($this->student->id, 'hifz_review', '2026-07-01');
    $inactive = snapshotPlan($this->student->id, 'review', '2026-07-02', ['status' => 'completed']);
    $empty = snapshotPlan($this->student->id, 'review', '2026-07-03');
    $elsewhere = snapshotPlan(Student::factory()->create(['circle_id' => Circle::factory()->create()->id])->id, 'hifz', '2026-07-01');

    foreach ([$older, $newer, $inactive, $elsewhere] as $plan) {
        StudentPlanDay::create(['student_plan_id' => $plan->id, 'date' => '2026-07-08', 'day_name' => 'Wednesday', 'from_ayah_id' => 1, 'to_ayah_id' => 2]);
    }

    expect(snapshot()->json('data.tasmeeh_plans'))->toBe([
        ['id' => $older->id, 'student_id' => $this->student->id, 'type' => 'hifz', 'position' => 1],
        ['id' => $newer->id, 'student_id' => $this->student->id, 'type' => 'hifz_review', 'position' => 0],
    ]);
});

it('leaves out a plan a student drew up until a teacher approves it', function () {
    $approved = snapshotPlan($this->student->id, 'hifz', '2026-06-01');
    $awaiting = snapshotPlan($this->student->id, 'review', '2026-07-01', ['is_approved' => false, 'created_by_role' => 'student']);

    foreach ([$approved, $awaiting] as $plan) {
        StudentPlanDay::create(['student_plan_id' => $plan->id, 'date' => '2026-07-08', 'day_name' => 'Wednesday', 'from_ayah_id' => 1, 'to_ayah_id' => 2, 'review_from_ayah_id' => 3, 'review_to_ayah_id' => 4]);
    }

    $response = snapshot();

    expect(collect($response->json('data.tasmeeh_plans'))->pluck('id')->all())->toBe([$approved->id])
        ->and(collect($response->json('data.tasmeeh_days'))->pluck('plan_id')->unique()->all())->toBe([$approved->id]);
});

it('sends every day of a plan with its portions as surah and verse', function () {
    $plan = snapshotPlan($this->student->id, 'hifz', '2026-07-01');

    $second = StudentPlanDay::create(['student_plan_id' => $plan->id, 'date' => '2026-07-09', 'day_name' => 'Thursday', 'from_ayah_id' => 3, 'to_ayah_id' => 4]);
    $first = StudentPlanDay::create([
        'student_plan_id' => $plan->id, 'date' => '2026-01-01', 'day_name' => 'Thursday',
        'from_ayah_id' => 1, 'to_ayah_id' => 2,
        // A hifz plan grades no review, whatever the row holds.
        'review_from_ayah_id' => 5, 'review_to_ayah_id' => 6,
    ]);

    expect(snapshot()->json('data.tasmeeh_days'))->toBe([
        ['id' => $first->id, 'plan_id' => $plan->id, 'position' => 0, 'date' => '2026-01-01',
            'hifz' => ['from' => ['surah' => 1, 'verse' => 1], 'to' => ['surah' => 1, 'verse' => 2]], 'review' => null],
        ['id' => $second->id, 'plan_id' => $plan->id, 'position' => 1, 'date' => '2026-07-09',
            'hifz' => ['from' => ['surah' => 1, 'verse' => 3], 'to' => ['surah' => 1, 'verse' => 4]], 'review' => null],
    ]);
});

it('sends a cell for each part that holds a grade or a recited range, «لم يسمع» included', function () {
    $plan = snapshotPlan($this->student->id, 'hifz_review', '2026-07-01');

    $day = StudentPlanDay::create([
        'student_plan_id' => $plan->id, 'date' => '2026-07-08', 'day_name' => 'Wednesday',
        'from_ayah_id' => 1, 'to_ayah_id' => 2, 'review_from_ayah_id' => 3, 'review_to_ayah_id' => 4,
        'hifz_achievement' => 0, 'hifz_graded_at' => '2026-07-08 06:00:00', 'hifz_recorded_by' => $this->teacher->id,
        'review_recited_from_ayah_id' => 3, 'review_recited_to_ayah_id' => 7,
    ]);
    StudentPlanDay::create(['student_plan_id' => $plan->id, 'date' => '2026-07-09', 'day_name' => 'Thursday', 'from_ayah_id' => 3, 'to_ayah_id' => 4]);

    $cells = snapshot()->json('data.tasmeeh_cells');

    expect($cells)->toHaveCount(2)
        ->and($cells[0])->toMatchArray([
            'day_id' => $day->id, 'part' => 'hifz', 'grade' => 0, 'recited' => null,
            'graded_on' => '2026-07-08', 'updated_by' => $this->teacher->name,
        ])
        ->and($cells[1])->toMatchArray([
            'day_id' => $day->id, 'part' => 'review', 'grade' => null, 'graded_at' => null,
            'recited' => ['from' => ['surah' => 1, 'verse' => 3], 'to' => ['surah' => 1, 'verse' => 7]],
        ]);
});

it('sends the free recitations of the window', function () {
    $inside = FreeRecitation::factory()->hifz()->graded(2)->create(['student_id' => $this->student->id, 'recited_on' => '2026-07-07', 'from_ayah_id' => 1, 'to_ayah_id' => 3]);
    FreeRecitation::factory()->hifz()->graded(2)->create(['student_id' => $this->student->id, 'recited_on' => '2026-01-01']);

    snapshot()
        ->assertJsonCount(1, 'data.free_recitations')
        ->assertJsonPath('data.free_recitations.0.id', $inside->id)
        ->assertJsonPath('data.free_recitations.0.part', 'hifz')
        ->assertJsonPath('data.free_recitations.0.date', '2026-07-07')
        ->assertJsonPath('data.free_recitations.0.grade', 2)
        ->assertJsonPath('data.free_recitations.0.recited', ['from' => ['surah' => 1, 'verse' => 1], 'to' => ['surah' => 1, 'verse' => 3]]);
});

it('sends the exam levels by juz count, with the pending exams and the level each student sits next', function () {
    $open = ExamLevel::create(['name' => 'مستوى بلا نهاية']);
    $three = ExamLevel::create(['name' => 'ثلاثة أجزاء', 'end_ayah_id' => 3]);
    $one = ExamLevel::create(['name' => 'جزء واحد', 'end_ayah_id' => 1]);
    $two = ExamLevel::create(['name' => 'جزءان', 'end_ayah_id' => 2]);

    $classmate = Student::factory()->create(['circle_id' => $this->circle->id]);

    StudentExam::create(['student_id' => $this->student->id, 'exam_level_id' => $one->id, 'status' => 'passed', 'date_time' => '2026-05-01 00:00:00']);
    StudentExam::create(['student_id' => $this->student->id, 'exam_level_id' => $two->id, 'status' => 'failed', 'date_time' => '2026-06-01 00:00:00']);
    $pending = StudentExam::create(['student_id' => $this->student->id, 'exam_level_id' => $two->id, 'status' => 'pending', 'date_time' => '2026-07-01 16:00:00', 'location' => 'المسجد']);

    $response = snapshot();

    expect(collect($response->json('data.exam_levels'))->map(fn ($level) => [$level['id'], $level['juz_count'], $level['position']])->all())
        ->toBe([[$one->id, 1, 0], [$two->id, 2, 1], [$three->id, 3, 2], [$open->id, null, 3]]);

    expect($response->json('data.exams'))->toBe([[
        'id' => $pending->id, 'uuid' => null, 'student_id' => $this->student->id, 'level_id' => $two->id,
        'status' => 'pending', 'date' => '2026-07-01', 'date_hijri' => HijriDate::full('2026-07-01'),
        'location' => 'المسجد', 'updated_at' => $pending->updated_at->toISOString(),
    ]]);

    expect($response->json('data.exam_suggestions'))->toEqualCanonicalizing([
        ['student_id' => $this->student->id, 'level_id' => $two->id],
        ['student_id' => $classmate->id, 'level_id' => $one->id],
    ]);
});

it('sends the hijri months from the window\'s first through five after the one holding today', function () {
    $response = snapshot();
    $months = $response->json('data.hijri_months');
    $todays = collect($months)->search(fn (array $month) => $month['title'] === HijriDate::monthYear('2026-07-08'));

    expect($months[0]['first_day'])->toBe($response->json('data.window.from'))
        ->and(Carbon::parse($months[0]['first_day'])->addDays($months[0]['length'])->toDateString())->toBe($months[1]['first_day'])
        ->and($months[0]['key'])->toMatch('/^\d{4}-\d{2}$/')
        ->and($todays)->toBe(1) // The window opens on the previous month.
        ->and($months)->toHaveCount($todays + 6);
});

it('sends no plans or exams once both screens are switched off for teachers', function () {
    $plan = snapshotPlan($this->student->id, 'hifz', '2026-07-01');
    StudentPlanDay::create(['student_plan_id' => $plan->id, 'date' => '2026-07-08', 'day_name' => 'Wednesday', 'from_ayah_id' => 1, 'to_ayah_id' => 2]);
    $level = ExamLevel::create(['name' => 'جزء واحد', 'end_ayah_id' => 1]);
    StudentExam::create(['student_id' => $this->student->id, 'exam_level_id' => $level->id, 'status' => 'pending', 'date_time' => '2026-07-12 16:00:00']);

    RoleScreenPermission::whereIn('screen_id', Screen::whereIn('route_name', ['teacher.tasmeeh', 'teacher.student-exams'])->pluck('id'))->delete();

    snapshot()
        ->assertJsonPath('data.pages', ['tasmeeh' => false, 'student_exams' => false, 'pairs' => true, 'plan_creator' => true])
        ->assertJsonPath('data.tasmeeh_plans', [])
        ->assertJsonPath('data.tasmeeh_days', [])
        ->assertJsonPath('data.exam_levels', [])
        ->assertJsonPath('data.exams', [])
        ->assertJsonPath('data.exam_suggestions', []);
});

it('still sends the exams, read-only, while tasmeeh is on and the exams screen is off', function () {
    $level = ExamLevel::create(['name' => 'جزء واحد', 'end_ayah_id' => 1]);
    $pending = StudentExam::create(['student_id' => $this->student->id, 'exam_level_id' => $level->id, 'status' => 'pending', 'date_time' => '2026-07-12 16:00:00']);

    RoleScreenPermission::where('screen_id', Screen::where('route_name', 'teacher.student-exams')->value('id'))->delete();

    $response = snapshot()
        ->assertJsonPath('data.pages', ['tasmeeh' => true, 'student_exams' => false, 'pairs' => true, 'plan_creator' => true])
        ->assertJsonPath('data.exam_levels.0.id', $level->id)
        ->assertJsonPath('data.exam_levels.0.juz_count', 1)
        ->assertJsonPath('data.exams.0.id', $pending->id);

    expect($response->json('data.exam_suggestions'))->not->toBeEmpty();
});

it('sends the exams but no plans while only the exams screen is on', function () {
    $plan = snapshotPlan($this->student->id, 'hifz', '2026-07-01');
    StudentPlanDay::create(['student_plan_id' => $plan->id, 'date' => '2026-07-08', 'day_name' => 'Wednesday', 'from_ayah_id' => 1, 'to_ayah_id' => 2]);
    ExamLevel::create(['name' => 'جزء واحد', 'end_ayah_id' => 1]);

    RoleScreenPermission::where('screen_id', Screen::where('route_name', 'teacher.tasmeeh')->value('id'))->delete();

    snapshot()
        ->assertJsonPath('data.pages', ['tasmeeh' => false, 'student_exams' => true, 'pairs' => true, 'plan_creator' => true])
        ->assertJsonPath('data.tasmeeh_plans', [])
        ->assertJsonCount(1, 'data.exam_levels');
});
