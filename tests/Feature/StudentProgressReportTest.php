<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Circle;
use App\Models\ExamLevel;
use App\Models\FreeRecitation;
use App\Models\PlanDayAttempt;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Surah;
use App\Services\StudentProgressReport;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Wednesday 8 July 2026, in Muharram 1448; the week began on Saturday 4 July.
 * A small mushaf of 60 one-line verses, fifteen to a page: juz 1 is pages 1–2
 * (verses 1–30), juz 2 pages 3–4 (verses 31–60).
 */
beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-07-08 10:00:00');

    $this->student = Student::factory()->create(['circle_id' => Circle::factory()->create(['stage_id' => Stage::factory()->create()->id])->id]);

    AcademicCalendarEvent::create([
        'event_name' => 'الفصل الأول', 'start_date' => '2026-06-01', 'end_date' => '2026-08-30',
        'is_attendance_period' => true, 'weekdays' => [1, 2, 3, 4, 5], 'is_visible' => true,
    ]);

    $surah = Surah::create([
        'number' => 1, 'name_arabic' => 'سورة الاختبار', 'name_simple' => 'Test', 'revelation_place' => 'makkah',
        'revelation_order' => 1, 'verses_count' => 60, 'start_page' => 1, 'end_page' => 4,
    ]);
    foreach (range(1, 60) as $id) {
        DB::table('ayahs')->insert([
            'id' => $id, 'surah_id' => $surah->id, 'verse_number' => $id, 'verse_key' => "1:{$id}",
            'juz_number' => $id <= 30 ? 1 : 2, 'hizb_number' => 1, 'rub_number' => 1,
            'page_number' => intdiv($id - 1, 15) + 1,
            'line_number_start' => ($id - 1) % 15 + 1, 'line_number_end' => ($id - 1) % 15 + 1,
            'ruku_number' => 1, 'manzil_number' => 1, 'text_uthmani' => 'نص',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $this->plan = StudentPlan::create([
        'student_id' => $this->student->id, 'plan_type' => 'hifz_review', 'direction' => 'forward',
        'start_date' => '2026-06-28', 'is_approved' => true, 'status' => 'active', 'days_count' => 3,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);
});

function reportDay(StudentPlan $plan, string $date, int $from, int $to, ?int $grade): StudentPlanDay
{
    return StudentPlanDay::create([
        'student_plan_id' => $plan->id, 'date' => $date, 'day_name' => 'يوم',
        'from_ayah_id' => $from, 'to_ayah_id' => $to, 'review_from_ayah_id' => 1, 'review_to_ayah_id' => 15,
        'hifz_achievement' => $grade,
    ]);
}

function reportSession(StudentPlanDay $day, string $part, string $on, int $grade, ?int $from = null, ?int $to = null): void
{
    PlanDayAttempt::create([
        'student_plan_day_id' => $day->id, 'part' => $part, 'recited_on' => $on, 'grade' => $grade,
        'recited_from_ayah_id' => $from, 'recited_to_ayah_id' => $to, 'graded_at' => $on.' 09:00:00',
    ]);
}

/** A page recited whole, a page recited short, one not heard, a review and a free page. */
function reciteForReport(StudentPlan $plan, Student $student): void
{
    $first = reportDay($plan, '2026-06-28', 1, 15, 3);
    $second = reportDay($plan, '2026-07-01', 16, 30, 2);
    $third = reportDay($plan, '2026-07-05', 31, 45, 0);

    reportSession($first, 'hifz', '2026-06-28', 3);
    reportSession($second, 'hifz', '2026-07-01', 2, 16, 25);   // Ten lines of fifteen.
    reportSession($third, 'hifz', '2026-07-05', 0);
    reportSession($first, 'review', '2026-07-06', 3);

    FreeRecitation::create([
        'student_id' => $student->id, 'type' => 'hifz', 'recited_on' => '2026-07-06',
        'from_ayah_id' => 31, 'to_ayah_id' => 45, 'achievement' => 3, 'graded_at' => '2026-07-06 09:00:00',
    ]);
}

it('maps how much of each juz is held and the one being worked on', function () {
    reciteForReport($this->plan, $this->student);

    $mushaf = StudentProgressReport::mushaf($this->student);

    expect($mushaf['pages'])->toBe(2)
        ->and($mushaf['juzs'])->toHaveCount(30)
        ->and($mushaf['juzs'][0])->toBe(['juz' => 1, 'fraction' => 1.0])
        ->and($mushaf['juzs'][1])->toBe(['juz' => 2, 'fraction' => 0.0])
        ->and($mushaf['current'])->toBe(['juz' => 2, 'left' => 2.0]);
});

it('measures the pages memorised each week by the lines recited, and forecasts the juz', function () {
    reciteForReport($this->plan, $this->student);

    $pace = StudentProgressReport::pace($this->student);
    $weeks = collect($pace['weeks'])->keyBy('start');

    expect($pace['weeks'])->toHaveCount(12)
        ->and($weeks['2026-06-27']['pages'])->toBe(1.7)   // Fifteen lines and ten.
        ->and($weeks['2026-07-04']['pages'])->toBe(1.0)   // The free page; the unheard one counts nothing.
        // Averaged from the first week with memorising, this unfinished one left out.
        ->and($pace['average'])->toBe(1.7)
        ->and(StudentProgressReport::forecast(StudentProgressReport::mushaf($this->student), $pace))->toBe(['juz' => 2, 'weeks' => 2]);
});

it('counts a line once, the first time it is recited', function () {
    reciteForReport($this->plan, $this->student);

    // Before the twelve weeks: the first page was already reached in April.
    FreeRecitation::create([
        'student_id' => $this->student->id, 'type' => 'hifz', 'recited_on' => '2026-04-05',
        'from_ayah_id' => 1, 'to_ayah_id' => 15, 'achievement' => 3, 'graded_at' => '2026-04-05 09:00:00',
    ]);
    // This week: the short portion recited whole — five new lines, 26 to 30 —
    // and the free page again, which adds nothing.
    $second = StudentPlanDay::where('student_plan_id', $this->plan->id)->whereDate('date', '2026-07-01')->first();
    reportSession($second, 'hifz', '2026-07-07', 3, 16, 30);
    FreeRecitation::create([
        'student_id' => $this->student->id, 'type' => 'hifz', 'recited_on' => '2026-07-07',
        'from_ayah_id' => 31, 'to_ayah_id' => 45, 'achievement' => 3, 'graded_at' => '2026-07-07 09:00:00',
    ]);

    $weeks = collect(StudentProgressReport::pace($this->student)['weeks'])->keyBy('start');

    expect($weeks->has('2026-04-04'))->toBeFalse()
        ->and($weeks['2026-06-27']['pages'])->toBe(0.7)                 // Ten new lines; the first page was old.
        ->and($weeks['2026-07-04']['pages'])->toBe(1.3)                 // Fifteen new lines and five.
        ->and(StudentProgressReport::comparison($this->student)['pages']['now'])->toBe(2.0);
});

it('reads the grades and the keeping to the plan over the chosen period', function () {
    reciteForReport($this->plan, $this->student);
    $month = StudentProgressReport::period($this->student, 'month');

    $quality = StudentProgressReport::quality($this->student, $month['from'], $month['to']);
    $adherence = StudentProgressReport::adherence($this->student, $month['from'], $month['to']);

    expect($month)->toBe(['from' => '2026-06-16', 'to' => '2026-07-08', 'label' => 'محرم ١٤٤٨'])
        ->and(StudentProgressReport::period($this->student, 'term')['from'])->toBe('2026-06-01')
        ->and($quality['hifz'])->toBe([3 => 2, 2 => 1, 1 => 0, 0 => 1])
        ->and($quality['review'])->toBe([3 => 1, 2 => 0, 1 => 0, 0 => 0])
        ->and(collect($quality['recent'])->pluck('grade')->all())->toBe([3, 2, 0, 3, 3])
        // The free page is no plan session: one whole, one short, one not heard.
        ->and($adherence)->toBe(['whole' => 1, 'short' => 1, 'not_heard' => 1, 'total' => 3, 'rate' => 33]);
});

it('sets this month so far against the same days of the last', function () {
    reciteForReport($this->plan, $this->student);

    expect(StudentProgressReport::comparison($this->student))->toBe([
        'days' => 23,
        'pages' => ['now' => 2.7, 'before' => 0.0],
        'excellent' => ['now' => 60, 'before' => null],
        'attended' => ['now' => 0, 'before' => 0],
    ]);
});

it('lays the exams out in order, the next one last', function () {
    $level = ExamLevel::create(['name' => 'المستوى الأول', 'direction' => 'nas_to_baqarah']);
    StudentExam::create(['student_id' => $this->student->id, 'exam_level_id' => $level->id, 'status' => 'pending', 'date_time' => '2026-07-20 08:00:00']);
    StudentExam::create(['student_id' => $this->student->id, 'exam_level_id' => $level->id, 'status' => 'passed', 'date_time' => '2026-06-01 08:00:00', 'score_percentage' => 92.4]);

    expect(collect(StudentProgressReport::exams($this->student))->map(fn ($exam) => [$exam['status'], $exam['score']])->all())
        ->toBe([['passed', 92], ['pending', null]]);
});

it('draws the report on the student page', function () {
    reciteForReport($this->plan, $this->student);
    $this->actingAs($this->student, 'student');

    $this->get(route('student.reports'))
        ->assertSuccessful()
        ->assertSee('data-juz="1" data-fraction="1"', false)
        ->assertSee('معدلك ١٫٧ وجه في الأسبوع.')
        ->assertSee('بهذا المعدل تُتم الجزء ٢ بعد نحو أسبوعين.')
        ->assertSee('data-adherence="33"', false)
        ->assertSee('أتممت الورد كاملاً في ١ من ٣ جلسات حفظ.');
});

it('says when the period holds no recitation', function () {
    $this->actingAs($this->student, 'student');

    Livewire::test('student.reports')
        ->assertSee('لا تسميع في محرم ١٤٤٨')
        ->assertSee('لا جلسات حفظ من خطتك في محرم ١٤٤٨.')
        ->set('scope', 'term')
        ->assertSee('لا جلسات حفظ من خطتك في هذا الفصل.')
        ->assertDontSee('مسار اختباراتك');
});
