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
 * One queued exam as the phone sends it: the first level scheduled for next
 * Wednesday for a student with no exam pending.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function examChange(array $overrides = []): array
{
    $id = $overrides['id'] ?? (string) Str::uuid();

    return array_merge([
        'id' => $id,
        'action' => 'create',
        'student_id' => test()->student->id,
        'exam' => ['id' => null, 'uuid' => $id],
        'level_id' => test()->firstLevel->id,
        'date' => '2026-07-15',
        'base' => null,
        'edited_on' => '2026-07-08',
    ], $overrides);
}

function postExams(array ...$changes): TestResponse
{
    return test()->postJson('/api/v1/teacher/exams/changes', ['changes' => $changes]);
}

function pendingExam(array $attributes = []): StudentExam
{
    return StudentExam::create(array_merge([
        'student_id' => test()->student->id,
        'exam_level_id' => test()->firstLevel->id,
        'status' => 'pending',
        'date_time' => '2026-07-15 16:30:00',
        'location' => 'قاعة الجمعية',
    ], $attributes));
}

it('schedules an exam and tells the student', function () {
    $change = examChange();

    postExams($change)
        ->assertSuccessful()
        ->assertJsonPath('data.results.0.id', $change['id'])
        ->assertJsonPath('data.results.0.result', 'applied')
        ->assertJsonPath('data.results.0.exam.uuid', $change['id'])
        ->assertJsonPath('data.results.0.exam.level_id', $this->firstLevel->id)
        ->assertJsonPath('data.results.0.exam.date', '2026-07-15');

    $exam = StudentExam::sole();

    expect($exam->status)->toBe('pending')
        ->and($exam->date_time->toDateTimeString())->toBe('2026-07-15 00:00:00')
        ->and(AppNotification::where('recipient_id', $this->student->id)->where('type', 'exam_scheduled')->count())->toBe(1);
});

it('answers a resent exam as applied without scheduling it twice', function () {
    $change = examChange();

    postExams($change);
    postExams($change)->assertJsonPath('data.results.0.result', 'applied');

    expect(StudentExam::count())->toBe(1)
        ->and(AppNotification::count())->toBe(1);
});

it('holds back a second exam while one is pending', function () {
    $pending = pendingExam();

    postExams(examChange(['level_id' => $this->secondLevel->id]))
        ->assertJsonPath('data.results.0.result', 'conflict')
        ->assertJsonPath('data.results.0.exam.id', $pending->id);

    expect(StudentExam::count())->toBe(1);
});

it('moves an exam, keeping the hour the web page set and telling no one', function () {
    $exam = pendingExam();

    postExams(examChange([
        'action' => 'update',
        'exam' => ['id' => $exam->id, 'uuid' => null],
        'level_id' => $this->secondLevel->id,
        'date' => '2026-07-20',
        'base' => ['level_id' => $this->firstLevel->id, 'date' => '2026-07-15'],
    ]))->assertJsonPath('data.results.0.result', 'applied');

    $exam->refresh();

    expect($exam->exam_level_id)->toBe($this->secondLevel->id)
        ->and($exam->date_time->toDateTimeString())->toBe('2026-07-20 16:30:00')
        ->and($exam->location)->toBe('قاعة الجمعية')
        ->and(AppNotification::count())->toBe(0);
});

it('finds an exam created on a phone by its uuid', function () {
    $uuid = (string) Str::uuid();
    $exam = pendingExam(['uuid' => $uuid]);

    postExams(examChange([
        'action' => 'update',
        'exam' => ['id' => null, 'uuid' => $uuid],
        'date' => '2026-07-22',
        'base' => ['level_id' => $this->firstLevel->id, 'date' => '2026-07-15'],
    ]))->assertJsonPath('data.results.0.result', 'applied');

    expect($exam->fresh()->date_time->toDateString())->toBe('2026-07-22');
});

it('holds back a move when the exam was moved elsewhere since', function () {
    $exam = pendingExam(['date_time' => '2026-07-18 09:00:00']);

    postExams(examChange([
        'action' => 'update',
        'exam' => ['id' => $exam->id, 'uuid' => null],
        'date' => '2026-07-20',
        'base' => ['level_id' => $this->firstLevel->id, 'date' => '2026-07-15'],
    ]))
        ->assertJsonPath('data.results.0.result', 'conflict')
        ->assertJsonPath('data.results.0.exam.date', '2026-07-18');
});

it('leaves an exam alone once it has a result', function () {
    $exam = pendingExam(['status' => 'passed']);

    postExams(examChange([
        'action' => 'update',
        'exam' => ['id' => $exam->id, 'uuid' => null],
        'date' => '2026-07-20',
        'base' => ['level_id' => $this->firstLevel->id, 'date' => '2026-07-15'],
    ]))
        ->assertJsonPath('data.results.0.code', 'exam_closed')
        ->assertJsonPath('data.results.0.exam.status', 'passed');
});

it('refuses a day gone by, judged by the day the teacher made the edit', function () {
    postExams(examChange(['date' => '2026-07-07']))
        ->assertJsonPath('data.results.0.code', 'date_in_past');

    // Set for the day itself while offline, reaching the server a day later.
    postExams(examChange(['date' => '2026-07-07', 'edited_on' => '2026-07-07']))
        ->assertJsonPath('data.results.0.result', 'applied');
});

it('lets an overdue exam change level without being moved', function () {
    $exam = pendingExam(['date_time' => '2026-07-01 00:00:00']);

    postExams(examChange([
        'action' => 'update',
        'exam' => ['id' => $exam->id, 'uuid' => null],
        'level_id' => $this->secondLevel->id,
        'date' => '2026-07-01',
        'base' => ['level_id' => $this->firstLevel->id, 'date' => '2026-07-01'],
    ]))->assertJsonPath('data.results.0.result', 'applied');

    expect($exam->fresh()->exam_level_id)->toBe($this->secondLevel->id);
});

it('refuses what it cannot apply, each change on its own', function () {
    $stranger = Student::factory()->create(['circle_id' => Circle::factory()->create()->id]);

    postExams(
        examChange(['student_id' => $stranger->id]),
        examChange(['level_id' => 999999]),
        examChange(['action' => 'update', 'exam' => ['id' => 999999, 'uuid' => null], 'base' => ['level_id' => $this->firstLevel->id, 'date' => '2026-07-15']]),
        examChange(),
    )
        ->assertJsonPath('data.results.0.code', 'student_unavailable')
        ->assertJsonPath('data.results.1.code', 'exam_level_unavailable')
        ->assertJsonPath('data.results.2.code', 'exam_unavailable')
        ->assertJsonPath('data.results.3.result', 'applied');
});

it('needs the value the phone last saw to move an exam', function () {
    postExams(examChange(['action' => 'update', 'exam' => ['id' => 1, 'uuid' => null]]))
        ->assertUnprocessable();
});

it('stops scheduling once the exams page is switched off for teachers', function () {
    RoleScreenPermission::where('screen_id', Screen::where('route_name', 'teacher.student-exams')->value('id'))->delete();

    postExams(examChange())
        ->assertForbidden()
        ->assertJsonPath('code', 'page_disabled');
});
