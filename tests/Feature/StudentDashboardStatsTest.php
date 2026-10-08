<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Circle;
use App\Models\GamificationLevel;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
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
        'name_arabic' => 'سورة الاختبار',
        'name_simple' => 'Test Surah',
        'revelation_place' => 'makkah',
        'revelation_order' => 1,
        'verses_count' => 1,
        'start_page' => 1,
        'end_page' => 1,
    ]);
    DB::table('ayahs')->insert([
        'id' => 1,
        'surah_id' => $surah->id,
        'verse_number' => 1,
        'verse_key' => '1:1',
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

    $this->actingAs($this->student, 'student');
});

it('shows honest empty states for a brand new student with no leaderboard', function () {
    $this->get(route('student.dashboard'))
        ->assertSuccessful()
        ->assertSee('ابدأ رحلتك في الحفظ')
        ->assertSee('لم تكتمل سورة بعد')
        ->assertSee('ابدأ اليوم!')
        ->assertDontSee('لا توجد مسابقة نشطة حالياً لعرض مستواك ونقاطك');
});

it('shows no level card for a competition that is not gamification', function () {
    $leaderboard = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة تجريبية',
        'competition_type' => 'points',
        'start_date' => now()->subDays(2),
        'end_date' => now()->addDays(2),
        'is_active' => true,
        'settings' => [],
    ]);
    $leaderboard->circles()->attach($this->circle->id);

    GamificationLevel::create([
        'leaderboard_id' => $leaderboard->id,
        'level_number' => 1,
        'name' => 'مبتدئ',
        'xp_required' => 0,
        'icon' => 'sparkles',
    ]);
    GamificationLevel::create([
        'leaderboard_id' => $leaderboard->id,
        'level_number' => 2,
        'name' => 'حافظ',
        'xp_required' => 100,
        'icon' => 'star',
    ]);

    GamificationTransaction::create([
        'student_id' => $this->student->id,
        'leaderboard_id' => $leaderboard->id,
        'type' => 'earn',
        'amount' => 40,
        'xp_amount' => 40,
        'description' => 'نقاط تجريبية',
    ]);

    $this->get(route('student.dashboard'))
        ->assertSuccessful()
        ->assertDontSee('مبتدئ')
        ->assertDontSee('للمستوى القادم')
        ->assertDontSee('لا توجد مسابقة نشطة حالياً لعرض مستواك ونقاطك');
});

it('shows real memorized ayah and completed surah counts', function () {
    $plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'plan_type' => 'hifz',
        'direction' => 'forward',
        'start_date' => now()->subDays(5),
        'is_approved' => true,
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => today(),
        'day_name' => 'الثلاثاء',
        'hifz_achievement' => 3,
        'from_ayah_id' => 1,
        'to_ayah_id' => 1,
    ]);

    $this->get(route('student.dashboard'))
        ->assertSuccessful()
        ->assertDontSee('ابدأ رحلتك في الحفظ')
        ->assertDontSee('لم تكتمل سورة بعد');
});
