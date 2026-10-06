<?php

use App\Livewire\Teacher\LeaderboardGrade;
use App\Livewire\Teacher\Leaderboards;
use App\Models\Circle;
use App\Models\CircleTurn;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-06-10 10:00:00');

    $this->stage = Stage::factory()->create();
    $this->circleA = Circle::factory()->create(['stage_id' => $this->stage->id, 'name' => 'حلقة أ']);
    $this->circleB = Circle::factory()->create(['stage_id' => $this->stage->id, 'name' => 'حلقة ب']);

    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach([$this->circleA->id, $this->circleB->id]);

    $this->studentA = Student::factory()->create([
        'name' => 'طالب الحلقة الأولى',
        'circle_id' => $this->circleA->id,
        'status' => 'active',
        'is_approved' => true,
    ]);
    $this->studentB = Student::factory()->create([
        'name' => 'طالب الحلقة الثانية',
        'circle_id' => $this->circleB->id,
        'status' => 'active',
        'is_approved' => true,
    ]);
});

it('shows students from all teacher circles on the leaderboard grading page', function () {
    $leaderboard = Leaderboard::create([
        'circle_id' => $this->circleA->id,
        'title' => 'مسابقة الحلقتين',
        'competition_type' => 'leaderboard',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDays(10),
        'is_active' => true,
        'is_active_for_grading' => true,
        'settings' => [],
    ]);
    $leaderboard->circles()->attach([$this->circleA->id, $this->circleB->id]);

    $this->actingAs($this->teacher, 'teacher');

    $component = Livewire::test(LeaderboardGrade::class, ['leaderboardId' => $leaderboard->id]);

    $shownIds = collect($component->viewData('students'))->pluck('id');

    expect($shownIds->all())->toContain($this->studentA->id);
    expect($shownIds->all())->toContain($this->studentB->id);
});

it('lists competitions from every teacher circle on the leaderboards page', function () {
    $boardA = Leaderboard::create([
        'circle_id' => $this->circleA->id,
        'title' => 'مسابقة الحلقة الأولى',
        'competition_type' => 'leaderboard',
        'start_date' => now()->subDay(),
        'is_active' => true,
        'settings' => [],
    ]);
    $boardB = Leaderboard::create([
        'circle_id' => $this->circleB->id,
        'title' => 'مسابقة الحلقة الثانية',
        'competition_type' => 'leaderboard',
        'start_date' => now()->subDay(),
        'is_active' => true,
        'settings' => [],
    ]);

    $this->actingAs($this->teacher, 'teacher');

    $component = Livewire::test(Leaderboards::class);

    $listedIds = collect($component->get('leaderboards'))->pluck('id');

    expect($listedIds->all())->toContain($boardA->id);
    expect($listedIds->all())->toContain($boardB->id);
});

it('numbers each circle\'s tasmeeh queue from one', function () {
    openTurnBooking($this->circleA);

    // A student from each of the teacher's circles books; each circle counts its own.
    $this->actingAs($this->studentA, 'student');
    Livewire::test('student.⚡dashboard')->call('reserveTurn');

    $this->actingAs($this->studentB, 'student');
    Livewire::test('student.⚡dashboard')->call('reserveTurn');

    $turnA = CircleTurn::where('student_id', $this->studentA->id)->first();
    $turnB = CircleTurn::where('student_id', $this->studentB->id)->first();

    expect([$turnA->circle_id, $turnA->turn_number])->toBe([$this->circleA->id, 1])
        ->and([$turnB->circle_id, $turnB->turn_number])->toBe([$this->circleB->id, 1]);
});

it('shows the booking window and the circle\'s turns to every teacher of the circle', function () {
    $coTeacher = Teacher::factory()->create();
    $coTeacher->circles()->attach($this->circleA->id);

    openTurnBooking($this->circleA);

    CircleTurn::create([
        'circle_id' => $this->circleA->id,
        'student_id' => $this->studentA->id,
        'date' => now('Asia/Riyadh')->toDateString(),
        'turn_number' => 1,
    ]);

    $this->actingAs($coTeacher, 'teacher');
    $component = Livewire::test('teacher.⚡tasmeeh-manager');

    expect($component->viewData('turnWindow'))->not->toBeNull()
        ->and($component->viewData('turnWindow')->hours())->toBe('12:00 ص - 11:59 م');
});

it('leaves the booking window to the supervisor: the teacher has no settings for it', function () {
    openTurnBooking($this->circleA);

    $this->actingAs($this->teacher, 'teacher');

    Livewire::test('teacher.⚡tasmeeh-manager')
        ->assertSee('حجز الأدوار اليوم')
        ->assertDontSee('إعدادات حجز الأدوار');
});

it('opens booking for students of the teacher\'s second circle', function () {
    openTurnBooking($this->circleA);

    $this->actingAs($this->studentB, 'student');

    $component = Livewire::test('student.⚡dashboard');

    expect($component->viewData('turnWindow'))->not->toBeNull();
});
