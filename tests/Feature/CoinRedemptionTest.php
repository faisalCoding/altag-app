<?php

use App\Livewire\Public\CoinRedemption;
use App\Models\Circle;
use App\Models\GamificationStudentState;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use App\Services\GamificationService;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->stage = Stage::create(['name' => 'المرحلة الأولى']);
    $this->circle = Circle::create(['name' => 'حلقة النور', 'stage_id' => $this->stage->id]);

    $this->student = Student::create([
        'name' => 'أحمد علي',
        'email' => 'ahmed@example.com',
        'password' => bcrypt('password'),
        'circle_id' => $this->circle->id,
        'is_approved' => true,
        'status' => 'active',
    ]);

    $this->leaderboard = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة الفضاء',
        'competition_type' => 'gamification',
        'start_date' => now()->subDays(10),
        'end_date' => now()->addDays(10),
        'is_active' => true,
        'settings' => [],
    ]);
    $this->leaderboard->circles()->attach($this->circle->id);

    GamificationTransaction::create([
        'leaderboard_id' => $this->leaderboard->id,
        'student_id' => $this->student->id,
        'type' => 'earn',
        'amount' => 150,
        'description' => 'نقاط حفظ',
    ]);
    GamificationService::recalculateStudentState($this->student->id, $this->leaderboard->id);
});

function redemptionUrl(int $leaderboardId, int $circleId): string
{
    return URL::signedRoute('redemption.circle', [
        'leaderboard' => $leaderboardId,
        'circle' => $circleId,
    ]);
}

it('rejects an unsigned redemption link', function () {
    $this->get(route('redemption.circle', [
        'leaderboard' => $this->leaderboard->id,
        'circle' => $this->circle->id,
    ]))->assertForbidden();
});

it('shows circle students with their coin balances on a signed link', function () {
    $this->get(redemptionUrl($this->leaderboard->id, $this->circle->id))
        ->assertOk()
        ->assertSee('أحمد علي')
        ->assertSee('حلقة النور')
        ->assertSee('150');
});

it('rejects a circle that does not belong to the competition', function () {
    $otherCircle = Circle::create(['name' => 'حلقة أخرى', 'stage_id' => $this->stage->id]);

    $this->get(redemptionUrl($this->leaderboard->id, $otherCircle->id))
        ->assertNotFound();
});

it('redeems coins as a spend transaction and lowers the balance', function () {
    Livewire::withQueryParams(['leaderboard' => $this->leaderboard->id, 'circle' => $this->circle->id])
        ->test(CoinRedemption::class)
        ->call('openRedeem', $this->student->id)
        ->set('redeemAmount', 100)
        ->set('redeemNote', 'قسيمة مكتبة')
        ->call('redeem')
        ->assertHasNoErrors();

    $spend = GamificationTransaction::where('reference_type', 'redemption')->first();

    expect($spend)->not->toBeNull()
        ->and($spend->type)->toBe('spend')
        ->and($spend->amount)->toBe(-100)
        ->and($spend->description)->toContain('قسيمة مكتبة');

    $state = GamificationStudentState::where('leaderboard_id', $this->leaderboard->id)
        ->where('student_id', $this->student->id)
        ->first();

    expect($state->coins)->toBe(50);
});

it('refuses to redeem more coins than the student holds', function () {
    Livewire::withQueryParams(['leaderboard' => $this->leaderboard->id, 'circle' => $this->circle->id])
        ->test(CoinRedemption::class)
        ->call('openRedeem', $this->student->id)
        ->set('redeemAmount', 500)
        ->call('redeem')
        ->assertHasErrors('redeemAmount');

    expect(GamificationTransaction::where('reference_type', 'redemption')->count())->toBe(0);
});

it('does not affect standings XP when redeeming', function () {
    Livewire::withQueryParams(['leaderboard' => $this->leaderboard->id, 'circle' => $this->circle->id])
        ->test(CoinRedemption::class)
        ->call('openRedeem', $this->student->id)
        ->set('redeemAmount', 100)
        ->call('redeem');

    $xpTotal = GamificationTransaction::where('leaderboard_id', $this->leaderboard->id)
        ->where('student_id', $this->student->id)
        ->sum('xp_amount');

    expect((int) $xpTotal)->toBe(150);
});

it('will not let a redemption link be pointed at another circle', function () {
    $otherCircle = Circle::create(['name' => 'حلقة أخرى', 'stage_id' => $this->stage->id]);

    Livewire::withQueryParams(['leaderboard' => $this->leaderboard->id, 'circle' => $this->circle->id])
        ->test(CoinRedemption::class)
        ->set('circleId', $otherCircle->id);
})->throws(CannotUpdateLockedPropertyException::class);
