<?php

use App\Livewire\Teacher\Attendance;
use App\Livewire\Teacher\LeaderboardGrade;
use App\Livewire\Teacher\Leaderboards;
use App\Models\AcademicCalendarEvent;
use App\Models\Attendance as AttendanceModel;
use App\Models\Circle;
use App\Models\GamificationBadge;
use App\Models\GamificationStudentState;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\LeaderboardCriterion;
use App\Models\LeaderboardScore;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\GamificationService;
use App\Services\LeaderboardService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stage = Stage::create(['name' => 'مرحلة اختبار دمج المعلم']);
    $this->circle = Circle::create(['name' => 'حلقة اختبار دمج المعلم', 'stage_id' => $this->stage->id]);

    $this->teacher = Teacher::create([
        'name' => 'أحمد المعلم',
        'email' => 'teacher-integration@example.com',
        'password' => bcrypt('password'),
        'status' => 'active',
        'is_approved' => true,
    ]);
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::create([
        'name' => 'طالب دمج التلغيب',
        'email' => 'student-int@example.com',
        'password' => bcrypt('password'),
        'circle_id' => $this->circle->id,
        'is_approved' => true,
        'status' => 'active',
    ]);

    // Active Gamification Competition
    $this->leaderboard = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة الحماسة والدمج',
        'competition_type' => 'gamification',
        'start_date' => now()->subDays(5),
        'end_date' => now()->addDays(5),
        'is_active' => true,
        'settings' => [
            'hifz_enabled' => true,
            'hifz_excellent' => 10,
            'hifz_good' => 7,
            'hifz_acceptable' => 4,
            'review_enabled' => true,
            'review_excellent' => 5,
            'review_good' => 3,
            'attendance_enabled' => true,
            'attendance_present' => 4,
            'attendance_late' => 2,
            'enthusiasm_enabled' => true,
            'enthusiasm_type' => 'both',
            'enthusiasm_min_grade' => 2,
            'extra_points_enabled' => true,
        ],
    ]);
    $this->leaderboard->circles()->attach($this->circle->id);

    $this->criterion = LeaderboardCriterion::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'الانضباط والتحية',
        'points' => 5,
        'is_enthusiasm_trigger' => true,
    ]);

    $this->actingAs($this->teacher, 'teacher');
});

it('syncs attendance points on marking attendance present or late', function () {
    Livewire::test(Attendance::class)
        ->set('selectedCircle', $this->circle->id)
        ->call('markStatus', $this->student->id, 'present');

    // Assert attendance record is created
    $attendance = AttendanceModel::where('student_id', $this->student->id)
        ->whereDate('date', now()->format('Y-m-d'))
        ->first();

    expect($attendance)->not->toBeNull();
    expect($attendance->status)->toBe('present');

    // Assert gamification transaction is created
    $transaction = GamificationTransaction::where('student_id', $this->student->id)
        ->where('reference_type', AttendanceModel::class)
        ->where('reference_id', $attendance->id)
        ->first();

    expect($transaction)->not->toBeNull();
    expect($transaction->amount)->toBe(4); // attendance_present = 4

    // Assert state coins are updated
    $state = GamificationStudentState::where('student_id', $this->student->id)
        ->where('leaderboard_id', $this->leaderboard->id)
        ->first();
    expect($state)->not->toBeNull();
    expect($state->coins)->toBe(4);
});

it('removes attendance points when clearing day attendance', function () {
    // Manually mark attendance first
    $attendance = AttendanceModel::create([
        'student_id' => $this->student->id,
        'circle_id' => $this->circle->id,
        'teacher_id' => $this->teacher->id,
        'date' => now()->format('Y-m-d'),
        'status' => 'present',
    ]);
    GamificationService::syncStudentAttendanceXP($attendance);

    expect(AttendanceModel::where('student_id', $this->student->id)->count())->toBe(1);
    expect(GamificationTransaction::where('student_id', $this->student->id)->count())->toBe(1);

    // Call clearDayAttendance via Livewire component
    Livewire::test(Attendance::class)
        ->set('selectedCircle', $this->circle->id)
        ->set('date', now()->format('Y-m-d'))
        ->call('loadStudents')
        ->call('clearDayAttendance');

    // Assert database is cleared
    expect(AttendanceModel::where('student_id', $this->student->id)->count())->toBe(0);
    expect(GamificationTransaction::where('student_id', $this->student->id)->count())->toBe(0);

    $state = GamificationStudentState::where('student_id', $this->student->id)
        ->where('leaderboard_id', $this->leaderboard->id)
        ->first();
    expect($state->coins)->toBe(0);
});

it('syncs custom criteria points on toggleScore', function () {
    Livewire::test(LeaderboardGrade::class, ['leaderboardId' => $this->leaderboard->id])
        ->call('toggleScore', $this->student->id, $this->criterion->id, $this->criterion->points);

    $score = LeaderboardScore::where('student_id', $this->student->id)
        ->where('leaderboard_criterion_id', $this->criterion->id)
        ->first();

    expect($score)->not->toBeNull();

    // Assert gamification transaction
    $transaction = GamificationTransaction::where('student_id', $this->student->id)
        ->where('reference_type', LeaderboardScore::class)
        ->where('reference_id', $score->id)
        ->first();
    expect($transaction)->not->toBeNull();
    expect($transaction->amount)->toBe(5); // points = 5

    // Toggle again to remove score
    Livewire::test(LeaderboardGrade::class, ['leaderboardId' => $this->leaderboard->id])
        ->call('toggleScore', $this->student->id, $this->criterion->id, $this->criterion->points);

    expect(LeaderboardScore::where('student_id', $this->student->id)->count())->toBe(0);
    expect(GamificationTransaction::where('student_id', $this->student->id)->count())->toBe(0);
});

it('syncs extra points on saveExtraPoints', function () {
    Livewire::test(LeaderboardGrade::class, ['leaderboardId' => $this->leaderboard->id])
        ->call('saveExtraPoints', $this->student->id, 15, 'عمل متميز إضافي');

    $extraPoint = DB::table('leaderboard_extra_points')
        ->where('student_id', $this->student->id)
        ->first();

    expect($extraPoint)->not->toBeNull();
    expect((int) $extraPoint->points)->toBe(15);

    // Assert gamification transaction
    $transaction = GamificationTransaction::where('student_id', $this->student->id)
        ->where('reference_type', 'leaderboard_extra_points')
        ->where('reference_id', $extraPoint->id)
        ->first();
    expect($transaction)->not->toBeNull();
    expect($transaction->amount)->toBe(15);

    // Delete extra points
    Livewire::test(LeaderboardGrade::class, ['leaderboardId' => $this->leaderboard->id])
        ->call('deleteExtraPoints', $extraPoint->id);

    expect(DB::table('leaderboard_extra_points')->where('student_id', $this->student->id)->count())->toBe(0);
    expect(GamificationTransaction::where('student_id', $this->student->id)->count())->toBe(0);
});

it('surfaces a newly-qualifying badge for approval when the teacher opens the grade page', function () {
    // All weekdays are working days so consecutive calendar days form a streak.
    AcademicCalendarEvent::create([
        'event_name' => 'دوام كامل التجريبي',
        'start_date' => now()->subDays(30)->format('Y-m-d'),
        'end_date' => now()->addDays(30)->format('Y-m-d'),
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'is_visible' => true,
    ]);

    // Student already attended 3 consecutive days (created directly — no badge sync).
    foreach ([now()->subDays(2), now()->subDay(), now()] as $d) {
        AttendanceModel::create([
            'student_id' => $this->student->id,
            'circle_id' => $this->circle->id,
            'teacher_id' => $this->teacher->id,
            'date' => $d->format('Y-m-d'),
            'status' => 'present',
        ]);
    }

    // Badge requiring 3 consecutive attendance days, added AFTER the streak existed.
    $badge = GamificationBadge::create([
        'leaderboard_id' => $this->leaderboard->id,
        'name' => 'وسام الانضباط',
        'icon' => 'bolt',
        'badge_type' => 'streak_attendance',
        'requirement_value' => 3,
    ]);

    // No award row exists yet (the student is stuck in "reached but unawarded").
    expect(DB::table('gamification_badge_student')->where('badge_id', $badge->id)->count())->toBe(0);

    // Opening the grade page reconciles awards and lists the pending badge.
    Livewire::test(LeaderboardGrade::class, ['leaderboardId' => $this->leaderboard->id])
        ->assertSee('وسام الانضباط')
        ->assertSee('مراجعة واعتماد');

    // The pending-approval row now exists, ready for the teacher to approve.
    expect(DB::table('gamification_badge_student')
        ->where('badge_id', $badge->id)
        ->where('student_id', $this->student->id)
        ->where('status', 'pending_approval')
        ->exists())->toBeTrue();
});

it('reconciles badge awards once an hour on opening the grade page, and at once when the badges change', function () {
    $this->actingAs($this->teacher, 'teacher');

    AcademicCalendarEvent::create([
        'event_name' => 'دوام كامل التجريبي',
        'start_date' => now()->subDays(30)->format('Y-m-d'),
        'end_date' => now()->addDays(30)->format('Y-m-d'),
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'is_visible' => true,
    ]);

    foreach ([now()->subDays(2), now()->subDay(), now()] as $d) {
        AttendanceModel::create([
            'student_id' => $this->student->id, 'circle_id' => $this->circle->id, 'teacher_id' => $this->teacher->id,
            'date' => $d->format('Y-m-d'), 'status' => 'present',
        ]);
    }

    $badge = GamificationBadge::create([
        'leaderboard_id' => $this->leaderboard->id, 'name' => 'وسام الانضباط', 'icon' => 'bolt',
        'badge_type' => 'streak_attendance', 'requirement_value' => 3,
    ]);
    $pending = fn () => DB::table('gamification_badge_student')->where('badge_id', $badge->id)->where('student_id', $this->student->id);
    $open = fn () => Livewire::test(LeaderboardGrade::class, ['leaderboardId' => $this->leaderboard->id]);

    $open();
    expect($pending()->exists())->toBeTrue();

    // Opened again within the hour, the page does not reconcile again.
    $pending()->delete();
    $open();
    expect($pending()->exists())->toBeFalse();

    // A badge changed: the next opening reconciles at once.
    $this->travel(1)->seconds();
    $badge->update(['name' => 'وسام الانضباط المحدّث']);
    $open();
    expect($pending()->exists())->toBeTrue();
});

it('reckons the day\'s points only for the students asked for', function () {
    $other = Student::create([
        'name' => 'طالب آخر', 'email' => 'other-daily@example.com', 'password' => bcrypt('password'),
        'circle_id' => $this->circle->id, 'is_approved' => true, 'status' => 'active',
    ]);

    $service = new LeaderboardService;
    $today = now()->format('Y-m-d');

    expect(array_keys($service->getDailyScores($this->leaderboard, $today)))->toEqualCanonicalizing([$this->student->id, $other->id])
        ->and(array_keys($service->getDailyScores($this->leaderboard, $today, [$other->id])))->toBe([$other->id]);
});

it('keeps a teacher to the competitions of their own circles', function () {
    $this->actingAs($this->teacher, 'teacher');

    $elsewhere = Circle::create(['name' => 'حلقة معلم آخر', 'stage_id' => $this->stage->id]);
    $foreign = Leaderboard::create([
        'circle_id' => $elsewhere->id, 'title' => 'مسابقة حلقة أخرى', 'competition_type' => 'standard',
        'start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'is_active' => true, 'settings' => [],
    ]);

    // Opening the grading page of another circle's competition by its id.
    $this->get(route('teacher.leaderboards.grade', $foreign->id))->assertNotFound();

    // Pausing or deleting it from the teacher's own list.
    $list = Livewire::test(Leaderboards::class);
    expect(fn () => $list->call('deleteLeaderboard', $foreign->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $list->call('toggleActive', $foreign->id))->toThrow(ModelNotFoundException::class);

    expect($foreign->fresh())->not->toBeNull()->is_active->toBeTrue();
});

it('scores only the students the grading page lists', function () {
    $this->actingAs($this->teacher, 'teacher');

    $criterion = LeaderboardCriterion::create(['leaderboard_id' => $this->leaderboard->id, 'name' => 'التبكير', 'points' => 5]);
    $stranger = Student::factory()->create([
        'circle_id' => Circle::create(['name' => 'حلقة بعيدة', 'stage_id' => $this->stage->id])->id,
        'status' => 'active',
    ]);

    Livewire::test(LeaderboardGrade::class, ['leaderboardId' => $this->leaderboard->id])
        ->call('toggleScore', $stranger->id, $criterion->id, 5)
        ->assertNotFound();

    Livewire::test(LeaderboardGrade::class, ['leaderboardId' => $this->leaderboard->id])
        ->call('toggleScore', $this->student->id, $criterion->id, 5)
        ->assertSuccessful();

    expect(LeaderboardScore::where('student_id', $stranger->id)->exists())->toBeFalse()
        ->and(LeaderboardScore::where('student_id', $this->student->id)->exists())->toBeTrue();
});
