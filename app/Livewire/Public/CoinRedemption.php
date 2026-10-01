<?php

namespace App\Livewire\Public;

use App\Models\GamificationStudentState;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\Student;
use App\Services\GamificationService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Teacher-facing coin redemption sheet for one circle in one competition,
 * reached through a non-expiring signed link generated from the supervisor's
 * gamification management page (same sharing model as public circle reports).
 * The teacher exchanges a student's coins for a physical reward (paper tokens,
 * prize); each exchange is a negative "spend" transaction so standings XP is
 * untouched and the coin balance drops through the normal recalculation.
 */
class CoinRedemption extends Component
{
    #[Locked]
    public int $leaderboardId;

    #[Locked]
    public int $circleId;

    public ?int $redeemStudentId = null;

    public $redeemAmount = '';

    public string $redeemNote = '';

    public function mount(Request $request): void
    {
        // The `signed` middleware already authenticated the link; here we only
        // read its frozen parameters and verify they still belong together.
        $this->leaderboardId = (int) $request->query('leaderboard');
        $this->circleId = (int) $request->query('circle');

        $this->leaderboardOrFail();
    }

    protected function leaderboardOrFail(): Leaderboard
    {
        $leaderboard = Leaderboard::where('competition_type', 'gamification')
            ->findOrFail($this->leaderboardId);

        $circleAttached = $leaderboard->circle_id === $this->circleId
            || $leaderboard->circles()->where('circles.id', $this->circleId)->exists();

        abort_unless($circleAttached, 404);

        return $leaderboard;
    }

    /** @return Collection<int, Student> */
    protected function circleStudents()
    {
        return Student::where('circle_id', $this->circleId)
            ->whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->orderBy('name')
            ->get();
    }

    public function openRedeem(int $studentId): void
    {
        $this->resetValidation();
        $this->redeemStudentId = $studentId;
        $this->redeemAmount = '';
        $this->redeemNote = '';

        Flux::modal('redeem-modal')->show();
    }

    public function redeem(): void
    {
        $this->validate([
            'redeemAmount' => 'required|integer|min:1',
            'redeemNote' => 'nullable|string|max:255',
        ], [], [
            'redeemAmount' => __('عدد العملات'),
            'redeemNote' => __('البيان'),
        ]);

        $leaderboard = $this->leaderboardOrFail();
        $student = $this->circleStudents()->firstWhere('id', $this->redeemStudentId);

        if (! $student) {
            Flux::toast(__('الطالب غير موجود في هذه الحلقة'), variant: 'danger');

            return;
        }

        $amount = (int) $this->redeemAmount;
        $balance = (int) (GamificationStudentState::where('leaderboard_id', $leaderboard->id)
            ->where('student_id', $student->id)
            ->value('coins') ?? 0);

        if ($amount > $balance) {
            $this->addError('redeemAmount', __('رصيد الطالب الحالي :balance عملة فقط.', ['balance' => $balance]));

            return;
        }

        DB::transaction(function () use ($leaderboard, $student, $amount) {
            GamificationTransaction::create([
                'leaderboard_id' => $leaderboard->id,
                'student_id' => $student->id,
                'type' => 'spend',
                'amount' => -$amount,
                'reference_type' => 'redemption',
                'description' => $this->redeemNote !== ''
                    ? __('صرف عملات (جائزة): :note', ['note' => $this->redeemNote])
                    : __('صرف عملات مقابل جائزة من معلم الحلقة'),
            ]);

            GamificationService::recalculateStudentState($student->id, $leaderboard->id);
        });

        $this->reset(['redeemStudentId', 'redeemAmount', 'redeemNote']);
        Flux::modal('redeem-modal')->close();
        Flux::toast(__('تم صرف :amount عملة للطالب :name بنجاح', ['amount' => $amount, 'name' => $student->name]), variant: 'success');
    }

    public function render()
    {
        $leaderboard = $this->leaderboardOrFail();
        $students = $this->circleStudents();

        $coins = GamificationStudentState::where('leaderboard_id', $leaderboard->id)
            ->whereIn('student_id', $students->pluck('id'))
            ->pluck('coins', 'student_id');

        $recentRedemptions = GamificationTransaction::with('student')
            ->where('leaderboard_id', $leaderboard->id)
            ->where('reference_type', 'redemption')
            ->whereIn('student_id', $students->pluck('id'))
            ->latest()
            ->limit(20)
            ->get();

        $redeemStudent = $this->redeemStudentId
            ? $students->firstWhere('id', $this->redeemStudentId)
            : null;

        return view('livewire.public.coin-redemption', [
            'leaderboard' => $leaderboard,
            'circle' => $leaderboard->circle_id === $this->circleId
                ? $leaderboard->circle
                : $leaderboard->circles->firstWhere('id', $this->circleId),
            'students' => $students,
            'coins' => $coins,
            'recentRedemptions' => $recentRedemptions,
            'redeemStudent' => $redeemStudent,
            'redeemBalance' => $redeemStudent ? (int) ($coins[$redeemStudent->id] ?? 0) : 0,
        ])->layout('layouts.blank');
    }
}
