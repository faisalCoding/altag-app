<?php

use App\Models\Ayah;
use App\Models\Circle;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\Surah;
use App\Models\Teacher;
use Carbon\Carbon;
use Livewire\Livewire;

/*
 * A hifz and review plan whose second day holds review alone: it owes no
 * hifz, so the hifz it never had is not counted as not recited.
 */
it('counts only the parts a day holds', function () {
    Carbon::setTestNow('2026-07-08 10:00:00');

    Surah::create([
        'id' => 1, 'number' => 1, 'name_arabic' => 'الفاتحة', 'name_simple' => 'Al-Fatihah', 'revelation_place' => 'makkah',
        'revelation_order' => 1, 'verses_count' => 2, 'start_page' => 1, 'end_page' => 1,
    ]);
    foreach ([1, 2] as $verse) {
        Ayah::create([
            'id' => $verse, 'surah_id' => 1, 'verse_number' => $verse, 'page_number' => 1, 'line_number_start' => $verse,
            'line_number_end' => $verse, 'verse_key' => "1:{$verse}", 'juz_number' => 1, 'hizb_number' => 1, 'rub_number' => 1,
            'ruku_number' => 1, 'manzil_number' => 1, 'text_uthmani' => 'آية',
        ]);
    }

    $circle = Circle::factory()->create();
    $teacher = Teacher::factory()->create();
    $teacher->circles()->attach($circle->id);
    $student = Student::factory()->create(['circle_id' => $circle->id, 'status' => 'active']);

    $plan = StudentPlan::create([
        'student_id' => $student->id, 'teacher_id' => $teacher->id, 'start_date' => '2026-07-05', 'days_count' => 2,
        'active_days' => ['Sunday', 'Monday'], 'plan_type' => 'hifz_review', 'direction' => 'reverse',
        'review_direction' => 'reverse', 'status' => 'active', 'is_approved' => true, 'created_by_role' => 'teacher',
    ]);
    $plan->days()->create(['date' => '2026-07-05', 'day_name' => 'الأحد', 'from_ayah_id' => 1, 'to_ayah_id' => 2, 'review_from_ayah_id' => 1, 'review_to_ayah_id' => 2, 'hifz_achievement' => 3, 'review_achievement' => 3]);
    $plan->days()->create(['date' => '2026-07-06', 'day_name' => 'الاثنين', 'from_ayah_id' => null, 'to_ayah_id' => null, 'review_from_ayah_id' => 1, 'review_to_ayah_id' => 2, 'review_achievement' => 3]);

    $this->actingAs($teacher, 'teacher');

    $stats = Livewire::test('teacher.quranic-discipline')->viewData('studentsStats')->firstWhere('student.id', $student->id);

    expect($stats['excellent'])->toBe(3)
        ->and($stats['unrecited'])->toBe(0);
});
