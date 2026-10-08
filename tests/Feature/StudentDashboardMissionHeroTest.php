<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Circle;
use App\Models\ExamLevel;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Surah;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-07-07 10:00:00');

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
        'number' => 1,
        'name_arabic' => 'الفاتحة',
        'name_simple' => 'Al-Fatihah',
        'revelation_place' => 'makkah',
        'revelation_order' => 1,
        'verses_count' => 7,
        'start_page' => 1,
        'end_page' => 1,
    ]);
    foreach (range(1, 7) as $id) {
        DB::table('ayahs')->insert([
            'id' => $id,
            'surah_id' => $surah->id,
            'verse_number' => $id,
            'verse_key' => "1:{$id}",
            'juz_number' => 1,
            'hizb_number' => 1,
            'rub_number' => 1,
            'page_number' => 1,
            'ruku_number' => 1,
            'manzil_number' => 1,
            'text_uthmani' => 'نص',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->actingAs($this->student, 'student');
});

it('shows a calm empty state when there are no pending missions', function () {
    $this->get(route('student.dashboard'))
        ->assertSuccessful()
        ->assertSee('لا واجب عليك الآن.')
        ->assertSee('لا توجد تنبيهات حالياً');
});

it('shows the pending hifz mission in the card at the top', function () {
    $plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'plan_type' => 'hifz',
        'direction' => 'forward',
        'status' => 'active',
        'is_approved' => true,
        'start_date' => now()->subDays(2),
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => today(),
        'day_name' => 'الثلاثاء',
        'from_ayah_id' => 1,
        'to_ayah_id' => 7,
    ]);

    // In the card at the top of the page, which took the hero card's place.
    $this->get(route('student.dashboard'))
        ->assertSuccessful()
        ->assertSee('واجبك القادم')
        ->assertSee('الفاتحة')
        ->assertDontSee('لا واجب عليك الآن.');
});

it('shows next exam and today session in the notifications card', function () {
    $examLevel = ExamLevel::create(['name' => 'المستوى الأول']);
    StudentExam::create([
        'student_id' => $this->student->id,
        'exam_level_id' => $examLevel->id,
        'status' => 'pending',
        'date_time' => now()->addDays(3),
    ]);

    $this->get(route('student.dashboard'))
        ->assertSuccessful()
        ->assertSee('اختبار قادم')
        ->assertDontSee('لا توجد تنبيهات حالياً');
});
