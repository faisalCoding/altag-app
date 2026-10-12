<?php

use App\Livewire\Supervisor\Competitions;
use App\Models\Circle;
use App\Models\GamificationLevel;
use App\Models\GamificationStudentState;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Supervisor;
use App\Services\GamificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Monday 20 July 2026. The competition runs from 1 July; the student earned
 * 30 coins on 5 July, spent 10 on 6 July and earned 50 on 15 July.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-07-20 10:00:00');

    $stage = Stage::create(['name' => 'المرحلة الأولى']);
    $this->circle = Circle::create(['name' => 'حلقة النور', 'stage_id' => $stage->id]);
    $this->supervisor = Supervisor::factory()->create(['is_approved' => true]);
    $this->supervisor->stages()->attach($stage->id);
    $this->student = Student::factory()->create(['circle_id' => $this->circle->id]);

    $this->competition = Leaderboard::create([
        'supervisor_id' => $this->supervisor->id,
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة الصيف',
        'competition_type' => 'gamification',
        'start_date' => '2026-07-01',
        'end_date' => '2026-08-30',
        'is_active' => true,
        'settings' => [],
    ]);
    $this->competition->circles()->attach($this->circle->id);

    $this->coinsOn = function (string $date, int $amount, array $attributes = []): GamificationTransaction {
        $transaction = GamificationTransaction::create($attributes + [
            'leaderboard_id' => $this->competition->id,
            'student_id' => $this->student->id,
            'type' => $amount < 0 ? 'spend' : 'earn',
            'amount' => $amount,
            'xp_amount' => 0,
            'description' => 'معاملة اختبارية',
        ]);
        $transaction->forceFill(['created_at' => $date.' 09:00:00'])->saveQuietly();

        return $transaction;
    };

    ($this->coinsOn)('2026-07-05', 30);
    ($this->coinsOn)('2026-07-06', -10);
    ($this->coinsOn)('2026-07-15', 50);
});

function coinsOf(Student $student, Leaderboard $competition): int
{
    GamificationService::recalculateStudentState($student->id, $competition->id);

    return (int) GamificationStudentState::where('leaderboard_id', $competition->id)
        ->where('student_id', $student->id)
        ->value('coins');
}

function moveStart(Supervisor $supervisor, Leaderboard $competition, string $start)
{
    test()->actingAs($supervisor, 'supervisor');

    return Livewire::test(Competitions::class)
        ->call('edit', $competition->id)
        ->set('start_date', $start)
        ->call('save')
        ->assertHasNoErrors();
}

it('counts the coins earned and spent from the start on', function () {
    expect(coinsOf($this->student, $this->competition))->toBe(70);
});

it('sets aside what came before a later start, and gives it back when the start is put back', function () {
    coinsOf($this->student, $this->competition);

    moveStart($this->supervisor, $this->competition, '2026-07-10');

    // Counted again on saving: the 30 earned and the 10 spent before the 10th are left out.
    expect(GamificationStudentState::where('student_id', $this->student->id)->value('coins'))->toBe(50)
        ->and(GamificationTransaction::count())->toBe(3);

    moveStart($this->supervisor, $this->competition, '2026-07-01');

    expect(GamificationStudentState::where('student_id', $this->student->id)->value('coins'))->toBe(70);
});

it('tells the supervisor the balances were counted again', function () {
    moveStart($this->supervisor, $this->competition, '2026-07-10')
        ->assertDispatched('toast-show', fn ($event, $params) => str_contains(json_encode($params, JSON_UNESCAPED_UNICODE), 'أُعيد حساب أرصدة الطلاب'));
});

it('neither offers nor pays a pending reward from before the start', function () {
    $this->competition->update(['start_date' => '2026-07-10']);
    $early = ($this->coinsOn)('2026-07-08', 20, ['claimed_at' => null]);
    $late = ($this->coinsOn)('2026-07-12', 5, ['claimed_at' => null]);

    expect(GamificationService::getPendingRewards($this->student->id, $this->competition->id)->pluck('id')->all())->toBe([$late->id])
        ->and(GamificationService::claimReward($early->id, $this->student->id))->toBeFalse()
        ->and(GamificationService::claimAllRewards($this->student->id, $this->competition->id))->toBe(1)
        ->and($early->fresh()->claimed_at)->toBeNull()
        ->and(coinsOf($this->student, $this->competition))->toBe(55);
});

it('keeps a level reward from before the start while the level is still held', function () {
    $level = GamificationLevel::create([
        'leaderboard_id' => $this->competition->id, 'level_number' => 2, 'name' => 'المستوى ٢',
        'xp_required' => 100, 'icon' => 'star', 'settings' => ['reward_coins' => 40],
    ]);
    ($this->coinsOn)('2026-07-05', 40, ['reference_type' => GamificationLevel::class, 'reference_id' => $level->id]);
    $points = ($this->coinsOn)('2026-07-15', 0, ['xp_amount' => 120]);
    $this->competition->update(['start_date' => '2026-07-10']);

    // 50 from the 15th, and the reward of a level the student still holds.
    expect(coinsOf($this->student, $this->competition))->toBe(90);

    // Below the level on the competition's own points: the reward goes too.
    $points->update(['xp_amount' => 60]);

    expect(coinsOf($this->student, $this->competition))->toBe(50);
});
