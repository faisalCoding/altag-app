<?php

use App\Models\AcademicCalendarEvent;
use App\Models\AppNotification;
use App\Models\Circle;
use App\Models\ExamLevel;
use App\Models\Screen;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\Teacher;

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

    $this->actingAs($this->student, 'student');
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

it('renders the reports page with real aggregate stats', function () {
    $this->get(route('student.reports'))
        ->assertSuccessful()
        ->assertSee('التقارير')
        ->assertSee('محفوظك من المصحف')
        ->assertSee('data-mushaf-map', false);
});

it('links the pages a student keeps from the sidebar, and none of the removed ones', function () {
    $response = $this->get(route('student.dashboard'));

    $response->assertSuccessful();

    foreach (['student.plan', 'student.exams', 'student.attendance', 'student.reports'] as $route) {
        $response->assertSee(route($route), false);
    }

    foreach (['/student/hifz', '/student/review', '/student/calendar', '/student/schedule'] as $path) {
        $response->assertDontSee(url($path), false);
    }
});

it('no longer serves the hifz, review, calendar and programme pages', function (string $path) {
    $this->get($path)->assertNotFound();
})->with(['/student/hifz', '/student/review', '/student/calendar', '/student/schedule']);

it('drops the removed pages from the screens a role can be granted', function () {
    expect(Screen::whereIn('route_name', ['student.hifz', 'student.review', 'student.calendar', 'student.schedule'])->exists())->toBeFalse()
        ->and(Screen::whereIn('route_name', ['student.plan', 'student.attendance', 'student.reports'])->count())->toBe(3);
});

it('sends the grading notices already out to the home page', function () {
    $notice = AppNotification::create([
        'recipient_type' => 'student', 'recipient_id' => $this->student->id, 'type' => 'grading',
        'title' => 'تقييم جديد', 'body' => 'قام معلمك بتقييم الحفظ الخاص بك', 'url' => 'https://altag.site/student/hifz',
    ]);

    (require database_path('migrations/2026_10_08_045114_remove_retired_student_screens.php'))->up();

    expect($notice->fresh()->url)->toBe('https://altag.site/student/dashboard');
});

it('gives a student the phone bottom bar, marking the page they are on', function () {
    $html = $this->get(route('student.attendance'))->assertSuccessful()->getContent();
    $bar = (string) str($html)->after('aria-label="التنقل السريع"')->before('</nav>');

    foreach (['الرئيسية', 'خطتي', 'الانضباط', 'التقارير', 'المزيد'] as $label) {
        expect($bar)->toContain($label);
    }

    expect($bar)->not->toContain('الحفظ')
        ->and($bar)->not->toContain('المراجعة')
        ->and($bar)->toContain('aria-current="page"')
        ->and(substr_count($bar, 'aria-current="page"'))->toBe(1);
});

it('declares the pages Arabic, so browsers neither offer to translate nor read them in English', function () {
    $this->get(route('student.attendance'))
        ->assertSuccessful()
        ->assertSee('<html lang="ar" dir="rtl"', false);
});
