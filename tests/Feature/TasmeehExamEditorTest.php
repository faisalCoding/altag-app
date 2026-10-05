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
        ->assertDontSee('تحديد اختبار');

    expect(DB::getQueryLog())->toBe([]);

    DB::disableQueryLog();
});

it('sets a new exam for a student without one, the level they would sit next chosen and a fortnight on', function () {
    editorExam($this->one, '2026-06-20 16:00:00', 'passed');

    $html = openExamEditor()
        ->assertSet('showEditor', true)
        ->assertSet('examId', null)
        ->assertSet('suggestedLevelId', $this->two->id)
        ->assertSet('levelId', $this->two->id)
        ->assertSet('date', '2026-07-22')
        ->assertSee('تحديد اختبار — أحمد')
        ->assertSee('جزء النبأ والملك — المقترح')
        ->assertSee(HijriDate::full('2026-07-22'))
        ->assertSee('إضافة الاختبار')
        ->html();

    // The levels in the order teachers climb them, not the order they were
    // made, and the teacher app's shortcuts on its days.
    expect($html)->toMatch('/جزء عم.*جزء النبأ والملك.*ثلاثة أجزاء/s')
        ->toMatch('/\$set\(\'date\', \'2026-07-12\'\).*الأحد القادم.*\$set\(\'date\', \'2026-07-22\'\).*بعد أسبوعين.*\$set\(\'date\', \'2026-08-07\'\).*بعد شهر/s');
});

it('starts a student who has passed nothing on the first level', function () {
    openExamEditor()->assertSet('levelId', $this->one->id);
});

it('writes one pending exam on the chosen day, without an hour, and tells the student', function () {
    openExamEditor()
        ->set('levelId', $this->three->id)
        ->set('date', '2026-07-20')
        ->set('location', 'قاعة الجمعية')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showEditor', false)
        ->assertDispatched('toast-show')
        ->assertDispatched('exam-saved', studentId: $this->student->id)
        ->assertDispatched('exam-saved.'.$this->student->id);

    $exam = StudentExam::sole();

    expect($exam->student_id)->toBe($this->student->id)
        ->and($exam->status)->toBe('pending')
        ->and($exam->exam_level_id)->toBe($this->three->id)
        ->and($exam->date_time->toDateTimeString())->toBe('2026-07-20 00:00:00')
        ->and($exam->location)->toBe('قاعة الجمعية')
        ->and(scheduledNotices())->toBe(1);
});

it('shows a student\'s pending exam, and changes it in place', function () {
    $exam = editorExam($this->two, '2026-07-26 16:00:00', location: 'المسجد');

    $editor = openExamEditor()
        ->assertSet('examId', $exam->id)
        ->assertSee('الاختبار القادم — أحمد')
        ->assertSee('جزء النبأ والملك')
        ->assertSee(HijriDate::withWeekday('2026-07-26'))
        ->assertSee('بعد ١٨ يوماً')
        ->assertSee('المسجد')
        ->assertSee('صفحة الاختبارات')
        ->assertSeeHtml('href="'.route('teacher.student-exams').'"')
        ->assertDontSee('حفظ التغيير');

    // Leaving the form drops what was typed into it.
    $editor->set('changing', true)
        ->assertSee('حفظ التغيير')
        ->set('location', 'مكان آخر')
        ->call('cancel')
        ->assertSet('changing', false)
        ->assertSet('location', 'المسجد')
        ->assertSet('showEditor', true);

    $editor->set('changing', true)
        ->set('levelId', $this->three->id)
        ->set('date', '2026-07-28')
        ->set('location', '')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showEditor', false)
        ->assertDispatched('exam-saved', studentId: $this->student->id);

    $exam->refresh();

    // The same row, moved: the hour the exams page set stays, and nobody is
    // told of a new exam that is not new.
    expect(StudentExam::count())->toBe(1)
        ->and($exam->exam_level_id)->toBe($this->three->id)
        ->and($exam->date_time->toDateTimeString())->toBe('2026-07-28 16:00:00')
        ->and($exam->location)->toBeNull()
        ->and(scheduledNotices())->toBe(0);
});

it('refuses a day already gone', function () {
    openExamEditor()
        ->set('date', '2026-07-07')
        ->call('save')
        ->assertHasErrors(['date' => 'after_or_equal'])
        ->assertSee('مضى هذا اليوم، فاختر يوماً قادماً للاختبار.')
        ->assertSet('showEditor', true);

    expect(StudentExam::count())->toBe(0);
});

it('judges a day gone on the academy\'s clock, not UTC', function () {
    // 22:00 UTC on the 8th is already 01:00 on the 9th in Riyadh.
    $this->travelTo(Carbon::create(2026, 7, 8, 22, 0, 0, 'UTC'));

    openExamEditor()
        ->set('date', '2026-07-08')
        ->call('save')
        ->assertHasErrors(['date' => 'after_or_equal'])
        ->set('date', '2026-07-09')
        ->call('save')
        ->assertHasNoErrors();

    expect(StudentExam::sole()->date_time->toDateString())->toBe('2026-07-09');
});

it('lets an exam past its day change level without being moved, but not move to another day gone', function () {
    $exam = editorExam($this->one, '2026-07-05 16:00:00');

    openExamEditor()
        ->assertSee('مضى ٣ أيام، ولم تُرصد نتيجته بعد')
        ->set('changing', true)
        ->set('date', '2026-07-06')
        ->call('save')
        ->assertHasErrors(['date' => 'after_or_equal'])
        ->set('date', '2026-07-05')
        ->set('levelId', $this->two->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($exam->refresh()->exam_level_id)->toBe($this->two->id)
        ->and($exam->date_time->toDateTimeString())->toBe('2026-07-05 16:00:00');
});

it('requires a level that exists and a day', function () {
    openExamEditor()
        ->set('levelId', 999)
        ->set('date', null)
        ->call('save')
        ->assertHasErrors(['levelId' => 'exists', 'date' => 'required']);

    expect(StudentExam::count())->toBe(0);
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

it('shows an exam set meanwhile instead of writing a second one', function () {
    $editor = openExamEditor()->assertSet('examId', null);

    // Set from the phone, or by another teacher of the circle, while the editor was open.
    $meanwhile = editorExam($this->three, '2026-07-15 00:00:00');

    $editor->set('date', '2026-07-20')
        ->call('save')
        ->assertDispatched('toast-show')
        ->assertNotDispatched('exam-saved')
        ->assertSet('showEditor', true)
        ->assertSet('examId', $meanwhile->id)
        ->assertSet('date', '2026-07-15')
        ->assertSee('الاختبار القادم — أحمد');

    expect(StudentExam::pluck('id')->all())->toBe([$meanwhile->id])
        ->and(scheduledNotices())->toBe(0);
});

it('leaves an exam alone once its result came in meanwhile', function () {
    $exam = editorExam($this->two, '2026-07-26 16:00:00');

    $editor = openExamEditor()->set('changing', true)->set('date', '2026-07-30');

    $exam->update(['status' => 'passed', 'score_percentage' => 95]);

    $editor->call('save')
        ->assertDispatched('toast-show')
        ->assertNotDispatched('exam-saved')
        // Nothing pending any more: the editor turns to setting the next one.
        ->assertSet('examId', null);

    expect($exam->refresh()->date_time->toDateTimeString())->toBe('2026-07-26 16:00:00');
});

it('fills the change from the exam as it stands now, not as it stood when the editor opened', function () {
    $exam = editorExam($this->two, '2026-07-26 16:00:00', location: 'المسجد');

    $editor = openExamEditor();

    // Moved from the phone while the editor stood open.
    $exam->update(['exam_level_id' => $this->three->id, 'date_time' => '2026-07-30 16:00:00']);

    $editor->call('startChanging')
        ->assertSet('changing', true)
        ->assertSet('levelId', $this->three->id)
        ->assertSet('date', '2026-07-30');
});

it('refuses to write a change over one made elsewhere since the form was filled', function () {
    $exam = editorExam($this->two, '2026-07-26 16:00:00', location: 'المسجد');

    $editor = openExamEditor()->call('startChanging')->set('location', 'القاعة');

    // A co-teacher's phone moves the exam before this teacher saves.
    $exam->update(['exam_level_id' => $this->three->id, 'date_time' => '2026-07-30 16:00:00']);

    $editor->call('save')
        ->assertSet('showEditor', true)
        ->assertSet('changing', true)
        ->assertSet('levelId', $this->three->id)
        ->assertSet('date', '2026-07-30')
        ->assertSet('location', 'المسجد')
        ->assertNotDispatched('exam-saved');

    $exam->refresh();

    // The phone's move stands; nothing of the stale form was written.
    expect($exam->exam_level_id)->toBe($this->three->id)
        ->and($exam->date_time->toDateString())->toBe('2026-07-30')
        ->and($exam->location)->toBe('المسجد');

    // Saved again from the form now in step, it goes through.
    $editor->set('location', 'القاعة')->call('save')->assertHasNoErrors()->assertSet('showEditor', false);

    expect($exam->refresh()->location)->toBe('القاعة');
});

it('tells the tapped box it answered, whether or not the editor opens', function () {
    openExamEditor()->assertDispatched('exam-editor-ready')->assertSet('showEditor', true);

    $stranger = Student::factory()->create(['status' => 'active']);

    openExamEditor($stranger)->assertDispatched('exam-editor-ready')->assertSet('showEditor', false);
});

it('labels the date once, under «الموعد»', function () {
    openExamEditor()
        ->assertSee('الموعد')
        ->assertDontSee('التاريخ (هجري)');
});
