<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\ExamLevel;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Surah;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-07-08 10:00:00');

    $this->stage = Stage::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $this->teacher = Teacher::factory()->create();
    $this->student = Student::factory()->create(['circle_id' => $this->circle->id]);

    AcademicCalendarEvent::create([
        'event_name' => 'دوام كامل',
        'start_date' => now()->subDays(30)->format('Y-m-d'),
        'end_date' => now()->addDays(30)->format('Y-m-d'),
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'is_visible' => true,
    ]);

    $surah = Surah::create([
        'number' => 1, 'name_arabic' => 'سورة الاختبار', 'name_simple' => 'Test Surah',
        'revelation_place' => 'makkah', 'revelation_order' => 1, 'verses_count' => 6,
        'start_page' => 1, 'end_page' => 1,
    ]);
    foreach (range(1, 6) as $id) {
        DB::table('ayahs')->insert([
            'id' => $id, 'surah_id' => $surah->id, 'verse_number' => $id, 'verse_key' => "1:{$id}",
            'juz_number' => 1, 'hizb_number' => 1, 'rub_number' => 1, 'page_number' => 1,
            'ruku_number' => 1, 'manzil_number' => 1, 'text_uthmani' => 'نص',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $this->actingAs($this->student, 'student');
});

it('renders the hifz page with a graded plan day', function () {
    $plan = StudentPlan::create([
        'student_id' => $this->student->id, 'plan_type' => 'hifz', 'direction' => 'forward',
        'start_date' => now()->subDays(2), 'is_approved' => true, 'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);
    StudentPlanDay::create([
        'student_plan_id' => $plan->id, 'date' => today(), 'day_name' => 'الأربعاء',
        'hifz_achievement' => 3, 'from_ayah_id' => 1, 'to_ayah_id' => 3,
    ]);

    $this->get(route('student.hifz'))
        ->assertSuccessful()
        ->assertSee('الحفظ')
        ->assertSee('ممتاز');
});

it('renders the review page with a graded review day', function () {
    $plan = StudentPlan::create([
        'student_id' => $this->student->id, 'plan_type' => 'hifz', 'direction' => 'forward',
        'start_date' => now()->subDays(2), 'is_approved' => true, 'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);
    StudentPlanDay::create([
        'student_plan_id' => $plan->id, 'date' => today(), 'day_name' => 'الأربعاء',
        'review_achievement' => 2, 'review_from_ayah_id' => 1, 'review_to_ayah_id' => 3,
    ]);

    $this->get(route('student.review'))
        ->assertSuccessful()
        ->assertSee('المراجعة')
        ->assertSee('جيد');
});

it('renders the exams page with the student own exam', function () {
    $level = ExamLevel::create(['name' => 'المستوى الأول', 'direction' => 'nas_to_baqarah']);
    StudentExam::create([
        'student_id' => $this->student->id, 'exam_level_id' => $level->id,
        'status' => 'pending', 'date_time' => now()->addDays(3), 'location' => 'قاعة 1',
    ]);

    $this->get(route('student.exams'))
        ->assertSuccessful()
        ->assertSee('الاختبارات')
        ->assertSee('المستوى الأول')
        ->assertSee('قادم');
});

it('renders the calendar page and shows details for the selected day', function () {
    Attendance::create([
        'student_id' => $this->student->id, 'teacher_id' => $this->teacher->id,
        'circle_id' => $this->circle->id, 'date' => today(), 'status' => 'present',
    ]);

    $this->get(route('student.calendar'))
        ->assertSuccessful()
        ->assertSee('التقويم')
        ->assertSee('حاضر');
});

it('renders the reports page with real aggregate stats', function () {
    $this->get(route('student.reports'))
        ->assertSuccessful()
        ->assertSee('التقارير')
        ->assertSee('آيات محفوظة');
});

it('links all five pages from the student sidebar instead of showing them as coming soon', function () {
    $response = $this->get(route('student.dashboard'));

    $response->assertSuccessful();
    $response->assertSee(route('student.hifz'), false);
    $response->assertSee(route('student.review'), false);
    $response->assertSee(route('student.exams'), false);
    $response->assertSee(route('student.calendar'), false);
    $response->assertSee(route('student.reports'), false);
});

it('gives a student the phone bottom bar, marking the page they are on', function () {
    $this->actingAs($this->student, 'student');

    $html = $this->get(route('student.hifz'))->assertSuccessful()->getContent();
    $bar = (string) str($html)->after('aria-label="التنقل السريع"')->before('</nav>');

    foreach (['الرئيسية', 'خطتي', 'الحفظ', 'المراجعة', 'المزيد'] as $label) {
        expect($bar)->toContain($label);
    }

    expect($bar)->toContain('aria-current="page"')
        ->and(substr_count($bar, 'aria-current="page"'))->toBe(1);
});

it('declares the pages Arabic, so browsers neither offer to translate nor read them in English', function () {
    $this->actingAs($this->student, 'student');

    $this->get(route('student.hifz'))
        ->assertSuccessful()
        ->assertSee('<html lang="ar" dir="rtl"', false);
});
