<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\FreeRecitation;
use App\Models\GamificationTrack;
use App\Models\Leaderboard;
use App\Models\LeaderboardScore;
use App\Models\Student;
use App\Models\StudentHadithAchievement;
use App\Models\StudentOdeAchievement;
use App\Models\StudentPlanDay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeaderboardService
{
    public function getDetailedStandings(Leaderboard $leaderboard)
    {
        $isGamification = $leaderboard->competition_type === 'gamification';
        $relations = ['circle'];
        if ($isGamification) {
            $relations['gamificationTeams'] = function ($q) use ($leaderboard) {
                $q->where('leaderboard_id', $leaderboard->id);
            };
        }

        // For supervisor competitions, load students from all participating circles
        if ($leaderboard->isSupervisorCompetition()) {
            $circleIds = $leaderboard->circles()->pluck('circles.id')->toArray();
            if (empty($circleIds)) {
                $circleIds = [$leaderboard->circle_id];
            }
            $students = Student::whereIn('circle_id', $circleIds)->with($relations)->get();
        } else {
            $students = Student::where('circle_id', $leaderboard->circle_id)->with($relations)->get();
        }

        if ($students->isEmpty()) {
            return collect([]);
        }

        $startDate = $leaderboard->start_date->startOfDay();
        $endDate = $leaderboard->end_date ? $leaderboard->end_date->endOfDay() : now()->endOfDay();
        $settings = $leaderboard->settings ?? [];

        $isGamification = $leaderboard->competition_type === 'gamification';

        if ($isGamification) {
            // Get all earn transactions for this leaderboard
            $transactions = DB::table('gamification_transactions')
                ->where('leaderboard_id', $leaderboard->id)
                ->where('type', 'earn')
                ->get();

            // Points from work graded outside the competition window (e.g. a teacher
            // back-grading pre-competition days) do not belong to this competition.
            $transactions = GamificationService::filterTransactionsWithinWindow($transactions, $leaderboard);

            // Pre-fetch related models to avoid N+1 queries
            $planDayIds = $transactions->where('reference_type', 'App\Models\StudentPlanDay')
                ->pluck('reference_id')
                ->unique()
                ->toArray();

            $planDays = [];
            if (! empty($planDayIds)) {
                $planDays = StudentPlanDay::whereIn('id', $planDayIds)->get()->keyBy('id');
            }

            // A free recitation (no plan) is hifz or review by its type.
            $freeIds = $transactions->where('reference_type', FreeRecitation::class)->pluck('reference_id')->unique()->all();
            $freeTypes = empty($freeIds) ? collect() : FreeRecitation::whereIn('id', $freeIds)->pluck('type', 'id');

            $scoreIds = $transactions->where('reference_type', 'App\Models\LeaderboardScore')
                ->pluck('reference_id')
                ->unique()
                ->toArray();

            $scoreRecords = [];
            if (! empty($scoreIds)) {
                $scoreRecords = LeaderboardScore::whereIn('id', $scoreIds)->get()->keyBy('id');
            }
        }

        $standings = [];

        foreach ($students as $student) {
            $totalScore = 0;
            $pendingScore = 0;
            $manualScore = 0;
            $attendanceScore = 0;
            $hifzScore = 0;
            $reviewScore = 0;
            $criteriaCounts = [];
            $extraPointsScore = 0;

            $extraPointsList = DB::table('leaderboard_extra_points')
                ->where('leaderboard_id', $leaderboard->id)
                ->where('student_id', $student->id)
                ->get();

            if ($isGamification) {
                $allStudentTxs = $transactions->where('student_id', $student->id);
                // Only claimed rewards count toward the standing; pending is surfaced separately.
                $studentTxs = $allStudentTxs->whereNotNull('claimed_at');
                $totalScore = (int) $studentTxs->sum('xp_amount');
                $pendingScore = (int) $allStudentTxs->whereNull('claimed_at')->sum('xp_amount');

                foreach ($studentTxs as $tx) {
                    if ($tx->reference_type === 'App\Models\Attendance') {
                        $attendanceScore += $tx->xp_amount;
                    } elseif ($tx->reference_type === 'App\Models\StudentPlanDay') {
                        $day = $planDays[$tx->reference_id] ?? null;
                        if ($day) {
                            $hifzBase = 0;
                            $reviewBase = 0;

                            if ($day->hifz_achievement !== null) {
                                $hifz = $day->hifz_achievement;
                                if ($hifz === 3 || $hifz === '3' || $hifz === 'excellent') {
                                    $hifzBase = ($settings['hifz_excellent'] ?? 10);
                                } elseif ($hifz === 2 || $hifz === '2' || $hifz === 'good') {
                                    $hifzBase = ($settings['hifz_good'] ?? 7);
                                } elseif ($hifz === 1 || $hifz === '1' || $hifz === 'acceptable') {
                                    $hifzBase = ($settings['hifz_acceptable'] ?? 4);
                                }
                            }

                            if ($day->review_achievement !== null) {
                                $review = $day->review_achievement;
                                if ($review === 3 || $review === '3' || $review === 'excellent') {
                                    $reviewBase = ($settings['review_excellent'] ?? 5);
                                } elseif ($review === 2 || $review === '2' || $review === 1 || $review === 1 || $review === 'good' || $review === 'acceptable') {
                                    $reviewBase = ($settings['review_good'] ?? 3);
                                }
                            }

                            $totalBase = $hifzBase + $reviewBase;
                            if ($totalBase > 0) {
                                $hifzShare = (int) round(($hifzBase / $totalBase) * $tx->xp_amount);
                                $hifzScore += $hifzShare;
                                $reviewScore += $tx->xp_amount - $hifzShare;
                            } else {
                                $hifzScore += $tx->xp_amount;
                            }
                        } else {
                            $hifzScore += $tx->xp_amount;
                        }
                    } elseif ($tx->reference_type === FreeRecitation::class) {
                        if (($freeTypes[$tx->reference_id] ?? 'hifz') === 'review') {
                            $reviewScore += $tx->xp_amount;
                        } else {
                            $hifzScore += $tx->xp_amount;
                        }
                    } elseif ($tx->reference_type === 'App\Models\LeaderboardScore') {
                        $scoreRecord = $scoreRecords[$tx->reference_id] ?? null;
                        if ($scoreRecord) {
                            $criterionId = $scoreRecord->leaderboard_criterion_id;
                            $criteriaCounts[$criterionId] = ($criteriaCounts[$criterionId] ?? 0) + 1;
                        }
                        $manualScore += $tx->xp_amount;
                    } elseif ($tx->reference_type === 'leaderboard_extra_points') {
                        $extraPointsScore += $tx->xp_amount;
                    } else {
                        // Milestone rewards, badges, and other gamification earn transactions
                        $extraPointsScore += $tx->xp_amount;
                    }
                }
            } else {
                // 1. Manual Criteria Scores
                $manualScoresList = DB::table('leaderboard_scores')
                    ->join('leaderboard_criteria', 'leaderboard_scores.leaderboard_criterion_id', '=', 'leaderboard_criteria.id')
                    ->where('leaderboard_scores.leaderboard_id', $leaderboard->id)
                    ->where('leaderboard_scores.student_id', $student->id)
                    ->select('leaderboard_criteria.id as criterion_id', 'leaderboard_criteria.name', 'leaderboard_criteria.points')
                    ->get();

                $manualScore = $manualScoresList->sum('points');

                // 1.5 Extra Points
                $extraPointsScore = $extraPointsList->sum('points');

                $totalScore += $manualScore + $extraPointsScore;

                // Count occurrences per criterion
                foreach ($manualScoresList as $ms) {
                    $criteriaCounts[$ms->criterion_id] = ($criteriaCounts[$ms->criterion_id] ?? 0) + 1;
                }

                // 2. Attendance Points
                if ($settings['attendance_enabled'] ?? false) {
                    $attendances = Attendance::where('student_id', $student->id)
                        ->where('date', '>=', $startDate->format('Y-m-d'))
                        ->where('date', '<=', $endDate->format('Y-m-d'))
                        ->get();

                    $attendanceScore += $attendances->where('status', 'present')->count() * ($settings['attendance_present'] ?? 4);
                    $attendanceScore += $attendances->where('status', 'late')->count() * ($settings['attendance_late'] ?? 2);
                    $totalScore += $attendanceScore;
                }

                // 3. Hifz & Review Points
                if (($settings['hifz_enabled'] ?? false) || ($settings['review_enabled'] ?? false)) {
                    $days = StudentPlanDay::whereHas('plan', function ($q) use ($student) {
                        $q->where('student_id', $student->id)
                            ->where('is_approved', 1);
                    })
                        ->where(function ($q) use ($startDate, $endDate) {
                            $q->whereBetween('hifz_graded_at', [$startDate, $endDate])
                                ->orWhereBetween('review_graded_at', [$startDate, $endDate])
                                ->orWhere(function ($sub) use ($startDate, $endDate) {
                                    $sub->whereNull('hifz_graded_at')
                                        ->whereNull('review_graded_at')
                                        ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                                        ->where(function ($s) {
                                            $s->whereNotNull('hifz_achievement')
                                                ->orWhereNotNull('review_achievement');
                                        });
                                });
                        })->get();

                    if ($settings['hifz_enabled'] ?? false) {
                        foreach ($days as $day) {
                            $gradedAt = $day->hifz_graded_at ?? $day->date;
                            if ($gradedAt >= $startDate && $gradedAt <= $endDate && $day->hifz_achievement !== null) {
                                $hifz = $day->hifz_achievement;
                                if ($hifz === 3 || $hifz === '3' || $hifz === 'excellent') {
                                    $hifzScore += ($settings['hifz_excellent'] ?? 10);
                                } elseif ($hifz === 2 || $hifz === '2' || $hifz === 'good') {
                                    $hifzScore += ($settings['hifz_good'] ?? 7);
                                } elseif ($hifz === 1 || $hifz === '1' || $hifz === 'acceptable') {
                                    $hifzScore += ($settings['hifz_acceptable'] ?? 4);
                                }
                            }
                        }
                    }

                    if ($settings['review_enabled'] ?? false) {
                        foreach ($days as $day) {
                            $gradedAt = $day->review_graded_at ?? $day->date;
                            if ($gradedAt >= $startDate && $gradedAt <= $endDate && $day->review_achievement !== null) {
                                $review = $day->review_achievement;
                                if ($review === 3 || $review === '3' || $review === 'excellent') {
                                    $reviewScore += ($settings['review_excellent'] ?? 5);
                                } elseif ($review === 2 || $review === '2' || $review === '1' || $review === 1 || $review === 'good' || $review === 'acceptable') {
                                    $reviewScore += ($settings['review_good'] ?? 3);
                                }
                            }
                        }
                    }

                    foreach (FreeRecitation::where('student_id', $student->id)->recited()->whereBetween('graded_at', [$startDate, $endDate])->get() as $recitation) {
                        [$hifzPoints, $reviewPoints] = self::freeRecitationPoints($settings, $recitation);
                        $hifzScore += $hifzPoints;
                        $reviewScore += $reviewPoints;
                    }

                    $totalScore += $hifzScore + $reviewScore;
                }

                // 4. Ode Hifz & Review Points
                if (($settings['ode_hifz_enabled'] ?? false) || ($settings['ode_review_enabled'] ?? false)) {
                    $odeAchievements = StudentOdeAchievement::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
                        ->where(function ($q) use ($startDate, $endDate) {
                            $q->whereBetween('hifz_graded_at', [$startDate, $endDate])
                                ->orWhereBetween('review_graded_at', [$startDate, $endDate])
                                ->orWhere(function ($sub) use ($startDate, $endDate) {
                                    $sub->whereNull('hifz_graded_at')
                                        ->whereNull('review_graded_at')
                                        ->whereHas('pathDay', fn ($pd) => $pd->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]))
                                        ->where(fn ($s) => $s->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement'));
                                });
                        })->with('pathDay')->get();

                    $odeHifzScore = 0;
                    $odeReviewScore = 0;

                    if ($settings['ode_hifz_enabled'] ?? false) {
                        foreach ($odeAchievements as $ach) {
                            $gradedAt = $ach->hifz_graded_at ?? $ach->pathDay?->date;
                            if ($gradedAt && $gradedAt >= $startDate && $gradedAt <= $endDate && $ach->hifz_achievement !== null) {
                                if ($ach->hifz_achievement == 3) {
                                    $odeHifzScore += ($settings['ode_hifz_excellent'] ?? 10);
                                } elseif ($ach->hifz_achievement == 2) {
                                    $odeHifzScore += ($settings['ode_hifz_good'] ?? 7);
                                } elseif ($ach->hifz_achievement == 1) {
                                    $odeHifzScore += ($settings['ode_hifz_acceptable'] ?? 4);
                                }
                            }
                        }
                    }

                    if ($settings['ode_review_enabled'] ?? false) {
                        foreach ($odeAchievements as $ach) {
                            $gradedAt = $ach->review_graded_at ?? $ach->pathDay?->date;
                            if ($gradedAt && $gradedAt >= $startDate && $gradedAt <= $endDate && $ach->review_achievement !== null) {
                                if ($ach->review_achievement == 3) {
                                    $odeReviewScore += ($settings['ode_review_excellent'] ?? 5);
                                } elseif ($ach->review_achievement <= 2) {
                                    $odeReviewScore += ($settings['ode_review_good'] ?? 3);
                                }
                            }
                        }
                    }

                    $hifzScore += $odeHifzScore;
                    $reviewScore += $odeReviewScore;
                    $totalScore += $odeHifzScore + $odeReviewScore;
                }

                // 5. Hadith Hifz & Review Points
                if (($settings['hadith_hifz_enabled'] ?? false) || ($settings['hadith_review_enabled'] ?? false)) {
                    $hadithAchievements = StudentHadithAchievement::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
                        ->where(function ($q) use ($startDate, $endDate) {
                            $q->whereBetween('hifz_graded_at', [$startDate, $endDate])
                                ->orWhereBetween('review_graded_at', [$startDate, $endDate])
                                ->orWhere(function ($sub) use ($startDate, $endDate) {
                                    $sub->whereNull('hifz_graded_at')
                                        ->whereNull('review_graded_at')
                                        ->whereHas('pathDay', fn ($pd) => $pd->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]))
                                        ->where(fn ($s) => $s->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement'));
                                });
                        })->with('pathDay')->get();

                    $hadithHifzScore = 0;
                    $hadithReviewScore = 0;

                    if ($settings['hadith_hifz_enabled'] ?? false) {
                        foreach ($hadithAchievements as $ach) {
                            $gradedAt = $ach->hifz_graded_at ?? $ach->pathDay?->date;
                            if ($gradedAt && $gradedAt >= $startDate && $gradedAt <= $endDate && $ach->hifz_achievement !== null) {
                                if ($ach->hifz_achievement == 3) {
                                    $hadithHifzScore += ($settings['hadith_hifz_excellent'] ?? 10);
                                } elseif ($ach->hifz_achievement == 2) {
                                    $hadithHifzScore += ($settings['hadith_hifz_good'] ?? 7);
                                } elseif ($ach->hifz_achievement == 1) {
                                    $hadithHifzScore += ($settings['hadith_hifz_acceptable'] ?? 4);
                                }
                            }
                        }
                    }

                    if ($settings['hadith_review_enabled'] ?? false) {
                        foreach ($hadithAchievements as $ach) {
                            $gradedAt = $ach->review_graded_at ?? $ach->pathDay?->date;
                            if ($gradedAt && $gradedAt >= $startDate && $gradedAt <= $endDate && $ach->review_achievement !== null) {
                                if ($ach->review_achievement == 3) {
                                    $hadithReviewScore += ($settings['hadith_review_excellent'] ?? 5);
                                } elseif ($ach->review_achievement <= 2) {
                                    $hadithReviewScore += ($settings['hadith_review_good'] ?? 3);
                                }
                            }
                        }
                    }

                    $hifzScore += $hadithHifzScore;
                    $reviewScore += $hadithReviewScore;
                    $totalScore += $hadithHifzScore + $hadithReviewScore;
                }
            }

            $standings[] = [
                'student' => $student,
                'score' => $totalScore,
                'pending_score' => $pendingScore,
                'details' => [
                    'manual' => $manualScore,
                    'extra_points_score' => $extraPointsScore,
                    'extra_points_list' => $extraPointsList,
                    'attendance' => $attendanceScore,
                    'hifz' => $hifzScore,
                    'review' => $reviewScore,
                    'criteria_counts' => $criteriaCounts,
                ],
            ];
        }

        $standings = collect($standings)->sortByDesc('score')->values();

        // Add rank for easier consumption in views
        return $standings->map(function ($item, $index) {
            $item['rank'] = $index + 1;

            return $item;
        });
    }

    // Proxy for original getStandings to not break student dashboard
    public function getStandings(Leaderboard $leaderboard)
    {
        return $this->getDetailedStandings($leaderboard);
    }

    /**
     * Group the (globally ranked) standings into competition tracks, each with its
     * own internal ranking. Students not assigned to any track fall into a trailing
     * "عام" group. Returns an empty collection when the competition has no tracks,
     * so callers can fall back to the flat leaderboard.
     *
     * @return Collection<int, array{id: int|null, name: string, description: ?string, standings: array<int, mixed>}>
     */
    public function getStandingsByTrack(Leaderboard $leaderboard)
    {
        $tracks = GamificationTrack::where('leaderboard_id', $leaderboard->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with('students:id')
            ->get();

        if ($tracks->isEmpty()) {
            return collect();
        }

        // One track per student by design — map student id to its track id.
        $studentTrack = [];
        foreach ($tracks as $track) {
            foreach ($track->students as $s) {
                $studentTrack[$s->id] = $track->id;
            }
        }

        $groups = [];
        foreach ($tracks as $track) {
            $groups[$track->id] = [
                'id' => $track->id,
                'name' => $track->name,
                'description' => $track->description,
                'standings' => [],
            ];
        }
        $general = ['id' => null, 'name' => 'عام', 'description' => null, 'standings' => []];

        foreach ($this->getDetailedStandings($leaderboard) as $row) {
            $trackId = $studentTrack[$row['student']->id] ?? null;
            if ($trackId !== null && isset($groups[$trackId])) {
                $groups[$trackId]['standings'][] = $row;
            } else {
                $general['standings'][] = $row;
            }
        }

        $result = collect(array_values($groups));
        if (! empty($general['standings'])) {
            $result->push($general);
        }

        return $result
            ->map(function ($group) {
                $group['standings'] = collect($group['standings'])->values()->map(function ($row, $index) {
                    $row['track_rank'] = $index + 1;

                    return $row;
                })->all();

                return $group;
            })
            ->filter(fn ($group) => ! empty($group['standings']))
            ->values();
    }

    public function getDailyScores(Leaderboard $leaderboard, $date)
    {
        // For supervisor competitions, include all participating circles
        if ($leaderboard->isSupervisorCompetition()) {
            $circleIds = $leaderboard->circles()->pluck('circles.id')->toArray();
            if (empty($circleIds)) {
                $circleIds = [$leaderboard->circle_id];
            }
            $students = Student::whereIn('circle_id', $circleIds)->get();
        } else {
            $students = Student::where('circle_id', $leaderboard->circle_id)->get();
        }
        $settings = $leaderboard->settings ?? [];

        $dailyScores = [];

        foreach ($students as $student) {
            $totalScore = 0;
            $manualScore = 0;
            $automatedScore = 0;
            $hifzScoreDaily = 0;
            $reviewScoreDaily = 0;
            $attendanceScoreDaily = 0;

            // 1. Manual Criteria Scores for TODAY
            $manualScore = DB::table('leaderboard_scores')
                ->join('leaderboard_criteria', 'leaderboard_scores.leaderboard_criterion_id', '=', 'leaderboard_criteria.id')
                ->where('leaderboard_scores.leaderboard_id', $leaderboard->id)
                ->where('leaderboard_scores.student_id', $student->id)
                ->whereDate('leaderboard_scores.date', $date)
                ->sum('leaderboard_criteria.points');

            $totalScore += $manualScore;

            // 2. Attendance Points for TODAY
            if ($settings['attendance_enabled'] ?? false) {
                $attendance = Attendance::where('student_id', $student->id)
                    ->whereDate('date', $date)
                    ->first();

                if ($attendance) {
                    if ($attendance->status === 'present') {
                        $attendanceScoreDaily = ($settings['attendance_present'] ?? 4);
                    } elseif ($attendance->status === 'late') {
                        $attendanceScoreDaily = ($settings['attendance_late'] ?? 2);
                    }
                    $automatedScore += $attendanceScoreDaily;
                }
            }

            // 3. Hifz & Review Points for TODAY
            if (($settings['hifz_enabled'] ?? false) || ($settings['review_enabled'] ?? false)) {
                $days = StudentPlanDay::whereHas('plan', function ($q) use ($student) {
                    $q->where('student_id', $student->id)
                        ->where('is_approved', 1);
                })
                    ->where(function ($q) use ($date) {
                        $q->whereDate('hifz_graded_at', $date)
                            ->orWhereDate('review_graded_at', $date)
                            ->orWhere(function ($sub) use ($date) {
                                $sub->whereNull('hifz_graded_at')
                                    ->whereNull('review_graded_at')
                                    ->whereDate('date', $date)
                                    ->where(function ($s) {
                                        $s->whereNotNull('hifz_achievement')
                                            ->orWhereNotNull('review_achievement');
                                    });
                            });
                    })->get();

                foreach ($days as $day) {
                    if ($settings['hifz_enabled'] ?? false) {
                        $gradedAt = $day->hifz_graded_at ? $day->hifz_graded_at->format('Y-m-d') : $day->date->format('Y-m-d');
                        if ($gradedAt === $date && $day->hifz_achievement !== null) {
                            $hifz = (int) $day->hifz_achievement;
                            if ($hifz === 3) {
                                $hifzScoreDaily += ($settings['hifz_excellent'] ?? 10);
                            } elseif ($hifz === 2) {
                                $hifzScoreDaily += ($settings['hifz_good'] ?? 7);
                            } elseif ($hifz === 1) {
                                $hifzScoreDaily += ($settings['hifz_acceptable'] ?? 4);
                            }
                        }
                    }

                    if ($settings['review_enabled'] ?? false) {
                        $gradedAt = $day->review_graded_at ? $day->review_graded_at->format('Y-m-d') : $day->date->format('Y-m-d');
                        if ($gradedAt === $date && $day->review_achievement !== null) {
                            $review = (int) $day->review_achievement;
                            if ($review === 3) {
                                $reviewScoreDaily += ($settings['review_excellent'] ?? 5);
                            } elseif ($review === 2 || $review === 1) {
                                $reviewScoreDaily += ($settings['review_good'] ?? 3);
                            }
                        }
                    }
                }

                foreach (FreeRecitation::where('student_id', $student->id)->recited()->whereDate('graded_at', $date)->get() as $recitation) {
                    [$hifzPoints, $reviewPoints] = self::freeRecitationPoints($settings, $recitation);
                    $hifzScoreDaily += $hifzPoints;
                    $reviewScoreDaily += $reviewPoints;
                }

                $automatedScore += $hifzScoreDaily + $reviewScoreDaily;
            }

            // Ode Hifz & Review for THIS day
            if (($settings['ode_hifz_enabled'] ?? false) || ($settings['ode_review_enabled'] ?? false)) {
                $odeAchievementsDaily = StudentOdeAchievement::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
                    ->where(function ($q) use ($date) {
                        $q->whereDate('hifz_graded_at', $date)
                            ->orWhereDate('review_graded_at', $date)
                            ->orWhere(function ($sub) use ($date) {
                                $sub->whereNull('hifz_graded_at')
                                    ->whereNull('review_graded_at')
                                    ->whereHas('pathDay', fn ($pd) => $pd->whereDate('date', $date))
                                    ->where(fn ($s) => $s->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement'));
                            });
                    })->get();

                foreach ($odeAchievementsDaily as $ach) {
                    if (($settings['ode_hifz_enabled'] ?? false) && $ach->hifz_achievement !== null) {
                        $gradedAt = $ach->hifz_graded_at ? $ach->hifz_graded_at->format('Y-m-d') : $date;
                        if ($gradedAt === $date) {
                            if ($ach->hifz_achievement == 3) {
                                $hifzScoreDaily += ($settings['ode_hifz_excellent'] ?? 10);
                            } elseif ($ach->hifz_achievement == 2) {
                                $hifzScoreDaily += ($settings['ode_hifz_good'] ?? 7);
                            } elseif ($ach->hifz_achievement == 1) {
                                $hifzScoreDaily += ($settings['ode_hifz_acceptable'] ?? 4);
                            }
                        }
                    }
                    if (($settings['ode_review_enabled'] ?? false) && $ach->review_achievement !== null) {
                        $gradedAt = $ach->review_graded_at ? $ach->review_graded_at->format('Y-m-d') : $date;
                        if ($gradedAt === $date) {
                            if ($ach->review_achievement == 3) {
                                $reviewScoreDaily += ($settings['ode_review_excellent'] ?? 5);
                            } elseif ($ach->review_achievement <= 2) {
                                $reviewScoreDaily += ($settings['ode_review_good'] ?? 3);
                            }
                        }
                    }
                }
            }

            // Hadith Hifz & Review for THIS day
            if (($settings['hadith_hifz_enabled'] ?? false) || ($settings['hadith_review_enabled'] ?? false)) {
                $hadithAchievementsDaily = StudentHadithAchievement::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
                    ->where(function ($q) use ($date) {
                        $q->whereDate('hifz_graded_at', $date)
                            ->orWhereDate('review_graded_at', $date)
                            ->orWhere(function ($sub) use ($date) {
                                $sub->whereNull('hifz_graded_at')
                                    ->whereNull('review_graded_at')
                                    ->whereHas('pathDay', fn ($pd) => $pd->whereDate('date', $date))
                                    ->where(fn ($s) => $s->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement'));
                            });
                    })->get();

                foreach ($hadithAchievementsDaily as $ach) {
                    if (($settings['hadith_hifz_enabled'] ?? false) && $ach->hifz_achievement !== null) {
                        $gradedAt = $ach->hifz_graded_at ? $ach->hifz_graded_at->format('Y-m-d') : $date;
                        if ($gradedAt === $date) {
                            if ($ach->hifz_achievement == 3) {
                                $hifzScoreDaily += ($settings['hadith_hifz_excellent'] ?? 10);
                            } elseif ($ach->hifz_achievement == 2) {
                                $hifzScoreDaily += ($settings['hadith_hifz_good'] ?? 7);
                            } elseif ($ach->hifz_achievement == 1) {
                                $hifzScoreDaily += ($settings['hadith_hifz_acceptable'] ?? 4);
                            }
                        }
                    }
                    if (($settings['hadith_review_enabled'] ?? false) && $ach->review_achievement !== null) {
                        $gradedAt = $ach->review_graded_at ? $ach->review_graded_at->format('Y-m-d') : $date;
                        if ($gradedAt === $date) {
                            if ($ach->review_achievement == 3) {
                                $reviewScoreDaily += ($settings['hadith_review_excellent'] ?? 5);
                            } elseif ($ach->review_achievement <= 2) {
                                $reviewScoreDaily += ($settings['hadith_review_good'] ?? 3);
                            }
                        }
                    }
                }
            }

            $automatedScore = $hifzScoreDaily + $reviewScoreDaily + $attendanceScoreDaily;
            $totalScore += $automatedScore;

            $dailyScores[$student->id] = [
                'total' => $totalScore,
                'manual' => $manualScore,
                'automated' => $automatedScore,
                'hifz' => $hifzScoreDaily,
                'review' => $reviewScoreDaily,
                'attendance' => $attendanceScoreDaily,
            ];
        }

        return $dailyScores;
    }

    /**
     * A free recitation's points in a points-based (not gamified) competition,
     * on the same scale as a plan day: hifz pays by grade, review pays as one
     * tier below excellent, «لم يسمع» pays nothing.
     *
     * @param  array<string, mixed>  $settings
     * @return array{0: int, 1: int} hifz and review points
     */
    private static function freeRecitationPoints(array $settings, FreeRecitation $recitation): array
    {
        $grade = $recitation->achievement;

        if ($recitation->type === 'review') {
            if (! ($settings['review_enabled'] ?? false)) {
                return [0, 0];
            }

            return [0, match ($grade) {
                3 => (int) ($settings['review_excellent'] ?? 5),
                2, 1 => (int) ($settings['review_good'] ?? 3),
                default => 0,
            }];
        }

        if (! ($settings['hifz_enabled'] ?? false)) {
            return [0, 0];
        }

        return [match ($grade) {
            3 => (int) ($settings['hifz_excellent'] ?? 10),
            2 => (int) ($settings['hifz_good'] ?? 7),
            1 => (int) ($settings['hifz_acceptable'] ?? 4),
            default => 0,
        }, 0];
    }
}
