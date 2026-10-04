<?php

use App\Livewire\Supervisor\ManageGamification;
use App\Models\Circle;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\Ode;
use App\Models\OdePath;
use App\Models\OdePathDay;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentOdeAchievement;
use App\Models\StudentOdePlan;
use App\Models\Supervisor;
use App\Services\GamificationRecalculator;
use App\Services\GamificationService;
use Livewire\Livewire;

beforeEach(function () {
    $this->stage = Stage::create(['name' => 'مرحلة إعادة الاحتساب']);
    $this->circle = Circle::create(['name' => 'حلقة إعادة الاحتساب', 'stage_id' => $this->stage->id]);
    $this->student = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'active']);

    $this->competition = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة إعادة الاحتساب',
        'competition_type' => 'gamification',
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'is_active' => true,
        // The ode criterion is off while the teacher grades.
        'settings' => ['ode_hifz_enabled' => false],
    ]);

    $ode = Ode::create(['name' => 'منظومة إعادة الاحتساب']);
    $path = OdePath::create(['ode_id' => $ode->id, 'name' => 'مسار', 'start_date' => '2026-07-01']);

    $plan = StudentOdePlan::create([
        'student_id' => $this->student->id,
        'ode_path_id' => $path->id,
        'start_date' => '2026-07-01',
        'status' => 'active',
        'created_by_role' => 'supervisor',
    ]);

    $day = OdePathDay::create([
        'ode_path_id' => $path->id,
        'day_number' => 1,
        'date' => '2026-07-10',
        'from_verse_number' => 1,
        'to_verse_number' => 4,
    ]);

    $this->achievement = StudentOdeAchievement::create([
        'student_ode_plan_id' => $plan->id,
        'ode_path_day_id' => $day->id,
        'hifz_achievement' => 3,
        'hifz_graded_at' => '2026-07-10 09:00:00',
    ]);

    // Grading with the criterion off writes nothing, exactly as in production.
    GamificationService::syncStudentOdeAchievementXP($this->achievement);

    $this->odeTransactions = fn () => GamificationTransaction::where('leaderboard_id', $this->competition->id)
        ->where('reference_type', StudentOdeAchievement::class);
});

it('writes no points while the criterion is off', function () {
    expect(($this->odeTransactions)()->count())->toBe(0);
});

/**
 * The reported bug: enabling a criterion after teachers had graded left those
 * gradings unscored forever, because points are written at grading time.
 */
it('scores gradings that happened before the criterion was enabled', function () {
    $this->competition->update(['settings' => [
        'ode_hifz_enabled' => true,
        'ode_hifz_excellent_xp' => 10,
        'ode_hifz_excellent_coins' => 10,
    ]]);

    $counts = GamificationRecalculator::forCompetition($this->competition->fresh());

    expect($counts['ode'])->toBe(1)
        ->and($counts['students'])->toBe(1);

    $transaction = ($this->odeTransactions)()->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->xp_amount)->toBe(10)
        ->and($transaction->amount)->toBe(10);
});

it('does not double count when run again', function () {
    $this->competition->update(['settings' => [
        'ode_hifz_enabled' => true,
        'ode_hifz_excellent_xp' => 10,
        'ode_hifz_excellent_coins' => 10,
    ]]);

    GamificationRecalculator::forCompetition($this->competition->fresh());
    GamificationRecalculator::forCompetition($this->competition->fresh());
    GamificationRecalculator::forCompetition($this->competition->fresh());

    expect(($this->odeTransactions)()->count())->toBe(1)
        ->and(($this->odeTransactions)()->sum('xp_amount'))->toBe(10);
});

it('removes the points again when the criterion is switched back off', function () {
    $this->competition->update(['settings' => ['ode_hifz_enabled' => true, 'ode_hifz_excellent_xp' => 10]]);
    GamificationRecalculator::forCompetition($this->competition->fresh());

    expect(($this->odeTransactions)()->count())->toBe(1);

    $this->competition->update(['settings' => ['ode_hifz_enabled' => false]]);
    GamificationRecalculator::forCompetition($this->competition->fresh());

    expect(($this->odeTransactions)()->count())->toBe(0);
});

it('leaves gradings outside the competition window alone', function () {
    // Graded a month before the competition opened.
    $this->achievement->update(['hifz_graded_at' => '2026-06-01 09:00:00']);

    $this->competition->update(['settings' => ['ode_hifz_enabled' => true, 'ode_hifz_excellent_xp' => 10]]);

    $counts = GamificationRecalculator::forCompetition($this->competition->fresh());

    expect($counts['ode'])->toBe(0)
        ->and(($this->odeTransactions)()->count())->toBe(0);
});

it('lets the supervisor trigger the recalculation from the competition page', function () {
    $supervisor = Supervisor::factory()->create();
    $supervisor->stages()->attach($this->stage->id);
    $this->actingAs($supervisor, 'supervisor');

    // The page only loads competitions the supervisor owns.
    $this->competition->update(['supervisor_id' => $supervisor->id]);
    $this->competition->circles()->attach($this->circle->id);

    $this->competition->update(['settings' => [
        'ode_hifz_enabled' => true,
        'ode_hifz_excellent_xp' => 10,
        'ode_hifz_excellent_coins' => 10,
    ]]);

    $component = Livewire::test(ManageGamification::class, ['competitionId' => $this->competition->id])
        ->call('startRecalculation')
        ->assertHasNoErrors();

    // The page drives one batch per poll until the cursor reports it is done.
    for ($i = 0; $i < 10 && $component->get('recalcCursor') !== null; $i++) {
        $component->call('recalculateStep');
    }

    expect($component->get('recalcCursor'))->toBeNull()
        ->and(($this->odeTransactions)()->count())->toBe(1);
});

it('advances one stage at a time and finishes', function () {
    $cursor = GamificationRecalculator::start();

    expect($cursor['stage'])->toBe('quran')
        ->and($cursor['done'])->toBeFalse();

    $stages = [];

    for ($i = 0; $i < 20 && ! $cursor['done']; $i++) {
        $stages[] = $cursor['stage'];
        $cursor = GamificationRecalculator::step($this->competition, $cursor);
    }

    expect($cursor['done'])->toBeTrue()
        // Every stage is visited, in order, and none is skipped.
        ->and(array_values(array_unique($stages)))
        ->toBe(['quran', 'ode', 'hadith', 'free', 'attendance', 'students']);
});

it('keeps each step small enough for a web request', function () {
    expect(GamificationRecalculator::BATCH)->toBeLessThanOrEqual(200);
});
