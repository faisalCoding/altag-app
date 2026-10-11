<?php

use App\Models\AppNotification;
use App\Models\Circle;
use App\Models\ExamLevel;
use App\Models\Guardian;
use App\Models\GuardianNotification;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\Teacher;
use App\Services\ExamReadiness;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;

/*
 * Sunday 11 October 2026, 10:00 in Riyadh. A teacher's circle with three
 * students, one of them with a guardian.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-11 07:00:00');

    $this->level = ExamLevel::create(['name' => 'جزء عم']);
    $this->nextLevel = ExamLevel::create(['name' => 'جزء تبارك']);
    $this->circle = Circle::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->guardian = Guardian::factory()->create();
    [$this->ali, $this->omar, $this->zaid] = collect(['علي', 'عمر', 'زيد'])
        ->map(fn ($name) => Student::factory()->create(['name' => $name, 'circle_id' => $this->circle->id]))
        ->all();
    $this->ali->update(['guardian_id' => $this->guardian->id]);
});

it('puts a student forward ready now, or by a day to come they are only expected', function () {
    $ready = ExamReadiness::nominate($this->ali, $this->level->id, null, $this->teacher);
    $expected = ExamReadiness::nominate($this->omar, $this->level->id, '2026-10-25', $this->teacher);
    // A day already here stays expected, due: only the teacher's word makes them ready.
    $today = ExamReadiness::nominate($this->zaid, $this->level->id, '2026-10-11', $this->teacher);

    expect($ready->status)->toBe('nominated')
        ->and($ready->isReady())->toBeTrue()
        ->and($ready->date_time)->toBeNull()
        ->and($ready->nominated_by)->toBe($this->teacher->id)
        ->and($expected->isReady())->toBeFalse()
        ->and($expected->expected_ready_on->toDateString())->toBe('2026-10-25')
        ->and($today->isReady())->toBeFalse()
        // Nothing reaches the student while they are only put forward.
        ->and(AppNotification::count())->toBe(0);
});

it('keeps one open exam a student', function () {
    ExamReadiness::nominate($this->ali, $this->level->id, null, $this->teacher);

    expect(ExamReadiness::nominate($this->ali, $this->nextLevel->id, null, $this->teacher))->toBeString()
        ->and(StudentExam::count())->toBe(1);
});

it('lets the teacher change or take back a nomination until it is seated', function () {
    $exam = ExamReadiness::nominate($this->omar, $this->level->id, '2026-10-25', $this->teacher);

    ExamReadiness::revise($exam, $this->nextLevel->id, null);
    expect($exam->fresh()->exam_level_id)->toBe($this->nextLevel->id)
        ->and($exam->fresh()->isReady())->toBeTrue();

    ExamReadiness::createBatch('2026-10-20 16:30', 'قاعة الجمعية', null, [$exam->id], null);

    expect(ExamReadiness::revise($exam->fresh(), $this->level->id, null))->toBeString()
        ->and(ExamReadiness::withdraw($exam->fresh()))->toBeFalse()
        ->and(StudentExam::count())->toBe(1);
});

it('seats students put forward in a batch, and tells them and their guardian the day', function () {
    $ali = ExamReadiness::nominate($this->ali, $this->level->id, null, $this->teacher);
    $omar = ExamReadiness::nominate($this->omar, $this->level->id, '2026-10-25', $this->teacher);

    $batch = ExamReadiness::createBatch('2026-10-20 16:30', 'قاعة الجمعية', 'يحضر قبل الموعد بربع ساعة', [$ali->id, $omar->id], null);

    expect($ali->fresh()->status)->toBe('pending')
        ->and($ali->fresh()->exam_batch_id)->toBe($batch->id)
        ->and($ali->fresh()->date_time->format('Y-m-d H:i'))->toBe('2026-10-20 16:30')
        ->and($ali->fresh()->location)->toBe('قاعة الجمعية')
        // Only expected, but the manager's call: seated all the same.
        ->and($omar->fresh()->status)->toBe('pending')
        ->and($omar->fresh()->exam_batch_id)->toBe($batch->id);

    $note = AppNotification::where('recipient_type', 'student')->where('recipient_id', $this->ali->id)->sole();

    expect($note->recipient_id)->toBe($this->ali->id)
        ->and($note->body)->toContain('جزء عم')->toContain('الساعة 4:30 م')->toContain('قاعة الجمعية')
        ->and(GuardianNotification::where('guardian_id', $this->guardian->id)->where('type', 'exam_scheduled')->count())->toBe(1);

    // Seated already: not seated twice.
    expect(ExamReadiness::seat($batch, [$omar->id]))->toBe(0);
});

it('moves a batch\'s students with it, and puts a student taken out back among the ready', function () {
    $ali = ExamReadiness::nominate($this->ali, $this->level->id, null, $this->teacher);
    $zaid = ExamReadiness::nominate($this->zaid, $this->level->id, null, $this->teacher);
    $batch = ExamReadiness::createBatch('2026-10-20 16:30', null, null, [$ali->id, $zaid->id], null);

    ExamReadiness::updateBatch($batch, '2026-10-21 17:00', 'المسجد', null);

    expect($ali->fresh()->date_time->format('Y-m-d H:i'))->toBe('2026-10-21 17:00')
        ->and(AppNotification::where('type', 'exam_rescheduled')->count())->toBe(2);

    ExamReadiness::unseat($zaid->fresh());

    expect($zaid->fresh()->only(['status', 'exam_batch_id', 'date_time']))->toBe(['status' => 'nominated', 'exam_batch_id' => null, 'date_time' => null])
        ->and($zaid->fresh()->isReady())->toBeTrue();
});

it('puts a student seated while only expected back to their expected day when taken out', function () {
    $omar = ExamReadiness::nominate($this->omar, $this->level->id, '2026-10-25', $this->teacher);
    ExamReadiness::createBatch('2026-10-20 16:30', null, null, [$omar->id], null);

    ExamReadiness::unseat($omar->fresh());

    expect($omar->fresh()->status)->toBe('nominated')
        ->and($omar->fresh()->isReady())->toBeFalse()
        ->and($omar->fresh()->expected_ready_on->toDateString())->toBe('2026-10-25');
});

it('puts a student absent from a batch forward again, ready, for the same level', function () {
    $ali = ExamReadiness::nominate($this->ali, $this->level->id, null, $this->teacher);
    $omar = ExamReadiness::nominate($this->omar, $this->level->id, null, $this->teacher);
    ExamReadiness::createBatch('2026-10-20 16:30', null, null, [$ali->id, $omar->id], null);

    ExamReadiness::recordResult($ali->fresh(), 'absent', null);
    ExamReadiness::recordResult($omar->fresh(), 'passed', '96.5');

    $again = StudentExam::where('student_id', $this->ali->id)->nominated()->sole();

    expect($ali->fresh()->status)->toBe('absent')
        ->and($again->exam_level_id)->toBe($this->level->id)
        ->and($again->isReady())->toBeTrue()
        ->and($omar->fresh()->score_percentage)->toBe('96.50')
        ->and(AppNotification::where('type', 'exam_result')->where('recipient_id', $this->omar->id)->count())->toBe(1);
});

it('reminds the teacher once when the expected day comes, never before', function () {
    ExamReadiness::nominate($this->omar, $this->level->id, '2026-10-13', $this->teacher);

    expect(ExamReadiness::remindDue())->toBe(0);

    Carbon::setTestNow('2026-10-13 05:00:00');

    expect(ExamReadiness::remindDue())->toBe(1)
        ->and(ExamReadiness::remindDue())->toBe(0)
        ->and(AppNotification::where('recipient_type', 'teacher')->where('recipient_id', $this->teacher->id)->sole()->body)->toContain('عمر');

    // Still not ready until the teacher says so.
    expect(StudentExam::sole()->isReady())->toBeFalse();
});

it('runs the reminder every morning', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command ?? '');

    expect($events->contains(fn ($command) => str_contains($command, 'exams:remind-readiness')))->toBeTrue();
});
