<?php

use App\Models\Attendance;
use App\Models\Ayah;
use App\Models\Circle;
use App\Models\ExamLevel;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\StudentPlan;
use App\Models\Surah;
use App\Models\Teacher;
use App\Models\TurnReservation;
use App\Models\TurnReservationSession;
use App\Support\HijriDate;
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

function chipStudent(string $name, bool $withPlan = true): Student
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

function chipExam(Student $student, ExamLevel $level, string $dateTime, string $status = 'pending'): StudentExam
{
    return StudentExam::create([
        'student_id' => $student->id,
        'exam_level_id' => $level->id,
        'status' => $status,
        'date_time' => $dateTime,
    ]);
}

/**
 * The markup of one student's row in the list: the button that opens their
 * card, from its click handler to its end. The cards themselves render lazily
 * and carry no such handler.
 */
function chipRow(string $html, Student $student): string
{
    preg_match('/selectStudent\('.$student->id.'\)"(.*?)<\/button>/s', $html, $match);

    expect($match)->not->toBeEmpty();

    return $match[1];
}

it('shows each student\'s next exam beside their name in every section of the list', function () {
    $upcoming = chipStudent('أحمد');
    $overdue = chipStudent('بدر');
    $passedOnly = chipStudent('خالد');
    $none = chipStudent('سعد');
    $withoutPlan = chipStudent('فهد', withPlan: false);
    $twoPending = chipStudent('ماجد');

    // The overdue one is away today, so his row sits in the absent section.
    Attendance::create(['student_id' => $overdue->id, 'circle_id' => $this->circle->id, 'teacher_id' => $this->teacher->id, 'date' => '2026-07-08', 'status' => 'absent']);

    chipExam($upcoming, $this->three, '2026-07-26 16:00:00');
    chipExam($overdue, $this->two, '2026-07-05 16:00:00');
    chipExam($passedOnly, $this->three, '2026-07-01 16:00:00', 'passed');
    chipExam($withoutPlan, $this->two, '2026-07-30 16:00:00');
    chipExam($twoPending, $this->two, '2026-07-20 16:00:00');
    chipExam($twoPending, $this->one, '2026-07-12 16:00:00'); // The earliest pending one.

    $html = Livewire::test('teacher.⚡tasmeeh-manager')->html();

    expect(chipRow($html, $upcoming))
        ->toContain('data-exam-chip="upcoming"')
        ->toContain('٣ أجزاء')
        ->toContain('title="الاختبار القادم: ثلاثة أجزاء، '.HijriDate::full('2026-07-26').'"')
        ->not->toContain('bg-amber-500');

    expect(chipRow($html, $overdue))
        ->toContain('data-exam-chip="overdue"')
        ->toContain('border-red-500')
        ->toContain('٢ جزآن')
        ->toContain('aria-label="الاختبار القادم: جزء النبأ والملك، '.HijriDate::full('2026-07-05').'، مضى موعده"');

    expect(chipRow($html, $passedOnly))->not->toContain('data-exam-chip')
        ->and(chipRow($html, $none))->not->toContain('data-exam-chip');

    // The section of students without a plan, after its heading.
    expect(chipRow(Str::after($html, 'غير المجدولين'), $withoutPlan))
        ->toContain('data-exam-chip="upcoming"')
        ->toContain('٢ جزآن');

    expect(chipRow($html, $twoPending))
        ->toContain('data-exam-chip="soon"')
        ->toContain('bg-amber-500')
        ->toContain('١ جزء')
        ->not->toContain('جزآن');
});

it('keeps the chip beside the name and the turn number at the far end of the row', function () {
    $student = chipStudent('أحمد');
    chipExam($student, $this->three, '2026-07-26 16:00:00');

    $session = TurnReservationSession::create([
        'teacher_id' => $this->teacher->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-31',
        'days_of_week' => [0, 1, 2, 3, 4, 5, 6], 'start_time' => '16:00', 'end_time' => '18:00',
    ]);
    TurnReservation::create(['turn_reservation_session_id' => $session->id, 'student_id' => $student->id, 'date' => '2026-07-08', 'turn_number' => 7]);

    $html = Livewire::test('teacher.⚡tasmeeh-manager')->html();

    // In source order, which a right-to-left row lays out from the right.
    expect(chipRow($html, $student))->toMatch('/أحمد<\/span>.*data-exam-chip="upcoming".*٣ أجزاء.*>\s*7\s*<\/span>/s');
});

it('shows a graduation cap for a level without an end', function () {
    $student = chipStudent('أحمد');
    chipExam($student, ExamLevel::create(['name' => 'مستوى بلا نهاية']), '2026-07-26 16:00:00');

    expect(chipRow(Livewire::test('teacher.⚡tasmeeh-manager')->html(), $student))
        ->toContain('data-exam-chip="upcoming"')
        ->toContain('title="الاختبار القادم: مستوى بلا نهاية، '.HijriDate::full('2026-07-26').'"')
        ->not->toContain('جزء');
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

    chipExam(chipStudent('أحمد'), $this->one, '2026-07-26 16:00:00');

    expect($examQueries())->toBe([1, 1]);

    foreach (['بدر', 'خالد', 'سعد', 'فهد', 'ماجد'] as $name) {
        $student = chipStudent($name, withPlan: $name !== 'فهد');
        chipExam($student, $this->two, '2026-07-20 16:00:00');
        chipExam($student, $this->three, '2026-08-20 16:00:00');
    }

    expect($examQueries())->toBe([1, 1]);
});
