<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\FreeRecitation;
use App\Models\GamificationTransaction;
use App\Models\LeaderboardScore;
use App\Models\Student;
use App\Models\StudentHadithAchievement;
use App\Models\StudentOdeAchievement;
use App\Models\StudentPlanDay;
use App\Services\GamificationService;
use DateTimeInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Take a doubling no longer owed off the points it was baked into.
 *
 * A student's multiplier is baked into each earning when it is synced: the
 * points are doubled and the description tagged «[مضاعف النقاط نشط!]». The
 * lookup used to take any dated purchase of the student's for the day, so a
 * streak freeze doubled the points of the day it froze, and a multiplier
 * bought in one competition doubled the same day in every other one. The
 * lookup now takes only the multiplier products bought in the competition.
 *
 * This asks it again for every tagged earning, on the same day its sync asked,
 * and where no multiplier is owed any more halves the XP and coins and drops
 * the tag. Nothing is re-priced and no record is re-synced, so nothing else
 * about the points moves. A real multiplier day is left as it is, and a
 * second run finds nothing to change.
 */
#[Signature('gamification:unbake-unowed-multipliers')]
#[Description('Halve the points a streak freeze or another competition\'s multiplier doubled, now that only the competition\'s own multipliers count. Idempotent.')]
class UnbakeUnownedMultipliers extends Command
{
    private const TAG = ' [مضاعف النقاط نشط!]';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $fixed = 0;
        $touched = [];

        GamificationTransaction::where('type', 'earn')
            ->where('description', 'like', '%'.trim(self::TAG).'%')
            ->whereNotNull('student_id')
            ->whereNotNull('reference_id')
            ->chunkById(200, function ($transactions) use (&$fixed, &$touched): void {
                foreach ($transactions as $transaction) {
                    $date = $this->multiplierDate($transaction);
                    $student = Student::find($transaction->student_id);

                    if ($date === null || $student === null) {
                        continue;
                    }

                    if (GamificationService::getMultiplierForStudent($student, $transaction->leaderboard_id, $date) > 1) {
                        continue;
                    }

                    $transaction->update([
                        'amount' => intdiv((int) $transaction->amount, 2),
                        'xp_amount' => intdiv((int) $transaction->xp_amount, 2),
                        'description' => str_replace(self::TAG, '', $transaction->description),
                    ]);

                    $touched["{$transaction->student_id}:{$transaction->leaderboard_id}"] = [$transaction->student_id, $transaction->leaderboard_id];
                    $fixed++;
                }
            });

        foreach ($touched as [$studentId, $leaderboardId]) {
            GamificationService::recalculateStudentState($studentId, $leaderboardId);
        }

        $this->info("Halved {$fixed} earning(s) a multiplier no longer owed had doubled, for ".count($touched).' student(s).');

        return self::SUCCESS;
    }

    /**
     * The day the earning's sync looked its multiplier up by.
     */
    private function multiplierDate(GamificationTransaction $transaction): DateTimeInterface|string|null
    {
        return match ($transaction->reference_type) {
            StudentPlanDay::class => StudentPlanDay::find($transaction->reference_id)?->date,
            Attendance::class => Attendance::find($transaction->reference_id)?->date,
            LeaderboardScore::class => LeaderboardScore::find($transaction->reference_id)?->date,
            FreeRecitation::class => FreeRecitation::find($transaction->reference_id)?->graded_at,
            StudentOdeAchievement::class => $this->achievementDate(StudentOdeAchievement::with('pathDay')->find($transaction->reference_id)),
            StudentHadithAchievement::class => $this->achievementDate(StudentHadithAchievement::with('pathDay')->find($transaction->reference_id)),
            default => null,
        };
    }

    private function achievementDate(StudentOdeAchievement|StudentHadithAchievement|null $achievement): DateTimeInterface|string|null
    {
        return $achievement ? ($achievement->hifz_graded_at ?? $achievement->review_graded_at ?? $achievement->pathDay?->date) : null;
    }
}
