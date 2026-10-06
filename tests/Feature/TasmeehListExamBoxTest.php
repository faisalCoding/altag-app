<?php

use App\Models\Attendance;
use App\Models\Ayah;
use App\Models\Circle;
use App\Models\CircleTurn;
use App\Models\ExamLevel;
use App\Models\RoleScreenPermission;
use App\Models\Screen;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\StudentPlan;
use App\Models\Surah;
use App\Models\Teacher;
use App\Support\HijriDate;
use App\Support\NextExamBadge;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    $this->one = ExamLevel::create(['name' => 'جزء عم', 'end_ayah_id' => 1]);
    $this->two = ExamLevel::create(['name' => 'جزء النبأ والملك', 'end_ayah_id' => 2]);
    $this->three = ExamLevel::create(['name' => 'ثلاثة أجزاء', 'end_ayah_id' => 3]);

    $this->actingAs($this->teacher, 'teacher');
});

function boxStudent(string $name, bool $withPlan = true): Student
{
    $student = Student::factory()->create(['circle_id' => test()->circle->id, 'name' => $name, 'status' => 'active']);

    if ($withPlan) {
        StudentPlan::create([
            'student_id' => $student->id,
            'teacher_id' => test()->teacher->id,
            'start_date' => '2026-07-01',
            'days_count' => 5,
            'active_days' => [0, 1, 2, 3, 4, 5, 6],
            'status' => 'active',
            'plan_type' => 'hifz',
            'direction' => 'forward',
            'is_approved' => true,
            'created_by_role' => 'teacher',
        ]);
    }

    return $student;
}

function boxExam(Student $student, ExamLevel $level, string $dateTime, string $status = 'pending'): StudentExam
{
    return StudentExam::create([
        'student_id' => $student->id,
        'exam_level_id' => $level->id,
        'status' => $status,
        'date_time' => $dateTime,
    ]);
}

/**
 * A pattern for what may stand between two elements: whitespace, and the
 * markers Livewire leaves around each @if.
 */
function betweenTags(): string
{
    return '(?:\s|<!--\[if (?:END)?BLOCK\]><!\[endif\]-->)*';
}

/**
 * One student's row in the list, in its two parts: what sits inside the
 * button that opens their card, and the exam box right after that button
 * closes — empty when the row has none. The cards themselves render lazily
 * and carry no such handler.
 *
 * @return array{0: string, 1: string}
 */
function listRow(string $html, Student $student): array
{
    preg_match('/selectStudent\('.$student->id.'\)"(.*?)<\/button>'.betweenTags().'((<(button|div)\s[^>]*data-exam-box="[a-z]+".*?<\/\4>)?)/s', $html, $match);

    expect($match)->not->toBeEmpty();

    return [$match[1], $match[2]];
}

function openEditorCall(Student $student): string
{
    return 'wire:click="$dispatch(\'open-exam-editor\', { studentId: '.$student->id.' })"';
}

it('draws a box at the end of every name in every section: the next exam\'s number, or a "+" to set one', function () {
    $upcoming = boxStudent('أحمد');
    $overdue = boxStudent('بدر');
    $passedOnly = boxStudent('خالد');
    $none = boxStudent('سعد');
    $withoutPlan = boxStudent('فهد', withPlan: false);
    $twoPending = boxStudent('ماجد');

    // The overdue one is away today, so his row sits in the absent section.
    Attendance::create(['student_id' => $overdue->id, 'circle_id' => $this->circle->id, 'teacher_id' => $this->teacher->id, 'date' => '2026-07-08', 'status' => 'absent']);

    boxExam($upcoming, $this->three, '2026-07-26 16:00:00');
    boxExam($overdue, $this->two, '2026-07-05 16:00:00');
    boxExam($passedOnly, $this->three, '2026-07-01 16:00:00', 'passed');
    boxExam($withoutPlan, $this->two, '2026-07-30 16:00:00');
    boxExam($twoPending, $this->two, '2026-07-20 16:00:00');
    boxExam($twoPending, $this->one, '2026-07-12 16:00:00'); // The earliest pending one.

    $html = Livewire::test('teacher.⚡tasmeeh-manager')->html();

    // The box is never inside the button that opens the card.
    foreach ([$upcoming, $overdue, $passedOnly, $none, $twoPending] as $student) {
        expect(listRow($html, $student)[0])->not->toContain('data-exam-box');
    }

    expect(listRow($html, $upcoming)[1])
        ->toStartWith('<button')
        ->toContain('data-exam-box="upcoming"')
        ->toContain(openEditorCall($upcoming))
        ->toContain('>٣</span>')
        ->toContain('أجزاء')
        ->toContain('aria-label="الاختبار القادم: ثلاثة أجزاء، '.HijriDate::full('2026-07-26').' — '.$upcoming->name.'"')
        ->not->toContain('bg-amber-500');

    expect(listRow($html, $overdue)[1])
        ->toContain('data-exam-box="overdue"')
        ->toContain(openEditorCall($overdue))
        ->toContain('border-red-500')
        ->toContain('>٢</span>')
        ->toContain('جزآن')
        ->toContain('aria-label="الاختبار القادم: جزء النبأ والملك، '.HijriDate::full('2026-07-05').'، مضى موعده — '.$overdue->name.'"');

    foreach ([$passedOnly, $none] as $student) {
        expect(listRow($html, $student)[1])
            ->toStartWith('<button')
            ->toContain('data-exam-box="none"')
            ->toContain('aria-label="إضافة الاختبار القادم — '.$student->name.'"')
            ->toContain('border-dashed')
            ->toContain(openEditorCall($student));
    }

    // The section of students without a plan, after its heading: the box
    // sits between the name and the link that creates a plan.
    $noPlanSection = Str::after($html, 'غير المجدولين');
    [$button, $box] = listRow($noPlanSection, $withoutPlan);

    expect($button)->not->toContain('data-exam-box')
        ->and($box)->toContain('data-exam-box="upcoming"')->toContain('>٢</span>')->toContain(openEditorCall($withoutPlan))
        ->and(Str::after($noPlanSection, $box))->toMatch('/^'.betweenTags().'<a href="[^"]*plan-creator[^"]*studentId='.$withoutPlan->id.'/');

    expect(listRow($html, $twoPending)[1])
        ->toContain('data-exam-box="soon"')
        ->toContain('bg-amber-500')
        ->toContain('>١</span>')
        ->toContain('جزء')
        ->not->toContain('جزآن');
});

it('keeps the turn number inside the name\'s button and the box after it, at the row\'s end', function () {
    $student = boxStudent('أحمد');
    boxExam($student, $this->three, '2026-07-26 16:00:00');

    openTurnBooking($this->circle);
    CircleTurn::create(['circle_id' => $this->circle->id, 'student_id' => $student->id, 'date' => '2026-07-08', 'turn_number' => 7]);

    [$button, $box] = listRow(Livewire::test('teacher.⚡tasmeeh-manager')->html(), $student);

    // In source order, which a right-to-left row lays out from the right.
    expect($button)->toMatch('/أحمد<\/span>.*>\s*7\s*<\/span>'.betweenTags().'$/s')
        ->and($box)->toContain('data-exam-box="upcoming"')->toContain('>٣</span>');
});

it('shows a graduation cap for a level without an end', function () {
    $student = boxStudent('أحمد');
    boxExam($student, ExamLevel::create(['name' => 'مستوى بلا نهاية']), '2026-07-26 16:00:00');

    expect(listRow(Livewire::test('teacher.⚡tasmeeh-manager')->html(), $student)[1])
        ->toContain('data-exam-box="upcoming"')
        ->toContain('title="الاختبار القادم: مستوى بلا نهاية، '.HijriDate::full('2026-07-26').' — '.$student->name.'"')
        ->toContain('data-flux-icon')
        // No juz count is drawn, only the cap.
        ->not->toMatch('/>\s*\p{Nd}+\s*<\/span>/u')
        ->not->toContain('جزء');
});

it('draws only the numbers, as plain boxes, while the exams page is switched off for teachers', function () {
    RoleScreenPermission::where('screen_id', Screen::where('route_name', 'teacher.student-exams')->value('id'))->delete();

    $withExam = boxStudent('أحمد');
    $without = boxStudent('بدر');
    boxExam($withExam, $this->three, '2026-07-26 16:00:00');

    $html = Livewire::test('teacher.⚡tasmeeh-manager')->html();

    expect(listRow($html, $withExam)[1])
        ->toStartWith('<div')
        ->toContain('data-exam-box="upcoming"')
        ->toContain('title="الاختبار القادم: ثلاثة أجزاء، '.HijriDate::full('2026-07-26').' — '.$withExam->name.'"')
        ->and(listRow($html, $without)[1])->toBe('')
        ->and($html)->not->toContain('data-exam-box="none"')
        ->and($html)->not->toContain('$dispatch(\'open-exam-editor\'');
});

it('tells two pending exams on the same day apart by id, whatever their hour, as the teacher app does', function () {
    $student = boxStudent('أحمد');
    $first = boxExam($student, $this->three, '2026-07-20 16:00:00');
    boxExam($student, $this->one, '2026-07-20 09:00:00'); // Set later, for an earlier hour.

    expect(NextExamBadge::nextExams([$student->id])->get($student->id)->id)->toBe($first->id)
        ->and(listRow(Livewire::test('teacher.⚡tasmeeh-manager')->html(), $student)[1])
        ->toContain('>٣</span>')
        ->not->toContain('>١</span>');
});

it('redraws the boxes once the exam editor saves', function () {
    $student = boxStudent('أحمد');

    $manager = Livewire::test('teacher.⚡tasmeeh-manager');

    expect(listRow($manager->html(), $student)[1])->toContain('data-exam-box="none"');

    boxExam($student, $this->two, '2026-07-26 16:00:00');

    expect(listRow($manager->dispatch('exam-saved', studentId: $student->id)->html(), $student)[1])
        ->toContain('data-exam-box="upcoming"')
        ->toContain('>٢</span>');
});

it('mounts the one exam editor on the page', function () {
    boxStudent('أحمد');

    Livewire::test('teacher.⚡tasmeeh-manager')->assertSeeLivewire('teacher.tasmeeh-exam-editor');
});

it('reads the next exams of the whole list in one query, however many students it holds', function () {
    $examQueries = function (): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test('teacher.⚡tasmeeh-manager');
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        return [
            $queries->filter(fn (string $query) => str_contains($query, 'from "student_exams"'))->count(),
            $queries->filter(fn (string $query) => str_contains($query, 'from "exam_levels"'))->count(),
        ];
    };

    boxExam(boxStudent('أحمد'), $this->one, '2026-07-26 16:00:00');

    // The editor mounted on the page reads nothing until a box opens it.
    expect($examQueries())->toBe([1, 1]);

    foreach (['بدر', 'خالد', 'سعد', 'فهد', 'ماجد'] as $name) {
        $student = boxStudent($name, withPlan: $name !== 'فهد');
        boxExam($student, $this->two, '2026-07-20 16:00:00');
        boxExam($student, $this->three, '2026-08-20 16:00:00');
    }

    expect($examQueries())->toBe([1, 1]);
});

it('holds the box\'s place on rows without one, so the list stays in line while the exams page is off', function () {
    RoleScreenPermission::where('screen_id', Screen::where('route_name', 'teacher.student-exams')->value('id'))->delete();

    $withExam = boxStudent('أحمد');
    $without = boxStudent('بدر');
    boxExam($withExam, $this->three, '2026-07-26 16:00:00');

    $html = Livewire::test('teacher.⚡tasmeeh-manager')->html();

    expect($html)->toMatch('/selectStudent\('.$without->id.'\)".*?<\/button>'.betweenTags().'<span class="shrink-0 size-10" aria-hidden="true"><\/span>/s');

    // With no box drawn anywhere, no room is kept for one.
    StudentExam::query()->delete();

    expect(Livewire::test('teacher.⚡tasmeeh-manager')->html())->not->toContain('<span class="shrink-0 size-10" aria-hidden="true">');
});

it('redraws the boxes after a save without swapping the rows, so focus can return to the box', function () {
    $student = boxStudent('أحمد');

    Livewire::test('teacher.⚡tasmeeh-manager')
        ->assertSet('refreshToggle', false)
        ->dispatch('exam-saved', studentId: $student->id)
        ->assertSet('refreshToggle', false);
});

it('names the student in each box\'s label', function () {
    $student = boxStudent('أحمد');

    expect(listRow(Livewire::test('teacher.⚡tasmeeh-manager')->html(), $student)[1])
        ->toContain('aria-label="إضافة الاختبار القادم — أحمد"');
});
