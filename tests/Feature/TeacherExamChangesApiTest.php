<?php

use App\Models\AppNotification;
use App\Models\Ayah;
use App\Models\Circle;
use App\Models\ExamLevel;
use App\Models\RoleScreenPermission;
use App\Models\Screen;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\Surah;
use App\Models\Teacher;
use App\Services\ExamReadiness;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Carbon::setTestNow('2026-07-08 10:00:00');

    Surah::create([
        'id' => 1, 'number' => 1, 'name_arabic' => 'الفاتحة', 'name_simple' => 'Al-Fatihah',
        'revelation_place' => 'makkah', 'revelation_order' => 1, 'verses_count' => 7,
        'start_page' => 1, 'end_page' => 1,
    ]);

    // Ayahs ending juz 30 and juz 29, so the levels read as one and two juz.
    foreach ([30, 29] as $i => $juz) {
        Ayah::create([
            'id' => $i + 1, 'surah_id' => 1, 'verse_number' => $i + 1, 'page_number' => 1,
            'line_number_start' => 1, 'line_number_end' => 1, 'verse_key' => '1:'.($i + 1),
            'juz_number' => $juz, 'hizb_number' => 1, 'rub_number' => 1, 'ruku_number' => 1,
            'manzil_number' => 1, 'text_uthmani' => 'آية',
        ]);
    }

    $this->firstLevel = ExamLevel::create(['name' => 'اختبار جزء النبأ', 'end_ayah_id' => 1]);
    $this->secondLevel = ExamLevel::create(['name' => 'اختبار جزء النبأ والملك', 'end_ayah_id' => 2]);

    $this->circle = Circle::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::factory()->create(['circle_id' => $this->circle->id]);

    Sanctum::actingAs($this->teacher);
});

/**
 * One queued change as the phone sends it: putting a student forward for the
 * first level, ready now.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function examChange(array $overrides = []): array
{
    $id = $overrides['id'] ?? (string) Str::uuid();

    return array_merge([
        'id' => $id,
        'action' => 'nominate',
        'student_id' => test()->student->id,
        'exam' => ['id' => null, 'uuid' => $id],
        'level_id' => test()->firstLevel->id,
        'expected_on' => null,
        'base' => null,
        'edited_on' => '2026-07-08',
    ], $overrides);
}

function postExams(array ...$changes): TestResponse
{
    return test()->postJson('/api/v1/teacher/exams/changes', ['changes' => $changes]);
}

it('puts a student forward, ready, and tells no one yet', function () {
    $change = examChange();

    postExams($change)
        ->assertSuccessful()
        ->assertJsonPath('data.results.0.id', $change['id'])
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.exam.uuid', $change['id'])
        ->assertJsonPath('data.results.0.exam.status', 'nominated')
        ->assertJsonPath('data.results.0.exam.ready', true)
        ->assertJsonPath('data.results.0.exam.date', null)
        ->assertJsonPath('data.results.0.exam.expected_on', null);

    $exam = StudentExam::sole();

    expect($exam->nominated_by)->toBe($this->teacher->id)
        ->and(AppNotification::count())->toBe(0);
});

it('puts a student forward for a day expected, which is not readiness', function () {
    postExams(examChange(['expected_on' => '2026-07-20']))
        ->assertJsonPath('data.results.0.exam.ready', false)
        ->assertJsonPath('data.results.0.exam.expected_on', '2026-07-20');

    expect(StudentExam::sole()->isReady())->toBeFalse();
});

it('keeps a day expected that came while the phone was offline as due, never ready', function () {
    postExams(examChange(['expected_on' => '2026-07-05']))
        ->assertJsonPath('data.results.0.exam.ready', false)
        ->assertJsonPath('data.results.0.exam.expected_on', '2026-07-05');

    expect(StudentExam::sole()->isReady())->toBeFalse();
});

it('answers a resent nomination as applied without putting the student forward twice', function () {
    $change = examChange();

    postExams($change);
    postExams($change)->assertJsonPath('data.results.0.result', 'applied');

    expect(StudentExam::count())->toBe(1);
});

it('holds back a nomination while the student has an open exam', function () {
    $open = ExamReadiness::nominate($this->student, $this->secondLevel->id, null, $this->teacher);

    postExams(examChange())
        ->assertJsonPath('data.results.0.result', 'conflict')
        ->assertJsonPath('data.results.0.exam.id', $open->id);

    expect(StudentExam::count())->toBe(1);
});

it('revises a nomination, and confirms readiness as a revision to ready now', function () {
    $exam = ExamReadiness::nominate($this->student, $this->firstLevel->id, '2026-07-20', $this->teacher);

    postExams(examChange([
        'action' => 'revise',
        'exam' => ['id' => $exam->id, 'uuid' => null],
        'level_id' => $this->secondLevel->id,
        'expected_on' => '2026-07-25',
        'base' => ['level_id' => $this->firstLevel->id, 'expected_on' => '2026-07-20'],
    ]))->assertJsonPath('data.results.0.result', 'applied')->assertJsonPath('data.results.0.exam.expected_on', '2026-07-25');

    postExams(examChange([
        'action' => 'revise',
        'exam' => ['id' => $exam->id, 'uuid' => null],
        'level_id' => $this->secondLevel->id,
        'expected_on' => null,
        'base' => ['level_id' => $this->secondLevel->id, 'expected_on' => '2026-07-25'],
    ]))->assertJsonPath('data.results.0.exam.ready', true);

    expect($exam->fresh()->exam_level_id)->toBe($this->secondLevel->id)
        ->and($exam->fresh()->isReady())->toBeTrue();
});

it('finds a nomination made on a phone by its uuid', function () {
    $made = examChange();
    postExams($made);

    postExams(examChange([
        'action' => 'revise',
        'exam' => ['id' => null, 'uuid' => $made['id']],
        'expected_on' => '2026-07-30',
        'base' => ['level_id' => $this->firstLevel->id, 'expected_on' => null],
    ]))->assertJsonPath('data.results.0.result', 'applied');

    expect(StudentExam::sole()->expected_ready_on->toDateString())->toBe('2026-07-30');
});

it('holds back a revision when the nomination changed elsewhere since', function () {
    $exam = ExamReadiness::nominate($this->student, $this->secondLevel->id, null, $this->teacher);

    postExams(examChange([
        'action' => 'revise',
        'exam' => ['id' => $exam->id, 'uuid' => null],
        'expected_on' => '2026-07-25',
        'base' => ['level_id' => $this->firstLevel->id, 'expected_on' => null],
    ]))
        ->assertJsonPath('data.results.0.result', 'conflict')
        ->assertJsonPath('data.results.0.exam.level_id', $this->secondLevel->id);
});

it('leaves an exam the manager seated, or one finished, alone', function () {
    $seated = ExamReadiness::nominate($this->student, $this->firstLevel->id, null, $this->teacher);
    ExamReadiness::createBatch('2026-07-20 16:30', null, null, [$seated->id], null);

    postExams(
        examChange(['action' => 'revise', 'exam' => ['id' => $seated->id, 'uuid' => null], 'base' => ['level_id' => $this->firstLevel->id, 'expected_on' => null], 'expected_on' => '2026-07-25']),
        examChange(['action' => 'withdraw', 'exam' => ['id' => $seated->id, 'uuid' => null], 'level_id' => null]),
    )
        ->assertJsonPath('data.results.0.code', 'exam_scheduled')
        ->assertJsonPath('data.results.0.exam.date', '2026-07-20')
        ->assertJsonPath('data.results.1.code', 'exam_scheduled');

    ExamReadiness::recordResult($seated->fresh(), 'passed', '90');

    postExams(examChange(['action' => 'revise', 'exam' => ['id' => $seated->id, 'uuid' => null], 'base' => ['level_id' => $this->firstLevel->id, 'expected_on' => null]]))
        ->assertJsonPath('data.results.0.code', 'exam_closed');
});

it('takes a nomination back, and answers one gone already as applied', function () {
    $exam = ExamReadiness::nominate($this->student, $this->firstLevel->id, null, $this->teacher);
    $withdraw = examChange(['action' => 'withdraw', 'exam' => ['id' => $exam->id, 'uuid' => null], 'level_id' => null]);

    postExams($withdraw)->assertJsonPath('data.results.0.result', 'applied')->assertJsonPath('data.results.0.exam', null);
    postExams($withdraw)->assertJsonPath('data.results.0.result', 'applied');

    expect(StudentExam::count())->toBe(0);
});

it('asks an app that still schedules a day to update', function () {
    postExams(
        examChange(['action' => 'create', 'date' => '2026-07-15', 'expected_on' => null]),
        examChange(['action' => 'update', 'exam' => ['id' => 1, 'uuid' => null], 'date' => '2026-07-15', 'base' => ['level_id' => $this->firstLevel->id, 'date' => '2026-07-14']]),
    )
        ->assertSuccessful()
        ->assertJsonPath('data.results.0.code', 'update_required')
        ->assertJsonPath('data.results.1.code', 'update_required');

    expect(StudentExam::count())->toBe(0);
});

it('refuses what it cannot apply, each change on its own', function () {
    $stranger = Student::factory()->create();

    $results = postExams(
        examChange(['student_id' => $stranger->id]),
        examChange(['level_id' => 999]),
        examChange(),
    )->json('data.results');

    expect(array_column($results, 'result'))->toBe(['rejected', 'rejected', 'applied'])
        ->and(array_column($results, 'code'))->toBe(['student_unavailable', 'exam_level_unavailable']);
});

it('stops taking nominations once the exams page is switched off for teachers', function () {
    RoleScreenPermission::where('screen_id', Screen::where('route_name', 'teacher.student-exams')->value('id'))->delete();

    postExams(examChange())
        ->assertForbidden()
        ->assertJsonPath('code', 'page_disabled');
});

it('syncs seated exams as before, and nominations apart for the apps that know them', function () {
    $seated = ExamReadiness::nominate($this->student, $this->firstLevel->id, null, $this->teacher);
    ExamReadiness::createBatch('2026-07-20 16:30', 'قاعة', null, [$seated->id], null);
    $other = Student::factory()->create(['circle_id' => $this->circle->id]);
    $nominated = ExamReadiness::nominate($other, $this->secondLevel->id, '2026-07-25', $this->teacher);

    $data = $this->getJson('/api/v1/teacher/sync')->assertSuccessful()->json('data');

    expect(array_column($data['exams'], 'id'))->toBe([$seated->id])
        ->and($data['exams'][0]['date'])->toBe('2026-07-20')
        ->and(array_column($data['exam_nominations'], 'id'))->toBe([$nominated->id])
        ->and($data['exam_nominations'][0])->toMatchArray(['status' => 'nominated', 'date' => null, 'ready' => false, 'expected_on' => '2026-07-25']);
});
