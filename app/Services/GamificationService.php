<?php

namespace App\Services;

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\GamificationBadge;
use App\Models\GamificationLevel;
use App\Models\GamificationStoreItem;
use App\Models\GamificationStorePurchase;
use App\Models\GamificationStudentState;
use App\Models\GamificationTeam;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\LeaderboardCriterion;
use App\Models\LeaderboardScore;
use App\Models\Student;
use App\Models\StudentHadithAchievement;
use App\Models\StudentOdeAchievement;
use App\Models\StudentPlanDay;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GamificationService
{
    /**
     * Get active gamification leaderboards for a student on a specific date.
     *
     * @param  string|null  $date
     * @return Collection<int, Leaderboard>
     */
    public static function getActiveLeaderboards(Student $student, $date = null)
    {
        $date = $date ? Carbon::parse($date)->format('Y-m-d') : now()->format('Y-m-d');

        return Leaderboard::where('is_active', true)
            ->where('competition_type', 'gamification')
            // Compare on the date part only. start_date/end_date are stored with a
            // "00:00:00" time, so a plain string `where` would exclude the very first
            // day of the competition ("...00:00:00" > "Y-m-d").
            ->whereDate('start_date', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $date);
            })
            ->where(function ($query) use ($student) {
                $query->where('circle_id', $student->circle_id)
                    ->whereNull('supervisor_id')
                    ->orWhere(function ($q) use ($student) {
                        $q->whereNotNull('supervisor_id')
                            ->whereHas('circles', function ($c) use ($student) {
                                $c->where('circles.id', $student->circle_id);
                            });
                    });
            })
            ->orderByRaw('case when supervisor_id is null then 1 else 0 end')
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * Recalculate student state (total coins, total XP) for a leaderboard.
     */
    public static function recalculateStudentState(int $studentId, int $leaderboardId): void
    {
        $state = GamificationStudentState::firstOrNew([
            'leaderboard_id' => $leaderboardId,
            'student_id' => $studentId,
        ]);

        // Grant any newly-reached level rewards first so immediately-claimed
        // (non-manual-claim) rewards are reflected in the coin total below.
        self::syncStudentLevelRewards($studentId, $leaderboardId);

        $coins = GamificationTransaction::where('leaderboard_id', $leaderboardId)
            ->where('student_id', $studentId)
            ->whereNull('team_id')
            ->claimed()
            ->sum('amount');

        $state->coins = max(0, (int) $coins);
        $state->save();

        // Announce a level-up in the competition news feed when XP crosses a level.
        GamificationNewsService::syncStudentLevel($studentId, $leaderboardId);
    }

    /**
     * Grant a one-time coin reward for every level the student has reached.
     *
     * Idempotent: each level's reward is tied to a single transaction keyed by
     * the level (reference_type/reference_id), so repeated recalculations never
     * duplicate it. Rewards flow through the standard claim pipeline — pending a
     * manual claim when the competition enables it, applied immediately otherwise.
     * Level rewards are coins-only by design so they cannot feed back into XP and
     * trigger further level-ups.
     */
    public static function syncStudentLevelRewards(int $studentId, int $leaderboardId): void
    {
        $leaderboard = Leaderboard::find($leaderboardId);

        if (! $leaderboard) {
            return;
        }

        $levelInfo = self::getStudentLevel($studentId, $leaderboardId);
        $currentLevelNumber = (int) ($levelInfo['current']->level_number ?? 1);

        // Only persisted levels can be referenced by a transaction. Reward every
        // reached level (level_number <= current) that has a coin reward set.
        $reachedLevels = GamificationLevel::where('leaderboard_id', $leaderboardId)
            ->where('level_number', '<=', $currentLevelNumber)
            ->orderBy('level_number')
            ->get();

        foreach ($reachedLevels as $level) {
            $rewardCoins = (int) ($level->settings['reward_coins'] ?? 0);

            if ($rewardCoins <= 0) {
                continue;
            }

            $alreadyGranted = GamificationTransaction::where('leaderboard_id', $leaderboardId)
                ->where('student_id', $studentId)
                ->where('reference_type', GamificationLevel::class)
                ->where('reference_id', $level->id)
                ->exists();

            if ($alreadyGranted) {
                continue;
            }

            GamificationTransaction::create([
                'leaderboard_id' => $leaderboardId,
                'student_id' => $studentId,
                'type' => 'earn',
                'amount' => $rewardCoins,
                'xp_amount' => 0,
                'description' => "مكافأة الوصول للمستوى {$level->level_number}: {$level->name}",
                'reference_type' => GamificationLevel::class,
                'reference_id' => $level->id,
                'claimed_at' => self::resolveClaimedAt($leaderboard, $rewardCoins, 0),
            ]);
        }
    }

    /**
     * Resolve the claimed_at value for a newly earned individual reward.
     *
     * Returns null (pending a manual claim) only when the competition has manual
     * claim enabled and this is an individual positive earning. Everything else —
     * deductions, team-treasury entries, store spends, or competitions without the
     * feature — is claimed immediately.
     */
    public static function resolveClaimedAt(Leaderboard $leaderboard, int $amount, int $xpAmount): ?CarbonInterface
    {
        $manualClaim = (bool) ($leaderboard->settings['manual_claim_enabled'] ?? false);

        if ($manualClaim && ($amount >= 0 && $xpAmount >= 0) && ($amount > 0 || $xpAmount > 0)) {
            return null;
        }

        return now();
    }

    /**
     * Sync transaction for Hifz/Review grading.
     */
    public static function syncStudentPlanDayXP(StudentPlanDay $day): void
    {
        $student = $day->plan->student;
        // Gate the competition on the grading date: grading during a competition
        // earns its points regardless of which scheduled plan day it is. Falls back
        // to the day's own date only when it has not been graded yet.
        $date = $day->hifz_graded_at ?? $day->review_graded_at ?? $day->date;
        $leaderboards = self::getActiveLeaderboards($student, $date);

        // No competition covers this grading date — drop any stale points for the
        // day so a re-sync (e.g. after dates change) reconciles correctly.
        if ($leaderboards->isEmpty()) {
            self::clearTransactionsForReference(StudentPlanDay::class, $day->id);

            return;
        }

        foreach ($leaderboards as $leaderboard) {
            $settings = $leaderboard->settings ?? [];
            $xpPoints = 0;
            $coinPoints = 0;
            $details = [];

            // Hifz points
            if (($settings['hifz_enabled'] ?? false) && $day->hifz_achievement !== null) {
                $hifz = $day->hifz_achievement;
                if ($hifz === 3 || $hifz === '3' || $hifz === 'excellent') {
                    $xpPts = ($settings['hifz_excellent_xp'] ?? ($settings['hifz_excellent'] ?? 10));
                    $coinPts = ($settings['hifz_excellent_coins'] ?? ($settings['hifz_excellent'] ?? 10));
                    $xpPoints += $xpPts;
                    $coinPoints += $coinPts;
                    $details[] = "حفظ ممتاز (+{$xpPts} XP، +{$coinPts} عملة)";
                } elseif ($hifz === 2 || $hifz === '2' || $hifz === 'good') {
                    $xpPts = ($settings['hifz_good_xp'] ?? ($settings['hifz_good'] ?? 7));
                    $coinPts = ($settings['hifz_good_coins'] ?? ($settings['hifz_good'] ?? 7));
                    $xpPoints += $xpPts;
                    $coinPoints += $coinPts;
                    $details[] = "حفظ جيد (+{$xpPts} XP، +{$coinPts} عملة)";
                } elseif ($hifz === 1 || $hifz === '1' || $hifz === 'acceptable') {
                    $xpPts = ($settings['hifz_acceptable_xp'] ?? ($settings['hifz_acceptable'] ?? 4));
                    $coinPts = ($settings['hifz_acceptable_coins'] ?? ($settings['hifz_acceptable'] ?? 4));
                    $xpPoints += $xpPts;
                    $coinPoints += $coinPts;
                    $details[] = "حفظ مقبول (+{$xpPts} XP، +{$coinPts} عملة)";
                }
            }

            // Review points
            if (($settings['review_enabled'] ?? false) && $day->review_achievement !== null) {
                $review = $day->review_achievement;
                if ($review === 3 || $review === '3' || $review === 'excellent') {
                    $xpPts = ($settings['review_excellent_xp'] ?? ($settings['review_excellent'] ?? 5));
                    $coinPts = ($settings['review_excellent_coins'] ?? ($settings['review_excellent'] ?? 5));
                    $xpPoints += $xpPts;
                    $coinPoints += $coinPts;
                    $details[] = "مراجعة ممتازة (+{$xpPts} XP، +{$coinPts} عملة)";
                } elseif ($review === 2 || $review === '2' || $review === 1 || $review === 'good' || $review === 'acceptable') {
                    $xpPts = ($settings['review_good_xp'] ?? ($settings['review_good'] ?? 3));
                    $coinPts = ($settings['review_good_coins'] ?? ($settings['review_good'] ?? 3));
                    $xpPoints += $xpPts;
                    $coinPoints += $coinPts;
                    $details[] = "مراجعة جيدة (+{$xpPts} XP، +{$coinPts} عملة)";
                }
            }

            // Apply active multipliers (if any)
            $multiplier = self::getMultiplierForStudent($student, $leaderboard->id, $day->date);
            $multiplier = min(2, $multiplier);

            $finalXP = $xpPoints * $multiplier;
            $finalCoins = $coinPoints * $multiplier;

            // Find existing transaction
            $transaction = GamificationTransaction::where('leaderboard_id', $leaderboard->id)
                ->where('student_id', $student->id)
                ->where('reference_type', StudentPlanDay::class)
                ->where('reference_id', $day->id)
                ->first();

            if ($finalXP > 0 || $finalCoins > 0) {
                $desc = implode(' و ', $details).' لليوم '.$day->date->format('Y-m-d');
                if ($multiplier > 1) {
                    $desc .= ' [مضاعف النقاط نشط!]';
                }

                if ($transaction) {
                    $transaction->update([
                        'amount' => $finalCoins,
                        'xp_amount' => $finalXP,
                        'description' => $desc,
                    ]);
                } else {
                    GamificationTransaction::create([
                        'leaderboard_id' => $leaderboard->id,
                        'student_id' => $student->id,
                        'type' => 'earn',
                        'amount' => $finalCoins,
                        'xp_amount' => $finalXP,
                        'description' => $desc,
                        'reference_type' => StudentPlanDay::class,
                        'reference_id' => $day->id,
                        'claimed_at' => self::resolveClaimedAt($leaderboard, (int) $finalCoins, (int) $finalXP),
                    ]);
                }
            } else {
                if ($transaction) {
                    $transaction->delete();
                }
            }

            self::recalculateStudentState($student->id, $leaderboard->id);
            self::updateStudentStreak($student, $date, $leaderboard);
            self::syncStudentBadges($student->id, $leaderboard->id);
        }
    }

    /**
     * Remove every gamification transaction tied to a graded reference (plan day,
     * ode/hadith achievement…) and refresh the affected students' state. Used both
     * when a grading date leaves all competitions and when the reference row itself
     * is deleted, so no orphaned points linger.
     */
    public static function clearTransactionsForReference(string $referenceType, int $referenceId): void
    {
        $stale = GamificationTransaction::where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->get();

        if ($stale->isEmpty()) {
            return;
        }

        $pairs = $stale->map(fn ($t) => ['student' => $t->student_id, 'leaderboard' => $t->leaderboard_id])
            ->unique(fn ($p) => $p['student'].'-'.$p['leaderboard']);

        GamificationTransaction::whereIn('id', $stale->pluck('id'))->delete();

        foreach ($pairs as $pair) {
            self::recalculateStudentState($pair['student'], $pair['leaderboard']);
        }
    }

    /**
     * Sync XP/coins transaction for Ode achievement grading.
     */
    public static function syncStudentOdeAchievementXP(StudentOdeAchievement $achievement): void
    {
        $student = $achievement->plan->student;
        $date = $achievement->hifz_graded_at ?? $achievement->review_graded_at ?? $achievement->pathDay?->date;
        if (! $date) {
            return;
        }
        $leaderboards = self::getActiveLeaderboards($student, $date);

        if ($leaderboards->isEmpty()) {
            self::clearTransactionsForReference(StudentOdeAchievement::class, $achievement->id);

            return;
        }

        foreach ($leaderboards as $leaderboard) {
            $settings = $leaderboard->settings ?? [];
            $xpPoints = 0;
            $coinPoints = 0;
            $details = [];

            if (($settings['ode_hifz_enabled'] ?? false) && $achievement->hifz_achievement !== null) {
                $hifz = $achievement->hifz_achievement;
                if ($hifz == 3) {
                    $xpPts = (int) ($settings['ode_hifz_excellent_xp'] ?? 10);
                    $coinPts = (int) ($settings['ode_hifz_excellent_coins'] ?? 10);
                } elseif ($hifz == 2) {
                    $xpPts = (int) ($settings['ode_hifz_good_xp'] ?? 7);
                    $coinPts = (int) ($settings['ode_hifz_good_coins'] ?? 7);
                } else {
                    $xpPts = (int) ($settings['ode_hifz_acceptable_xp'] ?? 4);
                    $coinPts = (int) ($settings['ode_hifz_acceptable_coins'] ?? 4);
                }
                $xpPoints += $xpPts;
                $coinPoints += $coinPts;
                $details[] = "حفظ منظومة (+{$xpPts} XP، +{$coinPts} عملة)";
            }

            if (($settings['ode_review_enabled'] ?? false) && $achievement->review_achievement !== null) {
                $rev = $achievement->review_achievement;
                if ($rev == 3) {
                    $xpPts = (int) ($settings['ode_review_excellent_xp'] ?? 5);
                    $coinPts = (int) ($settings['ode_review_excellent_coins'] ?? 5);
                } else {
                    $xpPts = (int) ($settings['ode_review_good_xp'] ?? 3);
                    $coinPts = (int) ($settings['ode_review_good_coins'] ?? 3);
                }
                $xpPoints += $xpPts;
                $coinPoints += $coinPts;
                $details[] = "مراجعة منظومة (+{$xpPts} XP، +{$coinPts} عملة)";
            }

            $multiplier = min(2, self::getMultiplierForStudent($student, $leaderboard->id, $date));
            $finalXP = $xpPoints * $multiplier;
            $finalCoins = $coinPoints * $multiplier;

            $transaction = GamificationTransaction::where('leaderboard_id', $leaderboard->id)
                ->where('student_id', $student->id)
                ->where('reference_type', StudentOdeAchievement::class)
                ->where('reference_id', $achievement->id)
                ->first();

            if ($finalXP > 0 || $finalCoins > 0) {
                $dateStr = ($date instanceof Carbon ? $date : Carbon::parse($date))->format('Y-m-d');
                $desc = implode(' و ', $details).' لليوم '.$dateStr;
                if ($multiplier > 1) {
                    $desc .= ' [مضاعف النقاط نشط!]';
                }
                if ($transaction) {
                    $transaction->update(['amount' => $finalCoins, 'xp_amount' => $finalXP, 'description' => $desc]);
                } else {
                    GamificationTransaction::create([
                        'leaderboard_id' => $leaderboard->id,
                        'student_id' => $student->id,
                        'type' => 'earn',
                        'amount' => $finalCoins,
                        'xp_amount' => $finalXP,
                        'description' => $desc,
                        'reference_type' => StudentOdeAchievement::class,
                        'reference_id' => $achievement->id,
                        'claimed_at' => self::resolveClaimedAt($leaderboard, (int) $finalCoins, (int) $finalXP),
                    ]);
                }
            } elseif ($transaction) {
                $transaction->delete();
            }

            self::recalculateStudentState($student->id, $leaderboard->id);
            self::updateStudentStreak($student, $date, $leaderboard);
            self::syncStudentBadges($student->id, $leaderboard->id);
        }
    }

    /**
     * Sync XP/coins transaction for Hadith achievement grading.
     */
    public static function syncStudentHadithAchievementXP(StudentHadithAchievement $achievement): void
    {
        $student = $achievement->plan->student;
        $date = $achievement->hifz_graded_at ?? $achievement->review_graded_at ?? $achievement->pathDay?->date;
        if (! $date) {
            return;
        }
        $leaderboards = self::getActiveLeaderboards($student, $date);

        if ($leaderboards->isEmpty()) {
            self::clearTransactionsForReference(StudentHadithAchievement::class, $achievement->id);

            return;
        }

        foreach ($leaderboards as $leaderboard) {
            $settings = $leaderboard->settings ?? [];
            $xpPoints = 0;
            $coinPoints = 0;
            $details = [];

            if (($settings['hadith_hifz_enabled'] ?? false) && $achievement->hifz_achievement !== null) {
                $hifz = $achievement->hifz_achievement;
                if ($hifz == 3) {
                    $xpPts = (int) ($settings['hadith_hifz_excellent_xp'] ?? 10);
                    $coinPts = (int) ($settings['hadith_hifz_excellent_coins'] ?? 10);
                } elseif ($hifz == 2) {
                    $xpPts = (int) ($settings['hadith_hifz_good_xp'] ?? 7);
                    $coinPts = (int) ($settings['hadith_hifz_good_coins'] ?? 7);
                } else {
                    $xpPts = (int) ($settings['hadith_hifz_acceptable_xp'] ?? 4);
                    $coinPts = (int) ($settings['hadith_hifz_acceptable_coins'] ?? 4);
                }
                $xpPoints += $xpPts;
                $coinPoints += $coinPts;
                $details[] = "حفظ حديث (+{$xpPts} XP، +{$coinPts} عملة)";
            }

            if (($settings['hadith_review_enabled'] ?? false) && $achievement->review_achievement !== null) {
                $rev = $achievement->review_achievement;
                if ($rev == 3) {
                    $xpPts = (int) ($settings['hadith_review_excellent_xp'] ?? 5);
                    $coinPts = (int) ($settings['hadith_review_excellent_coins'] ?? 5);
                } else {
                    $xpPts = (int) ($settings['hadith_review_good_xp'] ?? 3);
                    $coinPts = (int) ($settings['hadith_review_good_coins'] ?? 3);
                }
                $xpPoints += $xpPts;
                $coinPoints += $coinPts;
                $details[] = "مراجعة حديث (+{$xpPts} XP، +{$coinPts} عملة)";
            }

            $multiplier = min(2, self::getMultiplierForStudent($student, $leaderboard->id, $date));
            $finalXP = $xpPoints * $multiplier;
            $finalCoins = $coinPoints * $multiplier;

            $transaction = GamificationTransaction::where('leaderboard_id', $leaderboard->id)
                ->where('student_id', $student->id)
                ->where('reference_type', StudentHadithAchievement::class)
                ->where('reference_id', $achievement->id)
                ->first();

            if ($finalXP > 0 || $finalCoins > 0) {
                $dateStr = ($date instanceof Carbon ? $date : Carbon::parse($date))->format('Y-m-d');
                $desc = implode(' و ', $details).' لليوم '.$dateStr;
                if ($multiplier > 1) {
                    $desc .= ' [مضاعف النقاط نشط!]';
                }
                if ($transaction) {
                    $transaction->update(['amount' => $finalCoins, 'xp_amount' => $finalXP, 'description' => $desc]);
                } else {
                    GamificationTransaction::create([
                        'leaderboard_id' => $leaderboard->id,
                        'student_id' => $student->id,
                        'type' => 'earn',
                        'amount' => $finalCoins,
                        'xp_amount' => $finalXP,
                        'description' => $desc,
                        'reference_type' => StudentHadithAchievement::class,
                        'reference_id' => $achievement->id,
                        'claimed_at' => self::resolveClaimedAt($leaderboard, (int) $finalCoins, (int) $finalXP),
                    ]);
                }
            } elseif ($transaction) {
                $transaction->delete();
            }

            self::recalculateStudentState($student->id, $leaderboard->id);
            self::updateStudentStreak($student, $date, $leaderboard);
            self::syncStudentBadges($student->id, $leaderboard->id);
        }
    }

    /**
     * Sync transaction for Attendance.
     */
    public static function syncStudentAttendanceXP(Attendance $attendance): void
    {
        $student = $attendance->student;
        $leaderboards = self::getActiveLeaderboards($student, $attendance->date);

        foreach ($leaderboards as $leaderboard) {
            $settings = $leaderboard->settings ?? [];
            $xpPoints = 0;
            $coinPoints = 0;
            $desc = '';

            if ($settings['attendance_enabled'] ?? false) {
                if ($attendance->status === 'present') {
                    $xpPts = ($settings['attendance_present_xp'] ?? ($settings['attendance_present'] ?? 4));
                    $coinPts = ($settings['attendance_present_coins'] ?? ($settings['attendance_present'] ?? 4));
                    $xpPoints = $xpPts;
                    $coinPoints = $coinPts;
                    $desc = "حضور الحلقة (+{$xpPts} XP، +{$coinPts} عملة)";
                } elseif ($attendance->status === 'late') {
                    $xpPts = ($settings['attendance_late_xp'] ?? ($settings['attendance_late'] ?? 2));
                    $coinPts = ($settings['attendance_late_coins'] ?? ($settings['attendance_late'] ?? 2));
                    $xpPoints = $xpPts;
                    $coinPoints = $coinPts;
                    $desc = "حضور الحلقة متأخراً (+{$xpPts} XP، +{$coinPts} عملة)";
                }
            }

            // Apply active multipliers (if any)
            $multiplier = self::getMultiplierForStudent($student, $leaderboard->id, $attendance->date);
            $multiplier = min(2, $multiplier);

            $finalXP = $xpPoints * $multiplier;
            $finalCoins = $coinPoints * $multiplier;

            $transaction = GamificationTransaction::where('leaderboard_id', $leaderboard->id)
                ->where('student_id', $student->id)
                ->where('reference_type', Attendance::class)
                ->where('reference_id', $attendance->id)
                ->first();

            if ($finalXP > 0 || $finalCoins > 0) {
                if ($multiplier > 1) {
                    $desc .= ' [مضاعف النقاط نشط!]';
                }

                if ($transaction) {
                    $transaction->update([
                        'amount' => $finalCoins,
                        'xp_amount' => $finalXP,
                        'description' => $desc,
                    ]);
                } else {
                    GamificationTransaction::create([
                        'leaderboard_id' => $leaderboard->id,
                        'student_id' => $student->id,
                        'type' => 'earn',
                        'amount' => $finalCoins,
                        'xp_amount' => $finalXP,
                        'description' => $desc,
                        'reference_type' => Attendance::class,
                        'reference_id' => $attendance->id,
                        'claimed_at' => self::resolveClaimedAt($leaderboard, (int) $finalCoins, (int) $finalXP),
                    ]);
                }
            } else {
                if ($transaction) {
                    $transaction->delete();
                }
            }

            self::recalculateStudentState($student->id, $leaderboard->id);
            self::updateStudentStreak($student, $attendance->date, $leaderboard);
            self::syncStudentBadges($student->id, $leaderboard->id);
        }
    }

    /**
     * Sync transaction for custom criteria.
     */
    public static function syncStudentCustomCriterionXP(LeaderboardScore $score): void
    {
        $student = $score->student;
        $leaderboard = $score->leaderboard;

        if ($leaderboard->competition_type !== 'gamification') {
            return;
        }

        $criterion = $score->criterion;
        $xpPoints = (int) $criterion->points;
        $coinPoints = (int) (($criterion->coins ?? 0) > 0 ? $criterion->coins : $criterion->points);

        // Apply active multipliers (if any)
        $multiplier = self::getMultiplierForStudent($student, $leaderboard->id, $score->date);
        $multiplier = min(2, $multiplier);

        $finalXP = $xpPoints * $multiplier;
        $finalCoins = $coinPoints * $multiplier;

        $transaction = GamificationTransaction::where('leaderboard_id', $leaderboard->id)
            ->where('student_id', $student->id)
            ->where('reference_type', LeaderboardScore::class)
            ->where('reference_id', $score->id)
            ->first();

        if ($finalXP > 0 || $finalCoins > 0) {
            $desc = "تقييم بند مخصص: {$criterion->name} (+{$finalXP} XP، +{$finalCoins} عملة)";
            if ($multiplier > 1) {
                $desc .= ' [مضاعف النقاط نشط!]';
            }

            if ($transaction) {
                $transaction->update([
                    'amount' => $finalCoins,
                    'xp_amount' => $finalXP,
                    'description' => $desc,
                ]);
            } else {
                GamificationTransaction::create([
                    'leaderboard_id' => $leaderboard->id,
                    'student_id' => $student->id,
                    'type' => 'earn',
                    'amount' => $finalCoins,
                    'xp_amount' => $finalXP,
                    'description' => $desc,
                    'reference_type' => LeaderboardScore::class,
                    'reference_id' => $score->id,
                    'claimed_at' => self::resolveClaimedAt($leaderboard, (int) $finalCoins, (int) $finalXP),
                ]);
            }
        } else {
            if ($transaction) {
                $transaction->delete();
            }
        }

        self::recalculateStudentState($student->id, $leaderboard->id);
        self::updateStudentStreak($student, $score->date, $leaderboard);
        self::syncStudentBadges($student->id, $leaderboard->id);
    }

    /**
     * Sync transaction for extra points.
     */
    public static function syncStudentExtraPointsXP(int $extraPointId): void
    {
        $extraPoint = DB::table('leaderboard_extra_points')->where('id', $extraPointId)->first();
        if (! $extraPoint) {
            // If deleted, delete corresponding transaction
            $transaction = GamificationTransaction::where('reference_type', 'leaderboard_extra_points')
                ->where('reference_id', $extraPointId)
                ->first();

            if ($transaction) {
                $studentId = $transaction->student_id;
                $leaderboardId = $transaction->leaderboard_id;
                $transaction->delete();
                self::recalculateStudentState($studentId, $leaderboardId);
            }

            return;
        }

        $leaderboard = Leaderboard::find($extraPoint->leaderboard_id);
        if (! $leaderboard || $leaderboard->competition_type !== 'gamification') {
            return;
        }

        $student = Student::find($extraPoint->student_id);
        if (! $student) {
            return;
        }

        $points = (int) $extraPoint->points;

        $transaction = GamificationTransaction::where('leaderboard_id', $leaderboard->id)
            ->where('student_id', $student->id)
            ->where('reference_type', 'leaderboard_extra_points')
            ->where('reference_id', $extraPointId)
            ->first();

        if ($points !== 0) {
            $desc = 'نقاط إضافية من المعلم: '.($extraPoint->notes ?: 'بدون ملاحظات')." ($points)";

            // Always an "earn" transaction: a negative value is a teacher deduction that
            // must reduce both the student's coins and their XP/standing (not coins only).
            if ($transaction) {
                $transaction->update([
                    'amount' => $points,
                    'xp_amount' => $points,
                    'type' => 'earn',
                    'description' => $desc,
                ]);
            } else {
                GamificationTransaction::create([
                    'leaderboard_id' => $leaderboard->id,
                    'student_id' => $student->id,
                    'type' => 'earn',
                    'amount' => $points,
                    'xp_amount' => $points,
                    'description' => $desc,
                    'reference_type' => 'leaderboard_extra_points',
                    'reference_id' => $extraPointId,
                    'claimed_at' => self::resolveClaimedAt($leaderboard, (int) $points, (int) $points),
                ]);
            }
        } else {
            if ($transaction) {
                $transaction->delete();
            }
        }

        self::recalculateStudentState($student->id, $leaderboard->id);
    }

    /**
     * Streak calculation engine.
     *
     * @param  mixed  $date
     */
    /**
     * An enthusiasm day requires EVERY enabled condition (attendance, achievement,
     * custom criteria) to be met that day — full AND. At least one condition must
     * be enabled, otherwise there is no enthusiasm day at all.
     */
    private static function enthusiasmAllConditionsMet(
        bool $attendanceEnabled,
        bool $attendanceMet,
        bool $achievementEnabled,
        bool $achievementMet,
        bool $customEnabled,
        bool $customMet,
    ): bool {
        $enabled = 0;

        if ($attendanceEnabled) {
            $enabled++;
            if (! $attendanceMet) {
                return false;
            }
        }
        if ($achievementEnabled) {
            $enabled++;
            if (! $achievementMet) {
                return false;
            }
        }
        if ($customEnabled) {
            $enabled++;
            if (! $customMet) {
                return false;
            }
        }

        return $enabled > 0;
    }

    /**
     * Check if the student achieved the enthusiasm criteria on a specific date.
     */
    public static function checkEnthusiasmForDate(Student $student, string $dateStr, Leaderboard $leaderboard): bool
    {
        $settings = $leaderboard->settings ?? [];
        if (! ($settings['enthusiasm_enabled'] ?? false)) {
            return false;
        }

        $hifzTrigger = (bool) ($settings['hifz_enthusiasm_trigger'] ?? true);
        $reviewTrigger = (bool) ($settings['review_enthusiasm_trigger'] ?? true);
        $attendanceTrigger = (bool) ($settings['attendance_enthusiasm_trigger'] ?? true);
        $odeHifzTrigger = (bool) ($settings['ode_hifz_enthusiasm_trigger'] ?? false);
        $odeReviewTrigger = (bool) ($settings['ode_review_enthusiasm_trigger'] ?? false);
        $hadithHifzTrigger = (bool) ($settings['hadith_hifz_enthusiasm_trigger'] ?? false);
        $hadithReviewTrigger = (bool) ($settings['hadith_review_enthusiasm_trigger'] ?? false);

        // 1. Attendance Trigger
        $attendanceMet = false;
        if ($attendanceTrigger) {
            $attendance = Attendance::where('student_id', $student->id)
                ->whereDate('date', $dateStr)
                ->first();
            if ($attendance && in_array($attendance->status, ['present', 'late'])) {
                $attendanceMet = true;
            }
        }

        // 2. Achievements Trigger (Hifz / Review)
        $achievementMet = false;
        if ($hifzTrigger || $reviewTrigger) {
            $dayPlan = StudentPlanDay::whereHas('plan', function ($q) use ($student) {
                $q->where('student_id', $student->id)->where('is_approved', 1);
            })
                ->where(function ($q) use ($dateStr) {
                    $q->whereDate('hifz_graded_at', $dateStr)
                        ->orWhereDate('review_graded_at', $dateStr)
                        ->orWhere(function ($sub) use ($dateStr) {
                            $sub->whereNull('hifz_graded_at')
                                ->whereNull('review_graded_at')
                                ->whereDate('date', $dateStr)
                                ->where(function ($s) {
                                    $s->whereNotNull('hifz_achievement')
                                        ->orWhereNotNull('review_achievement');
                                });
                        });
                })
                ->get();

            if ($dayPlan->isNotEmpty()) {
                foreach ($dayPlan as $dp) {
                    $hifzAch = $dp->hifz_achievement;
                    $revAch = $dp->review_achievement;

                    $hifzOk = $hifzTrigger && $hifzAch !== null && in_array($hifzAch, [1, 2, 3, '1', '2', '3', 'excellent', 'good', 'acceptable']);
                    $revOk = $reviewTrigger && $revAch !== null && in_array($revAch, [1, 2, 3, '1', '2', '3', 'excellent', 'good', 'acceptable']);

                    if ($hifzOk || $revOk) {
                        $achievementMet = true;
                        break;
                    }
                }
            }
        }

        // 2b. Ode & Hadith Achievement Trigger
        if (! $achievementMet && ($odeHifzTrigger || $odeReviewTrigger)) {
            $odeAch = StudentOdeAchievement::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
                ->where(function ($q) use ($dateStr) {
                    $q->whereDate('hifz_graded_at', $dateStr)
                        ->orWhereDate('review_graded_at', $dateStr)
                        ->orWhere(fn ($sub) => $sub->whereNull('hifz_graded_at')->whereNull('review_graded_at')
                            ->whereHas('pathDay', fn ($pd) => $pd->whereDate('date', $dateStr))
                            ->where(fn ($s) => $s->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement')));
                })->first();
            if ($odeAch) {
                $hifzOk = $odeHifzTrigger && $odeAch->hifz_achievement !== null;
                $revOk = $odeReviewTrigger && $odeAch->review_achievement !== null;
                if ($hifzOk || $revOk) {
                    $achievementMet = true;
                }
            }
        }

        if (! $achievementMet && ($hadithHifzTrigger || $hadithReviewTrigger)) {
            $hadithAch = StudentHadithAchievement::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
                ->where(function ($q) use ($dateStr) {
                    $q->whereDate('hifz_graded_at', $dateStr)
                        ->orWhereDate('review_graded_at', $dateStr)
                        ->orWhere(fn ($sub) => $sub->whereNull('hifz_graded_at')->whereNull('review_graded_at')
                            ->whereHas('pathDay', fn ($pd) => $pd->whereDate('date', $dateStr))
                            ->where(fn ($s) => $s->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement')));
                })->first();
            if ($hadithAch) {
                $hifzOk = $hadithHifzTrigger && $hadithAch->hifz_achievement !== null;
                $revOk = $hadithReviewTrigger && $hadithAch->review_achievement !== null;
                if ($hifzOk || $revOk) {
                    $achievementMet = true;
                }
            }
        }

        // 3. Custom Criteria Trigger
        $customMet = false;
        $customCriteriaIds = LeaderboardCriterion::where('leaderboard_id', $leaderboard->id)
            ->where('is_enthusiasm_trigger', true)
            ->pluck('id')
            ->toArray();

        if (! empty($customCriteriaIds)) {
            // Every criterion marked as an enthusiasm trigger is a required condition:
            // the student must have scored ALL of them on this date (AND), not just one.
            $scoredCriteriaCount = LeaderboardScore::where('leaderboard_id', $leaderboard->id)
                ->where('student_id', $student->id)
                ->whereIn('leaderboard_criterion_id', $customCriteriaIds)
                ->whereDate('date', $dateStr)
                ->pluck('leaderboard_criterion_id')
                ->unique()
                ->count();
            $customMet = $scoredCriteriaCount === count($customCriteriaIds);
        }

        $achievementEnabled = $hifzTrigger || $reviewTrigger || $odeHifzTrigger || $odeReviewTrigger || $hadithHifzTrigger || $hadithReviewTrigger;

        return self::enthusiasmAllConditionsMet(
            $attendanceTrigger, $attendanceMet,
            $achievementEnabled, $achievementMet,
            ! empty($customCriteriaIds), $customMet,
        );
    }

    /**
     * Get enthusiasm details for all dates in a range for a student in a leaderboard (Batch optimized).
     *
     * @return array<string, bool> Map of date string to enthusiasm status (true/false)
     */
    public static function getEnthusiasmMapForRange(Student $student, string $startDate, string $endDate, Leaderboard $leaderboard): array
    {
        $settings = $leaderboard->settings ?? [];
        if (! ($settings['enthusiasm_enabled'] ?? false)) {
            return [];
        }

        $startDate = Carbon::parse($startDate)->startOfDay()->toDateTimeString();
        $endDate = Carbon::parse($endDate)->endOfDay()->toDateTimeString();

        $hifzTrigger = (bool) ($settings['hifz_enthusiasm_trigger'] ?? true);
        $reviewTrigger = (bool) ($settings['review_enthusiasm_trigger'] ?? true);
        $attendanceTrigger = (bool) ($settings['attendance_enthusiasm_trigger'] ?? true);
        $odeHifzTrigger = (bool) ($settings['ode_hifz_enthusiasm_trigger'] ?? false);
        $odeReviewTrigger = (bool) ($settings['ode_review_enthusiasm_trigger'] ?? false);
        $hadithHifzTrigger = (bool) ($settings['hadith_hifz_enthusiasm_trigger'] ?? false);
        $hadithReviewTrigger = (bool) ($settings['hadith_review_enthusiasm_trigger'] ?? false);

        // Fetch attendances in range
        $attendances = Attendance::where('student_id', $student->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->groupBy(fn ($a) => Carbon::parse($a->date)->format('Y-m-d'));

        // Fetch plan days in range
        $planDays = StudentPlanDay::whereHas('plan', function ($q) use ($student) {
            $q->where('student_id', $student->id)->where('is_approved', 1);
        })
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('date', [$startDate, $endDate])
                    ->orWhereBetween('hifz_graded_at', [$startDate, $endDate])
                    ->orWhereBetween('review_graded_at', [$startDate, $endDate]);
            })
            ->get();

        $evalPlanDays = [];
        foreach ($planDays as $dp) {
            $hifzDate = $dp->hifz_graded_at ? Carbon::parse($dp->hifz_graded_at)->format('Y-m-d') : null;
            $revDate = $dp->review_graded_at ? Carbon::parse($dp->review_graded_at)->format('Y-m-d') : null;
            $schedDate = Carbon::parse($dp->date)->format('Y-m-d');

            if ($dp->hifz_achievement !== null) {
                $date = $hifzDate ?: $schedDate;
                $evalPlanDays[$date][] = ['type' => 'hifz', 'achievement' => $dp->hifz_achievement];
            }
            if ($dp->review_achievement !== null) {
                $date = $revDate ?: $schedDate;
                $evalPlanDays[$date][] = ['type' => 'review', 'achievement' => $dp->review_achievement];
            }
        }

        // Fetch ode achievements in range
        $evalOdeDays = [];
        if ($odeHifzTrigger || $odeReviewTrigger) {
            $odeAchs = StudentOdeAchievement::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('hifz_graded_at', [$startDate, $endDate])
                        ->orWhereBetween('review_graded_at', [$startDate, $endDate])
                        ->orWhereHas('pathDay', fn ($pd) => $pd->whereBetween('date', [$startDate, $endDate]));
                })->with('pathDay')->get();

            foreach ($odeAchs as $oa) {
                $schedDate = $oa->pathDay ? Carbon::parse($oa->pathDay->date)->format('Y-m-d') : null;
                if ($oa->hifz_achievement !== null) {
                    $date = $oa->hifz_graded_at ? Carbon::parse($oa->hifz_graded_at)->format('Y-m-d') : $schedDate;
                    if ($date) {
                        $evalOdeDays[$date][] = ['type' => 'hifz', 'achievement' => $oa->hifz_achievement];
                    }
                }
                if ($oa->review_achievement !== null) {
                    $date = $oa->review_graded_at ? Carbon::parse($oa->review_graded_at)->format('Y-m-d') : $schedDate;
                    if ($date) {
                        $evalOdeDays[$date][] = ['type' => 'review', 'achievement' => $oa->review_achievement];
                    }
                }
            }
        }

        // Fetch hadith achievements in range
        $evalHadithDays = [];
        if ($hadithHifzTrigger || $hadithReviewTrigger) {
            $hadithAchs = StudentHadithAchievement::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('hifz_graded_at', [$startDate, $endDate])
                        ->orWhereBetween('review_graded_at', [$startDate, $endDate])
                        ->orWhereHas('pathDay', fn ($pd) => $pd->whereBetween('date', [$startDate, $endDate]));
                })->with('pathDay')->get();

            foreach ($hadithAchs as $ha) {
                $schedDate = $ha->pathDay ? Carbon::parse($ha->pathDay->date)->format('Y-m-d') : null;
                if ($ha->hifz_achievement !== null) {
                    $date = $ha->hifz_graded_at ? Carbon::parse($ha->hifz_graded_at)->format('Y-m-d') : $schedDate;
                    if ($date) {
                        $evalHadithDays[$date][] = ['type' => 'hifz', 'achievement' => $ha->hifz_achievement];
                    }
                }
                if ($ha->review_achievement !== null) {
                    $date = $ha->review_graded_at ? Carbon::parse($ha->review_graded_at)->format('Y-m-d') : $schedDate;
                    if ($date) {
                        $evalHadithDays[$date][] = ['type' => 'review', 'achievement' => $ha->review_achievement];
                    }
                }
            }
        }

        // Fetch custom scores in range
        $customCriteriaIds = LeaderboardCriterion::where('leaderboard_id', $leaderboard->id)
            ->where('is_enthusiasm_trigger', true)
            ->pluck('id')
            ->toArray();

        // A date counts only when the student scored EVERY enthusiasm criterion that
        // day (AND), not merely one of them.
        $customMetDates = [];
        if (! empty($customCriteriaIds)) {
            $requiredCount = count($customCriteriaIds);
            $scoredByDate = [];
            LeaderboardScore::where('leaderboard_id', $leaderboard->id)
                ->where('student_id', $student->id)
                ->whereIn('leaderboard_criterion_id', $customCriteriaIds)
                ->whereBetween('date', [$startDate, $endDate])
                ->get(['leaderboard_criterion_id', 'date'])
                ->each(function ($score) use (&$scoredByDate) {
                    $d = Carbon::parse($score->date)->format('Y-m-d');
                    $scoredByDate[$d][$score->leaderboard_criterion_id] = true;
                });

            foreach ($scoredByDate as $d => $criteria) {
                if (count($criteria) === $requiredCount) {
                    $customMetDates[$d] = true;
                }
            }
        }

        // Loop through each day in the range and evaluate from memory
        $map = [];
        $current = Carbon::parse($startDate)->copy();
        $end = Carbon::parse($endDate);

        while ($current->lte($end)) {
            $dateStr = $current->format('Y-m-d');

            // 1. Attendance Met
            $attendanceMet = false;
            if ($attendanceTrigger) {
                $atts = $attendances->get($dateStr);
                if ($atts) {
                    foreach ($atts as $att) {
                        if (in_array($att->status, ['present', 'late'])) {
                            $attendanceMet = true;
                            break;
                        }
                    }
                }
            }

            // 2. Achievements Met
            $achievementMet = false;
            if ($hifzTrigger || $reviewTrigger) {
                $dayEvals = $evalPlanDays[$dateStr] ?? [];
                if (! empty($dayEvals)) {
                    foreach ($dayEvals as $ev) {
                        $ach = $ev['achievement'];
                        $type = $ev['type'];

                        if ($type === 'hifz' && $hifzTrigger) {
                            $ok = $ach !== null && in_array($ach, [1, 2, 3, '1', '2', '3', 'excellent', 'good', 'acceptable']);
                        } elseif ($type === 'review' && $reviewTrigger) {
                            $ok = $ach !== null && in_array($ach, [1, 2, 3, '1', '2', '3', 'excellent', 'good', 'acceptable']);
                        } else {
                            $ok = false;
                        }

                        if ($ok) {
                            $achievementMet = true;
                            break;
                        }
                    }
                }
            }

            if (! $achievementMet && ($odeHifzTrigger || $odeReviewTrigger)) {
                foreach ($evalOdeDays[$dateStr] ?? [] as $ev) {
                    $ok = ($ev['type'] === 'hifz' && $odeHifzTrigger && $ev['achievement'] !== null)
                        || ($ev['type'] === 'review' && $odeReviewTrigger && $ev['achievement'] !== null);
                    if ($ok) {
                        $achievementMet = true;
                        break;
                    }
                }
            }

            if (! $achievementMet && ($hadithHifzTrigger || $hadithReviewTrigger)) {
                foreach ($evalHadithDays[$dateStr] ?? [] as $ev) {
                    $ok = ($ev['type'] === 'hifz' && $hadithHifzTrigger && $ev['achievement'] !== null)
                        || ($ev['type'] === 'review' && $hadithReviewTrigger && $ev['achievement'] !== null);
                    if ($ok) {
                        $achievementMet = true;
                        break;
                    }
                }
            }

            // 3. Custom Met (all enthusiasm criteria scored that day)
            $customMet = isset($customMetDates[$dateStr]);

            // Evaluate: every enabled condition must be met (full AND).
            $achievementEnabled = $hifzTrigger || $reviewTrigger || $odeHifzTrigger || $odeReviewTrigger || $hadithHifzTrigger || $hadithReviewTrigger;
            $triggered = self::enthusiasmAllConditionsMet(
                $attendanceTrigger, $attendanceMet,
                $achievementEnabled, $achievementMet,
                ! empty($customCriteriaIds), $customMet,
            );

            $map[$dateStr] = $triggered;

            $current->addDay();
        }

        return $map;
    }

    /**
     * Streak calculation engine.
     *
     * @param  mixed  $date
     */
    public static function updateStudentStreak(Student $student, $date, Leaderboard $leaderboard): void
    {
        self::recalculateStudentStreak($student, $leaderboard);
    }

    /**
     * Get all working days for a leaderboard.
     *
     * A competition may span stages that keep different schedules, so pass the
     * stage whenever the answer is about one student.
     *
     * @return array<string> List of date strings (Y-m-d)
     */
    public static function getWorkingDaysForLeaderboard(Leaderboard $leaderboard, ?int $stageId = null): array
    {
        // An open-ended competition is measured up to today.
        return AcademicCalendarEvent::workingDaysBetween(
            Carbon::parse($leaderboard->start_date),
            Carbon::parse($leaderboard->end_date),
            $stageId,
        );
    }

    /**
     * Recalculate student streak and milestones chronologically.
     */
    public static function recalculateStudentStreak(Student $student, Leaderboard $leaderboard): void
    {
        $settings = $leaderboard->settings ?? [];
        if (! ($settings['enthusiasm_enabled'] ?? false)) {
            return;
        }

        $startDateStr = Carbon::parse($leaderboard->start_date)->format('Y-m-d');
        $endDateStr = Carbon::parse($leaderboard->end_date)->format('Y-m-d');

        // Fetch enthusiasm map with full time bounds
        $enthusiasmMap = self::getEnthusiasmMapForRange($student, $startDateStr, $endDateStr, $leaderboard);

        // Get working days for the leaderboard
        $workingDaysDates = self::getWorkingDaysForLeaderboard($leaderboard, $student->effective_stage_id);

        // Get all approved purchases of streak freezes with target_date
        $freezesPurchasedDates = GamificationStorePurchase::where('student_id', $student->id)
            ->where('status', 'approved')
            ->whereHas('item', fn ($q) => $q->where('is_streak_freeze', true))
            ->whereNotNull('target_date')
            ->pluck('target_date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->toArray();

        // 1. Get previously claimed milestone IDs that are already 'claimed'
        $previouslyClaimedMilestoneIds = DB::table('gamification_claimed_milestones')
            ->where('student_id', $student->id)
            ->where('status', 'claimed')
            ->pluck('milestone_id')
            ->toArray();

        // 2. Delete all claimed milestone records for this student on this leaderboard
        $milestoneIds = DB::table('gamification_streak_milestones')
            ->where('leaderboard_id', $leaderboard->id)
            ->pluck('id')
            ->toArray();

        DB::table('gamification_claimed_milestones')
            ->where('student_id', $student->id)
            ->whereIn('milestone_id', $milestoneIds)
            ->delete();

        // 3. Delete all milestone transactions for this student on this leaderboard
        GamificationTransaction::where('leaderboard_id', $leaderboard->id)
            ->where('student_id', $student->id)
            ->where('type', 'earn')
            ->where('description', 'like', 'مكافأة أيام الحماسة لـ%')
            ->delete();

        // 4. Delete all freeze consumption transactions to recreate them (deprecated, but keep cleanup for old logs)
        GamificationTransaction::where('leaderboard_id', $leaderboard->id)
            ->where('student_id', $student->id)
            ->where('type', 'spend')
            ->where('description', 'like', 'استهلاك عدد (%')
            ->delete();

        // Simulation state
        $currentStreak = 0;
        $maxStreak = 0;
        $lastActivityDate = null;
        $claimedInCurrentRun = [];

        $milestones = DB::table('gamification_streak_milestones')
            ->where('leaderboard_id', $leaderboard->id)
            ->orderBy('days_required', 'asc')
            ->get();

        foreach ($workingDaysDates as $dateStr) {
            $triggered = $enthusiasmMap[$dateStr] ?? false;
            $isFrozen = in_array($dateStr, $freezesPurchasedDates);

            if ($triggered || $isFrozen) {
                if ($lastActivityDate === null) {
                    $currentStreak = 1;
                    $lastActivityDate = $dateStr;
                    $claimedInCurrentRun = [];
                } else {
                    $currIdx = array_search($dateStr, $workingDaysDates);
                    $lastIdx = array_search($lastActivityDate, $workingDaysDates);
                    $diffInWorkingDays = ($currIdx !== false && $lastIdx !== false) ? ($currIdx - $lastIdx) : 1;

                    if ($diffInWorkingDays === 1) {
                        $currentStreak++;
                        $lastActivityDate = $dateStr;
                    } else {
                        // Streak broken
                        $currentStreak = 1;
                        $lastActivityDate = $dateStr;
                        $claimedInCurrentRun = [];
                    }
                }

                if ($currentStreak > $maxStreak) {
                    $maxStreak = $currentStreak;
                }

                // Check and award milestones for the current streak on this day
                foreach ($milestones as $milestone) {
                    if ($milestone->days_required <= $currentStreak && ! in_array($milestone->id, $claimedInCurrentRun)) {
                        $isPreviouslyClaimed = in_array($milestone->id, $previouslyClaimedMilestoneIds);

                        DB::table('gamification_claimed_milestones')->insert([
                            'student_id' => $student->id,
                            'milestone_id' => $milestone->id,
                            'streak_run_count' => $currentStreak,
                            'status' => $isPreviouslyClaimed ? 'claimed' : 'approved',
                            'created_at' => Carbon::parse($dateStr)->toDateTimeString(),
                            'updated_at' => Carbon::parse($dateStr)->toDateTimeString(),
                        ]);

                        if ($isPreviouslyClaimed) {
                            if ($milestone->reward_xp > 0 || $milestone->reward_coins > 0) {
                                GamificationTransaction::create([
                                    'leaderboard_id' => $leaderboard->id,
                                    'student_id' => $student->id,
                                    'type' => 'earn',
                                    'amount' => $milestone->reward_coins,
                                    'xp_amount' => $milestone->reward_xp,
                                    'description' => "مكافأة أيام الحماسة لـ {$milestone->days_required} أيام متتالية: {$milestone->description}",
                                    'created_at' => Carbon::parse($dateStr)->toDateTimeString(),
                                    'updated_at' => Carbon::parse($dateStr)->toDateTimeString(),
                                ]);
                            }
                        }

                        if ($milestone->reward_badge_id) {
                            $hasBadge = DB::table('gamification_badge_student')
                                ->where('badge_id', $milestone->reward_badge_id)
                                ->where('student_id', $student->id)
                                ->exists();

                            if (! $hasBadge) {
                                DB::table('gamification_badge_student')->insert([
                                    'badge_id' => $milestone->reward_badge_id,
                                    'student_id' => $student->id,
                                    'status' => 'pending_approval',
                                    'created_at' => Carbon::parse($dateStr)->toDateTimeString(),
                                    'updated_at' => Carbon::parse($dateStr)->toDateTimeString(),
                                ]);
                            }
                        }

                        $claimedInCurrentRun[] = $milestone->id;
                    }
                }
            }
        }

        // Save simulated state
        $state = GamificationStudentState::firstOrNew([
            'leaderboard_id' => $leaderboard->id,
            'student_id' => $student->id,
        ]);

        $state->current_streak = $currentStreak;
        $state->max_streak = $maxStreak;
        $state->last_activity_date = $lastActivityDate ? Carbon::parse($lastActivityDate) : null;
        $state->streak_freezes_count = count($freezesPurchasedDates);
        $state->save();

        self::recalculateStudentState($student->id, $leaderboard->id);
    }

    /**
     * Resolve the student's daily team-donation allowance.
     *
     * The daily limit is a percentage (configured per level) of the student's coin
     * balance at the START of the current day. Coins earned or spent during the same
     * day do not change this base, so the allowance is stable throughout the day.
     *
     * @return array{has_donation: bool, percentage: int, base: int, limit: int, donated: int, remaining: int}
     */
    public static function getDailyDonationStatus(int $studentId, int $leaderboardId): array
    {
        // Read the donation rules from the student's ACTUAL current level
        // (getStudentLevel returns the resolved level object under 'current').
        $currentLevel = self::getStudentLevel($studentId, $leaderboardId)['current'] ?? null;
        $levelSettings = $currentLevel->settings ?? [];
        $hasDonation = (bool) ($levelSettings['has_donation'] ?? true);
        $percentage = (int) ($levelSettings['donation_max_limit'] ?? 10);

        $todayStart = Carbon::today()->startOfDay();
        $todayEnd = Carbon::today()->endOfDay();

        // Coin balance as it stood at the start of today (all claimed transactions before today).
        $base = (int) GamificationTransaction::where('student_id', $studentId)
            ->where('leaderboard_id', $leaderboardId)
            ->whereNull('team_id')
            ->claimed()
            ->where('created_at', '<', $todayStart)
            ->sum('amount');
        $base = max(0, $base);

        $limit = (int) floor(($percentage / 100) * $base);

        $donated = (int) abs(GamificationTransaction::where('student_id', $studentId)
            ->where('leaderboard_id', $leaderboardId)
            ->where('type', 'spend')
            ->where('description', 'like', 'تبرع لخزينة الفريق:%')
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->sum('amount'));

        return [
            'has_donation' => $hasDonation,
            'percentage' => $percentage,
            'base' => $base,
            'limit' => $limit,
            'donated' => $donated,
            'remaining' => max(0, $limit - $donated),
        ];
    }

    /**
     * Donate coins to team.
     */
    public static function donateCoinsToTeam(int $studentId, int $teamId, int $amount, &$error = null): bool
    {
        if ($amount <= 0) {
            $error = 'الرجاء إدخال مبلغ صحيح للتبرع.';

            return false;
        }

        $student = Student::findOrFail($studentId);
        $team = GamificationTeam::findOrFail($teamId);
        $leaderboardId = $team->leaderboard_id;

        $status = self::getDailyDonationStatus($studentId, $leaderboardId);

        if (! $status['has_donation']) {
            $error = 'ميزة التبرع للفريق غير مفعلة لمستواك الحالي.';

            return false;
        }

        if ($status['donated'] >= $status['limit']) {
            $error = "لقد وصلت للحد الأقصى للتبرع اليومي المسموح به لمستواك وهو ({$status['limit']} عملة - يعادل {$status['percentage']}% من رصيد عملاتك في بداية اليوم: {$status['base']} عملة).";

            return false;
        }

        if ($status['limit'] < $status['donated'] + $amount) {
            $error = "المبلغ المتبقي المسموح لك بالتبرع به اليوم هو {$status['remaining']} عملة فقط (نسبة {$status['percentage']}% من رصيد عملاتك في بداية اليوم تعادل {$status['limit']} عملة).";

            return false;
        }

        $state = GamificationStudentState::where('leaderboard_id', $leaderboardId)
            ->where('student_id', $studentId)
            ->first();

        if (! $state || $state->coins < $amount) {
            $error = 'رصيدك الحالي غير كافٍ لإتمام التبرع.';

            return false;
        }

        DB::transaction(function () use ($studentId, $team, $amount, $leaderboardId) {
            GamificationTransaction::create([
                'leaderboard_id' => $leaderboardId,
                'student_id' => $studentId,
                'type' => 'spend',
                'amount' => -$amount,
                'description' => "تبرع لخزينة الفريق: {$team->name}",
            ]);

            $team->coins += $amount;
            $team->save();

            GamificationTransaction::create([
                'leaderboard_id' => $leaderboardId,
                'team_id' => $team->id,
                'student_id' => $studentId,
                'type' => 'earn',
                'amount' => $amount,
                'xp_amount' => 0, // coins-only transfer; the donor already scored these points when earned
                'description' => 'تبرع من الطالب: '.Student::find($studentId)->name,
            ]);

            self::recalculateStudentState($studentId, $leaderboardId);
        });

        return true;
    }

    /**
     * Request store purchase.
     */
    public static function requestStorePurchase(int $studentId, int $itemId, ?int $targetTeamId = null, ?string $targetDate = null, ?int $customPrice = null): string
    {
        $student = Student::findOrFail($studentId);
        $item = GamificationStoreItem::findOrFail($itemId);
        $leaderboardId = $item->leaderboard_id;
        $price = $customPrice !== null ? $customPrice : $item->price;

        $team = $student->gamificationTeams()->where('leaderboard_id', $leaderboardId)->first();

        // Validation for target_team_id (team_attack)
        if ($item->item_type === 'team_attack') {
            if (! $targetTeamId) {
                return 'target_team_required';
            }
            $targetTeamExists = GamificationTeam::where('leaderboard_id', $leaderboardId)
                ->where('id', $targetTeamId)
                ->exists();
            if (! $targetTeamExists) {
                return 'invalid_target_team';
            }
            if ($team && $team->id === (int) $targetTeamId) {
                return 'cannot_attack_own_team';
            }
        }

        // Validation for target_date (multiplier, shield)
        if (in_array($item->item_type, ['multiplier', 'shield'])) {
            if (! $targetDate && ! $item->target_date) {
                return 'target_date_required';
            }
            $resolvedDate = $targetDate ?: ($item->target_date instanceof \DateTimeInterface ? $item->target_date->format('Y-m-d') : $item->target_date);
            $targetCarbonDate = Carbon::parse($resolvedDate)->startOfDay();
            $tomorrow = Carbon::now('Asia/Riyadh')->addDay()->startOfDay();
            if ($targetCarbonDate->lt($tomorrow)) {
                return 'invalid_target_date';
            }
            $targetDate = $resolvedDate;

            if ($item->item_type === 'multiplier') {
                if ($item->is_team_product) {
                    if (! $team) {
                        return 'must_be_in_team';
                    }
                    $existingPurchase = GamificationStorePurchase::whereIn('status', ['approved', 'pending_approval'])
                        ->where('team_id', $team->id)
                        ->whereHas('item', function ($q) {
                            $q->where('item_type', 'multiplier');
                        })
                        ->whereDate('target_date', $targetDate)
                        ->exists();
                } else {
                    $existingPurchase = GamificationStorePurchase::whereIn('status', ['approved', 'pending_approval'])
                        ->where('student_id', $studentId)
                        ->whereNull('team_id')
                        ->whereHas('item', function ($q) {
                            $q->where('item_type', 'multiplier');
                        })
                        ->whereDate('target_date', $targetDate)
                        ->exists();
                }

                if ($existingPurchase) {
                    return $item->is_team_product ? 'team_multiplier_already_purchased' : 'individual_multiplier_already_purchased';
                }
            }
        }

        // Validation for freeze (Streak Freeze) target date
        if ($item->item_type === 'freeze') {
            if (! $targetDate) {
                return 'target_date_required';
            }
            $targetDate = Carbon::parse($targetDate)->format('Y-m-d');
            $freezeableDates = self::getFreezeableDates($student, $item->leaderboard);
            if (! in_array($targetDate, $freezeableDates)) {
                return 'invalid_target_date';
            }

            $alreadyFrozen = GamificationStorePurchase::where('student_id', $studentId)
                ->where('status', 'approved')
                ->where('target_date', $targetDate)
                ->whereHas('item', fn ($q) => $q->where('is_streak_freeze', true))
                ->exists();

            if ($alreadyFrozen) {
                return 'already_frozen';
            }
        }

        if ($item->is_team_product) {
            if (! $team) {
                return 'must_be_in_team';
            }

            $role = $team->pivot->role;
            if ($role !== 'leader') {
                return 'only_leader';
            }

            if ($team->coins < $price) {
                return 'insufficient_team_coins';
            }

            $leaderboard = $item->leaderboard;
            $settings = $leaderboard->settings ?? [];
            $requiresVoting = $item->require_assistant_approval || $item->require_member_approval_count > 0;

            if (! $requiresVoting && ($settings['team_purchase_voting_enabled'] ?? false)) {
                $requiresVoting = true;
            }

            if ($requiresVoting) {
                $memberCount = $team->students()->count();
                if ($memberCount > 1) {
                    $purchase = DB::transaction(function () use ($team, $item, $studentId, $leaderboardId, $targetTeamId, $targetDate, $price) {
                        $team->coins -= $price;
                        $team->save();

                        $purchase = GamificationStorePurchase::create([
                            'store_item_id' => $item->id,
                            'student_id' => $studentId,
                            'team_id' => $team->id,
                            'status' => 'pending_approval',
                            'price_paid' => $price,
                            'target_team_id' => $targetTeamId,
                            'target_date' => $targetDate ? Carbon::parse($targetDate)->format('Y-m-d') : null,
                        ]);

                        GamificationTransaction::create([
                            'leaderboard_id' => $leaderboardId,
                            'team_id' => $team->id,
                            'student_id' => $studentId,
                            'type' => 'spend',
                            'amount' => -$price,
                            'description' => "حجز رصيد لشراء قيد التصويت: {$item->name}",
                            'reference_type' => GamificationStorePurchase::class,
                            'reference_id' => $purchase->id,
                        ]);

                        return $purchase;
                    });

                    DB::table('gamification_purchase_votes')->insert([
                        'store_purchase_id' => $purchase->id,
                        'student_id' => $studentId,
                        'vote' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    self::checkPurchaseVotingStatus($purchase->id);
                    $purchase->refresh();

                    if ($purchase->status === 'approved') {
                        return 'success';
                    }

                    return 'pending_voting';
                }
            }

            DB::transaction(function () use ($team, $item, $studentId, $leaderboardId, $targetTeamId, $targetDate, $price) {
                $team->coins -= $price;
                $team->save();

                $purchase = GamificationStorePurchase::create([
                    'store_item_id' => $item->id,
                    'student_id' => $studentId,
                    'team_id' => $team->id,
                    'status' => 'approved',
                    'price_paid' => $price,
                    'target_team_id' => $targetTeamId,
                    'target_date' => $targetDate ? Carbon::parse($targetDate)->format('Y-m-d') : null,
                ]);

                GamificationTransaction::create([
                    'leaderboard_id' => $leaderboardId,
                    'team_id' => $team->id,
                    'student_id' => $studentId,
                    'type' => 'spend',
                    'amount' => -$price,
                    'description' => "شراء من المتجر: {$item->name}",
                    'reference_type' => GamificationStorePurchase::class,
                    'reference_id' => $purchase->id,
                ]);

                self::executePurchase($purchase);
            });

            return 'success';
        } else {
            $state = GamificationStudentState::where('leaderboard_id', $leaderboardId)
                ->where('student_id', $studentId)
                ->first();

            if (! $state || $state->coins < $price) {
                return 'insufficient_coins';
            }

            DB::transaction(function () use ($item, $studentId, $leaderboardId, $targetTeamId, $targetDate, $price) {
                GamificationTransaction::create([
                    'leaderboard_id' => $leaderboardId,
                    'student_id' => $studentId,
                    'type' => 'spend',
                    'amount' => -$price,
                    'description' => "شراء من المتجر: {$item->name}",
                ]);

                $purchase = GamificationStorePurchase::create([
                    'store_item_id' => $item->id,
                    'student_id' => $studentId,
                    'status' => 'approved',
                    'price_paid' => $price,
                    'target_team_id' => $targetTeamId,
                    'target_date' => $targetDate ? Carbon::parse($targetDate)->format('Y-m-d') : null,
                ]);

                self::executePurchase($purchase);
                self::recalculateStudentState($studentId, $leaderboardId);
            });

            return 'success';
        }
    }

    /**
     * Check the voting status and approve/reject the purchase if conditions are met.
     */
    public static function checkPurchaseVotingStatus(int $purchaseId): void
    {
        $purchase = GamificationStorePurchase::findOrFail($purchaseId);
        if ($purchase->status !== 'pending_approval') {
            return;
        }

        $team = $purchase->team;
        $item = $purchase->item;
        $members = $team->students()->get();
        $totalMembersCount = $members->count();
        $assistants = $members->filter(fn ($s) => $s->pivot->role === 'assistant');

        $votes = DB::table('gamification_purchase_votes')->where('store_purchase_id', $purchaseId)->get();
        $yesVotesCount = $votes->where('vote', true)->count();
        $noVotesCount = $votes->where('vote', false)->count();

        // 1. Check if requirements are MET (Approved)
        $assistantApprovalMet = true;
        if ($item->require_assistant_approval && $assistants->isNotEmpty()) {
            // Check if any assistant voted true
            $assistantApprovalMet = $votes->whereIn('student_id', $assistants->pluck('id'))->where('vote', true)->isNotEmpty();
        }

        $threshold = $item->require_member_approval_count;
        if ($threshold <= 0 && ! $item->require_assistant_approval) {
            $threshold = intval($totalMembersCount / 2) + 1;
        }

        $memberApprovalMet = true;
        if ($threshold > 0) {
            $memberApprovalMet = $yesVotesCount >= $threshold;
        }

        if ($assistantApprovalMet && $memberApprovalMet) {
            DB::transaction(function () use ($purchase, $team) {
                if (! self::purchaseCoinsAlreadyHeld($purchase)) {
                    $team->coins -= $purchase->price_paid;
                    $team->save();

                    GamificationTransaction::create([
                        'leaderboard_id' => $team->leaderboard_id,
                        'team_id' => $team->id,
                        'student_id' => $purchase->student_id,
                        'type' => 'spend',
                        'amount' => -$purchase->price_paid,
                        'description' => "شراء معتمد بالتصويت: {$purchase->item->name}",
                        'reference_type' => GamificationStorePurchase::class,
                        'reference_id' => $purchase->id,
                    ]);
                }

                $purchase->update(['status' => 'approved']);

                self::executePurchase($purchase);
            });

            return;
        }

        // 2. Check if requirements CANNOT be met (Rejected)
        $rejected = false;

        // If assistant approval is required but all assistants voted false
        if ($item->require_assistant_approval && $assistants->isNotEmpty()) {
            $assistantYesVotes = $votes->whereIn('student_id', $assistants->pluck('id'))->where('vote', true)->count();
            $assistantNoVotes = $votes->whereIn('student_id', $assistants->pluck('id'))->where('vote', false)->count();
            if ($assistantYesVotes === 0 && $assistantNoVotes === $assistants->count()) {
                $rejected = true;
            }
        }

        // If member approval count is required but maximum possible yes votes is less than required
        if ($threshold > 0) {
            $remainingVotesCount = $totalMembersCount - ($yesVotesCount + $noVotesCount);
            if (($yesVotesCount + $remainingVotesCount) < $threshold) {
                $rejected = true;
            }
        }

        // Or if all members voted and requirements are not met
        if (($yesVotesCount + $noVotesCount) === $totalMembersCount) {
            if (! $assistantApprovalMet || ! $memberApprovalMet) {
                $rejected = true;
            }
        }

        if ($rejected) {
            DB::transaction(function () use ($purchase, $team) {
                if (self::purchaseCoinsAlreadyHeld($purchase)) {
                    $team->coins += $purchase->price_paid;
                    $team->save();

                    GamificationTransaction::create([
                        'leaderboard_id' => $team->leaderboard_id,
                        'team_id' => $team->id,
                        'student_id' => $purchase->student_id,
                        'type' => 'earn',
                        'amount' => $purchase->price_paid,
                        'description' => "استرداد رصيد شراء مرفوض بالتصويت: {$purchase->item->name}",
                        'reference_type' => GamificationStorePurchase::class,
                        'reference_id' => $purchase->id,
                    ]);
                }

                $purchase->update(['status' => 'rejected']);
            });
        }
    }

    /**
     * Whether the team's coins were already deducted for this purchase when the
     * voting request was created (purchases created before upfront holding was
     * introduced have no spend transaction yet).
     */
    protected static function purchaseCoinsAlreadyHeld(GamificationStorePurchase $purchase): bool
    {
        return GamificationTransaction::where('reference_type', GamificationStorePurchase::class)
            ->where('reference_id', $purchase->id)
            ->where('type', 'spend')
            ->exists();
    }

    /**
     * Vote for a pending purchase.
     */
    public static function voteForPurchase(int $studentId, int $purchaseId, bool $vote): bool
    {
        $purchase = GamificationStorePurchase::findOrFail($purchaseId);
        if ($purchase->status !== 'pending_approval') {
            return false;
        }

        $student = Student::findOrFail($studentId);
        $team = $purchase->team;

        if (! $team->students()->where('users.id', $studentId)->exists()) {
            return false;
        }

        DB::table('gamification_purchase_votes')->updateOrInsert(
            ['store_purchase_id' => $purchaseId, 'student_id' => $studentId],
            ['vote' => $vote, 'created_at' => now(), 'updated_at' => now()]
        );

        self::checkPurchaseVotingStatus($purchaseId);

        return true;
    }

    /**
     * Execute purchase effect.
     */
    public static function executePurchase(GamificationStorePurchase $purchase): void
    {
        $item = $purchase->item;
        $student = $purchase->student;
        $team = $purchase->team;
        $leaderboardId = $item->leaderboard_id;

        if ($item->item_type === 'team_points') {
            if ($team) {
                GamificationTransaction::create([
                    'leaderboard_id' => $leaderboardId,
                    'team_id' => $team->id,
                    'type' => 'earn',
                    'amount' => $item->value,
                    'description' => "تفعيل قوة ميزة دعم الفريق: +{$item->value} نقاط",
                ]);
            }
        } elseif ($item->is_streak_freeze) {
            $state = GamificationStudentState::firstOrCreate([
                'leaderboard_id' => $leaderboardId,
                'student_id' => $student->id,
            ]);
            $state->streak_freezes_count++;
            $state->save();
        } elseif ($item->item_type === 'team_attack') {
            if ($purchase->target_team_id) {
                $targetTeam = GamificationTeam::find($purchase->target_team_id);
                if ($targetTeam) {
                    $executionDate = Carbon::now('Asia/Riyadh')->format('Y-m-d');
                    $isShieldActive = self::isShieldActiveForTeam($targetTeam, $executionDate);

                    if ($isShieldActive) {
                        // Attack blocked by shield
                        GamificationTransaction::create([
                            'leaderboard_id' => $leaderboardId,
                            'team_id' => $targetTeam->id,
                            'type' => 'spend',
                            'amount' => 0,
                            'description' => 'تم صد هجوم خصم النقاط من فريق '.($purchase->team ? $purchase->team->name : 'فريق منافس').' بفضل درع الحماية!',
                        ]);

                        // News: the attacker is intentionally not named.
                        GamificationNewsService::record($leaderboardId, 'team_attack_blocked', [
                            'target_team_name' => $targetTeam->name,
                        ]);
                    } else {
                        // Deduct points/coins from target team treasury
                        $teamAttackValue = (int) $item->value;
                        $targetTeam->coins -= $teamAttackValue;
                        $targetTeam->save();

                        GamificationTransaction::create([
                            'leaderboard_id' => $leaderboardId,
                            'team_id' => $targetTeam->id,
                            'type' => 'spend',
                            'amount' => -$teamAttackValue,
                            'description' => 'خصم عملات بسبب هجوم من فريق '.($purchase->team ? $purchase->team->name : 'فريق منافس').": -{$teamAttackValue} عملة",
                        ]);

                        // News: the attacker is intentionally not named.
                        GamificationNewsService::record($leaderboardId, 'team_attack', [
                            'target_team_name' => $targetTeam->name,
                            'amount' => $teamAttackValue,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Cancel a store purchase and fully reverse its effect.
     *
     * Handles every product type: the paid price is always refunded to the buyer
     * (student wallet or team treasury), one-time effects (team support points and
     * team attacks) are undone with compensating transactions, and dynamic effects
     * (multiplier, shield, streak freeze) are reversed by re-syncing the affected
     * student's points so already-applied bonuses are recomputed as if the purchase
     * never happened.
     *
     * @return 'success'|'not_found'|'not_cancellable'
     */
    public static function cancelPurchase(int $purchaseId): string
    {
        $purchase = GamificationStorePurchase::with(['item', 'student', 'team'])->find($purchaseId);

        if (! $purchase || ! $purchase->item) {
            return 'not_found';
        }

        if (! in_array($purchase->status, ['approved', 'pending_approval'], true)) {
            return 'not_cancellable';
        }

        $item = $purchase->item;
        $leaderboardId = $item->leaderboard_id;
        $wasApproved = $purchase->status === 'approved';

        DB::transaction(function () use ($purchase, $item, $leaderboardId, $wasApproved): void {
            // 1. Reverse one-time effects that were applied on approval.
            if ($wasApproved) {
                if ($item->item_type === 'team_points' && $purchase->team_id) {
                    $team = $purchase->team;
                    if ($team) {
                        GamificationTransaction::create([
                            'leaderboard_id' => $leaderboardId,
                            'team_id' => $team->id,
                            'type' => 'spend',
                            'amount' => -(int) $item->value,
                            'description' => "إلغاء دعم الفريق: -{$item->value} نقاط",
                            'reference_type' => GamificationStorePurchase::class,
                            'reference_id' => $purchase->id,
                        ]);
                    }
                } elseif ($item->item_type === 'team_attack' && $purchase->target_team_id) {
                    $targetTeam = GamificationTeam::find($purchase->target_team_id);
                    if ($targetTeam) {
                        $attackValue = (int) $item->value;
                        $targetTeam->coins += $attackValue;
                        $targetTeam->save();

                        GamificationTransaction::create([
                            'leaderboard_id' => $leaderboardId,
                            'team_id' => $targetTeam->id,
                            'type' => 'earn',
                            'amount' => $attackValue,
                            'description' => "استرداد عملات بعد إلغاء هجوم خصم النقاط: +{$attackValue} عملة",
                            'reference_type' => GamificationStorePurchase::class,
                            'reference_id' => $purchase->id,
                        ]);
                    }
                }
            }

            // 2. Refund the price paid to the buyer. Individual purchases always
            // deduct the price up front; team purchases only when the coins were
            // actually held (approved or reserved for voting).
            $shouldRefund = $purchase->price_paid > 0
                && ($purchase->team_id === null || self::purchaseCoinsAlreadyHeld($purchase));

            if ($shouldRefund) {
                if ($purchase->team_id) {
                    $team = $purchase->team;
                    if ($team) {
                        $team->coins += $purchase->price_paid;
                        $team->save();

                        GamificationTransaction::create([
                            'leaderboard_id' => $leaderboardId,
                            'team_id' => $team->id,
                            'student_id' => $purchase->student_id,
                            'type' => 'earn',
                            'amount' => $purchase->price_paid,
                            'description' => "استرداد قيمة شراء ملغى: {$item->name}",
                            'reference_type' => GamificationStorePurchase::class,
                            'reference_id' => $purchase->id,
                        ]);
                    }
                } else {
                    GamificationTransaction::create([
                        'leaderboard_id' => $leaderboardId,
                        'student_id' => $purchase->student_id,
                        'type' => 'earn',
                        'amount' => $purchase->price_paid,
                        'description' => "استرداد قيمة شراء ملغى: {$item->name}",
                        'reference_type' => GamificationStorePurchase::class,
                        'reference_id' => $purchase->id,
                    ]);
                }
            }

            // 3. Mark the purchase cancelled so every "approved"/"pending_approval"
            // lookup (multipliers, shields, freeze dates, inventory) stops counting it.
            $purchase->update(['status' => 'cancelled']);

            // 4. Reverse dynamic effects that were baked into or derived from the
            // now-cancelled purchase.
            $student = $purchase->student;

            if ($student) {
                // An individual multiplier is baked into the graded transactions of
                // its target day, so re-sync the student's points to strip it.
                if ($item->item_type === 'multiplier' && ! $item->is_team_product) {
                    self::resyncStudentGamification($student);
                }

                // A streak freeze's count and streak simulation are derived from the
                // approved freeze purchases, so recompute the streak without it.
                if ($item->is_streak_freeze) {
                    self::recalculateStudentStreak($student, $item->leaderboard);
                }

                self::recalculateStudentState($student->id, $leaderboardId);
            }
        });

        return 'success';
    }

    /**
     * Re-run every multiplier-eligible XP sync for a single student so their graded
     * transactions are recomputed against the currently-active store purchases. Used
     * when cancelling an individual multiplier whose bonus was already baked into the
     * student's points. Idempotent: records outside the cancelled day are unchanged.
     */
    protected static function resyncStudentGamification(Student $student): void
    {
        $graded = fn ($q) => $q->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement');

        StudentPlanDay::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
            ->where($graded)
            ->with('plan.student')
            ->chunkById(200, function ($days): void {
                foreach ($days as $day) {
                    if ($day->plan?->student) {
                        self::syncStudentPlanDayXP($day);
                    }
                }
            });

        StudentOdeAchievement::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
            ->where($graded)
            ->with(['plan.student', 'pathDay'])
            ->chunkById(200, function ($achievements): void {
                foreach ($achievements as $achievement) {
                    if ($achievement->plan?->student) {
                        self::syncStudentOdeAchievementXP($achievement);
                    }
                }
            });

        StudentHadithAchievement::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
            ->where($graded)
            ->with(['plan.student', 'pathDay'])
            ->chunkById(200, function ($achievements): void {
                foreach ($achievements as $achievement) {
                    if ($achievement->plan?->student) {
                        self::syncStudentHadithAchievementXP($achievement);
                    }
                }
            });

        Attendance::where('student_id', $student->id)
            ->chunkById(200, function ($attendances): void {
                foreach ($attendances as $attendance) {
                    self::syncStudentAttendanceXP($attendance);
                }
            });

        LeaderboardScore::where('student_id', $student->id)
            ->with(['student', 'leaderboard', 'criterion'])
            ->chunkById(200, function ($scores): void {
                foreach ($scores as $score) {
                    if ($score->student && $score->leaderboard && $score->criterion) {
                        self::syncStudentCustomCriterionXP($score);
                    }
                }
            });
    }

    /**
     * Get active multiplier factor for a team on a given date.
     */
    public static function getMultiplierForTeam(GamificationTeam $team, $date): int
    {
        $formattedDate = Carbon::parse($date)->format('Y-m-d');

        $purchasedMultipliers = GamificationStorePurchase::where('team_id', $team->id)
            ->where('status', 'approved')
            ->where(function ($query) use ($formattedDate) {
                $query->whereDate('target_date', $formattedDate)
                    ->orWhereHas('item', function ($q) use ($formattedDate) {
                        $q->where('item_type', 'multiplier')
                            ->whereDate('target_date', $formattedDate);
                    });
            })
            ->with('item')
            ->get();

        if ($purchasedMultipliers->isNotEmpty()) {
            $maxMultiplier = $purchasedMultipliers->max(function ($purchase) {
                return max(2, $purchase->item->value);
            });

            return (int) $maxMultiplier;
        }

        if ($team->multiplier_active_until) {
            if (Carbon::parse($date)->startOfDay()->lte($team->multiplier_active_until)) {
                return 2;
            }
        }

        return 1;
    }

    /**
     * Get active multiplier factor for a student on a given date.
     */
    public static function getMultiplierForStudent(Student $student, int $leaderboardId, $date): int
    {
        $formattedDate = Carbon::parse($date)->format('Y-m-d');

        $purchasedMultipliers = GamificationStorePurchase::where('student_id', $student->id)
            ->whereNull('team_id')
            ->where('status', 'approved')
            ->where(function ($query) use ($formattedDate) {
                $query->whereDate('target_date', $formattedDate)
                    ->orWhereHas('item', function ($q) use ($formattedDate) {
                        $q->where('item_type', 'multiplier')
                            ->whereDate('target_date', $formattedDate);
                    });
            })
            ->with('item')
            ->get();

        if ($purchasedMultipliers->isNotEmpty()) {
            $maxMultiplier = $purchasedMultipliers->max(function ($purchase) {
                return max(2, $purchase->item->value);
            });

            return (int) $maxMultiplier;
        }

        return 1;
    }

    /**
     * Check if a multiplier is active for a team on a given date.
     */
    public static function isMultiplierActiveForTeam(GamificationTeam $team, $date): bool
    {
        return self::getMultiplierForTeam($team, $date) > 1;
    }

    /**
     * Check if a shield is active for a team on a given date.
     */
    public static function isShieldActiveForTeam(GamificationTeam $team, $date): bool
    {
        $formattedDate = Carbon::parse($date)->format('Y-m-d');

        $hasPurchasedShieldForDate = GamificationStorePurchase::where('team_id', $team->id)
            ->where('status', 'approved')
            ->where(function ($query) use ($formattedDate) {
                $query->whereDate('target_date', $formattedDate)
                    ->orWhereHas('item', function ($q) use ($formattedDate) {
                        $q->where('item_type', 'shield')
                            ->whereDate('target_date', $formattedDate);
                    });
            })
            ->exists();

        if ($hasPurchasedShieldForDate) {
            return true;
        }

        if ($team->shield_active_until) {
            return Carbon::parse($date)->startOfDay()->lte($team->shield_active_until);
        }

        return false;
    }

    /**
     * Get details about the next level upgrade for streak freezes.
     *
     * @return array<string, mixed>|null
     */
    public static function getNextFreezeUpgrade(Student $student, Leaderboard $leaderboard): ?array
    {
        $levelInfo = self::getStudentLevel($student->id, $leaderboard->id);
        $currentLevel = $levelInfo['current'] ?? null;
        $currentLevelNum = $currentLevel->level_number ?? 1;
        $currentMax = self::getStudentMaxFreezeDays($student, $leaderboard);

        // Get all levels for this leaderboard
        $levels = GamificationLevel::where('leaderboard_id', $leaderboard->id)
            ->orderBy('level_number', 'asc')
            ->get();

        if ($levels->isEmpty()) {
            $theme = GamificationThemeService::getTheme($leaderboard);
            $levels = collect($theme['default_levels'] ?? [])->map(fn ($lvl, $num) => (object) [
                'level_number' => $num,
                'name' => $lvl['name'],
                'xp_required' => $lvl['xp_required'],
                'icon' => $lvl['icon'] ?? 'sparkles',
                'settings' => [
                    'has_individual_multiplier' => true,
                    'individual_multiplier_price' => 150,
                    'has_team_multiplier' => true,
                    'team_multiplier_price' => 150,
                    'has_freeze' => true,
                    'freeze_price' => 100,
                    'freeze_max_days' => 1,
                    'has_donation' => true,
                    'donation_max_limit' => 10,
                ],
            ]);
        }

        foreach ($levels as $level) {
            $levelNum = $level->level_number;
            if ($levelNum > $currentLevelNum) {
                $lvlSettings = is_array($level->settings) ? $level->settings : (array) ($level->settings ?? []);
                $days = (int) ($lvlSettings['freeze_max_days'] ?? 1);
                if ($days > $currentMax) {
                    return [
                        'level' => $levelNum,
                        'days' => $days,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Get the maximum number of previous days a student can freeze.
     */
    public static function getStudentMaxFreezeDays(Student $student, Leaderboard $leaderboard): int
    {
        $levelInfo = self::getStudentLevel($student->id, $leaderboard->id);
        $level = $levelInfo['current'] ?? null;
        if ($level) {
            $lvlSettings = is_array($level->settings) ? $level->settings : (array) ($level->settings ?? []);

            return (int) ($lvlSettings['freeze_max_days'] ?? 1);
        }

        return 1;
    }

    /**
     * Get the list of dates that are currently freezeable for a student.
     *
     * @return array<string>
     */
    public static function getFreezeableDates(Student $student, Leaderboard $leaderboard): array
    {
        $settings = $leaderboard->settings ?? [];
        if (! ($settings['enthusiasm_enabled'] ?? false)) {
            return [];
        }

        $levelInfo = self::getStudentLevel($student->id, $leaderboard->id);
        $level = $levelInfo['current'] ?? null;
        if ($level) {
            $lvlSettings = is_array($level->settings) ? $level->settings : (array) ($level->settings ?? []);
            if (! ($lvlSettings['has_freeze'] ?? true)) {
                return [];
            }
        }

        // Get all working days for the leaderboard
        $workingDays = self::getWorkingDaysForLeaderboard($leaderboard, $student->effective_stage_id);
        if (empty($workingDays)) {
            return [];
        }

        $todayStr = now('Asia/Riyadh')->format('Y-m-d');

        // Find the index of the latest working day that is <= today
        $latestIdx = -1;
        foreach ($workingDays as $idx => $dateStr) {
            if ($dateStr <= $todayStr) {
                $latestIdx = $idx;
            } else {
                break;
            }
        }

        if ($latestIdx === -1) {
            return [];
        }

        $maxDays = self::getStudentMaxFreezeDays($student, $leaderboard);

        // We can freeze from index: max(0, latestIdx - maxDays) to latestIdx
        $freezeableRange = [];
        $startIdx = max(0, $latestIdx - $maxDays);
        for ($i = $startIdx; $i <= $latestIdx; $i++) {
            $freezeableRange[] = $workingDays[$i];
        }

        return $freezeableRange;
    }

    /**
     * Get total earned XP for a student in a leaderboard.
     */
    public static function getStudentXP(int $studentId, int $leaderboardId): int
    {
        return (int) GamificationTransaction::where('leaderboard_id', $leaderboardId)
            ->where('student_id', $studentId)
            ->where('type', 'earn')
            ->claimed()
            ->sum('xp_amount');
    }

    /**
     * Pending (unclaimed) reward transactions for a student in a leaderboard.
     *
     * @return Collection<int, GamificationTransaction>
     */
    public static function getPendingRewards(int $studentId, int $leaderboardId)
    {
        return GamificationTransaction::where('student_id', $studentId)
            ->where('leaderboard_id', $leaderboardId)
            ->where('type', 'earn')
            ->unclaimed()
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Claim a single pending reward; only the owning student may claim it.
     */
    public static function claimReward(int $transactionId, int $studentId): bool
    {
        $tx = GamificationTransaction::whereKey($transactionId)
            ->where('student_id', $studentId)
            ->unclaimed()
            ->first();

        if (! $tx) {
            return false;
        }

        $tx->claimed_at = now();
        $tx->save();

        self::recalculateStudentState($studentId, $tx->leaderboard_id);
        self::syncStudentBadges($studentId, $tx->leaderboard_id);

        return true;
    }

    /**
     * Claim every pending reward for a student in a leaderboard.
     *
     * @return int Number of rewards claimed.
     */
    public static function claimAllRewards(int $studentId, int $leaderboardId): int
    {
        $ids = GamificationTransaction::where('student_id', $studentId)
            ->where('leaderboard_id', $leaderboardId)
            ->unclaimed()
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        GamificationTransaction::whereIn('id', $ids)->update(['claimed_at' => now()]);

        self::recalculateStudentState($studentId, $leaderboardId);
        self::syncStudentBadges($studentId, $leaderboardId);

        return $ids->count();
    }

    /**
     * Get the total team score for a team in a leaderboard,
     * applying the team multiplier only to the team score.
     */
    public static function getTeamScore(GamificationTeam $team, Leaderboard $leaderboard, ?string $onlyDate = null): int
    {
        $totals = self::teamScoreTotals($team, $leaderboard, $onlyDate);

        return $onlyDate === null ? $totals['total'] : $totals['on_date'];
    }

    /**
     * The team's whole-competition score and its score on a single day, from one
     * read of its transactions.
     *
     * The standings want both numbers for every team, and asking for them
     * separately means loading — and dating — the same rows twice.
     *
     * @return array{total: int, on_date: int}
     */
    private static function teamScoreTotals(GamificationTeam $team, Leaderboard $leaderboard, ?string $onlyDate = null): array
    {
        $teamStudents = $team->students()->get();
        $teamStudentIds = $teamStudents->pluck('id')->toArray();

        // Get all earn transactions for this team (via students or direct team earnings)
        $transactions = GamificationTransaction::where('leaderboard_id', $leaderboard->id)
            ->where(function ($query) use ($teamStudentIds, $team) {
                $query->whereIn('student_id', $teamStudentIds)
                    ->orWhere(fn ($q) => $q->whereNull('student_id')->where('team_id', $team->id));
            })
            ->where('type', 'earn')
            ->claimed()
            ->get();

        // Pre-fetch related models to get their dates and avoid N+1 queries
        $attendanceIds = $transactions->where('reference_type', Attendance::class)
            ->pluck('reference_id')
            ->unique()
            ->toArray();
        $attendances = empty($attendanceIds) ? collect() : Attendance::whereIn('id', $attendanceIds)->get()->keyBy('id');

        $planDayIds = $transactions->where('reference_type', StudentPlanDay::class)
            ->pluck('reference_id')
            ->unique()
            ->toArray();
        $planDays = empty($planDayIds) ? collect() : StudentPlanDay::whereIn('id', $planDayIds)->get()->keyBy('id');

        $scoreIds = $transactions->where('reference_type', LeaderboardScore::class)
            ->pluck('reference_id')
            ->unique()
            ->toArray();
        $scores = empty($scoreIds) ? collect() : LeaderboardScore::whereIn('id', $scoreIds)->get()->keyBy('id');

        $odeIds = $transactions->where('reference_type', StudentOdeAchievement::class)
            ->pluck('reference_id')
            ->unique()
            ->toArray();
        $odeAchievements = empty($odeIds) ? collect() : StudentOdeAchievement::with('pathDay')->whereIn('id', $odeIds)->get()->keyBy('id');

        $hadithIds = $transactions->where('reference_type', StudentHadithAchievement::class)
            ->pluck('reference_id')
            ->unique()
            ->toArray();
        $hadithAchievements = empty($hadithIds) ? collect() : StudentHadithAchievement::with('pathDay')->whereIn('id', $hadithIds)->get()->keyBy('id');

        // Teacher-given extra points carry their own intended date on the source row,
        // which may differ from when the sync job created the transaction.
        $extraPointIds = $transactions->where('reference_type', 'leaderboard_extra_points')
            ->pluck('reference_id')
            ->unique()
            ->toArray();
        $extraPoints = empty($extraPointIds) ? collect() : DB::table('leaderboard_extra_points')->whereIn('id', $extraPointIds)->get()->keyBy('id');

        // Read both multiplier calendars once rather than per transaction.
        $studentMultipliers = self::multiplierCalendar(
            empty($teamStudentIds) ? collect() : GamificationStorePurchase::whereIn('student_id', $teamStudentIds)
                ->whereNull('team_id')
                ->where('status', 'approved')
                ->with('item')
                ->get(),
            fn ($purchase) => $purchase->student_id,
        );

        $teamMultipliers = self::multiplierCalendar(
            GamificationStorePurchase::where('team_id', $team->id)
                ->where('status', 'approved')
                ->with('item')
                ->get(),
            fn ($purchase) => $purchase->team_id,
        )[$team->id] ?? [];

        $totalTeamScore = 0;
        $onDateScore = 0;

        // Only these sources bake an individual multiplier into xp_amount at creation time,
        // so only they need the base XP recovered before the team multiplier is reapplied.
        $individualMultiplierEligibleTypes = [
            Attendance::class,
            StudentPlanDay::class,
            LeaderboardScore::class,
            StudentOdeAchievement::class,
            StudentHadithAchievement::class,
        ];

        foreach ($transactions as $tx) {
            $date = null;

            if ($tx->reference_type === Attendance::class) {
                $date = $attendances->get($tx->reference_id)?->date;
            } elseif ($tx->reference_type === StudentPlanDay::class) {
                $date = $planDays->get($tx->reference_id)?->date;
            } elseif ($tx->reference_type === LeaderboardScore::class) {
                $date = $scores->get($tx->reference_id)?->date;
            } elseif ($tx->reference_type === StudentOdeAchievement::class) {
                $ach = $odeAchievements->get($tx->reference_id);
                $date = $ach ? ($ach->hifz_graded_at ?? $ach->review_graded_at ?? $ach->pathDay?->date) : null;
            } elseif ($tx->reference_type === StudentHadithAchievement::class) {
                $ach = $hadithAchievements->get($tx->reference_id);
                $date = $ach ? ($ach->hifz_graded_at ?? $ach->review_graded_at ?? $ach->pathDay?->date) : null;
            } elseif ($tx->reference_type === 'leaderboard_extra_points') {
                $date = $extraPoints->get($tx->reference_id)?->date;
            }

            // Every other XP source (team tasks, activity wins, manual adjustments, streak
            // milestones, ...) is dated by when it was credited, so the team multiplier
            // still applies to every source of XP, not just the ones with a business date.
            $date ??= $tx->created_at;

            $day = Carbon::parse($date)->format('Y-m-d');

            $individualMultiplier = 1;
            if (in_array($tx->reference_type, $individualMultiplierEligibleTypes, true)) {
                $student = $teamStudents->firstWhere('id', $tx->student_id);
                if ($student) {
                    $individualMultiplier = $studentMultipliers[$student->id][$day] ?? 1;
                }
            }

            // Determine the team multiplier on this date. The standing grant on the
            // team row is open-ended, so it stays a direct check rather than a date key.
            $teamMultiplier = $teamMultipliers[$day] ?? 1;

            if ($teamMultiplier === 1 && $team->multiplier_active_until
                && Carbon::parse($date)->startOfDay()->lte($team->multiplier_active_until)) {
                $teamMultiplier = 2;
            }

            // Effective multiplier is max of individual and team multiplier, up to 2
            $effectiveMultiplier = min(2, max($individualMultiplier, $teamMultiplier));

            // Since $tx->xp_amount may have an individual multiplier already applied, recover the base XP.
            $baseXp = $individualMultiplier > 1 ? (int) round($tx->xp_amount / $individualMultiplier) : $tx->xp_amount;

            // Team receives base XP multiplied by the effective multiplier
            $earned = $baseXp * $effectiveMultiplier;
            $totalTeamScore += $earned;

            if ($onlyDate !== null && $day === $onlyDate) {
                $onDateScore += $earned;
            }
        }

        return ['total' => $totalTeamScore, 'on_date' => $onDateScore];
    }

    /**
     * Ranked standings for every team in the leaderboard, including the points
     * earned on a given day and the number of the team's students who reached an
     * enthusiasm day on that same day.
     *
     * @return array<int, array{team: GamificationTeam, score: int, rank: int, points_today: int, enthusiasm_today: int}>
     */
    public static function getTeamStandings(Leaderboard $leaderboard, ?string $date = null): array
    {
        $date = $date ? Carbon::parse($date)->format('Y-m-d') : now()->format('Y-m-d');

        // The standings are the same table for everyone in the competition, yet every
        // student's dashboard rebuilt them from scratch. They are cached per version
        // instead: anything that can move a score bumps the version, so a recitation
        // graded a second ago is already reflected — the short expiry is only a floor
        // under the things no write hook covers.
        $key = "gamification.standings.{$leaderboard->id}.".self::standingsVersion($leaderboard->id).".{$date}";

        return Cache::remember($key, now()->addSeconds(30), fn () => self::computeTeamStandings($leaderboard, $date));
    }

    /**
     * The current standings version for a competition. Part of the cache key, so
     * bumping it retires every cached day at once without enumerating them.
     */
    private static function standingsVersion(int $leaderboardId): int
    {
        return (int) Cache::get("gamification.standings.version.{$leaderboardId}", 0);
    }

    /**
     * Retire the cached standings for a competition.
     *
     * Called from the model hooks on everything the standings read — earnings,
     * store purchases, the teams themselves — so the next read recomputes rather
     * than waiting out an expiry.
     */
    public static function forgetTeamStandings(?int $leaderboardId): void
    {
        if ($leaderboardId === null) {
            return;
        }

        Cache::forever(
            "gamification.standings.version.{$leaderboardId}",
            self::standingsVersion($leaderboardId) + 1,
        );
    }

    /**
     * @return array<int, array{team: GamificationTeam, score: int, rank: int, points_today: int, enthusiasm_today: int}>
     */
    private static function computeTeamStandings(Leaderboard $leaderboard, string $date): array
    {
        $teams = GamificationTeam::where('leaderboard_id', $leaderboard->id)
            ->with('students')
            ->get();

        $rows = [];
        foreach ($teams as $team) {
            $enthusiasmToday = 0;
            foreach ($team->students as $student) {
                if (self::checkEnthusiasmForDate($student, $date, $leaderboard)) {
                    $enthusiasmToday++;
                }
            }

            $totals = self::teamScoreTotals($team, $leaderboard, $date);

            $rows[] = [
                'team' => $team,
                'score' => $totals['total'],
                'points_today' => $totals['on_date'],
                'enthusiasm_today' => $enthusiasmToday,
            ];
        }

        // Rank by total score (highest first); stable order for ties by team id.
        usort($rows, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['team']->id <=> $b['team']->id);

        foreach ($rows as $i => &$row) {
            $row['rank'] = $i + 1;
        }
        unset($row);

        return $rows;
    }

    /**
     * Every date a bought multiplier covers, mapped to its factor.
     *
     * `getMultiplierForStudent()` and `getMultiplierForTeam()` each cost a query,
     * and the team score asks for both on every single transaction — which is a
     * query per row of a table that only grows. The purchases are the same handful
     * either way, so they are read once here and matched in PHP.
     *
     * A purchase counts on the date it was bought for, and — when the item itself
     * is a multiplier pinned to a date — on that date too, which is exactly the
     * pair of conditions the per-row lookups spell out as SQL.
     *
     * @param  \Illuminate\Support\Collection<int, GamificationStorePurchase>  $purchases
     * @param  callable(GamificationStorePurchase): (int|null)  $keyBy
     * @return array<int, array<string, int>> owner id → 'Y-m-d' → factor
     */
    private static function multiplierCalendar($purchases, callable $keyBy): array
    {
        $calendar = [];

        foreach ($purchases as $purchase) {
            $owner = $keyBy($purchase);

            if ($owner === null) {
                continue;
            }

            $factor = max(2, (int) ($purchase->item->value ?? 0));

            $dates = [$purchase->target_date];

            if (($purchase->item->item_type ?? null) === 'multiplier') {
                $dates[] = $purchase->item->target_date;
            }

            foreach (array_filter($dates) as $date) {
                $day = Carbon::parse($date)->format('Y-m-d');
                $calendar[$owner][$day] = max($calendar[$owner][$day] ?? 0, $factor);
            }
        }

        return $calendar;
    }

    /**
     * Resolve each earn transaction to the calendar day the underlying work belongs
     * to: the date it was graded / attended / scored, matching how earnings are
     * gated at creation time. Sources with no business date (team tasks, activity
     * wins, streak milestones, manual adjustments, ...) fall back to created_at.
     * Handles both Eloquent models and raw DB rows — both expose id / reference_type
     * / reference_id / created_at.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $transactions
     * @return array<int, string> Keyed by transaction id → 'Y-m-d'.
     */
    /**
     * Per-student XP totals for work whose graded day falls inside [$fromDate,
     * $toDate] ('Y-m-d'), using the same graded-date resolution and competition
     * window rules as the standings. Returns [student_id => xp] sorted desc.
     *
     * @return array<int, int>
     */
    public static function xpTotalsForRange(Leaderboard $leaderboard, string $fromDate, string $toDate): array
    {
        $transactions = GamificationTransaction::where('leaderboard_id', $leaderboard->id)
            ->where('type', 'earn')
            ->whereNotNull('student_id')
            ->get();

        if ($transactions->isEmpty()) {
            return [];
        }

        $dates = self::resolveTransactionDates($transactions);
        $totals = [];

        foreach ($transactions as $tx) {
            $day = $dates[$tx->id];

            if ($day < $fromDate || $day > $toDate || ! self::dateWithinWindow($day, $leaderboard)) {
                continue;
            }

            $totals[$tx->student_id] = ($totals[$tx->student_id] ?? 0) + (int) ($tx->xp_amount ?? $tx->amount);
        }

        arsort($totals);

        return $totals;
    }

    private static function resolveTransactionDates($transactions): array
    {
        $idsFor = fn (string $type) => $transactions->where('reference_type', $type)->pluck('reference_id')->filter()->unique()->all();

        $attendanceIds = $idsFor(Attendance::class);
        $attendances = empty($attendanceIds) ? collect() : Attendance::whereIn('id', $attendanceIds)->get()->keyBy('id');

        $planDayIds = $idsFor(StudentPlanDay::class);
        $planDays = empty($planDayIds) ? collect() : StudentPlanDay::whereIn('id', $planDayIds)->get()->keyBy('id');

        $scoreIds = $idsFor(LeaderboardScore::class);
        $scores = empty($scoreIds) ? collect() : LeaderboardScore::whereIn('id', $scoreIds)->get()->keyBy('id');

        $odeIds = $idsFor(StudentOdeAchievement::class);
        $odeAchievements = empty($odeIds) ? collect() : StudentOdeAchievement::with('pathDay')->whereIn('id', $odeIds)->get()->keyBy('id');

        $hadithIds = $idsFor(StudentHadithAchievement::class);
        $hadithAchievements = empty($hadithIds) ? collect() : StudentHadithAchievement::with('pathDay')->whereIn('id', $hadithIds)->get()->keyBy('id');

        $extraPointIds = $idsFor('leaderboard_extra_points');
        $extraPoints = empty($extraPointIds) ? collect() : DB::table('leaderboard_extra_points')->whereIn('id', $extraPointIds)->get()->keyBy('id');

        $dates = [];

        foreach ($transactions as $tx) {
            $date = null;

            if ($tx->reference_type === Attendance::class) {
                $date = $attendances->get($tx->reference_id)?->date;
            } elseif ($tx->reference_type === StudentPlanDay::class) {
                $day = $planDays->get($tx->reference_id);
                $date = $day ? ($day->hifz_graded_at ?? $day->review_graded_at ?? $day->date) : null;
            } elseif ($tx->reference_type === LeaderboardScore::class) {
                $date = $scores->get($tx->reference_id)?->date;
            } elseif ($tx->reference_type === StudentOdeAchievement::class) {
                $ach = $odeAchievements->get($tx->reference_id);
                $date = $ach ? ($ach->hifz_graded_at ?? $ach->review_graded_at ?? $ach->pathDay?->date) : null;
            } elseif ($tx->reference_type === StudentHadithAchievement::class) {
                $ach = $hadithAchievements->get($tx->reference_id);
                $date = $ach ? ($ach->hifz_graded_at ?? $ach->review_graded_at ?? $ach->pathDay?->date) : null;
            } elseif ($tx->reference_type === 'leaderboard_extra_points') {
                $date = $extraPoints->get($tx->reference_id)?->date;
            }

            $date ??= $tx->created_at;
            $dates[$tx->id] = Carbon::parse($date)->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * Whether a resolved 'Y-m-d' date falls inside the competition's active window.
     * end_date is inclusive; a null end_date leaves the competition open-ended.
     */
    private static function dateWithinWindow(string $date, Leaderboard $leaderboard): bool
    {
        if ($date < $leaderboard->start_date->format('Y-m-d')) {
            return false;
        }

        if ($leaderboard->end_date !== null && $date > $leaderboard->end_date->format('Y-m-d')) {
            return false;
        }

        return true;
    }

    /**
     * Keep only the earn transactions whose underlying work falls inside the
     * competition's date window, so points from days graded before the competition
     * started (or after it ended) never count toward it.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $transactions
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    public static function filterTransactionsWithinWindow($transactions, Leaderboard $leaderboard)
    {
        if ($transactions->isEmpty()) {
            return $transactions;
        }

        $dates = self::resolveTransactionDates($transactions);

        return $transactions->filter(
            fn ($tx) => self::dateWithinWindow($dates[$tx->id], $leaderboard)
        )->values();
    }

    /**
     * Every earned achievement for the leaderboard's students, resolved to the day
     * its work was graded (matching how points are earned) and grouped by student
     * then date. Earnings whose work falls outside the competition window are
     * dropped, team-level transactions (with no owning student) are excluded, and
     * each student's days are ordered most-recent first.
     *
     * @return array<int, array<string, array<int, array{description: string, xp: int, coins: int, pending: bool}>>>
     */
    public static function getAchievementsByStudentAndDay(Leaderboard $leaderboard): array
    {
        $transactions = GamificationTransaction::where('leaderboard_id', $leaderboard->id)
            ->where('type', 'earn')
            ->whereNotNull('student_id')
            ->orderBy('id')
            ->get();

        if ($transactions->isEmpty()) {
            return [];
        }

        $txDates = self::resolveTransactionDates($transactions);

        $byStudent = [];

        foreach ($transactions as $tx) {
            $day = $txDates[$tx->id];

            // Points from work graded outside the competition window (e.g. a teacher
            // back-grading pre-competition days) do not belong to this competition.
            if (! self::dateWithinWindow($day, $leaderboard)) {
                continue;
            }

            $byStudent[$tx->student_id][$day][] = [
                'description' => $tx->description,
                'xp' => (int) $tx->xp_amount,
                'coins' => (int) $tx->amount,
                'pending' => $tx->claimed_at === null,
            ];
        }

        foreach ($byStudent as &$days) {
            krsort($days);
        }
        unset($days);

        return $byStudent;
    }

    /**
     * Get student level and next level details.
     *
     * @return array<string, mixed>
     */
    public static function getStudentLevel(int $studentId, int $leaderboardId): array
    {
        $xp = self::getStudentXP($studentId, $leaderboardId);
        $levels = GamificationLevel::where('leaderboard_id', $leaderboardId)
            ->orderBy('level_number', 'asc')
            ->get();

        if ($levels->isEmpty()) {
            $leaderboard = Leaderboard::find($leaderboardId);
            $theme = GamificationThemeService::getTheme($leaderboard);
            $levels = collect($theme['default_levels'] ?? [])->map(fn ($lvl, $num) => (object) [
                'level_number' => $num,
                'name' => $lvl['name'],
                'xp_required' => $lvl['xp_required'],
                'icon' => $lvl['icon'] ?? 'sparkles',
                'settings' => [
                    'has_individual_multiplier' => true,
                    'individual_multiplier_price' => 150,
                    'has_team_multiplier' => true,
                    'team_multiplier_price' => 150,
                    'has_freeze' => true,
                    'freeze_price' => 100,
                    'freeze_max_days' => 1,
                    'has_donation' => true,
                    'donation_max_limit' => 10,
                ],
            ]);
        }

        $currentLevel = null;
        $nextLevel = null;

        foreach ($levels as $level) {
            if ($xp >= $level->xp_required) {
                $currentLevel = $level;
            } else {
                $nextLevel = $level;
                break;
            }
        }

        if (! $currentLevel && $levels->isNotEmpty()) {
            $currentLevel = $levels->first();
        }

        return [
            'xp' => $xp,
            'current' => $currentLevel,
            'next' => $nextLevel,
        ];
    }

    /**
     * Progress toward the next level as a 0-100 percentage, given the array
     * returned by getStudentLevel(). Returns 100 when there is no next level
     * (max level reached).
     *
     * @param  array<string, mixed>  $levelData
     */
    public static function levelProgressPercentage(array $levelData): float
    {
        $current = $levelData['current'] ?? null;
        $next = $levelData['next'] ?? null;
        $xp = (int) ($levelData['xp'] ?? 0);

        if (! $next) {
            return 100.0;
        }

        $base = (int) ($current->xp_required ?? 0);
        $target = (int) $next->xp_required;
        $range = $target - $base;

        if ($range <= 0) {
            return 100.0;
        }

        return min(100.0, max(0.0, round((($xp - $base) / $range) * 100, 1)));
    }

    /**
     * Calculate the maximum consecutive day streak from a list of dates.
     */
    public static function calculateMaxStreakOfDates($dates): int
    {
        $datesArray = is_array($dates) ? $dates : (method_exists($dates, 'toArray') ? $dates->toArray() : (array) $dates);
        if (empty($datesArray)) {
            return 0;
        }
        $maxStreak = 0;
        $currentStreak = 0;
        $prevDate = null;
        foreach ($datesArray as $dateStr) {
            $date = Carbon::parse($dateStr)->startOfDay();
            if ($prevDate === null) {
                $currentStreak = 1;
            } else {
                $diff = (int) $date->diffInDays($prevDate, true);
                if ($diff === 1) {
                    $currentStreak++;
                } elseif ($diff > 1) {
                    $maxStreak = max($maxStreak, $currentStreak);
                    $currentStreak = 1;
                }
            }
            $prevDate = $date;
        }

        return max($maxStreak, $currentStreak);
    }

    /**
     * Calculate the longest run of consecutive *working days* (circle days) from a
     * set of activity dates, ignoring weekly days off and academic holidays.
     *
     * Two dates count as consecutive when they are adjacent in the leaderboard's
     * working-day sequence, so gaps that fall entirely on non-circle days (weekends,
     * holidays) do not break the streak — matching how the enthusiasm streak is
     * computed in recalculateStudentStreak. Activity dates that are not circle days
     * are ignored. Falls back to raw calendar-day streaks when no working-day
     * calendar is available.
     *
     * @param  iterable<string>  $dates  Activity dates (Y-m-d).
     * @param  array<string>  $workingDays  Ordered working-day dates (Y-m-d).
     */
    public static function calculateMaxStreakOnWorkingDays($dates, array $workingDays): int
    {
        if (empty($workingDays)) {
            return self::calculateMaxStreakOfDates($dates);
        }

        $workingDayIndex = array_flip($workingDays);

        $indices = [];
        foreach ($dates as $dateStr) {
            $normalized = Carbon::parse($dateStr)->format('Y-m-d');
            if (isset($workingDayIndex[$normalized])) {
                $indices[$workingDayIndex[$normalized]] = true;
            }
        }

        if (empty($indices)) {
            return 0;
        }

        $indices = array_keys($indices);
        sort($indices);

        $maxStreak = 1;
        $currentStreak = 1;
        for ($i = 1, $count = count($indices); $i < $count; $i++) {
            if ($indices[$i] - $indices[$i - 1] === 1) {
                $currentStreak++;
            } else {
                $currentStreak = 1;
            }
            if ($currentStreak > $maxStreak) {
                $maxStreak = $currentStreak;
            }
        }

        return $maxStreak;
    }

    /**
     * Compute a student's current progress value toward a single badge.
     *
     * Streak badges return the longest run of consecutive circle days; count
     * badges return the total number of qualifying days. Returns null for manual
     * or non-trackable badges (no automatic progress).
     *
     * @param  array<string>  $workingDays  Ordered working-day dates (Y-m-d).
     */
    public static function calculateBadgeValue(GamificationBadge $badge, int $studentId, Leaderboard $leaderboard, array $workingDays): ?int
    {
        $leaderboardId = $leaderboard->id;
        $startDate = $leaderboard->start_date;
        $endDate = $leaderboard->end_date ?? now()->format('Y-m-d');

        switch ($badge->badge_type) {
            case 'streak_attendance':
            case 'attendance_streak':
                $dates = Attendance::where('student_id', $studentId)
                    ->whereBetween('date', [$startDate, $endDate])
                    ->whereIn('status', ['present', 'late'])
                    ->orderBy('date', 'asc')
                    ->pluck('date')
                    ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
                    ->unique()
                    ->values();

                return self::calculateMaxStreakOnWorkingDays($dates, $workingDays);

            case 'count_attendance':
                return Attendance::where('student_id', $studentId)
                    ->whereBetween('date', [$startDate, $endDate])
                    ->whereIn('status', ['present', 'late'])
                    ->count();

            case 'streak_hifz':
            case 'hifz_streak':
                $dates = StudentPlanDay::whereHas('plan', function ($q) use ($studentId) {
                    $q->where('student_id', $studentId)->where('is_approved', 1);
                })
                    ->whereBetween('date', [$startDate, $endDate])
                    ->whereIn('hifz_achievement', [1, 2, 3, '1', '2', '3', 'acceptable', 'good', 'excellent'])
                    ->orderBy('date', 'asc')
                    ->pluck('date')
                    ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
                    ->unique()
                    ->values();

                return self::calculateMaxStreakOnWorkingDays($dates, $workingDays);

            case 'count_hifz':
                return StudentPlanDay::whereHas('plan', function ($q) use ($studentId) {
                    $q->where('student_id', $studentId)->where('is_approved', 1);
                })
                    ->whereBetween('date', [$startDate, $endDate])
                    ->whereIn('hifz_achievement', [1, 2, 3, '1', '2', '3', 'acceptable', 'good', 'excellent'])
                    ->count();

            case 'streak_review':
                $dates = StudentPlanDay::whereHas('plan', function ($q) use ($studentId) {
                    $q->where('student_id', $studentId)->where('is_approved', 1);
                })
                    ->whereBetween('date', [$startDate, $endDate])
                    ->whereIn('review_achievement', [1, 2, 3, '1', '2', '3', 'acceptable', 'good', 'excellent'])
                    ->orderBy('date', 'asc')
                    ->pluck('date')
                    ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
                    ->unique()
                    ->values();

                return self::calculateMaxStreakOnWorkingDays($dates, $workingDays);

            case 'count_review':
                return StudentPlanDay::whereHas('plan', function ($q) use ($studentId) {
                    $q->where('student_id', $studentId)->where('is_approved', 1);
                })
                    ->whereBetween('date', [$startDate, $endDate])
                    ->whereIn('review_achievement', [1, 2, 3, '1', '2', '3', 'acceptable', 'good', 'excellent'])
                    ->count();

            case 'streak_criterion':
                if (! $badge->leaderboard_criterion_id) {
                    return null;
                }
                $dates = LeaderboardScore::where('leaderboard_id', $leaderboardId)
                    ->where('student_id', $studentId)
                    ->where('leaderboard_criterion_id', $badge->leaderboard_criterion_id)
                    ->orderBy('date', 'asc')
                    ->pluck('date')
                    ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
                    ->unique()
                    ->values();

                return self::calculateMaxStreakOnWorkingDays($dates, $workingDays);

            case 'count_criterion':
            case 'custom':
                if (! $badge->leaderboard_criterion_id) {
                    return null;
                }

                return LeaderboardScore::where('leaderboard_id', $leaderboardId)
                    ->where('student_id', $studentId)
                    ->where('leaderboard_criterion_id', $badge->leaderboard_criterion_id)
                    ->count();

            default:
                return null;
        }
    }

    /**
     * Sync and automatically award/revoke all badges of all types for a student.
     */
    public static function syncStudentBadges(int $studentId, int $leaderboardId): void
    {
        $leaderboard = Leaderboard::find($leaderboardId);
        $student = Student::find($studentId);
        if (! $leaderboard || ! $student) {
            return;
        }

        $badges = GamificationBadge::where('leaderboard_id', $leaderboardId)->get();

        // Ordered circle working days of the student's own stage (excludes its
        // weekly days off, its closures, and academy holidays) so streak badges
        // count consecutive circle days, not calendar days. Computed once and
        // reused across every streak badge.
        $workingDays = self::getWorkingDaysForLeaderboard($leaderboard, $student->effective_stage_id);

        foreach ($badges as $badge) {
            $value = self::calculateBadgeValue($badge, $studentId, $leaderboard, $workingDays);

            // Manual/custom badges have no automatic progress to sync.
            if ($value === null) {
                continue;
            }

            $existing = DB::table('gamification_badge_student')
                ->where('badge_id', $badge->id)
                ->where('student_id', $studentId)
                ->first();

            if ($value >= $badge->requirement_value) {
                if (! $existing) {
                    DB::table('gamification_badge_student')->insert([
                        'badge_id' => $badge->id,
                        'student_id' => $studentId,
                        'status' => 'pending_approval',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    GamificationNewsService::record($leaderboardId, 'badge', [
                        'student_id' => $studentId,
                        'student_name' => $student->name,
                        'badge_name' => $badge->name,
                        'badge_icon' => $badge->icon,
                    ]);
                }
            } else {
                if ($existing && $existing->status !== 'claimed') {
                    DB::table('gamification_badge_student')
                        ->where('badge_id', $badge->id)
                        ->where('student_id', $studentId)
                        ->delete();
                }
            }
        }
    }
}
