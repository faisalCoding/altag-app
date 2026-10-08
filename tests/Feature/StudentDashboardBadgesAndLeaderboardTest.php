<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Circle;
use App\Models\GamificationBadge;
use App\Models\Leaderboard;
use App\Models\LeaderboardCriterion;
use App\Models\LeaderboardScore;
use App\Models\Stage;
use App\Models\Student;
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

    $this->actingAs($this->student, 'student');
});

it('shows no empty badge or leaderboard cards with no active competition', function () {
    $this->get(route('student.dashboard'))
        ->assertSuccessful()
        ->assertDontSee('لا توجد مسابقة نشطة لعرض الأوسمة بعد')
        ->assertDontSee('لا توجد مسابقة نشطة حالياً')
        ->assertDontSee('لم تحصل على أوسمة بعد')
        ->assertDontSee('لوحة المتصدرين');
});

it('shows no empty badge card in a competition the student won no badge in', function () {
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

    $this->get(route('student.dashboard'))
        ->assertSuccessful()
        ->assertDontSee('لم تحصل على أوسمة بعد')
        ->assertDontSee('لا توجد مسابقة نشطة حالياً')
        ->assertDontSee('لوحة المتصدرين');
});

it('shows claimed badges and excludes pending ones', function () {
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

    $claimedBadge = GamificationBadge::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'حافظ متميز',
        'icon' => 'trophy',
        'badge_type' => 'count_hifz',
        'requirement_value' => 3,
    ]);
    $pendingBadge = GamificationBadge::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'وسام معلّق',
        'icon' => 'star',
        'badge_type' => 'count_hifz',
        'requirement_value' => 5,
    ]);

    DB::table('gamification_badge_student')->insert([
        'badge_id' => $claimedBadge->id,
        'student_id' => $this->student->id,
        'status' => 'claimed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('gamification_badge_student')->insert([
        'badge_id' => $pendingBadge->id,
        'student_id' => $this->student->id,
        'status' => 'pending_approval',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->get(route('student.dashboard'))
        ->assertSuccessful()
        ->assertSee('حافظ متميز')
        ->assertDontSee('وسام معلّق');
});

it('shows the top-3 podium widget when standings exist', function () {
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

    $criterion = LeaderboardCriterion::create([
        'leaderboard_id' => $leaderboard->id,
        'name' => 'الحفظ',
        'points' => 55,
    ]);
    LeaderboardScore::create([
        'leaderboard_id' => $leaderboard->id,
        'student_id' => $this->student->id,
        'leaderboard_criterion_id' => $criterion->id,
        'date' => now(),
    ]);

    $this->get(route('student.dashboard'))
        ->assertSuccessful()
        ->assertSee('لوحة المتصدرين')
        ->assertSee('55 نقطة')
        ->assertDontSee('55 XP');
});
