<?php

namespace App\Console\Commands;

use App\Models\FreeRecitation;
use App\Models\GamificationTransaction;
use App\Models\StudentHadithAchievement;
use App\Models\StudentOdeAchievement;
use App\Models\StudentPlanDay;
use App\Services\GamificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gamification:recompute-plan-day-points')]
#[Description('Re-sync gamification XP/coins for every graded hifz/review/ode/hadith/free-recitation record (idempotent backfill).')]
class RecomputePlanDayPoints extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $graded = fn ($q) => $q->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement');
        $count = 0;

        StudentPlanDay::query()->where($graded)->with('plan.student')
            ->chunkById(200, function ($days) use (&$count) {
                foreach ($days as $day) {
                    if ($day->plan?->student) {
                        GamificationService::syncStudentPlanDayXP($day);
                        $count++;
                    }
                }
            });

        StudentOdeAchievement::query()->where($graded)->with(['plan.student', 'pathDay'])
            ->chunkById(200, function ($achievements) use (&$count) {
                foreach ($achievements as $achievement) {
                    if ($achievement->plan?->student) {
                        GamificationService::syncStudentOdeAchievementXP($achievement);
                        $count++;
                    }
                }
            });

        StudentHadithAchievement::query()->where($graded)->with(['plan.student', 'pathDay'])
            ->chunkById(200, function ($achievements) use (&$count) {
                foreach ($achievements as $achievement) {
                    if ($achievement->plan?->student) {
                        GamificationService::syncStudentHadithAchievementXP($achievement);
                        $count++;
                    }
                }
            });

        FreeRecitation::query()->whereNotNull('achievement')->with('student')
            ->chunkById(200, function ($recitations) use (&$count) {
                foreach ($recitations as $recitation) {
                    if ($recitation->student) {
                        GamificationService::syncFreeRecitationXP($recitation);
                        $count++;
                    }
                }
            });

        // Purge orphaned transactions whose referenced record was deleted.
        $orphans = 0;
        foreach ([
            StudentPlanDay::class => StudentPlanDay::query()->pluck('id'),
            StudentOdeAchievement::class => StudentOdeAchievement::query()->pluck('id'),
            StudentHadithAchievement::class => StudentHadithAchievement::query()->pluck('id'),
            FreeRecitation::class => FreeRecitation::query()->pluck('id'),
        ] as $type => $existingIds) {
            $orphanRefIds = GamificationTransaction::where('reference_type', $type)
                ->whereNotIn('reference_id', $existingIds)
                ->distinct()
                ->pluck('reference_id');

            foreach ($orphanRefIds as $refId) {
                GamificationService::clearTransactionsForReference($type, $refId);
                $orphans++;
            }
        }

        $this->info("Recomputed gamification points for {$count} graded records (plan days + odes + hadith + free recitations).");
        $this->info("Purged {$orphans} orphaned reference(s) whose record was deleted.");

        return self::SUCCESS;
    }
}
