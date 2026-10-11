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
use App\Support\HijriDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    // A Wednesday: the coming Sunday is the 12th, a fortnight on the 22nd.
    Carbon::setTestNow('2026-07-08 10:00:00');

    Surah::create([
        'id' => 1, 'number' => 1, 'name_arabic' => 'الفاتحة', 'name_simple' => 'Al-Fatihah',
        'revelation_place' => 'makkah', 'revelation_order' => 1, 'verses_count' => 7,
        'start_page' => 1, 'end_page' => 1,
    ]);

    // Verse n sits in juz 31 - n, so a level ending on it is n juz long.
    foreach (range(1, 3) as $verse) {
        Ayah::create([
            'id' => $verse, 'surah_id' => 1, 'verse_number' => $verse, 'page_number' => 1,
            'line_number_start' => 1, 'line_number_end' => 1, 'verse_key' => "1:{$verse}",
            'juz_number' => 31 - $verse, 'hizb_number' => 1, 'rub_number' => 1, 'ruku_number' => 1,
            'manzil_number' => 1, 'text_uthmani' => 'آية',
        ]);
    }

    $this->teacher = Teacher::factory()->create();
    $this->circle = Circle::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    // Created out of climbing order, which the choices must not follow.
    $this->three = ExamLevel::create(['name' => 'ثلاثة أجزاء', 'end_ayah_id' => 3]);
    $this->one = ExamLevel::create(['name' => 'جزء عم', 'end_ayah_id' => 1]);
    $this->two = ExamLevel::create(['name' => 'جزء النبأ والملك', 'end_ayah_id' => 2]);

    $this->student = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'أحمد', 'status' => 'active']);

    $this->actingAs($this->teacher, 'teacher');
});

function editorExam(ExamLevel $level, string $dateTime, string $status = 'pending', ?string $location = null): StudentExam
{
    return StudentExam::create([
        'student_id' => test()->student->id,
        'exam_level_id' => $level->id,
        'status' => $status,
        'date_time' => $dateTime,
        'location' => $location,
    ]);
}

function openExamEditor(?Student $student = null): Testable
{
    return Livewire::test('teacher.⚡tasmeeh-exam-editor')
        ->dispatch('open-exam-editor', studentId: ($student ?? test()->student)->id);
}

function scheduledNotices(): int
{
    return AppNotification::where('recipient_id', test()->student->id)->where('type', 'exam_scheduled')->count();
}

it('reads nothing until a box opens it', function () {
    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test('teacher.⚡tasmeeh-exam-editor')
        ->assertSet('showEditor', false)
        ->assertDontSee('ترشيح للاختبار');

    expect(DB::getQueryLog())->toBe([]);

    DB::disableQueryLog();
});

it('puts forward a student without an open exam, the level they would sit next chosen and ready now', function () {
    editorExam($this->one, '2026-06-20 16:00:00', 'passed');

    $html = openExamEditor()
        ->assertSet('showEditor', true)
        ->assertSet('examId', null)
        ->assertSet('suggestedLevelId', $this->two->id)
        ->assertSet('levelId', $this->two->id)
        ->assertSet('readiness', 'now')
        ->assertSee('ترشيح للاختبار — أحمد')
        ->assertSee('جزء النبأ والملك — المقترح')
        ->assertSee('جاهز الآن')
        ->assertDontSee('الموعد')
        ->html();

    // The levels in the order teachers climb them, not the order they were made.
    expect($html)->toMatch('/جزء عم.*جزء النبأ والملك.*ثلاثة أجزاء/s');
});

it('starts a student who has passed nothing on the first level', function () {
    openExamEditor()->assertSet('levelId', $this->one->id);
});

it('puts the student forward ready, with no day, and tells the student nothing yet', function () {
    openExamEditor()
        ->set('levelId', $this->three->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showEditor', false)
        ->assertDispatched('toast-show')
        ->assertDispatched('exam-saved', studentId: $this->student->id)
        ->assertDispatched('exam-saved.'.$this->student->id);

    $exam = StudentExam::sole();

    expect($exam->status)->toBe('nominated')
        ->and($exam->exam_level_id)->toBe($this->three->id)
        ->and($exam->isReady())->toBeTrue()
        ->and($exam->date_time)->toBeNull()
        ->and($exam->nominated_by)->toBe($this->teacher->id)
        ->and(scheduledNotices())->toBe(0)
        ->and(AppNotification::count())->toBe(0);
});

it('puts the student forward for a day expected, which is not readiness', function () {
    openExamEditor()
        ->set('readiness', 'expected')
        ->assertSee('اليوم المتوقع لجاهزيته')
        ->set('expectedOn', '2026-07-20')
        ->call('save')
        ->assertHasNoErrors();

    $exam = StudentExam::sole();

    expect($exam->expected_ready_on->toDateString())->toBe('2026-07-20')
        ->and($exam->isReady())->toBeFalse();
});

it('asks for a level that exists, and a day to come when one is expected', function () {
    openExamEditor()
        ->set('levelId', 999)
        ->set('readiness', 'expected')
        ->set('expectedOn', null)
        ->call('save')
        ->assertHasErrors(['levelId' => 'exists', 'expectedOn' => 'required_if']);

    openExamEditor()
        ->set('readiness', 'expected')
        ->set('expectedOn', '2026-07-08')
        ->call('save')
        ->assertHasErrors(['expectedOn' => 'after']);

    expect(StudentExam::count())->toBe(0);
});

it('judges a day to come on the academy\'s clock, not UTC', function () {
    // 22:30 UTC on the 8th is already the 9th in Riyadh.
    Carbon::setTestNow('2026-07-08 22:30:00');

    openExamEditor()
        ->set('readiness', 'expected')
        ->set('expectedOn', '2026-07-09')
        ->call('save')
        ->assertHasErrors(['expectedOn' => 'after']);
});

it('shows a nomination, and lets the teacher change it or take it back', function () {
    ExamReadiness::nominate($this->student, $this->one->id, '2026-07-20', $this->teacher);

    openExamEditor()
        ->assertSet('readiness', 'expected')
        ->assertSet('expectedOn', '2026-07-20')
        ->assertSee('جاهزيته متوقعة')
        ->assertSee('إلغاء الترشيح')
        ->call('startChanging')
        ->assertSet('changing', true)
        ->set('levelId', $this->two->id)
        ->set('readiness', 'now')
        ->call('save')
        ->assertHasNoErrors();

    expect(StudentExam::sole()->exam_level_id)->toBe($this->two->id)
        ->and(StudentExam::sole()->isReady())->toBeTrue();

    openExamEditor()->assertSee('جاهز، بانتظار موعد من الإدارة')->call('withdraw')->assertDispatched('exam-saved');

    expect(StudentExam::count())->toBe(0);
});

it('asks the teacher to confirm once the expected day comes', function () {
    ExamReadiness::nominate($this->student, $this->one->id, '2026-07-10', $this->teacher);
    Carbon::setTestNow('2026-07-10 08:00:00');

    openExamEditor()
        ->assertSee('حان اليوم المتوقع لجاهزيته')
        ->assertSee('تأكيد الجاهزية')
        ->call('confirmReady')
        ->assertDispatched('exam-saved');

    expect(StudentExam::sole()->isReady())->toBeTrue();
});

it('shows an exam the manager seated as it stands, with nothing to change', function () {
    $exam = ExamReadiness::nominate($this->student, $this->one->id, null, $this->teacher);
    ExamReadiness::createBatch('2026-07-20 16:30', 'قاعة الجمعية', null, [$exam->id], null);

    openExamEditor()
        ->assertSee('الاختبار القادم — أحمد')
        ->assertSee(HijriDate::withWeekday('2026-07-20'))
        ->assertSee('قاعة الجمعية')
        ->assertSee('حدّدت الإدارة موعد هذا الاختبار.')
        ->assertDontSee('إلغاء الترشيح')
        ->call('startChanging')
        ->assertSet('changing', false)
        ->call('withdraw')
        ->assertNotDispatched('exam-saved');

    expect(StudentExam::sole()->status)->toBe('pending');
});

it('refuses a student outside the teacher\'s circles, or no longer active', function () {
    $elsewhere = Student::factory()->create(['circle_id' => Circle::factory()->create()->id, 'status' => 'active']);
    $left = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'left']);

    foreach ([$elsewhere, $left] as $student) {
        openExamEditor($student)
            ->assertDispatched('toast-show')
            ->assertSet('showEditor', false)
            ->assertSet('studentId', null);
    }
});

it('refuses while the exams page is switched off for teachers', function () {
    RoleScreenPermission::where('screen_id', Screen::where('route_name', 'teacher.student-exams')->value('id'))->delete();

    openExamEditor()
        ->assertDispatched('toast-show')
        ->assertSet('showEditor', false)
        ->assertSet('studentId', null);
});

it('writes nothing when the exams page is switched off while the editor is open', function () {
    $editor = openExamEditor()->assertSet('showEditor', true);

    RoleScreenPermission::where('screen_id', Screen::where('route_name', 'teacher.student-exams')->value('id'))->delete();
    Once::flush(); // A new request reads the switch afresh.

    $editor->call('save')
        ->assertSet('showEditor', false)
        ->assertNotDispatched('exam-saved');

    expect(StudentExam::count())->toBe(0);
});

it('shows a nomination made meanwhile instead of writing a second one', function () {
    $editor = openExamEditor()->assertSet('examId', null);

    // Put forward from the phone, or by another teacher of the circle, while the editor was open.
    $meanwhile = ExamReadiness::nominate($this->student, $this->two->id, null, $this->teacher);

    $editor->call('save')
        ->assertDispatched('toast-show')
        ->assertSet('examId', $meanwhile->id)
        ->assertNotDispatched('exam-saved');

    expect(StudentExam::count())->toBe(1);
});

it('tells the tapped box it answered, whether or not the editor opens', function () {
    openExamEditor()->assertDispatched('exam-editor-ready')->assertSet('showEditor', true);

    $stranger = Student::factory()->create(['status' => 'active']);

    openExamEditor($stranger)->assertDispatched('exam-editor-ready')->assertSet('showEditor', false);
});
