<?php

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
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
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

    $this->student = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'أحمد', 'status' => 'active']);

    $this->actingAs($this->teacher, 'teacher');
});

function examBoxCard(): Testable
{
    return Livewire::test('teacher.⚡student-tasmeeh-card', [
        'student' => test()->student,
        'sPlans' => collect(),
        'activePlanId' => null,
    ]);
}

function cardPendingExam(ExamLevel $level, string $dateTime): StudentExam
{
    return StudentExam::create([
        'student_id' => test()->student->id,
        'exam_level_id' => $level->id,
        'status' => 'pending',
        'date_time' => $dateTime,
    ]);
}

function cardOpensEditor(): string
{
    return 'wire:click="$dispatch(\'open-exam-editor\', { studentId: '.test()->student->id.' })"';
}

it('shows the juz count of the student\'s next exam beside their name, opening the exam editor', function () {
    $two = ExamLevel::create(['name' => 'اختبار جزء النبأ والملك', 'end_ayah_id' => 2]);
    $three = ExamLevel::create(['name' => 'ثلاثة أجزاء', 'end_ayah_id' => 3]);

    StudentExam::create(['student_id' => $this->student->id, 'exam_level_id' => $three->id, 'status' => 'passed', 'date_time' => '2026-07-01 16:00:00']);
    cardPendingExam($three, '2026-08-20 16:00:00');
    cardPendingExam($two, '2026-07-26 16:00:00'); // The earliest pending one.

    $html = examBoxCard()
        ->assertSeeInOrder(['أحمد', '٢', 'جزآن'])
        ->assertDontSee('أجزاء')
        ->assertSeeHtml('title="الاختبار القادم: اختبار جزء النبأ والملك، '.HijriDate::full('2026-07-26').' — '.$this->student->name.'"')
        ->assertSeeHtml(cardOpensEditor())
        ->assertDontSeeHtml(route('teacher.student-exams'))
        ->assertDontSee('إضافة الاختبار القادم')
        ->html();

    expect($html)->toMatch('/<button\s[^>]*data-exam-box="upcoming"/')
        ->toContain('size-12');
});

it('marks an exam a week away with a dot and one past its day in red', function (string $dateTime, string $state, bool $dot) {
    cardPendingExam(ExamLevel::create(['name' => 'جزء واحد', 'end_ayah_id' => 1]), $dateTime);

    $html = examBoxCard()->assertSeeHtml('data-exam-box="'.$state.'"')->html();

    expect(str_contains($html, 'bg-amber-500'))->toBe($dot)
        ->and(str_contains($html, 'border-red-500'))->toBe($state === 'overdue');
})->with([
    'today' => ['2026-07-08 16:00:00', 'soon', true],
    'in a week' => ['2026-07-15 16:00:00', 'soon', true],
    'in a fortnight' => ['2026-07-22 16:00:00', 'upcoming', false],
    'slipped past its day' => ['2026-07-05 16:00:00', 'overdue', false],
]);

it('shows a graduation cap for a level without an end', function () {
    cardPendingExam(ExamLevel::create(['name' => 'مستوى بلا نهاية']), '2026-07-26 16:00:00');

    examBoxCard()
        ->assertSeeHtml('title="الاختبار القادم: مستوى بلا نهاية، '.HijriDate::full('2026-07-26').' — '.$this->student->name.'"')
        ->assertDontSee('جزء');
});

it('offers a dashed "+" that opens the exam editor when no exam is pending', function () {
    StudentExam::create([
        'student_id' => $this->student->id,
        'exam_level_id' => ExamLevel::create(['name' => 'جزء واحد', 'end_ayah_id' => 1])->id,
        'status' => 'passed',
        'date_time' => '2026-07-01 16:00:00',
    ]);

    $html = examBoxCard()
        ->assertSeeHtml('data-exam-box="none"')
        ->assertSee('إضافة الاختبار القادم')
        ->assertSeeHtml(cardOpensEditor())
        ->assertDontSeeHtml(route('teacher.student-exams'))
        ->html();

    expect($html)->toMatch('/<button\s[^>]*type="button"[^>]*data-exam-box="none"/s');
});

it('shows the exam as a plain box, and no "+", while the exams page is switched off for teachers', function () {
    RoleScreenPermission::where('screen_id', Screen::where('route_name', 'teacher.student-exams')->value('id'))->delete();

    cardPendingExam(ExamLevel::create(['name' => 'جزء واحد', 'end_ayah_id' => 1]), '2026-07-26 16:00:00');

    $html = examBoxCard()
        ->assertSeeInOrder(['١', 'جزء'])
        ->assertDontSeeHtml('open-exam-editor\'')
        ->html();

    expect($html)->toMatch('/<div\s[^>]*data-exam-box="upcoming"/');

    StudentExam::query()->delete();

    examBoxCard()
        ->assertDontSeeHtml('data-exam-box')
        ->assertDontSee('إضافة الاختبار القادم');
});

it('draws its box again once the exam editor saves this student\'s exam', function () {
    $card = examBoxCard()->assertSeeHtml('data-exam-box="none"');

    cardPendingExam(ExamLevel::create(['name' => 'جزء واحد', 'end_ayah_id' => 1]), '2026-07-26 16:00:00');

    $card->dispatch('exam-saved.'.$this->student->id)
        ->assertSeeHtml('data-exam-box="upcoming"')
        ->assertDontSeeHtml('data-exam-box="none"');
});
