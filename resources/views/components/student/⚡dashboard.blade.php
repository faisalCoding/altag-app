<?php

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use App\Models\StudentPlanDay;
use Carbon\Carbon;

new class extends Component {
    public function with()
    {
        $student = Auth::guard('student')->user();
        $todayStr = Carbon::now()->format('Y-m-d');
        $last30Start = Carbon::now()->subDays(30)->format('Y-m-d');

        // Fetch Earliest Pending Missions (one per active approved plan)
        $activeApprovedPlans = \App\Models\StudentPlan::where('student_id', $student->id)->where('status', 'active')->where('is_approved', 1)->get();

        /*
         * Hifz and review advance at their own pace: a teacher may be several
         * days ahead on one and behind on the other. Picking the single
         * earliest day with either part ungraded meant the student saw only
         * whichever was further behind, and never the other one at all. Each
         * part gets its own earliest ungraded day, and carries a marker so the
         * card shows that part alone.
         */
        $pendingMissions = [];
        foreach ($activeApprovedPlans as $plan) {
            foreach (['hifz', 'review'] as $part) {
                $day = StudentPlanDay::with(['fromAyah.surah', 'toAyah.surah', 'reviewFromAyah.surah', 'reviewToAyah.surah'])
                    ->where('student_plan_id', $plan->id)
                    ->whereNull($part.'_achievement')
                    ->orderBy('date', 'asc')
                    ->first();

                if (! $day) {
                    continue;
                }

                $day->setRelation('plan', $plan);
                $day->pendingPart = $part;
                $pendingMissions[] = $day;
            }
        }

        // Named so the summary and hero cards never read hifz fields off a
        // review day, which would show a range the student already recited.
        $pendingHifzMission = collect($pendingMissions)->firstWhere('pendingPart', 'hifz');
        $pendingReviewMission = collect($pendingMissions)->firstWhere('pendingPart', 'review');

        // Fetch Earliest Pending Hadith Missions (one per active Hadith plan)
        $activeHadithPlans = \App\Models\StudentHadithPlan::where('student_id', $student->id)->where('status', 'active')->get();

        // Ode plans had no place on this dashboard at all, so the student could
        // not reach one from here.
        $activeOdePlans = \App\Models\StudentOdePlan::where('student_id', $student->id)
            ->where('status', 'active')->with('path.ode')->get();
        $pendingHadithMissions = [];
        foreach ($activeHadithPlans as $plan) {
            $mission = \App\Models\HadithPathDay::with(['fromHadith', 'toHadith', 'reviewFromHadith', 'reviewToHadith'])
                ->where('hadith_path_id', $plan->hadith_path_id)
                ->where(function ($query) use ($plan) {
                    $query->whereDoesntHave('achievements', function ($q) use ($plan) {
                        $q->where('student_hadith_plan_id', $plan->id);
                    })
                        ->orWhereHas('achievements', function ($q) use ($plan) {
                            $q->where('student_hadith_plan_id', $plan->id)
                                ->where(function ($sub) {
                                    $sub->where(function ($h) {
                                        $h->whereNotNull('hadith_path_days.from_hadith_id')
                                            ->whereNull('student_hadith_achievements.hifz_achievement');
                                    })
                                        ->orWhere(function ($r) {
                                            $r->whereNotNull('hadith_path_days.review_from_hadith_id')
                                                ->whereNull('student_hadith_achievements.review_achievement');
                                        });
                                });
                        });
                })
                ->orderBy('day_number', 'asc')
                ->first();

            if ($mission) {
                $mission->setRelation('plan', $plan);

                $achievement = \App\Models\StudentHadithAchievement::where('student_hadith_plan_id', $plan->id)
                    ->where('hadith_path_day_id', $mission->id)
                    ->first();

                $mission->hifz_achievement = $achievement?->hifz_achievement;
                $mission->review_achievement = $achievement?->review_achievement;
                $mission->hifz_graded_at = $achievement?->hifz_graded_at;
                $mission->review_graded_at = $achievement?->review_graded_at;

                // Fetch all hadiths for this plan's path to allow showing text and previous texts
                $allHadiths = \App\Models\Hadith::with('lines')->where(function ($query) use ($plan) {
                    $query->where('hadith_text_id', $plan->path->hadith_text_id)
                        ->orWhereHas('chapter', function ($q) use ($plan) {
                            $q->where('hadith_text_id', $plan->path->hadith_text_id);
                        });
                })->orderBy('hadith_chapter_id', 'asc')->orderBy('id', 'asc')->get();

                $mission->allHadiths = $allHadiths;
                $pendingHadithMissions[] = $mission;
            }
        }

        // Fetch Stats
        $recentDays = StudentPlanDay::whereHas('plan', fn($q) => $q->where('student_id', $student->id))->where('date', '>=', $last30Start)->get();

        $excellent = 0;
        $good = 0;
        $acceptable = 0;

        foreach ($recentDays as $day) {
            $h = $day->hifz_achievement;
            $r = $day->review_achievement;

            if ($h == 3 || $r == 3) {
                $excellent++;
            } elseif ($h == 2 || $r == 2) {
                $good++;
            } elseif ($h == 1 || $r == 1) {
                $acceptable++;
            }
        }

        // Discipline Stats
        $calcPeriod = (int) \App\Models\Setting::getVal('calculation_period_days', 30);
        $absenceLimit = (int) \App\Models\Setting::getVal('absence_limit', 3);
        $latenessLimit = (int) \App\Models\Setting::getVal('lateness_limit', 5);

        $periodStart = Carbon::now()->subDays($calcPeriod)->format('Y-m-d');

        $absences = \App\Models\Attendance::where('student_id', $student->id)->where('date', '>=', $periodStart)->where('status', 'absent')->count();

        $lateness = \App\Models\Attendance::where('student_id', $student->id)->where('date', '>=', $periodStart)->where('status', 'late')->count();

        // Fetch Active Leaderboard: supervisor competitions (active) have priority, then teacher competitions
        $leaderboard = \App\Models\Leaderboard::whereHas('circles', fn($q) => $q->where('circles.id', $student->circle_id))
            ->whereNotNull('supervisor_id')
            ->where('is_active', true)
            ->with('circles')
            ->latest()
            ->first();

        if (!$leaderboard) {
            $leaderboard = \App\Models\Leaderboard::where('circle_id', $student->circle_id)
                ->whereNull('supervisor_id')
                ->where('is_active', true)
                ->with('circles')
                ->latest()
                ->first();
        }

        $leaderboardStandings = [];
        if ($leaderboard) {
            $service = new \App\Services\LeaderboardService();
            $leaderboardStandings = $service->getStandings($leaderboard);
        }

        $activeGamification = null;
        if ($leaderboard && $leaderboard->competition_type === 'gamification') {
            $activeGamification = $leaderboard;
        }

        // Last Attended Day Logic
        $lastAttendance = \App\Models\Attendance::where('student_id', $student->id)
            ->whereIn('status', ['present', 'late'])
            ->orderBy('date', 'desc')
            ->first();

        $lastGradedPlanDay = StudentPlanDay::whereHas('plan', fn($q) => $q->where('student_id', $student->id))
            ->where(function ($q) {
                $q->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement');
            })
            ->orderBy('date', 'desc')
            ->first();

        $lastDateObj = null;
        if ($lastAttendance && $lastGradedPlanDay) {
            $lastDateObj = $lastAttendance->date > $lastGradedPlanDay->date ? $lastAttendance->date : $lastGradedPlanDay->date;
        } elseif ($lastAttendance) {
            $lastDateObj = $lastAttendance->date;
        } elseif ($lastGradedPlanDay) {
            $lastDateObj = $lastGradedPlanDay->date;
        }

        $lastDayStats = null;
        if ($lastDateObj) {
            $dateStr = $lastDateObj->format('Y-m-d');

            $dayAttendance = \App\Models\Attendance::where('student_id', $student->id)->whereDate('date', $dateStr)->first();
            $attStatus = $dayAttendance ? $dayAttendance->status : null;

            $dayPlans = StudentPlanDay::whereHas('plan', fn($q) => $q->where('student_id', $student->id))->whereDate('date', $dateStr)->get();

            $hifzMax = $dayPlans->max('hifz_achievement') ?: 0;
            $reviewMax = $dayPlans->max('review_achievement') ?: 0;

            $manualCriteria = \Illuminate\Support\Facades\DB::table('leaderboard_scores')->join('leaderboard_criteria', 'leaderboard_scores.leaderboard_criterion_id', '=', 'leaderboard_criteria.id')->where('leaderboard_scores.student_id', $student->id)->whereDate('leaderboard_scores.date', $dateStr)->pluck('leaderboard_criteria.name')->toArray();

            $manualCriteriaCount = count($manualCriteria);

            $score = 0;
            if ($hifzMax == 3) {
                $score += 3;
            } elseif ($hifzMax == 2) {
                $score += 2;
            } elseif ($hifzMax == 1) {
                $score += 1;
            }
            if ($reviewMax == 3) {
                $score += 3;
            } elseif ($reviewMax == 2) {
                $score += 2;
            } elseif ($reviewMax == 1) {
                $score += 1;
            }
            if ($attStatus === 'present') {
                $score += 2;
            } elseif ($attStatus === 'late') {
                $score += 1;
            }
            if ($manualCriteriaCount > 0) {
                $score += 1;
            }

            $case = 'weak';
            $message = 'بإمكانك تقديم أداء أفضل، لا تدع الكسل يغلبك واستعن بالله!';
            $color = 'zinc';
            $icon = 'arrow-trending-down';

            if ($score >= 7) {
                $case = 'excellent';
                $message = 'أداء مذهل ومثالي! أنت فخر لنا، استمر على هذا التميز والتألق في حفظ كتاب الله.';
                $color = 'emerald';
                $icon = 'star';
            } elseif ($score >= 5) {
                $case = 'good';
                $message = 'أداء رائع جداً! خطواتك ثابتة، بقليل من الجهد الإضافي ستصل للقمة.';
                $color = 'indigo';
                $icon = 'arrow-trending-up';
            } elseif ($score >= 3) {
                $case = 'acceptable';
                $message = 'أداء مقبول، لكننا نثق بأن قدراتك أعلى من ذلك بكثير. ننتظر منك الأفضل غداً!';
                $color = 'amber';
                $icon = 'minus';
            }

            $themeClasses = [
                'zinc' => [
                    'card' => 'bg-zinc-50 dark:bg-zinc-900/40 border-zinc-200 dark:border-zinc-800',
                    'strip' => 'bg-zinc-500',
                    'iconWrapper' => 'border-zinc-100 dark:border-zinc-700 text-zinc-500',
                    'heading' => 'text-zinc-700 dark:text-zinc-400',
                ],
                'emerald' => [
                    'card' => 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800/50',
                    'strip' => 'bg-emerald-500',
                    'iconWrapper' => 'border-emerald-100 dark:border-zinc-700 text-emerald-500',
                    'heading' => 'text-emerald-700 dark:text-emerald-400',
                ],
                'indigo' => [
                    'card' => 'bg-indigo-50 dark:bg-indigo-900/20 border-indigo-200 dark:border-indigo-800/50',
                    'strip' => 'bg-indigo-500',
                    'iconWrapper' => 'border-indigo-100 dark:border-zinc-700 text-indigo-500',
                    'heading' => 'text-indigo-700 dark:text-indigo-400',
                ],
                'amber' => [
                    'card' => 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800/50',
                    'strip' => 'bg-amber-500',
                    'iconWrapper' => 'border-amber-100 dark:border-zinc-700 text-amber-500',
                    'heading' => 'text-amber-700 dark:text-amber-400',
                ],
            ];

            $lastDayStats = [
                'date' => $dateStr,
                'hifz' => $hifzMax,
                'review' => $reviewMax,
                'attendance' => $attStatus,
                'criteria_count' => $manualCriteriaCount,
                'criteria_names' => $manualCriteria,
                'case' => $case,
                'message' => $message,
                'theme' => $themeClasses[$color],
                'icon' => $icon,
            ];
        }

        // Turn Reservation logic
        $activeSession = null;
        if ($student->circle_id) {
            $teacherIds = \Illuminate\Support\Facades\DB::table('circle_teacher')->where('circle_id', $student->circle_id)->pluck('teacher_id');

            $sessions = \App\Models\TurnReservationSession::whereIn('teacher_id', $teacherIds)->get();

            foreach ($sessions as $session) {
                if ($session->isActiveToday()) {
                    $activeSession = $session;
                    break;
                }
            }
        }

        $studentReservation = null;
        if ($activeSession) {
            $studentReservation = \App\Models\TurnReservation::where('turn_reservation_session_id', $activeSession->id)->whereDate('date', $todayStr)->where('student_id', $student->id)->first();
        }

        $pendingChallenges = \App\Models\Challenge::where('student_id', $student->id)->where('status', 'pending')->with('items', 'guardian')->get();

        $activeChallenges = \App\Models\Challenge::where('student_id', $student->id)->where('status', 'active')->with('items')->get();

        $nextExam = \App\Models\StudentExam::where('student_id', $student->id)
            ->where('status', 'pending')
            ->where('date_time', '>=', now())
            ->with('examLevel.startAyah.surah', 'examLevel.endAyah.surah')
            ->orderBy('date_time', 'asc')
            ->first();

        $memorizedRangeForStats = $student->getMemorizedRange();
        $memorizedAyahsCount = $memorizedRangeForStats ? $memorizedRangeForStats['max'] - $memorizedRangeForStats['min'] + 1 : 0;
        $surahMap = \App\Services\MemorizationJourneyService::surahMap($student);
        $completedSurahsCount = collect($surahMap)->where('status', 'full')->count();

        $studentLevel = null;
        if ($leaderboard) {
            $studentLevel = \App\Services\GamificationService::getStudentLevel($student->id, $leaderboard->id);
        }

        $monthlyStats = \App\Services\MemorizationJourneyService::monthlyAyahsMemorized($student);
        $recentActivity = \App\Services\StudentActivityFeedService::recentActivity($student, $leaderboard);

        $teamTaskAssignment = null;
        if ($leaderboard) {
            $studentTeamIds = $student->gamificationTeams()->pluck('gamification_teams.id');
            $teamTaskAssignment = \App\Models\GamificationTeamTaskAssignment::whereIn('team_id', $studentTeamIds)
                ->whereHas('task', fn ($q) => $q->where('leaderboard_id', $leaderboard->id))
                ->where('status', 'assigned')
                ->with('task')
                ->orderByDesc('end_date')
                ->first();
        }

        $studentBadges = collect();
        if ($leaderboard) {
            $studentBadges = \App\Models\GamificationBadge::join('gamification_badge_student', 'gamification_badges.id', '=', 'gamification_badge_student.badge_id')
                ->where('gamification_badge_student.student_id', $student->id)
                ->where('gamification_badge_student.status', 'claimed')
                ->where('gamification_badges.leaderboard_id', $leaderboard->id)
                ->select('gamification_badges.*')
                ->get();
        }

        $currentStreak = null;
        if ($leaderboard) {
            $currentStreak = \App\Models\GamificationStudentState::where('student_id', $student->id)
                ->where('leaderboard_id', $leaderboard->id)
                ->value('current_streak');
        }
        if ($currentStreak === null) {
            $currentStreak = $student->currentAttendanceStreakDays();
        }

        return [
            'student' => $student,
            'todayStr' => $todayStr,
            'verseOfDay' => \App\Services\QuranVerseOfDayService::today(),
            'circleNews' => \App\Services\StudentActivityFeedService::circleNews($student, $leaderboard),
            'memorizedAyahsCount' => $memorizedAyahsCount,
            'completedSurahsCount' => $completedSurahsCount,
            'memorizedJuzCount' => \App\Services\MemorizationJourneyService::memorizedJuzCount($student),
            'memorizationPercentage' => $student->memorizationPercentage(),
            'currentSurahProgress' => \App\Services\MemorizationJourneyService::currentSurahProgress($student),
            'todayMissionProgress' => \App\Services\MemorizationJourneyService::todayMissionProgress($student),
            'surahMap' => $surahMap,
            'studentBadges' => $studentBadges,
            'monthlyStats' => $monthlyStats,
            'recentActivity' => $recentActivity,
            'teamTaskAssignment' => $teamTaskAssignment,
            'currentStreak' => $currentStreak,
            'studentLevel' => $studentLevel,
            'pendingMissions' => $pendingMissions,
            'pendingHifzMission' => $pendingHifzMission,
            'pendingReviewMission' => $pendingReviewMission,
            'pendingHadithMissions' => $pendingHadithMissions,
            'activeHadithPlans' => $activeHadithPlans,
            'activeOdePlans' => $activeOdePlans,
            'excellent' => $excellent,
            'good' => $good,
            'acceptable' => $acceptable,
            'calcPeriod' => $calcPeriod,
            'absenceLimit' => $absenceLimit,
            'latenessLimit' => $latenessLimit,
            'absences' => $absences,
            'lateness' => $lateness,
            'leaderboard' => $leaderboard,
            'leaderboardStandings' => $leaderboardStandings,
            'lastDayStats' => $lastDayStats,
            'activeSession' => $activeSession,
            'studentReservation' => $studentReservation,
            'pendingChallenges' => $pendingChallenges,
            'activeChallenges' => $activeChallenges,
            'nextExam' => $nextExam,
            'activeGamification' => $activeGamification,
        ];
    }

    public function acceptChallenge($id)
    {
        $student = Auth::guard('student')->user();
        $challenge = \App\Models\Challenge::where('student_id', $student->id)->where('id', $id)->where('status', 'pending')->firstOrFail();

        $challenge->update([
            'status' => 'active',
            'accepted_at' => now(),
        ]);

        $this->dispatch('toast', variant: 'success', heading: 'تم قبول التحدي! بالتوفيق');
    }

    public function rejectChallenge($id)
    {
        $student = Auth::guard('student')->user();
        $challenge = \App\Models\Challenge::where('student_id', $student->id)->where('id', $id)->where('status', 'pending')->firstOrFail();

        $challenge->update(['status' => 'cancelled']);

        $this->dispatch('toast', variant: 'neutral', heading: 'تم رفض التحدي');
    }

    protected function getHijriLabel(\DateTimeInterface|string $date)
    {
        $parsed = is_string($date) ? \Carbon\Carbon::parse($date) : $date;
        return \App\Support\HijriDate::full($parsed->getTimestamp());
    }

    public function reserveTurn($sessionId)
    {
        $student = Auth::guard('student')->user();
        $session = \App\Models\TurnReservationSession::find($sessionId);

        if (!$session || !$session->isActiveNow()) {
            Flux::toast('عذراً، وقت الحجز غير متاح حالياً.', variant: 'danger');
            return;
        }

        $todayStr = \Carbon\Carbon::now('Asia/Riyadh')->format('Y-m-d');

        $existing = \App\Models\TurnReservation::where('turn_reservation_session_id', $sessionId)->whereDate('date', $todayStr)->where('student_id', $student->id)->first();

        if ($existing) {
            return;
        }

        $maxTurn = \App\Models\TurnReservation::where('turn_reservation_session_id', $sessionId)->whereDate('date', $todayStr)->max('turn_number') ?? 0;

        \App\Models\TurnReservation::create([
            'turn_reservation_session_id' => $sessionId,
            'student_id' => $student->id,
            'date' => $todayStr,
            'turn_number' => $maxTurn + 1,
        ]);

        Flux::toast('تم حجز دورك بنجاح!', variant: 'success');
    }

    public function cancelTurn($sessionId)
    {
        $student = Auth::guard('student')->user();
        $todayStr = \Carbon\Carbon::now('Asia/Riyadh')->format('Y-m-d');

        \App\Models\TurnReservation::where('turn_reservation_session_id', $sessionId)->whereDate('date', $todayStr)->where('student_id', $student->id)->delete();

        Flux::toast('تم إلغاء حجزك بنجاح.', variant: 'success');
    }
};
?>

<div>
    <x-shared.pending-surveys />

    {{-- The whole "everyday" dashboard is hidden while a competition is
    active — the page should show only the competition widget, not both. --}}
    @unless($leaderboard)
    <div class="space-y-6 mb-8" dir="rtl">
        {{-- الصف الأول: بانر آية اليوم المضغوط + الترحيب والإحصائيات --}}
        <div class="grid grid-cols-1 lg:grid-cols-[300px_1fr] gap-6 items-stretch">
            <div class="order-2 lg:order-1 relative overflow-hidden rounded-2xl bg-gradient-to-b from-[#f7efe0] via-[#faf5ea] to-[#fdfaf3] dark:from-zinc-800 dark:to-zinc-900 border border-amber-100/70 dark:border-zinc-800 p-6 flex flex-col items-center justify-center text-center">
                <svg viewBox="0 0 120 140" class="w-16 h-auto mb-3" xmlns="http://www.w3.org/2000/svg" fill="none">
                    <path d="M60 0v14" stroke="#a8791f" stroke-width="2" stroke-linecap="round" />
                    <rect x="40" y="18" width="40" height="60" rx="5" stroke="#a8791f" stroke-width="2" />
                    <circle cx="60" cy="48" r="11" fill="#f0c869" opacity="0.5" />
                    <path d="M50 80h20l5 12H45l5-12Z" stroke="#a8791f" stroke-width="2" stroke-linejoin="round" />
                    <circle cx="60" cy="96" r="2.5" fill="#a8791f" />
                </svg>
                @if($verseOfDay)
                    <p class="font-zain text-lg text-maroon dark:text-red-secondary leading-relaxed">
                        {{ '﴿ '.$verseOfDay->text_uthmani.' ﴾' }}
                    </p>
                    <p class="text-xs text-neutral-grey dark:text-zinc-400 mt-2">
                        {{ __('سورة :surah - الآية :ayah', ['surah' => $verseOfDay->surah->name_arabic, 'ayah' => $verseOfDay->verse_number]) }}
                    </p>
                @endif
            </div>

            <div class="order-1 lg:order-2 flex flex-col gap-6">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h1 class="text-xl md:text-2xl font-black text-zinc-900 dark:text-white">
                            👋 {{ __('مرحباً بك، :name', ['name' => $student->name]) }}
                        </h1>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ __('بالتوفيق في رحلتك لحفظ كتاب الله') }}</p>
                    </div>
                    @if(!empty($pendingMissions) || !empty($pendingHadithMissions))
                        <flux:button href="#today-mission" variant="primary" class="!bg-maroon hover:!bg-burgundy shrink-0 hidden sm:inline-flex">
                            {{ __('استكمل الحفظ') }}
                        </flux:button>
                    @endif
                </div>

                {{-- Quick stats --}}
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <x-student.partials.stat-card
                        icon="check-badge"
                        :label="__('سور مكتملة')"
                        :value="$completedSurahsCount"
                        :suffix="__('سورة')"
                        :empty="$completedSurahsCount === 0"
                        :empty-text="__('لم تكتمل سورة بعد')"
                        accent-class="bg-emerald-500/10 text-emerald-600" />
                    <x-student.partials.stat-card
                        icon="square-3-stack-3d"
                        :label="__('أجزاء محفوظة')"
                        :value="$memorizedJuzCount"
                        :suffix="__('جزء')"
                        :empty="$memorizedAyahsCount === 0"
                        :empty-text="__('ابدأ رحلتك في الحفظ')"
                        accent-class="bg-indigo-500/10 text-indigo-600" />
                    <x-student.partials.stat-card
                        icon="chart-pie"
                        :label="__('إجمالي الحفظ')"
                        :value="$memorizationPercentage"
                        suffix="%"
                        :empty="$memorizedAyahsCount === 0"
                        :empty-text="__('ابدأ رحلتك في الحفظ')"
                        accent-class="bg-sky-500/10 text-sky-600" />
                    <x-student.partials.stat-card
                        icon="fire"
                        :label="__('الأيام المتتالية')"
                        :value="$currentStreak"
                        :suffix="__('يوم')"
                        :empty="$currentStreak === 0"
                        :empty-text="__('ابدأ اليوم!')"
                        accent-class="bg-amber-500/10 text-amber-600" />
                </div>
            </div>
        </div>

        {{-- الصف الثاني: التقويم وأحدث الإنجازات + تقدم الحفظ وجلسات اليوم والواجبات --}}
        <div class="grid grid-cols-1 lg:grid-cols-[300px_1fr] gap-6 items-start">
            <div class="order-2 lg:order-1 flex flex-col gap-6">
                <livewire:student.calendar-widget />

                <div id="achievements" class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5">
                    <flux:heading size="sm" class="flex items-center gap-2 mb-4">
                        <flux:icon icon="trophy" class="size-4 text-maroon" />
                        {{ __('أحدث الإنجازات') }}
                    </flux:heading>

                    @if($studentBadges->isEmpty())
                        <p class="text-sm text-zinc-400 text-center py-6">{{ __('لا توجد إنجازات بعد') }}</p>
                    @else
                        <div class="space-y-3">
                            @foreach($studentBadges->sortByDesc('pivot.updated_at')->take(4) as $badge)
                                <div class="flex items-center gap-3">
                                    <div class="size-9 rounded-full bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center shrink-0">
                                        <flux:icon icon="{{ $badge->icon ?? 'star' }}" variant="solid" class="size-4 text-amber-500" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100 truncate">{{ $badge->name }}</div>
                                        <div class="text-xs text-zinc-400">+{{ $badge->reward_xp ?? 0 }} {{ __('نقطة') }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <flux:button href="#achievements" variant="ghost" size="sm" class="w-full mt-4">
                            {{ __('عرض جميع الإنجازات') }}
                        </flux:button>
                    @endif
                </div>
            </div>

            <div class="order-1 lg:order-2 flex flex-col gap-6">
                <div class="grid grid-cols-1 xl:grid-cols-[1fr_280px] gap-6">
                    {{-- تقدمك في الحفظ --}}
                    <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6">
                        <flux:heading size="sm" class="mb-4">{{ __('تقدمك في الحفظ') }}</flux:heading>

                        @if($currentSurahProgress)
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 items-center">
                                <div>
                                    <p class="text-xs text-zinc-400">{{ __('أنت الآن في') }}</p>
                                    <p class="text-lg font-black text-zinc-900 dark:text-white mt-1">{{ $currentSurahProgress['surah_name'] }}</p>
                                    <p class="text-xs text-zinc-400 mt-1">
                                        {{ __('من الآية :from إلى الآية :to', ['from' => $currentSurahProgress['from_verse'], 'to' => $currentSurahProgress['to_verse']]) }}
                                    </p>

                                    <div class="flex items-center gap-6 mt-5">
                                        <div>
                                            <div class="text-lg font-extrabold text-emerald-600">{{ $currentSurahProgress['memorized_in_surah'] }}</div>
                                            <div class="text-[11px] text-zinc-400">{{ __('الآيات المحفوظة') }}</div>
                                        </div>
                                        <div>
                                            <div class="text-lg font-extrabold text-zinc-700 dark:text-zinc-200">{{ $currentSurahProgress['total_in_surah'] }}</div>
                                            <div class="text-[11px] text-zinc-400">{{ __('إجمالي آيات السورة') }}</div>
                                        </div>
                                    </div>

                                    <flux:button href="{{ route('student.plan') }}" variant="primary" size="sm" class="!bg-maroon hover:!bg-burgundy mt-5" wire:navigate>
                                        {{ __('متابعة الحفظ') }}
                                    </flux:button>
                                </div>

                                <div class="flex flex-col items-center justify-center">
                                    <x-student.partials.progress-ring :percentage="$currentSurahProgress['percentage']" :size="140" :stroke-width="10" progress-class="text-emerald-500">
                                        <span class="text-2xl font-black text-zinc-900 dark:text-white">{{ $currentSurahProgress['percentage'] }}%</span>
                                    </x-student.partials.progress-ring>
                                    <p class="text-xs text-zinc-400 mt-2">{{ __('إجمالي التقدم في السورة') }}</p>
                                </div>
                            </div>

                            @if($pendingHifzMission)
                                <div class="mt-6 pt-5 border-t border-zinc-100 dark:border-zinc-800">
                                    <div class="flex items-center justify-between text-xs mb-2">
                                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('الهدف التالي') }}</span>
                                        <span class="font-bold text-zinc-700 dark:text-zinc-200">{{ round($memorizationPercentage) }}%</span>
                                    </div>
                                    <p class="text-sm font-bold text-zinc-800 dark:text-zinc-100 mb-2">
                                        {{ $pendingHifzMission->fromAyah?->surah?->name_arabic }} - {{ __('من الآية :from إلى الآية :to', ['from' => $pendingHifzMission->fromAyah?->verse_number, 'to' => $pendingHifzMission->toAyah?->verse_number]) }}
                                    </p>
                                    <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                        <div class="h-full bg-maroon rounded-full" style="width: {{ min(100, round($memorizationPercentage)) }}%"></div>
                                    </div>
                                </div>
                            @endif
                        @else
                            <p class="text-sm text-zinc-400 text-center py-6">{{ __('لم تبدأ الحفظ بعد') }}</p>
                        @endif
                    </div>

                    {{-- جلسات اليوم --}}
                    <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 flex flex-col">
                        <flux:heading size="sm" class="flex items-center gap-2 mb-4">
                            <flux:icon icon="calendar" class="size-4 text-maroon" />
                            {{ __('جلسات اليوم') }}
                        </flux:heading>

                        <div class="flex-1 flex items-center justify-between gap-4">
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ __(':completed من :total جلسات مكتملة', ['completed' => $todayMissionProgress['completed'], 'total' => $todayMissionProgress['total']]) }}
                            </p>
                            <x-student.partials.progress-ring :percentage="$todayMissionProgress['percentage']" :size="64" :stroke-width="6" progress-class="text-maroon">
                                <span class="text-sm font-black text-zinc-900 dark:text-white">{{ $todayMissionProgress['percentage'] }}%</span>
                            </x-student.partials.progress-ring>
                        </div>
                    </div>
                </div>

                {{-- الواجبات --}}
                <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6">
                    @php
                        $assignmentItems = collect();
                        foreach ($pendingMissions as $mission) {
                            if ($mission->pendingPart === 'hifz' && $mission->fromAyah && $mission->toAyah) {
                                $assignmentItems->push([
                                    'title' => __('حفظ آيات جديدة'),
                                    'detail' => $mission->fromAyah->surah->name_arabic.' - '.__('من الآية :from إلى الآية :to', ['from' => $mission->fromAyah->verse_number, 'to' => $mission->toAyah->verse_number]),
                                ]);
                            }
                            if ($mission->pendingPart === 'review' && $mission->reviewFromAyah && $mission->reviewToAyah) {
                                $assignmentItems->push([
                                    'title' => __('مراجعة الآيات المحفوظة'),
                                    'detail' => $mission->reviewFromAyah->surah->name_arabic.' - '.__('من الآية :from إلى الآية :to', ['from' => $mission->reviewFromAyah->verse_number, 'to' => $mission->reviewToAyah->verse_number]),
                                ]);
                            }
                        }
                        if ($nextExam) {
                            $assignmentItems->push([
                                'title' => __('اختبار قادم'),
                                'detail' => $nextExam->examLevel?->name ?? __('اختبار في المستوى الحالي'),
                            ]);
                        }
                    @endphp

                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="sm" class="flex items-center gap-2">
                            <flux:icon icon="clipboard-document-list" class="size-4 text-maroon" />
                            {{ __('الواجبات') }}
                        </flux:heading>
                        <span class="text-xs text-zinc-400">{{ __('لديك :count واجبات متبقية', ['count' => $assignmentItems->count()]) }}</span>
                    </div>

                    @if($assignmentItems->isEmpty())
                        <p class="text-sm text-zinc-400 text-center py-6">{{ __('لا توجد واجبات متبقية 🎉') }}</p>
                    @else
                        <div class="space-y-3">
                            @foreach($assignmentItems as $item)
                                <div class="flex items-center justify-between gap-3 py-2 border-b border-zinc-50 dark:border-zinc-800/60 last:border-0">
                                    <div class="min-w-0">
                                        <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100 truncate">{{ $item['title'] }}</div>
                                        <div class="text-xs text-zinc-400 truncate">{{ $item['detail'] }}</div>
                                    </div>
                                    <flux:badge color="amber" size="sm" class="shrink-0">{{ __('متبقي') }}</flux:badge>
                                </div>
                            @endforeach
                        </div>
                        <flux:button href="#today-mission" variant="ghost" size="sm" class="w-full mt-4">
                            {{ __('عرض جميع الواجبات') }}
                        </flux:button>
                    @endif
                </div>

                {{-- لا تتوقف + آخر الأنشطة --}}
                <div class="grid grid-cols-1 xl:grid-cols-[280px_1fr] gap-6">
                    <div class="rounded-2xl bg-gradient-to-b from-emerald-50 to-white dark:from-emerald-900/10 dark:to-zinc-900 border border-emerald-100 dark:border-emerald-900/30 p-6 flex flex-col items-center text-center">
                        <flux:icon icon="sparkles" variant="solid" class="size-8 text-emerald-500 mb-3" />
                        <p class="font-bold text-zinc-800 dark:text-zinc-100">{{ __('لا تتوقف') }}</p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-2 leading-relaxed">
                            {{ __('كل آية تحفظها اليوم هي نور لك في الدنيا والآخرة') }}
                        </p>
                        <flux:button href="{{ route('student.plan') }}" variant="primary" size="sm" class="!bg-maroon hover:!bg-burgundy mt-4" wire:navigate>
                            {{ __('استمر في رحلتك') }}
                        </flux:button>
                    </div>

                    <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6">
                        <flux:heading size="sm" class="mb-4">{{ __('آخر الأنشطة') }}</flux:heading>

                        @if(empty($recentActivity))
                            <p class="text-sm text-zinc-400 text-center py-6">{{ __('لا يوجد نشاط بعد') }}</p>
                        @else
                            <div class="space-y-4">
                                @foreach(array_slice($recentActivity, 0, 3) as $activityItem)
                                    <div class="flex items-start gap-3">
                                        <div class="size-8 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center shrink-0 mt-0.5">
                                            <flux:icon icon="{{ $activityItem['icon'] ?? 'check' }}" variant="solid" class="size-4 text-emerald-500" />
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-sm text-zinc-700 dark:text-zinc-200">{{ $activityItem['title'] }}</div>
                                            <div class="text-xs text-zinc-400 mt-0.5">{{ $activityItem['date']->diffForHumans() }}</div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endunless

    @if($activeGamification)
        <livewire:student.gamification-dashboard />
    @else
        <div class="space-y-8" dir="rtl">

            {{-- Level / XP progress: kept visible even when a competition is
            active, since it's scoped to that competition (leaderboard_id),
            not general daily content. --}}
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-xs">
                @if($studentLevel && $studentLevel['current'])
                    @php
                        $levelProgress = \App\Services\GamificationService::levelProgressPercentage($studentLevel);
                    @endphp
                    <div class="flex items-center gap-5">
                        <x-student.partials.progress-ring :percentage="$levelProgress" :size="88" progress-class="text-maroon">
                            <div class="text-center">
                                <div class="text-lg font-black text-zinc-800 dark:text-zinc-100">{{ $studentLevel['current']->level_number ?? 1 }}</div>
                                <div class="text-[10px] text-zinc-400">{{ __('مستوى') }}</div>
                            </div>
                        </x-student.partials.progress-ring>
                        <div class="min-w-0">
                            <div class="font-bold text-zinc-800 dark:text-zinc-100">{{ $studentLevel['current']->name ?? __('المستوى الحالي') }}</div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                                {{ $studentLevel['xp'] }} XP
                                @if($studentLevel['next'])
                                    / {{ $studentLevel['next']->xp_required }} XP
                                    <span class="text-zinc-400">({{ __('باقي :points نقطة للمستوى القادم', ['points' => max(0, $studentLevel['next']->xp_required - $studentLevel['xp'])]) }})</span>
                                @else
                                    <span class="text-zinc-400">{{ __('أقصى مستوى!') }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @else
                    <div class="flex items-center gap-3 text-zinc-400">
                        <flux:icon icon="trophy" class="size-6" />
                        <span class="text-sm">{{ __('لا توجد مسابقة نشطة حالياً لعرض مستواك ونقاطك') }}</span>
                    </div>
                @endif
            </div>

            @unless($leaderboard)
            {{-- Today's mission + notifications --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="lg:col-span-2 rounded-2xl border border-emerald-100 dark:border-emerald-900/40 bg-gradient-to-br from-emerald-50 to-white dark:from-emerald-950/20 dark:to-zinc-900 p-6">
                    @php
                        // Prefer the next hifz; fall back to the next review when hifz is done.
                        $heroMission = $pendingHifzMission ?? $pendingReviewMission;
                        $heroHadithMission = $pendingHadithMissions[0] ?? null;
                    @endphp
                    @if($heroMission)
                        <div class="flex items-center gap-2 text-emerald-600 dark:text-emerald-400 text-sm font-bold mb-3">
                            <flux:icon icon="flag" variant="solid" class="size-4" />
                            {{ __('مهمة اليوم') }}
                        </div>
                        <div class="text-xl font-bold text-zinc-800 dark:text-zinc-100 mb-1">
                            {{ $heroMission->formatRange($heroMission->pendingPart) ?? __('حفظ مقطع جديد') }}
                        </div>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">{{ $heroMission->pendingPart === 'review' ? __('مراجعة') : __('حفظ') }}</p>
                        <flux:button href="#today-mission" variant="primary" icon="play" class="!bg-emerald-600 hover:!bg-emerald-700">
                            {{ __('ابدأ الآن') }}
                        </flux:button>
                    @elseif($heroHadithMission)
                        <div class="flex items-center gap-2 text-rose-600 dark:text-rose-400 text-sm font-bold mb-3">
                            <flux:icon icon="flag" variant="solid" class="size-4" />
                            {{ __('مهمة اليوم') }}
                        </div>
                        <div class="text-xl font-bold text-zinc-800 dark:text-zinc-100 mb-1">
                            {{ __('حفظ حديث جديد') }}
                        </div>
                        <flux:button href="{{ route('student.plan') }}" variant="primary" icon="play" class="!bg-rose-600 hover:!bg-rose-700" wire:navigate>
                            {{ __('ابدأ الآن') }}
                        </flux:button>
                    @else
                        <div class="flex items-center gap-3 text-emerald-700 dark:text-emerald-400">
                            <flux:icon icon="check-circle" variant="solid" class="size-8" />
                            <div>
                                <div class="font-bold">{{ __('أنت في يوم راحة اليوم!') }}</div>
                                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">{{ __('لا توجد مهام معلقة حالياً') }}</p>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6">
                    <div class="flex items-center gap-2 text-zinc-500 dark:text-zinc-400 text-sm font-bold mb-3">
                        <flux:icon icon="bell" class="size-4" />
                        {{ __('تنبيهات') }}
                    </div>
                    <div class="space-y-2">
                        @if($nextExam)
                            <div class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                <flux:icon icon="academic-cap" class="size-4 text-indigo-500 shrink-0" />
                                {{ __('اختبار قادم: :date', ['date' => $nextExam->date_time->translatedFormat('d F')]) }}
                            </div>
                        @endif
                        @if($activeSession)
                            <div class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                <flux:icon icon="user-circle" class="size-4 text-amber-500 shrink-0" />
                                {{ __('لديك جلسة تسميع اليوم') }}
                            </div>
                        @endif
                        @if(!$nextExam && !$activeSession)
                            <div class="text-sm text-zinc-400">{{ __('لا توجد تنبيهات حالياً') }}</div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Quran journey: progress rings + timeline, both driven by surahMap() --}}
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-xs">
                <x-student.partials.section-heading :title="__('تقدم الحفظ')" icon="chart-bar" />
                <div class="flex gap-4 overflow-x-auto pb-2 -mx-1 px-1">
                    @foreach($surahMap as $surahItem)
                        <x-student.partials.surah-progress-ring :surah="$surahItem" wire:key="ring-{{ $surahItem['surah_id'] }}" />
                    @endforeach
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-xs">
                <x-student.partials.section-heading :title="__('مسارك القرآني')" icon="map" />
                <div class="max-h-96 overflow-y-auto space-y-1 pr-1">
                    @foreach($surahMap as $surahItem)
                        @php
                            $statusIcon = match($surahItem['status']) {
                                'full' => ['icon' => 'check-circle', 'class' => 'text-emerald-500'],
                                'partial' => ['icon' => 'ellipsis-horizontal-circle', 'class' => 'text-amber-500'],
                                default => ['icon' => 'minus-circle', 'class' => 'text-zinc-300 dark:text-zinc-700'],
                            };
                        @endphp
                        <div class="flex items-center gap-3 py-2 px-2 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <flux:icon icon="{{ $statusIcon['icon'] }}" variant="solid" class="size-5 shrink-0 {{ $statusIcon['class'] }}" />
                            <span class="text-sm text-zinc-400 w-6 shrink-0">{{ $surahItem['number'] }}</span>
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300 flex-1 truncate">{{ $surahItem['name'] }}</span>
                            <span class="text-xs font-bold text-zinc-500 dark:text-zinc-400 shrink-0">
                                {{ $surahItem['status'] === 'none' ? __('لم تبدأ') : $surahItem['percentage'].'%' }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
            @endunless

            {{-- Achievements + leaderboard mini widget: badges earned and the
            top-3 preview are both scoped to the active competition, so they
            stay visible alongside it rather than being hidden as "everything
            else". --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-xs">
                    <x-student.partials.section-heading :title="__('الإنجازات')" icon="trophy" href="{{ $leaderboard ? '#leaderboard-standings' : null }}" />
                    @if($studentBadges->isNotEmpty())
                        <div class="grid grid-cols-3 sm:grid-cols-4 gap-3">
                            @foreach($studentBadges as $badge)
                                <div class="flex flex-col items-center gap-1.5 p-3 rounded-xl bg-amber-50 dark:bg-amber-900/20 text-center" wire:key="badge-{{ $badge->id }}">
                                    <flux:icon icon="{{ $badge->icon ?: 'trophy' }}" variant="solid" class="size-6 text-amber-500" />
                                    <span class="text-[11px] font-bold text-zinc-600 dark:text-zinc-300 truncate w-full">{{ $badge->name }}</span>
                                </div>
                            @endforeach
                        </div>
                    @elseif($leaderboard)
                        <div class="text-center py-6 text-sm text-zinc-400">{{ __('لم تحصل على أوسمة بعد') }}</div>
                    @else
                        <div class="text-center py-6 text-sm text-zinc-400">{{ __('لا توجد مسابقة نشطة لعرض الأوسمة بعد') }}</div>
                    @endif
                </div>

                <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-xs">
                    <x-student.partials.section-heading :title="__('لوحة المتصدرين')" icon="star" href="{{ $leaderboard ? '#leaderboard-standings' : null }}" />
                    @if($leaderboard && !empty($leaderboardStandings) && collect($leaderboardStandings)->isNotEmpty())
                        <x-student.partials.leaderboard-podium :top3="collect($leaderboardStandings)->take(3)->values()" />
                    @else
                        <div class="text-center py-6 text-sm text-zinc-400">{{ __('لا توجد مسابقة نشطة حالياً') }}</div>
                    @endif
                </div>
            </div>

            @unless($leaderboard)
            {{-- Monthly stats + recent activity + team assignment --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-xs">
                    <x-student.partials.section-heading :title="__('إحصائيات الشهر')" icon="chart-bar-square" />
                    @if(!empty($monthlyStats))
                        @php $maxCount = collect($monthlyStats)->max('count') ?: 1; @endphp
                        <div class="flex items-end gap-1.5 h-32">
                            @foreach($monthlyStats as $stat)
                                <div class="flex-1 flex flex-col items-center justify-end h-full group">
                                    <div class="w-full bg-maroon/70 hover:bg-maroon rounded-t transition-colors" style="height: {{ max(4, round($stat['count'] / $maxCount * 100)) }}%" title="{{ $stat['date'] }}: {{ $stat['count'] }}"></div>
                                </div>
                            @endforeach
                        </div>
                        <p class="text-xs text-zinc-400 mt-3 text-center">{{ __('عدد الآيات المحفوظة يومياً هذا الشهر') }}</p>
                    @else
                        <div class="text-center py-8 text-sm text-zinc-400">{{ __('لا توجد بيانات حفظ لهذا الشهر بعد') }}</div>
                    @endif
                </div>

                <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-xs">
                    <x-student.partials.section-heading :title="__('النشاط الأخير')" icon="clock" />
                    @if(!empty($recentActivity))
                        <div class="space-y-3">
                            @foreach($recentActivity as $activityItem)
                                <div class="flex items-center gap-3">
                                    <div class="size-8 rounded-full bg-emerald-50 dark:bg-emerald-900/30 flex items-center justify-center shrink-0">
                                        <flux:icon icon="{{ $activityItem['icon'] }}" variant="solid" class="size-4 text-emerald-500" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm text-zinc-700 dark:text-zinc-300 truncate">{{ $activityItem['title'] }}</p>
                                        <p class="text-xs text-zinc-400">{{ $activityItem['date']->diffForHumans() }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8 text-sm text-zinc-400">{{ __('ستظهر أنشطتك هنا بمجرد أن تبدأ رحلتك') }}</div>
                    @endif
                </div>
            </div>

            {{-- Team assignment --}}
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-xs">
                <x-student.partials.section-heading :title="__('الواجبات')" icon="clipboard-document-list" />
                @if($teamTaskAssignment && $teamTaskAssignment->task)
                    @php
                        $daysLeft = now()->startOfDay()->diffInDays($teamTaskAssignment->end_date->startOfDay(), false);
                        $isOverdue = $daysLeft < 0;
                    @endphp
                    <div class="flex items-center justify-between gap-4 p-4 rounded-xl {{ $isOverdue ? 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/50' : 'bg-zinc-50 dark:bg-zinc-800/50' }}">
                        <div class="min-w-0">
                            <div class="font-bold text-zinc-800 dark:text-zinc-100 truncate">{{ $teamTaskAssignment->task->name }}</div>
                            <div class="text-xs {{ $isOverdue ? 'text-red-500' : 'text-zinc-400' }} mt-1">
                                @if($isOverdue)
                                    {{ __('انتهى الموعد') }}
                                @else
                                    {{ __('ينتهي بعد :days يوم', ['days' => $daysLeft]) }}
                                @endif
                            </div>
                        </div>
                        @if($teamTaskAssignment->task->xp_reward > 0)
                            <flux:badge color="amber">+{{ $teamTaskAssignment->task->xp_reward }} XP</flux:badge>
                        @endif
                    </div>
                @else
                    <div class="text-center py-6 text-sm text-zinc-400">{{ __('لا توجد واجبات حالياً') }}</div>
                @endif
            </div>

            {{-- Next Exam Banner --}}
            @if ($nextExam)
                @php
                    $examDaysLeft = (int) now('Asia/Riyadh')->startOfDay()->diffInDays($nextExam->date_time->startOfDay(), false);

                    // Calculate exam readiness percentage
                    $examReadiness = 0;
                    $examLevel = $nextExam->examLevel;
                    if ($examLevel?->start_ayah_id && $examLevel?->end_ayah_id) {
                        $examStartId = min($examLevel->start_ayah_id, $examLevel->end_ayah_id);
                        $examEndId = max($examLevel->start_ayah_id, $examLevel->end_ayah_id);
                        $examTotal = $examEndId - $examStartId + 1;

                        $memorizedRange = $student->getMemorizedRange();
                        if ($memorizedRange && $examTotal > 0) {
                            $memMin = $memorizedRange['min'];
                            $memMax = $memorizedRange['max'];
                            // Intersect the memorized range with the exam range
                            $overlapStart = max($examStartId, $memMin);
                            $overlapEnd = min($examEndId, $memMax);
                            $overlap = max(0, $overlapEnd - $overlapStart + 1);
                            $examReadiness = min(100, round(($overlap / $examTotal) * 100));
                        }
                    }

                    $readinessColor = match (true) {
                        $examReadiness >= 80 => ['bar' => 'from-emerald-400 to-emerald-600', 'text' => 'text-emerald-600 dark:text-emerald-400', 'bg' => 'bg-emerald-100 dark:bg-emerald-900/30'],
                        $examReadiness >= 50 => ['bar' => 'from-blue-400 to-blue-600', 'text' => 'text-blue-600 dark:text-blue-400', 'bg' => 'bg-blue-100 dark:bg-blue-900/30'],
                        $examReadiness >= 25 => ['bar' => 'from-amber-400 to-amber-600', 'text' => 'text-amber-600 dark:text-amber-400', 'bg' => 'bg-amber-100 dark:bg-amber-900/30'],
                        default => ['bar' => 'from-rose-400 to-rose-600', 'text' => 'text-rose-600 dark:text-rose-400', 'bg' => 'bg-rose-100 dark:bg-rose-900/30'],
                    };
                @endphp
                <div
                    class="relative overflow-hidden rounded-2xl border border-indigo-200 dark:border-indigo-800/60 bg-indigo-50 dark:bg-indigo-900/20 p-5 shadow-sm">
                    <div class="absolute top-0 right-0 w-1.5 h-full bg-indigo-500 rounded-r-2xl"></div>
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <div
                                class="p-3 bg-indigo-100 dark:bg-indigo-800/50 rounded-xl text-indigo-600 dark:text-indigo-300 shrink-0">
                                <flux:icon icon="academic-cap" class="size-7" variant="solid" />
                            </div>
                            <div class="flex-1">
                                <p
                                    class="text-xs font-bold text-indigo-500 dark:text-indigo-400 uppercase tracking-widest mb-1">
                                    {{ __('الاختبار القادم') }}
                                </p>
                                <h3 class="font-black text-zinc-900 dark:text-white text-lg leading-tight">
                                    {{ $nextExam->examLevel->name ?? __('اختبار حفظ') }}
                                </h3>
                                @if ($nextExam->examLevel?->startAyah && $nextExam->examLevel?->endAyah)
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                        {{ $nextExam->examLevel->startAyah->surah->name_arabic }}
                                        ({{ $nextExam->examLevel->startAyah->verse_number }})
                                        —
                                        {{ $nextExam->examLevel->endAyah->surah->name_arabic }}
                                        ({{ $nextExam->examLevel->endAyah->verse_number }})
                                    </p>
                                @endif
                                @if ($nextExam->location)
                                    <p class="text-xs text-zinc-400 dark:text-zinc-500 mt-0.5 flex items-center gap-1">
                                        <flux:icon icon="map-pin" class="size-3.5" />
                                        {{ $nextExam->location }}
                                    </p>
                                @endif

                                {{-- Readiness Progress --}}
                                <div class="mt-3">
                                    <div class="flex items-center justify-between mb-1">
                                        <span
                                            class="text-xs font-semibold text-zinc-500 dark:text-zinc-400">{{ __('جاهزيتك للاختبار') }}</span>
                                        <span
                                            class="text-xs font-black {{ $readinessColor['text'] }}">{{ $examReadiness }}%</span>
                                    </div>
                                    <div class="{{ $readinessColor['bg'] }} rounded-full h-2.5 overflow-hidden w-full">
                                        <div class="h-2.5 rounded-full bg-gradient-to-l {{ $readinessColor['bar'] }}   duration-700"
                                            style="width: {{ $examReadiness }}%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div
                            class="shrink-0 text-center bg-white dark:bg-zinc-900 border border-indigo-200 dark:border-indigo-700/50 rounded-2xl px-5 py-3 shadow-sm">
                            @if ($examDaysLeft === 0)
                                <p class="text-2xl font-black text-indigo-600 dark:text-indigo-400">{{ __('اليوم!') }}</p>
                                <p class="text-xs text-zinc-500 mt-0.5">{{ $nextExam->date_time->format('g:i A') }}</p>
                            @elseif ($examDaysLeft > 0)
                                <p class="text-3xl font-black text-indigo-600 dark:text-indigo-400">{{ $examDaysLeft }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400 font-medium mt-0.5">{{ __('يوم متبقي') }}</p>
                            @else
                                <p class="text-sm font-bold text-zinc-400">{{ __('قريباً') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            @if ($activeSession)
                <flux:card
                    class="border-indigo-200 dark:border-indigo-800 bg-indigo-50/50 dark:bg-indigo-900/20 overflow-hidden relative">
                    <div class="absolute top-0 right-0 p-4 opacity-10">
                        <flux:icon icon="ticket" class="w-24 h-24 text-indigo-500" />
                    </div>
                    <div class="relative z-10 flex flex-col sm:flex-row items-center justify-between gap-4">
                        <div>
                            <flux:heading size="lg" class="text-indigo-800 dark:text-indigo-300 flex items-center gap-2">
                                <flux:icon icon="ticket" class="size-5" />
                                {{ $activeSession->isActiveNow() ? __('حجز دور التسميع متاح الآن') : __('جدول التسميع لليوم') }}
                            </flux:heading>
                            <p class="text-indigo-600/80 dark:text-indigo-400 mt-1 text-sm">
                                {{ __('بادر بحجز رقمك في طابور التسميع قبل انتهاء الوقت المخصص.') }}
                                ({{ \Carbon\Carbon::parse($activeSession->start_time)->format('g:i A') }} -
                                {{ \Carbon\Carbon::parse($activeSession->end_time)->format('g:i A') }})
                            </p>
                        </div>

                        <div class="shrink-0 w-full sm:w-auto">
                            @if ($studentReservation)
                                <div class="flex flex-col sm:flex-row items-center gap-2">
                                    <div
                                        class="bg-indigo-600 text-white px-6 py-3 rounded-xl flex items-center gap-3 shadow-lg shadow-indigo-500/30 w-full sm:w-auto justify-center">
                                        <flux:icon icon="check-badge" class="size-6 text-indigo-200" />
                                        <div>
                                            <div class="text-xs text-indigo-200 uppercase tracking-wider font-semibold">
                                                {{ __('تم الحجز') }}
                                            </div>
                                            <div class="font-bold text-xl">{{ __('رقمك: ') }}
                                                {{ $studentReservation->turn_number }}
                                            </div>
                                        </div>
                                    </div>
                                    @if ($activeSession->isActiveNow())
                                        <flux:button wire:click="cancelTurn({{ $activeSession->id }})"
                                            wire:confirm="{{ __('هل أنت متأكد من إلغاء حجزك؟') }}" variant="danger" icon="x-mark"
                                            class="w-full sm:w-auto h-full min-h-[52px] rounded-xl px-4">
                                            {{ __('إلغاء') }}
                                        </flux:button>
                                    @endif
                                </div>
                            @else
                                @if ($activeSession->isActiveNow())
                                    <flux:button wire:click="reserveTurn({{ $activeSession->id }})" variant="primary" icon="ticket"
                                        class="w-full bg-indigo-600 hover:bg-indigo-700 border-none shadow-lg shadow-indigo-500/20 text-white">
                                        {{ __('احجز دوري الآن') }}
                                    </flux:button>
                                @else
                                    <div
                                        class="text-indigo-600/60 dark:text-indigo-400 font-semibold text-sm bg-white/50 dark:bg-black/20 px-4 py-2 rounded-lg">
                                        {{ __('غير متاح الآن') }}
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>
                </flux:card>
            @endif

            <div class="w-full h-[2px] bg-zinc-100 dark:bg-zinc-800/50 rounded-full my-10"></div>

            <!-- Student Top Stats -->
            <div class="space-y-6">
                <flux:heading size="xl" class="flex items-center gap-3">
                    <div class="p-2 bg-emerald-500 rounded-lg shadow-lg shadow-emerald-500/20">
                        <flux:icon icon="presentation-chart-line" class="size-6 text-white" variant="solid" />
                    </div>
                    {{ __('محفوظي من القرآن الكريم') }}
                </flux:heading>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4">
                    @php
                        $memorizedPages = $student->memorizedPagesCount();
                        $percentage = $student->memorizationPercentage();
                    @endphp

                    {{-- Percentage & Range --}}
                    <div
                        class="rounded-2xl border border-emerald-100 dark:border-emerald-900/50 bg-white dark:bg-zinc-900 p-4 md:p-5 shadow-sm relative overflow-hidden">
                        <div class="absolute top-0 right-0 w-1 md:w-1.5 h-full bg-emerald-500"></div>
                        <div class="flex items-center gap-2 md:gap-3 mb-2 md:mb-3">
                            <div
                                class="p-1.5 md:p-2.5 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 rounded-lg md:rounded-xl">
                                <flux:icon icon="chart-pie" class="size-5" />
                            </div>
                            <p class="text-[15px] md:text-sm font-bold text-zinc-600 dark:text-zinc-400 leading-tight">نسبة
                                المحفوظ
                            </p>
                        </div>
                        <p class="text-2xl md:text-3xl font-black text-emerald-600 dark:text-emerald-400">{{ $percentage }}%
                        </p>
                        <div class="mt-2 w-full bg-emerald-100 dark:bg-emerald-900/30 rounded-full h-2 overflow-hidden">
                            <div class="h-2 rounded-full bg-gradient-to-l from-emerald-400 to-emerald-600   duration-700"
                                style="width: {{ $percentage }}%"></div>
                        </div>
                        <p class="text-[15px] md:text-sm text-zinc-500 dark:text-zinc-400 mt-2 font-medium line-clamp-1"
                            title="{{ $student->memorizationText() }}">
                            {{ $student->memorizationText() }}
                        </p>
                    </div>

                    {{-- Juz and Pages --}}
                    <div
                        class="rounded-2xl border border-blue-100 dark:border-blue-900/50 bg-white dark:bg-zinc-900 p-4 md:p-5 shadow-sm relative overflow-hidden">
                        <div class="absolute top-0 right-0 w-1 md:w-1.5 h-full bg-blue-500"></div>
                        <div class="flex items-center gap-2 md:gap-3 mb-2 md:mb-3">
                            <div
                                class="p-1.5 md:p-2.5 bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 rounded-lg md:rounded-xl">
                                <flux:icon icon="book-open" class="size-5" />
                            </div>
                            <p class="text-[15px] md:text-sm font-bold text-zinc-600 dark:text-zinc-400 leading-tight">
                                الأجزاء
                            </p>
                        </div>
                        <p class="text-2xl md:text-3xl font-black text-blue-600 dark:text-blue-400">
                            {{ floor($memorizedPages / 20) }} <span
                                class="text-sm md:text-lg font-bold text-zinc-400">جزء</span>
                        </p>
                        <p
                            class="text-[15px] md:text-sm text-zinc-500 dark:text-zinc-400 mt-1 md:mt-2 font-medium truncate">
                            بمجموع
                            {{ number_format($memorizedPages) }} ص
                        </p>
                    </div>
                </div>

                <div class="w-full h-[2px] bg-zinc-100 dark:bg-zinc-800/50 rounded-full my-10"></div>

                <!-- Pending Challenges -->
                @if (count($pendingChallenges) > 0)
                    <div class="space-y-4">
                        <flux:heading size="lg" class="flex items-center gap-2">
                            <flux:icon icon="gift" class="size-6 text-indigo-500" variant="solid" />
                            {{ __('دعوات تحدي جديدة') }}
                        </flux:heading>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach ($pendingChallenges as $challenge)
                                <div
                                    class="bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 rounded-2xl p-5 shadow-sm relative overflow-hidden">
                                    <div class="flex items-start justify-between mb-4">
                                        <div>
                                            <h4 class="font-bold text-lg text-indigo-900 dark:text-indigo-100 mb-1">
                                                {{ __('تحدي من:') }} {{ $challenge->guardian->name }}
                                            </h4>
                                            <p class="text-sm text-indigo-700 dark:text-indigo-400 font-medium">
                                                {{ __('المكافأة:') }} {{ $challenge->prize_description }}
                                            </p>
                                        </div>
                                        <div
                                            class="bg-indigo-100 dark:bg-indigo-800 text-indigo-600 dark:text-indigo-300 p-2 rounded-lg">
                                            <flux:icon icon="trophy" class="size-6" />
                                        </div>
                                    </div>

                                    <div class="flex gap-2">
                                        <flux:button wire:click="acceptChallenge({{ $challenge->id }})" variant="primary"
                                            class="flex-1 bg-indigo-600 hover:bg-indigo-700 border-none">
                                            {{ __('أنا قبلت التحدي! ✅') }}
                                        </flux:button>
                                        <flux:button wire:click="rejectChallenge({{ $challenge->id }})" variant="ghost"
                                            class="text-zinc-500 hover:text-red-500">
                                            {{ __('لا أريد') }}
                                        </flux:button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <!-- Active Rewards Status -->
                @if (count($activeChallenges) > 0)
                    <div class="space-y-6">
                        <flux:heading size="xl" class="flex items-center gap-3">
                            <div class="p-2 bg-orange-500 rounded-lg shadow-lg shadow-orange-500/20">
                                <flux:icon icon="trophy" class="size-6 text-white" variant="solid" />
                            </div>
                            {{ __('تحدياتي وجوائزي الحالية') }}
                        </flux:heading>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach ($activeChallenges as $challenge)
                                <div
                                    class="bg-white dark:bg-zinc-900 border border-orange-200 dark:border-orange-900/50 rounded-2xl p-5 shadow-sm relative overflow-hidden">
                                    <div class="absolute top-0 right-0 w-2 h-full bg-orange-500"></div>

                                    <div class="flex items-start justify-between mb-4">
                                        <div>
                                            <p class="text-xs text-zinc-400 mb-0.5">المكافأة</p>
                                            <h4 class="font-bold text-base text-orange-600 dark:text-orange-400">
                                                @if ($challenge->prize_type === 'financial')
                                                    💰 {{ $challenge->prize_description }}
                                                @else
                                                    🎁 {{ $challenge->prize_description }}
                                                @endif
                                            </h4>
                                            @if ($challenge->end_date)
                                                <div class="text-xs text-zinc-500 flex items-center gap-1 mt-1">
                                                    <flux:icon icon="clock" class="size-3.5" />
                                                    {{ __('ينتهي في:') }} <x-hijri-date :date="$challenge->end_date" />
                                                </div>
                                            @endif
                                        </div>
                                        <div class="bg-orange-100 dark:bg-orange-900/30 text-orange-600 p-2 rounded-lg shrink-0">
                                            <flux:icon icon="trophy" class="size-6" />
                                        </div>
                                    </div>

                                    <div class="space-y-3">
                                        @foreach ($challenge->items as $item)
                                            @php
                                                $calculatedProgress = $item->calculateProgress();
                                                $progressPercent =
                                                    $item->target_value > 0
                                                    ? min(100, round(($calculatedProgress / $item->target_value) * 100))
                                                    : 0;

                                                $itemTitle = match ($item->type) {
                                                    'attendance' => '🗓️ مكافأة الحضور والانضباط',
                                                    'recitation_days' => '📖 إنجاز أيام محددة',
                                                    'recitation_amount' => '📖 كمية الإنجاز',
                                                    'recitation_quality' => '⭐ جودة التسميع',
                                                    'exam_passed' => '📝 اجتياز الاختبار',
                                                    default => 'بند المكافأة',
                                                };

                                                if ($item->type === 'exam_passed') {
                                                    $progressText =
                                                        $calculatedProgress >= $item->target_value
                                                        ? 'تم الاجتياز ✅'
                                                        : "الدرجة المطلوبة: {$item->target_value}%";
                                                } else {
                                                    $progressText =
                                                        $item->target_value > 0
                                                        ? "{$calculatedProgress} / {$item->target_value}"
                                                        : 'مستمر';
                                                }
                                            @endphp
                                            <div>
                                                <div class="flex justify-between text-sm mb-1.5">
                                                    <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $itemTitle }}</span>
                                                    <span class="font-bold text-zinc-900 dark:text-white">{{ $progressText }}</span>
                                                </div>
                                                @if ($item->target_value > 0)
                                                    <div class="w-full bg-zinc-100 dark:bg-zinc-800 rounded-full h-2.5 overflow-hidden">
                                                        <div class="h-2.5 rounded-full bg-gradient-to-r from-orange-400 to-red-500   duration-500"
                                                            style="width: {{ $progressPercent }}%"></div>
                                                    </div>
                                                @else
                                                    <div
                                                        class="w-full bg-emerald-100 dark:bg-emerald-900/30 rounded-full h-2.5 overflow-hidden">
                                                        <div class="h-2.5 rounded-full bg-emerald-500 w-full animate-pulse"></div>
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="w-full h-[2px] bg-zinc-100 dark:bg-zinc-800/50 rounded-full my-10"></div>

                @endif


                <!-- Last Attended Day Summary -->
                @if ($lastDayStats)
                    <div class="space-y-6">
                        <flux:heading size="xl" class="flex items-center gap-3">
                            <div class="p-2 bg-blue-500 rounded-lg shadow-lg shadow-blue-500/20">
                                <flux:icon icon="clipboard-document-check" class="size-6 text-white" variant="solid" />
                            </div>
                            {{ __('تقرير الإنجازات اليومية') }}
                        </flux:heading>

                        <flux:card class="{{ $lastDayStats['theme']['card'] }} relative overflow-hidden border">
                            <div class="absolute top-0 right-0 w-2 h-full {{ $lastDayStats['theme']['strip'] }}"></div>
                            <div class="flex flex-col md:flex-row gap-6 items-center">
                                <div
                                    class="bg-white dark:bg-zinc-800 p-4 rounded-full shadow-sm border {{ $lastDayStats['theme']['iconWrapper'] }}">
                                    <flux:icon icon="{{ $lastDayStats['icon'] }}" class="size-8" variant="solid" />
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <flux:heading size="lg" class="{{ $lastDayStats['theme']['heading'] }} font-bold">
                                            {{ __('ملخص الإنجاز اليومي') }}
                                        </flux:heading>
                                        <flux:badge color="zinc" size="sm" class="text-xs">
                                            {{ $this->getHijriLabel($lastDayStats['date']) }}
                                        </flux:badge>
                                    </div>
                                    <p class="text-sm text-zinc-600 dark:text-zinc-400 font-medium mb-4">
                                        {{ $lastDayStats['message'] }}
                                    </p>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
                                        <!-- Hifz Checklist Item -->
                                        <div
                                            class="flex items-center gap-3 bg-white dark:bg-zinc-800/50 p-3 rounded-xl border {{ $lastDayStats['hifz'] > 0 ? 'border-emerald-200 dark:border-emerald-900/50 shadow-sm' : 'border-zinc-200 dark:border-zinc-800/50 opacity-70' }}">
                                            @if ($lastDayStats['hifz'] > 0)
                                                <flux:icon icon="check-circle" variant="solid" class="size-6 text-emerald-500" />
                                            @else
                                                <div
                                                    class="size-6 rounded-full border-2 border-zinc-200 dark:border-zinc-700 flex items-center justify-center">
                                                    <flux:icon icon="minus" class="size-4 text-zinc-300 dark:text-zinc-600" />
                                                </div>
                                            @endif
                                            <div class="flex flex-col">
                                                <span
                                                    class="text-xs text-zinc-500 dark:text-zinc-400 font-medium">{{ __('تسميع الحفظ') }}</span>
                                                <span
                                                    class="font-bold text-sm {{ $lastDayStats['hifz'] == 3 ? 'text-emerald-700 dark:text-emerald-400' : ($lastDayStats['hifz'] == 2 ? 'text-indigo-700 dark:text-indigo-400' : ($lastDayStats['hifz'] == 1 ? 'text-amber-700 dark:text-amber-400' : 'text-zinc-400')) }}">
                                                    {{ $lastDayStats['hifz'] == 3 ? 'ممتاز' : ($lastDayStats['hifz'] == 2 ? 'جيد' : ($lastDayStats['hifz'] == 1 ? 'مقبول' : 'لم يسمع')) }}
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Review Checklist Item -->
                                        <div
                                            class="flex items-center gap-3 bg-white dark:bg-zinc-800/50 p-3 rounded-xl border {{ $lastDayStats['review'] > 0 ? 'border-emerald-200 dark:border-emerald-900/50 shadow-sm' : 'border-zinc-200 dark:border-zinc-800/50 opacity-70' }}">
                                            @if ($lastDayStats['review'] > 0)
                                                <flux:icon icon="check-circle" variant="solid" class="size-6 text-emerald-500" />
                                            @else
                                                <div
                                                    class="size-6 rounded-full border-2 border-zinc-200 dark:border-zinc-700 flex items-center justify-center">
                                                    <flux:icon icon="minus" class="size-4 text-zinc-300 dark:text-zinc-600" />
                                                </div>
                                            @endif
                                            <div class="flex flex-col">
                                                <span
                                                    class="text-xs text-zinc-500 dark:text-zinc-400 font-medium">{{ __('تسميع المراجعة') }}</span>
                                                <span
                                                    class="font-bold text-sm {{ $lastDayStats['review'] == 3 ? 'text-emerald-700 dark:text-emerald-400' : ($lastDayStats['review'] == 2 ? 'text-indigo-700 dark:text-indigo-400' : ($lastDayStats['review'] == 1 ? 'text-amber-700 dark:text-amber-400' : 'text-zinc-400')) }}">
                                                    {{ $lastDayStats['review'] == 3 ? 'ممتاز' : ($lastDayStats['review'] == 2 ? 'جيد' : ($lastDayStats['review'] == 1 ? 'مقبول' : 'لم يراجع')) }}
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Attendance Checklist Item -->
                                        <div
                                            class="flex items-center gap-3 bg-white dark:bg-zinc-800/50 p-3 rounded-xl border {{ $lastDayStats['attendance'] == 'present' ? 'border-emerald-200 dark:border-emerald-900/50 shadow-sm' : ($lastDayStats['attendance'] == 'late' ? 'border-amber-200 dark:border-amber-900/50 shadow-sm' : 'border-zinc-200 dark:border-zinc-800/50 opacity-70') }}">
                                            @if ($lastDayStats['attendance'] == 'present')
                                                <flux:icon icon="check-circle" variant="solid" class="size-6 text-emerald-500" />
                                            @elseif($lastDayStats['attendance'] == 'late')
                                                <flux:icon icon="exclamation-circle" variant="solid"
                                                    class="size-6 text-amber-500" />
                                            @else
                                                <div
                                                    class="size-6 rounded-full border-2 border-zinc-200 dark:border-zinc-700 flex items-center justify-center">
                                                    <flux:icon icon="x-mark" class="size-4 text-zinc-300 dark:text-zinc-600" />
                                                </div>
                                            @endif
                                            <div class="flex flex-col">
                                                <span
                                                    class="text-xs text-zinc-500 dark:text-zinc-400 font-medium">{{ __('الحضور') }}</span>
                                                <span
                                                    class="font-bold text-sm {{ $lastDayStats['attendance'] == 'present' ? 'text-emerald-700 dark:text-emerald-400' : ($lastDayStats['attendance'] == 'late' ? 'text-amber-700 dark:text-amber-400' : 'text-zinc-400') }}">
                                                    {{ $lastDayStats['attendance'] == 'present' ? 'حاضر (بدون تأخير)' : ($lastDayStats['attendance'] == 'late' ? 'متأخر' : 'غياب') }}
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Criteria Checklist Item (if > 0) -->
                                        @if ($lastDayStats['criteria_count'] > 0)
                                            <div
                                                class="flex items-center gap-3 bg-white dark:bg-zinc-800/50 p-3 rounded-xl border border-emerald-200 dark:border-emerald-900/50 shadow-sm">
                                                <flux:icon icon="check-circle" variant="solid" class="size-6 text-emerald-500" />
                                                <div class="flex flex-col">
                                                    <span
                                                        class="text-xs text-zinc-500 dark:text-zinc-400 font-medium">{{ __('بنود السلوك') }}</span>
                                                    <span class="font-bold text-sm text-emerald-700 dark:text-emerald-400 truncate"
                                                        title="{{ implode('، ', $lastDayStats['criteria_names']) }}">
                                                        {{ implode('، ', $lastDayStats['criteria_names']) }}
                                                    </span>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </flux:card>
                @endif

                    <div class="w-full h-[2px] bg-zinc-100 dark:bg-zinc-800/50 rounded-full my-10"></div>

                    <!-- Pending Missions Widget -->
                    @if (count($pendingMissions) > 0)
                        <div class="space-y-6" id="today-mission">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <flux:heading size="xl" class="flex items-center gap-3">
                                    <div class="p-2 bg-emerald-500 rounded-lg shadow-lg shadow-emerald-500/20">
                                        <flux:icon icon="sparkles" class="size-6 text-white" variant="solid" />
                                    </div>
                                    {{ __('المهام القرآنية المجدولة') }}
                                </flux:heading>

                                {{-- One link per plan, not per pending part, so it is not offered twice. --}}
                                @foreach (collect($pendingMissions)->pluck('plan')->unique('id') as $plan)
                                    <flux:button as="a" href="{{ route('student.plan.print', ['kind' => 'quran', 'id' => $plan->id]) }}"
                                        icon="printer" size="sm" variant="ghost">
                                        {{ __('عرض وطباعة الخطة') }}
                                    </flux:button>
                                @endforeach
                            </div>

                            <flux:card
                                class="bg-gradient-to-br from-emerald-500 to-teal-600 border-none text-white overflow-hidden relative shadow-lg shadow-emerald-600/20 p-0">
                                <div class="absolute -top-10 -right-10 p-4 opacity-10 pointer-events-none">
                                    <flux:icon icon="book-open" class="w-48 h-48" />
                                </div>

                                <div class="relative z-10 p-6">
                                    <div class="flex items-center gap-3 mb-6 border-b border-white/20 pb-4">
                                        <div class="bg-white/20 p-2 rounded-lg">
                                            <flux:icon icon="flag" class="size-6 text-white" />
                                        </div>
                                        <h2 class="text-xl font-bold">{{ __('المهام القادمة') }}</h2>
                                    </div>

                                    <div class="space-y-6">
                                        @foreach ($pendingMissions as $pendingMission)
                                            @php
                                                $isToday = $pendingMission->date === $todayStr;
                                            @endphp
                                            <div class="bg-white/10 rounded-2xl p-5 backdrop-blur-sm border border-white/20">
                                                <div
                                                    class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4 border-b border-white/10 pb-3">
                                                    <div>
                                                        <div class="font-bold text-lg text-white">
                                                            {{ $pendingMission->plan->description ?? __('خطة بدون عنوان') }}
                                                        </div>
                                                        <div class="text-sm text-emerald-100 flex items-center gap-2 mt-1">
                                                            <flux:icon icon="calendar" class="size-4" />
                                                            <span>{{ $isToday ? __('مهمة اليوم') : __('مهمة فائتة أو قادمة') }} -
                                                                {{ $pendingMission->day_name }}
                                                                {{ $this->getHijriLabel($pendingMission->date) }}</span>
                                                        </div>
                                                    </div>
                                                    <div
                                                        class="shrink-0 bg-white/20 px-3 py-1 rounded-full text-xs font-bold text-white uppercase tracking-wide">
                                                        {{ $pendingMission->plan->plan_type === 'hifz' ? __('حفظ') : ($pendingMission->plan->plan_type === 'review' ? __('مراجعة') : __('حفظ ومراجعة')) }}
                                                    </div>
                                                </div>

                                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                    @if ($pendingMission->pendingPart === 'hifz' && $pendingMission->from_ayah_id && $pendingMission->to_ayah_id)
                                                        <div>
                                                            <div class="flex justify-between items-center mb-1">
                                                                <div class="text-emerald-100 text-sm">{{ __('مقرر الحفظ') }}</div>
                                                                @if (is_null($pendingMission->hifz_achievement))
                                                                    <span
                                                                        class="bg-white/20 text-[10px] px-2 py-0.5 rounded text-white">{{ __('بانتظار التسميع') }}</span>
                                                                @endif
                                                            </div>
                                                            <div class="text-lg font-bold">
                                                                {{ $pendingMission->fromAyah->surah->name_arabic }}
                                                                ({{ $pendingMission->fromAyah->verse_number }})
                                                                -
                                                                {{ $pendingMission->toAyah->surah->name_arabic }}
                                                                ({{ $pendingMission->toAyah->verse_number }})
                                                            </div>
                                                            @php
                                                                $hLinks = [];
                                                                $hFrom = $pendingMission->fromAyah;
                                                                $hTo = $pendingMission->toAyah;
                                                                if ($hFrom->surah_id === $hTo->surah_id) {
                                                                    $hLinks[] = [
                                                                        'name' => $hFrom->surah->name_arabic,
                                                                        'url' =>
                                                                            'https://quran.com/ar/' .
                                                                            $hFrom->surah->number .
                                                                            '/' .
                                                                            $hFrom->verse_number .
                                                                            '-' .
                                                                            $hTo->verse_number,
                                                                    ];
                                                                } else {
                                                                    $low = min($hFrom->surah_id, $hTo->surah_id);
                                                                    $high = max($hFrom->surah_id, $hTo->surah_id);
                                                                    $direction = $hFrom->surah_id <= $hTo->surah_id ? 'asc' : 'desc';
                                                                    $surahs = \App\Models\Surah::whereBetween('id', [$low, $high])
                                                                        ->orderBy('id', $direction)
                                                                        ->get();
                                                                    foreach ($surahs as $s) {
                                                                        $from = $s->id === $hFrom->surah_id ? $hFrom->verse_number : 1;
                                                                        $to =
                                                                            $s->id === $hTo->surah_id
                                                                            ? $hTo->verse_number
                                                                            : $s->verses_count;
                                                                        $hLinks[] = [
                                                                            'name' => $s->name_arabic,
                                                                            'url' =>
                                                                                'https://quran.com/ar/' .
                                                                                $s->number .
                                                                                '/' .
                                                                                $from .
                                                                                '-' .
                                                                                $to,
                                                                        ];
                                                                    }
                                                                }
                                                            @endphp
                                                            @if (count($hLinks) === 1)
                                                                <a href="{{ $hLinks[0]['url'] }}" target="_blank"
                                                                    class="inline-flex items-center gap-1.5 mt-2 px-2.5 py-1 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-medium text-white   s">
                                                                    <flux:icon icon="book-open" class="size-3.5" />
                                                                    {{ __('افتح') }} {{ $hLinks[0]['name'] }}
                                                                </a>
                                                            @elseif(count($hLinks) > 1)
                                                                <div x-data="{ open: false }" class="mt-2">
                                                                    <button type="button" @click="open = !open"
                                                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-medium text-white   s">
                                                                        <flux:icon icon="book-open" class="size-3.5" />
                                                                        <span>{{ __('افتح الآيات في القرآن') }}
                                                                            ({{ count($hLinks) }})</span>
                                                                        <flux:icon icon="chevron-down" class="size-3.5 transition-transform"
                                                                            x-bind:class="open ? 'rotate-180' : ''" />
                                                                    </button>
                                                                    <div x-show="open" x-collapse class="flex flex-wrap gap-2 mt-2">
                                                                        @foreach ($hLinks as $link)
                                                                            <a href="{{ $link['url'] }}" target="_blank"
                                                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-medium text-white   s">
                                                                                <flux:icon icon="book-open" class="size-3.5" />
                                                                                {{ $link['name'] }}
                                                                            </a>
                                                                        @endforeach
                                                                    </div>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    @endif

                                                    @if ($pendingMission->pendingPart === 'review' && $pendingMission->review_from_ayah_id && $pendingMission->review_to_ayah_id)
                                                        <div>
                                                            <div class="flex justify-between items-center mb-1">
                                                                <div class="text-emerald-100 text-sm">{{ __('مقرر المراجعة') }}</div>
                                                                @if (is_null($pendingMission->review_achievement))
                                                                    <span
                                                                        class="bg-white/20 text-[10px] px-2 py-0.5 rounded text-white">{{ __('بانتظار التسميع') }}</span>
                                                                @endif
                                                            </div>
                                                            <div class="text-lg font-bold">
                                                                {{ $pendingMission->reviewFromAyah->surah->name_arabic }}
                                                                ({{ $pendingMission->reviewFromAyah->verse_number }}) -
                                                                {{ $pendingMission->reviewToAyah->surah->name_arabic }}
                                                                ({{ $pendingMission->reviewToAyah->verse_number }})
                                                            </div>
                                                            @php
                                                                $rLinks = [];
                                                                $rFrom = $pendingMission->reviewFromAyah;
                                                                $rTo = $pendingMission->reviewToAyah;
                                                                if ($rFrom->surah_id === $rTo->surah_id) {
                                                                    $rLinks[] = [
                                                                        'name' => $rFrom->surah->name_arabic,
                                                                        'url' =>
                                                                            'https://quran.com/ar/' .
                                                                            $rFrom->surah->number .
                                                                            '/' .
                                                                            $rFrom->verse_number .
                                                                            '-' .
                                                                            $rTo->verse_number,
                                                                    ];
                                                                } else {
                                                                    $low = min($rFrom->surah_id, $rTo->surah_id);
                                                                    $high = max($rFrom->surah_id, $rTo->surah_id);
                                                                    $direction = $rFrom->surah_id <= $rTo->surah_id ? 'asc' : 'desc';
                                                                    $surahs = \App\Models\Surah::whereBetween('id', [$low, $high])
                                                                        ->orderBy('id', $direction)
                                                                        ->get();
                                                                    foreach ($surahs as $s) {
                                                                        $from = $s->id === $rFrom->surah_id ? $rFrom->verse_number : 1;
                                                                        $to =
                                                                            $s->id === $rTo->surah_id
                                                                            ? $rTo->verse_number
                                                                            : $s->verses_count;
                                                                        $rLinks[] = [
                                                                            'name' => $s->name_arabic,
                                                                            'url' =>
                                                                                'https://quran.com/ar/' .
                                                                                $s->number .
                                                                                '/' .
                                                                                $from .
                                                                                '-' .
                                                                                $to,
                                                                        ];
                                                                    }
                                                                }
                                                            @endphp
                                                            @if (count($rLinks) === 1)
                                                                <a href="{{ $rLinks[0]['url'] }}" target="_blank"
                                                                    class="inline-flex items-center gap-1.5 mt-2 px-2.5 py-1 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-medium text-white   s">
                                                                    <flux:icon icon="book-open" class="size-3.5" />
                                                                    {{ __('افتح') }} {{ $rLinks[0]['name'] }}
                                                                </a>
                                                            @elseif(count($rLinks) > 1)
                                                                <div x-data="{ open: false }" class="mt-2">
                                                                    <button type="button" @click="open = !open"
                                                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-medium text-white   s">
                                                                        <flux:icon icon="book-open" class="size-3.5" />
                                                                        <span>{{ __('افتح الآيات في القرآن') }}
                                                                            ({{ count($rLinks) }})</span>
                                                                        <flux:icon icon="chevron-down" class="size-3.5 transition-transform"
                                                                            x-bind:class="open ? 'rotate-180' : ''" />
                                                                    </button>
                                                                    <div x-show="open" x-collapse class="flex flex-wrap gap-2 mt-2">
                                                                        @foreach ($rLinks as $link)
                                                                            <a href="{{ $link['url'] }}" target="_blank"
                                                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-medium text-white   s">
                                                                                <flux:icon icon="book-open" class="size-3.5" />
                                                                                {{ $link['name'] }}
                                                                            </a>
                                                                        @endforeach
                                                                    </div>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="mt-6 flex justify-end">
                                        <flux:button href="{{ route('student.plan') }}" variant="filled"
                                            class="bg-white text-emerald-600 hover:bg-emerald-50">
                                            {{ __('الانتقال إلى خططي') }}
                                        </flux:button>
                                    </div>
                                </div>
                            </flux:card>
                    @else
                            <flux:card
                                class="border-t-4 border-t-zinc-400 border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/50">
                                <div class="flex flex-col items-center justify-center py-8 text-center space-y-4">
                                    <div class="bg-zinc-200 dark:bg-zinc-800 p-4 rounded-full text-zinc-500">
                                        <flux:icon icon="sparkles" class="size-8" />
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-bold text-zinc-700 dark:text-zinc-300">
                                            {{ __('لا توجد مهام مجدولة لك اليوم') }}
                                        </h3>
                                        <p class="text-zinc-500 text-sm mt-1 max-w-sm">
                                            {{ __('اغتنم هذا اليوم في مراجعة ما حفظته مسبقاً وثبّت جذور القرآن في قلبك.') }}
                                        </p>
                                    </div>
                                </div>
                            </flux:card>
                        @endif

                        {{-- Hadith plans daily rendering --}}
                        {{--
                            Ode plans had no section here, so a student following one
                            could not open it. Their days live on a shared path with the
                            grades kept separately, so this offers the plan itself rather
                            than restating the mission cards above.
                        --}}
                        @if ($activeOdePlans->isNotEmpty())
                            <div class="space-y-4 mt-6">
                                <flux:heading size="xl" class="flex items-center gap-3">
                                    <div class="p-2 bg-purple-500 rounded-lg shadow-lg shadow-purple-500/20">
                                        <flux:icon icon="sparkles" class="size-6 text-white" variant="solid" />
                                    </div>
                                    {{ __('خطط المنظومات') }}
                                </flux:heading>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    @foreach ($activeOdePlans as $odePlan)
                                        <div class="rounded-2xl border border-purple-100 dark:border-purple-900/40 bg-purple-50/40 dark:bg-purple-950/20 p-5 flex items-center justify-between gap-3">
                                            <div>
                                                <div class="font-bold text-zinc-800 dark:text-zinc-100">
                                                    {{ $odePlan->path?->ode?->name ?? __('منظومة') }}
                                                </div>
                                                <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                                    {{ $odePlan->path?->name }}
                                                </div>
                                            </div>
                                            <flux:button as="a" href="{{ route('student.plan.print', ['kind' => 'ode', 'id' => $odePlan->id]) }}"
                                                icon="printer" size="sm" variant="ghost" class="shrink-0">
                                                {{ __('عرض وطباعة الخطة') }}
                                            </flux:button>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if (count($pendingHadithMissions) > 0)
                            <div class="space-y-6 mt-6">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <flux:heading size="xl" class="flex items-center gap-3">
                                        <div class="p-2 bg-rose-500 rounded-lg shadow-lg shadow-rose-500/20">
                                            <flux:icon icon="sparkles" class="size-6 text-white" variant="solid" />
                                        </div>
                                        {{ __('مهام حفظ الحديث المجدولة') }}
                                    </flux:heading>

                                    @foreach ($activeHadithPlans as $hadithPlan)
                                        <flux:button as="a" href="{{ route('student.plan.print', ['kind' => 'hadith', 'id' => $hadithPlan->id]) }}"
                                            icon="printer" size="sm" variant="ghost">
                                            {{ __('عرض وطباعة الخطة') }}
                                        </flux:button>
                                    @endforeach
                                </div>

                                <flux:card
                                    class="bg-gradient-to-br from-rose-500 to-red-600 border-none text-white overflow-hidden relative shadow-lg shadow-rose-600/20 p-0">
                                    <div class="absolute -top-10 -right-10 p-4 opacity-10 pointer-events-none">
                                        <flux:icon icon="document-text" class="w-48 h-48" />
                                    </div>

                                    <div class="relative z-10 p-6">
                                        <div class="flex items-center gap-3 mb-6 border-b border-white/20 pb-4">
                                            <div class="bg-white/20 p-2 rounded-lg">
                                                <flux:icon icon="flag" class="size-6 text-white" />
                                            </div>
                                            <h2 class="text-xl font-bold">{{ __('المهام القادمة') }}</h2>
                                        </div>

                                        <div class="space-y-6">
                                            @foreach ($pendingHadithMissions as $pendingHadithMission)
                                                @php
                                                    $isToday = $pendingHadithMission->date->toDateString() === $todayStr;
                                                    $allHadiths = $pendingHadithMission->allHadiths ?? collect();

                                                    // Calculate current and previous Hadiths
                                                    $hifzHadiths = collect();
                                                    if ($pendingHadithMission->memorize_type === 'hadiths' && $pendingHadithMission->from_hadith_id && $pendingHadithMission->to_hadith_id) {
                                                        $startIdx = $allHadiths->search(fn($h) => $h->id == $pendingHadithMission->from_hadith_id);
                                                        $endIdx = $allHadiths->search(fn($h) => $h->id == $pendingHadithMission->to_hadith_id);
                                                        if ($startIdx !== false && $endIdx !== false) {
                                                            $hifzHadiths = $allHadiths->slice($startIdx, $endIdx - $startIdx + 1);
                                                        }
                                                    } elseif ($pendingHadithMission->memorize_type === 'lines' && $pendingHadithMission->from_hadith_id) {
                                                        $hadith = $allHadiths->first(fn($h) => $h->id == $pendingHadithMission->from_hadith_id);
                                                        if ($hadith) {
                                                            $hifzHadiths->push($hadith);
                                                        }
                                                    }

                                                    $firstHifzHadith = $hifzHadiths->first();
                                                    $firstHifzIdx = $firstHifzHadith ? $allHadiths->search(fn($h) => $h->id == $firstHifzHadith->id) : false;
                                                    $previousHifzHadiths = $firstHifzIdx !== false && $firstHifzIdx > 0
                                                        ? $allHadiths->slice(0, $firstHifzIdx)->values()
                                                        : collect();
                                                @endphp
                                                <div x-data="{ showTextModal: false, prevCount: 0 }"
                                                    class="bg-white/10 rounded-2xl p-5 backdrop-blur-sm border border-white/20">
                                                    <div
                                                        class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4 border-b border-white/10 pb-3">
                                                        <div>
                                                            <div class="font-bold text-lg text-white">
                                                                {{ $pendingHadithMission->plan->path->name ?? __('خطة بدون عنوان') }}
                                                            </div>
                                                            <div class="text-sm text-rose-100 flex items-center gap-2 mt-1">
                                                                <flux:icon icon="calendar" class="size-4" />
                                                                <span>{{ $isToday ? __('مهمة اليوم') : __('مهمة فائتة أو قادمة') }}
                                                                    -
                                                                    {{ $pendingHadithMission->day_name }}
                                                                    {{ $this->getHijriLabel($pendingHadithMission->date) }}</span>
                                                            </div>
                                                        </div>
                                                        <div
                                                            class="shrink-0 bg-white/20 px-3 py-1 rounded-full text-xs font-bold text-white uppercase tracking-wide">
                                                            {{ ($pendingHadithMission->from_line_number && $pendingHadithMission->to_line_number) ? __('حفظ بالأسطر') : __('حفظ بالأحاديث') }}
                                                        </div>
                                                    </div>

                                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                        @php
                                                            $hasHifz = ($pendingHadithMission->from_line_number && $pendingHadithMission->to_line_number) || ($pendingHadithMission->from_hadith_id && $pendingHadithMission->to_hadith_id);
                                                            $hasReview = ($pendingHadithMission->review_from_line_number && $pendingHadithMission->review_to_line_number) || ($pendingHadithMission->review_from_hadith_id && $pendingHadithMission->review_to_hadith_id);
                                                        @endphp

                                                        @if ($hasHifz)
                                                            <div>
                                                                <div class="flex justify-between items-center mb-1">
                                                                    <div class="text-rose-100 text-sm">{{ __('مقرر الحفظ') }}</div>
                                                                    @if (is_null($pendingHadithMission->hifz_achievement))
                                                                        <span
                                                                            class="bg-white/20 text-[10px] px-2 py-0.5 rounded text-white">{{ __('بانتظار التسميع') }}</span>
                                                                    @endif
                                                                </div>
                                                                <div class="text-lg font-bold">
                                                                    {{ $pendingHadithMission->formatHadithRange('hifz') }}
                                                                </div>
                                                            </div>
                                                        @endif

                                                        @if ($hasReview)
                                                            <div>
                                                                <div class="flex justify-between items-center mb-1">
                                                                    <div class="text-rose-100 text-sm">{{ __('مقرر المراجعة') }}</div>
                                                                    @if (is_null($pendingHadithMission->review_achievement))
                                                                        <span
                                                                            class="bg-white/20 text-[10px] px-2 py-0.5 rounded text-white">{{ __('بانتظار التسميع') }}</span>
                                                                    @endif
                                                                </div>
                                                                <div class="text-lg font-bold">
                                                                    {{ $pendingHadithMission->formatHadithRange('review') }}
                                                                </div>
                                                            </div>
                                                        @endif
                                                    </div>

                                                    <div class="mt-4 pt-3 border-t border-white/10 flex justify-end">
                                                        <button type="button" @click="showTextModal = true"
                                                            class="flex items-center gap-2 px-4 py-2 bg-white/20 hover:bg-white/30 text-white rounded-lg text-sm font-bold transition-all shadow-sm">
                                                            <flux:icon icon="book-open" class="size-4" />
                                                            <span>{{ __('إظهار نص الحديث') }}</span>
                                                        </button>
                                                    </div>

                                                    {{-- Hadith Text Modal for Student --}}
                                                    <template x-teleport="body">
                                                        <div x-show="showTextModal"
                                                            x-transition:enter="transition ease-out duration-300"
                                                            x-transition:enter-start="opacity-0 translate-y-4"
                                                            x-transition:enter-end="opacity-100 translate-y-0"
                                                            x-transition:leave="transition ease-in duration-200"
                                                            x-transition:leave-start="opacity-100 translate-y-0"
                                                            x-transition:leave-end="opacity-0 translate-y-4"
                                                            class="fixed inset-0 z-50 bg-white dark:bg-zinc-900 flex flex-col w-full h-full text-zinc-900 dark:text-white"
                                                            x-cloak>

                                                            {{-- Modal Header --}}
                                                            <div
                                                                class="flex items-center justify-between p-5 border-b border-zinc-100 dark:border-zinc-900 bg-zinc-50/50 dark:bg-zinc-900/50 shrink-0">
                                                                <div>
                                                                    <h3
                                                                        class="font-bold text-lg text-zinc-900 dark:text-white leading-tight">
                                                                        {{ __('نص الحديث') }}
                                                                    </h3>
                                                                    <p
                                                                        class="text-xs text-rose-600 dark:text-rose-400 mt-1 font-semibold">
                                                                        {{ $pendingHadithMission->plan->path->name ?? '' }}
                                                                        ({{ $pendingHadithMission->formatHadithRange('hifz') }})
                                                                    </p>
                                                                </div>
                                                                <button type="button" @click="showTextModal = false"
                                                                    class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-900 transition-colors">
                                                                    <flux:icon icon="x-mark" class="size-5" />
                                                                </button>
                                                            </div>

                                                            {{-- Modal Content (Scrollable) --}}
                                                            <div
                                                                class="flex-1 overflow-y-auto p-4 md:p-6 space-y-6 bg-zinc-50/30 dark:bg-zinc-950/30 text-right">
                                                                <div class="w-full space-y-8">
                                                                    {{-- Previous Hadiths Button --}}
                                                                    @if($previousHifzHadiths->isNotEmpty())
                                                                        <div x-show="prevCount < {{ $previousHifzHadiths->count() }}"
                                                                            class="flex justify-center mb-8 shrink-0">
                                                                            <flux:button type="button" @click="prevCount++"
                                                                                icon="arrow-up" variant="subtle"
                                                                                class="w-full sm:w-auto font-bold text-zinc-800 dark:text-white border border-zinc-200 dark:border-zinc-850">
                                                                                {{ __('إظهار الحديث السابق') }}
                                                                            </flux:button>
                                                                        </div>
                                                                    @endif

                                                                    {{-- Previous Hadiths (Dimmed) --}}
                                                                    @foreach($previousHifzHadiths as $index => $hadith)
                                                                        @php
                                                                            $currentHadithLines = $hadith->lines;
                                                                         @endphp
                                                                        <div x-show="prevCount >= {{ $previousHifzHadiths->count() - $index }}"
                                                                            x-cloak
                                                                            class="space-y-4 opacity-50 hover:opacity-100 transition-opacity duration-200">
                                                                            {{-- Hadith Header (Name) --}}
                                                                            <div
                                                                                class="text-lg font-bold text-zinc-500 dark:text-zinc-400 pb-2 border-b border-zinc-200 dark:border-zinc-850 font-serif">
                                                                                {{ $hadith->name }} <span
                                                                                    class="text-xs font-sans text-zinc-400">({{ __('سابق') }})</span>
                                                                            </div>

                                                                            @if ($hadith->sanad)
                                                                                <div
                                                                                    class="p-4 bg-zinc-50 dark:bg-zinc-900 rounded-xl text-sm font-semibold text-zinc-400 dark:text-zinc-500 pr-4 border-r-4 border-zinc-300 font-serif">
                                                                                    <strong>{{ __('السند') }}: </strong>{{ $hadith->sanad }}
                                                                                </div>
                                                                            @endif

                                                                            @foreach($currentHadithLines as $line)
                                                                                <div
                                                                                    class="flex items-start gap-4 p-4 md:p-6 bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800/60 shadow-sm hover:shadow-md transition-shadow">
                                                                                    <span
                                                                                        class="shrink-0 flex items-center justify-center size-8 rounded-xl bg-zinc-100 dark:bg-zinc-800 text-zinc-400 dark:text-zinc-500 font-extrabold text-sm shadow-sm">
                                                                                        {{ $line->line_number }}
                                                                                    </span>
                                                                                    <div
                                                                                        class="flex-1 text-base md:text-xl font-semibold text-zinc-500 dark:text-zinc-400 leading-relaxed text-right pr-4 border-r-4 border-zinc-300 dark:border-zinc-600 font-serif">
                                                                                        {{ $line->text }}
                                                                                    </div>
                                                                                </div>
                                                                            @endforeach

                                                                            @if ($hadith->ruling)
                                                                                <div
                                                                                    class="p-4 bg-zinc-50 dark:bg-zinc-900 rounded-xl text-sm font-bold text-zinc-400 dark:text-zinc-500 pr-4 border-r-4 border-zinc-300">
                                                                                    <strong>{{ __('حكم الحديث') }}:
                                                                                    </strong>{{ $hadith->ruling }}
                                                                                </div>
                                                                            @endif
                                                                        </div>
                                                                    @endforeach

                                                                    {{-- Current Hadiths --}}
                                                                    @foreach($hifzHadiths as $hadith)
                                                                        <div class="space-y-4 text-zinc-800 dark:text-zinc-100">
                                                                            {{-- Hadith Header (Name) if multiple --}}
                                                                            <div
                                                                                class="text-lg font-bold text-rose-600 dark:text-rose-400 pb-2 border-b border-rose-100 dark:border-rose-900/50 font-serif">
                                                                                {{ $hadith->name }}
                                                                            </div>

                                                                            @if ($hadith->sanad)
                                                                                <div
                                                                                    class="p-4 bg-zinc-100 dark:bg-zinc-800 rounded-xl text-sm font-semibold text-zinc-650 dark:text-zinc-400 pr-4 border-r-4 border-zinc-450 font-serif">
                                                                                    <strong>{{ __('السند') }}: </strong>{{ $hadith->sanad }}
                                                                                </div>
                                                                            @endif

                                                                            @php
                                                                                $currentHadithLines = $hadith->lines;
                                                                                if ($pendingHadithMission->memorize_type === 'lines') {
                                                                                    $currentHadithLines = $currentHadithLines->filter(function ($l) use ($pendingHadithMission) {
                                                                                        return $l->line_number <= $pendingHadithMission->to_line_number;
                                                                                    });
                                                                                }
                                                                             @endphp

                                                                            @foreach($currentHadithLines as $line)
                                                                                <div
                                                                                    class="flex items-start gap-4 p-4 md:p-6 bg-white dark:bg-zinc-900 rounded-2xl border border-rose-100/60 dark:border-rose-950/40 shadow-sm hover:shadow-md transition-shadow">
                                                                                    <span
                                                                                        class="shrink-0 flex items-center justify-center size-8 rounded-xl bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 font-extrabold text-sm shadow-sm">
                                                                                        {{ $line->line_number }}
                                                                                    </span>
                                                                                    <div
                                                                                        class="flex-1 text-base md:text-xl font-semibold text-zinc-800 dark:text-zinc-150 leading-relaxed text-right pr-4 border-r-4 border-rose-500 dark:border-rose-400 font-serif">
                                                                                        {{ $line->text }}
                                                                                    </div>
                                                                                </div>
                                                                            @endforeach

                                                                            @if ($hadith->ruling)
                                                                                <div
                                                                                    class="p-4 bg-rose-50 dark:bg-rose-955/30 rounded-xl text-sm font-bold text-rose-700 dark:text-rose-300 pr-4 border-r-4 border-rose-500">
                                                                                    <strong>{{ __('حكم الحديث') }}:
                                                                                    </strong>{{ $hadith->ruling }}
                                                                                </div>
                                                                            @endif
                                                                        </div>
                                                                    @endforeach
                                                                </div>
                                                            </div>

                                                            {{-- Modal Footer --}}
                                                            <div
                                                                class="p-4 border-t border-zinc-100 dark:border-zinc-900 bg-zinc-50/50 dark:bg-zinc-900/50 flex justify-end shrink-0">
                                                                <flux:button type="button" @click="showTextModal = false"
                                                                    variant="ghost" class="text-zinc-700 dark:text-zinc-300">
                                                                    {{ __('إغلاق') }}
                                                                </flux:button>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </flux:card>
                            </div>
                        @endif

                        <div class="w-full h-[2px] bg-zinc-100 dark:bg-zinc-800/50 rounded-full my-10"></div>
                        @endunless

                        <!-- 🏆 Leaderboard Section -->
                        @if ($leaderboard)
                            <div class="space-y-6" id="leaderboard-standings">
                                <div class="flex items-center justify-between">
                                    <flux:heading size="xl" class="flex items-center gap-3">
                                        <div class="p-2 bg-amber-500 rounded-lg shadow-lg shadow-amber-500/20">
                                            <flux:icon icon="trophy" variant="solid" class="size-6 text-white" />
                                        </div>
                                        {{$leaderboard->title }}
                                    </flux:heading>
                                    <div
                                        class="flex items-center gap-2 text-xs font-bold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-3 py-1.5 rounded-full border border-amber-200 dark:border-amber-800/50">
                                        <flux:icon icon="clock" class="size-3.5" />
                                        @php
                                            $daysRemaining = now('Asia/Riyadh')->startOfDay()->diffInDays($leaderboard->end_date->startOfDay(), false);
                                        @endphp
                                        @if($daysRemaining > 0)
                                            {{ __('متبقي :days يوم', ['days' => $daysRemaining]) }}
                                        @elseif($daysRemaining == 0)
                                            {{ __('اليوم الأخير!') }}
                                        @else
                                            {{ __('انتهت المنافسة') }}
                                        @endif
                                    </div>
                                </div>

                                @php
                                    $top3 = $leaderboardStandings->take(3)->values();
                                    $rest = $leaderboardStandings->skip(3)->values();
                                    $currentStudentId = auth('student')->id();
                                    $myRank = $leaderboardStandings->search(fn($s) => $s['student']->id === $currentStudentId);
                                    $myRank = $myRank !== false ? $myRank + 1 : 0;
                                    $myScore =
                                        $myRank > 0 ? $leaderboardStandings->firstWhere('student.id', $currentStudentId)['score'] : 0;
                                @endphp

                                @if ($top3->isNotEmpty())
                                    <!-- Podium -->
                                    <div
                                        class="bg-gradient-to-t from-zinc-100/50 to-white dark:from-zinc-900 dark:to-zinc-800/80 p-4 md:p-6 rounded-2xl border border-zinc-200 dark:border-zinc-700 shadow-sm relative overflow-hidden mb-6">
                                        <div class="flex justify-center items-end gap-2 md:gap-8 pt-6">
                                            <!-- Second Place -->
                                            @if (isset($top3[1]))
                                                <div class="flex flex-col items-center w-24 md:w-32 z-10">
                                                    <div class="relative mb-2 shrink-0">
                                                        <div
                                                            class="w-14 h-14 md:w-16 md:h-16 rounded-full bg-slate-100 dark:bg-slate-800 border-[3px] border-slate-300 dark:border-slate-600 flex items-center justify-center text-xl font-bold shadow-lg text-slate-600 dark:text-slate-300">
                                                            {{ mb_substr($top3[1]['student']->name, 0, 1) }}
                                                        </div>
                                                        <div
                                                            class="absolute -top-2 -right-2 bg-slate-400 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs font-bold border-2 border-white dark:border-zinc-800 shadow">
                                                            2</div>
                                                    </div>
                                                    <div
                                                        class="text-xs md:text-sm font-bold truncate w-full text-center text-zinc-700 dark:text-zinc-300 mb-1">
                                                        {{ explode(' ', $top3[1]['student']->name)[0] }}
                                                    </div>
                                                    <div
                                                        class="bg-white dark:bg-zinc-800 text-slate-500 dark:text-slate-400 font-bold text-[10px] md:text-xs px-2 py-0.5 rounded shadow-sm border border-zinc-100 dark:border-zinc-700">
                                                        {{ $top3[1]['score'] }} {{ __('نقطة') }}
                                                    </div>
                                                    <div
                                                        class="w-16 md:w-20 h-16 md:h-20 bg-gradient-to-t from-slate-200 to-slate-100 dark:from-slate-700/50 dark:to-slate-800/50 mt-3 rounded-t-lg border-t-2 border-slate-300 dark:border-slate-600 shadow-inner">
                                                    </div>
                                                </div>
                                            @endif

                                            <!-- First Place -->
                                            <div class="flex flex-col items-center w-28 md:w-36 z-20 relative">
                                                <flux:icon icon="star" variant="solid"
                                                    class="size-6 md:size-8 text-amber-400 absolute -top-8 animate-pulse drop-shadow-md" />
                                                <div class="relative mb-2 shrink-0">
                                                    <div
                                                        class="w-16 h-16 md:w-20 md:h-20 rounded-full bg-amber-50 dark:bg-amber-900/30 border-[4px] border-amber-400 flex items-center justify-center text-2xl font-bold shadow-xl text-amber-600 dark:text-amber-400 z-10 relative">
                                                        {{ mb_substr($top3[0]['student']->name, 0, 1) }}
                                                    </div>
                                                    <div
                                                        class="absolute -top-3 -right-3 bg-amber-500 text-white rounded-full w-7 h-7 flex items-center justify-center text-sm font-bold border-2 border-white dark:border-zinc-800 z-20 shadow-md">
                                                        1</div>
                                                </div>
                                                <div
                                                    class="text-sm md:text-base font-extrabold truncate w-full text-center text-zinc-900 dark:text-zinc-100 mb-1">
                                                    {{ explode(' ', $top3[0]['student']->name)[0] }}
                                                </div>
                                                <div
                                                    class="bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-400 font-black text-xs md:text-sm px-3 py-1 rounded shadow-sm">
                                                    {{ $top3[0]['score'] }} {{ __('نقطة') }}
                                                </div>
                                                <div
                                                    class="w-20 md:w-24 h-20 md:h-28 bg-gradient-to-t from-amber-200/50 to-amber-100/50 dark:from-amber-900/30 dark:to-amber-900/10 mt-3 rounded-t-lg border-t-2 border-amber-400 shadow-[inset_0_4px_6px_-1px_rgba(251,191,36,0.3)]">
                                                </div>
                                            </div>

                                            <!-- Third Place -->
                                            @if (isset($top3[2]))
                                                <div class="flex flex-col items-center w-24 md:w-32 z-10">
                                                    <div class="relative mb-2 shrink-0">
                                                        <div
                                                            class="w-14 h-14 md:w-16 md:h-16 rounded-full bg-orange-50 dark:bg-orange-900/20 border-[3px] border-orange-300 dark:border-orange-700/80 flex items-center justify-center text-xl font-bold shadow-lg text-orange-600 dark:text-orange-500">
                                                            {{ mb_substr($top3[2]['student']->name, 0, 1) }}
                                                        </div>
                                                        <div
                                                            class="absolute -top-2 -right-2 bg-orange-400 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs font-bold border-2 border-white dark:border-zinc-800 shadow">
                                                            3</div>
                                                    </div>
                                                    <div
                                                        class="text-xs md:text-sm font-bold truncate w-full text-center text-zinc-700 dark:text-zinc-300 mb-1">
                                                        {{ explode(' ', $top3[2]['student']->name)[0] }}
                                                    </div>
                                                    <div
                                                        class="bg-white dark:bg-zinc-800 text-orange-600 dark:text-orange-500 font-bold text-[10px] md:text-xs px-2 py-0.5 rounded shadow-sm border border-zinc-100 dark:border-zinc-700">
                                                        {{ $top3[2]['score'] }} {{ __('نقطة') }}
                                                    </div>
                                                    <div
                                                        class="w-16 md:w-20 h-10 md:h-16 bg-gradient-to-t from-orange-100 to-orange-50 dark:from-orange-900/40 dark:to-orange-900/10 mt-3 rounded-t-lg border-t-2 border-orange-300 dark:border-orange-700 shadow-inner">
                                                    </div>
                                                </div>
                                            @endif
                                        </div>

                                        <!-- My Rank Banner -->
                                        @if ($myRank > 3)
                                            <div
                                                class="mt-6 bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-100 dark:border-indigo-800/50 text-indigo-700 dark:text-indigo-300 p-4 rounded-xl flex items-center justify-between shadow-sm">
                                                <div class="flex items-center gap-4">
                                                    <div
                                                        class="bg-indigo-600 dark:bg-indigo-500 text-white w-10 h-10 rounded-full flex items-center justify-center font-black text-lg shadow-inner">
                                                        {{ $myRank }}
                                                    </div>
                                                    <div>
                                                        <div class="font-bold text-sm">{{ __('ترتيبك الحالي بين المتنافسين') }}</div>
                                                        <div class="text-xs opacity-90 mt-0.5">{{ __('مجموع نقاطك:') }} <span
                                                                class="font-bold">{{ $myScore }}</span> {{ __('شد الهمة!') }}
                                                        </div>
                                                    </div>
                                                </div>
                                                <flux:icon icon="arrow-trending-up" class="size-6 opacity-50" />
                                            </div>
                                        @endif
                                    </div>

                                    @if ($rest->isNotEmpty())
                                        <!-- Students List -->
                                        <div
                                            class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 overflow-hidden shadow-sm">
                                            <div class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                                                @foreach ($rest as $index => $standing)
                                                    @php $rank = $index + 4; @endphp
                                                    <div
                                                        class="flex items-center justify-between p-3 md:p-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/30   s {{ $standing['student']->id === $student->id ? 'bg-indigo-50/40 dark:bg-indigo-900/10' : '' }}">
                                                        <div class="flex items-center gap-3 md:gap-4">
                                                            <div
                                                                class="w-6 h-6 md:w-8 md:h-8 flex items-center justify-center text-zinc-400 font-black text-sm md:text-base">
                                                                {{ $rank }}
                                                            </div>
                                                            <div
                                                                class="w-8 h-8 md:w-10 md:h-10 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-sm font-bold text-zinc-600 dark:text-zinc-400 border border-zinc-200/50 dark:border-zinc-700/50">
                                                                {{ mb_substr($standing['student']->name, 0, 1) }}
                                                            </div>
                                                            <div>
                                                                <div
                                                                    class="font-semibold text-sm md:text-base {{ $standing['student']->id === $student->id ? 'text-indigo-600 dark:text-indigo-400' : 'text-zinc-800 dark:text-zinc-200' }}">
                                                                    {{ $standing['student']->name }}
                                                                    @if ($standing['student']->id === $student->id)
                                                                        <span
                                                                            class="text-[10px] font-bold ms-2 bg-indigo-100 dark:bg-indigo-900 text-indigo-700 dark:text-indigo-300 px-2 py-0.5 rounded-full">{{ __('أنت') }}</span>
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div
                                                            class="font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-100 dark:border-emerald-800/50 px-3 py-1 rounded-full text-xs md:text-sm">
                                                            {{ $standing['score'] }} <span
                                                                class="font-normal text-[10px] mx-1 opacity-70">{{ __('نقطة') }}</span>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                @else
                                    <div
                                        class="text-center py-10 bg-zinc-50 dark:bg-zinc-900/50 rounded-2xl border border-dashed border-zinc-200 dark:border-zinc-800">
                                        <div
                                            class="bg-amber-100 dark:bg-amber-900/30 text-amber-500 w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3">
                                            <flux:icon icon="bolt" class="size-6" />
                                        </div>
                                        <div class="font-bold text-zinc-700 dark:text-zinc-300">{{ __('المسابقة بدأت للتو!') }}
                                        </div>
                                        <p class="text-sm text-zinc-500 mt-1">{{ __('كن أول من يحصد النقاط و يتصدر القائمة.') }}</p>
                                    </div>
                                @endif
                            </div>
                        @endif

                        @unless($leaderboard)
                        <div class="w-full h-[2px] bg-zinc-100 dark:bg-zinc-800/50 rounded-full my-10"></div>

                        <!-- My Plans Section -->
                        <div class="space-y-10">
                            <div class="flex items-center justify-between">
                                <flux:heading size="xl" class="flex items-center gap-3">
                                    <div class="p-2 bg-indigo-500 rounded-lg shadow-lg shadow-indigo-500/20">
                                        <flux:icon icon="map-pin" class="size-6 text-white" variant="solid" />
                                    </div>
                                    {{ __('مساراتي وخططي الدراسية') }}
                                </flux:heading>
                                <flux:button variant="primary" size="sm" icon="plus"
                                    href="{{ route('student.plan-creator') }}"
                                    class="bg-indigo-600 hover:bg-indigo-500 text-white border-none shadow-md">
                                    {{ __('إضافة خطة جديدة') }}
                                </flux:button>
                            </div>

                            @php
                                $approvedPlans = $student->plans()->where('is_approved', true)->latest()->get();
                                $privatePlans = $student->plans()->where('is_approved', false)->latest()->get();
                            @endphp

                            @if ($approvedPlans->count() > 0 || $privatePlans->count() > 0)

                                {{-- Approved Plans --}}
                                @if ($approvedPlans->count() > 0)
                                    <div class="space-y-4">
                                        <div class="flex items-center gap-2 px-1">
                                            <div class="w-1.5 h-6 bg-emerald-500 rounded-full"></div>
                                            <flux:heading size="lg">{{ __('الخطط المعتمدة') }}</flux:heading>
                                        </div>
                                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6">
                                            @foreach ($approvedPlans as $plan)
                                                                <div
                                                                    class="group relative rounded-3xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-sm hover:shadow-xl hover:border-emerald-500/30   duration-300">
                                                                    <div class="flex items-start justify-between mb-5">
                                                                        <div
                                                                            class="p-3 bg-emerald-50 dark:bg-emerald-500/10 rounded-2xl text-emerald-600">
                                                                            <flux:icon icon="academic-cap" class="size-7" />
                                                                        </div>
                                                                        <flux:badge color="emerald" size="sm" class="font-bold px-3 py-1 rounded-full">
                                                                            {{ __('نشطة') }}
                                                                        </flux:badge>
                                                                    </div>

                                                                    <div class="mb-6">
                                                                        <div class="flex items-center gap-2 mb-2">
                                                                            <span
                                                                                class="text-xs font-black uppercase tracking-widest text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 px-3 py-1 rounded-lg">
                                                                                {{ match ($plan->plan_type) {
                                                    'hifz' => __('حفظ'),
                                                    'review' => __('مراجعة'),
                                                    'hifz_review' => __('حفظ ومراجعة'),
                                                    default => __('مسار تعليمي'),
                                                } }}
                                                                            </span>
                                                                        </div>
                                                                        <h4 class="font-black text-zinc-900 dark:text-white text-xl line-clamp-1 mb-2">
                                                                            {{ $plan->description }}
                                                                        </h4>
                                                                        <div class="flex items-center gap-2 text-zinc-500 dark:text-zinc-400">
                                                                            <flux:icon icon="calendar" class="size-4" />
                                                                            <span class="text-sm font-medium">{{ __('بدأت في:') }}
                                                                                <x-hijri-date :date="$plan->start_date" /></span>
                                                                        </div>
                                                                    </div>

                                                                    <div class="pt-5 border-t border-zinc-100 dark:border-zinc-800">
                                                                        <flux:button variant="filled"
                                                                            class="w-full bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-500 dark:hover:bg-emerald-600 text-white text-lg font-bold py-3 rounded-2xl border-none   duration-300"
                                                                            icon="eye" href="{{ route('student.show-plan', $plan->id) }}">
                                                                            {{ __('عرض التقدم') }}
                                                                        </flux:button>
                                                                    </div>
                                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- Private Plans (Pending) --}}
                                @if ($privatePlans->count() > 0)
                                    <div class="space-y-4 mt-10">
                                        <div class="flex items-center gap-2 px-1">
                                            <div class="w-1.5 h-6 bg-amber-500 rounded-full"></div>
                                            <flux:heading size="lg">{{ __('خططي الخاصة') }}</flux:heading>
                                            <flux:badge color="amber" size="sm" variant="outline" class="ms-2 font-bold">
                                                {{ __('قيد المراجعة') }}
                                            </flux:badge>
                                        </div>
                                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6">
                                            @foreach ($privatePlans as $plan)
                                                                <div
                                                                    class="group relative rounded-3xl border border-dashed border-zinc-300 dark:border-zinc-700 bg-zinc-50/30 dark:bg-zinc-900/30 p-6 hover:bg-white dark:hover:bg-zinc-900 hover:border-amber-500/50   duration-300">
                                                                    <div class="flex items-start justify-between mb-5 opacity-70">
                                                                        <div class="p-3 bg-zinc-100 dark:bg-zinc-800 rounded-2xl text-zinc-500">
                                                                            <flux:icon icon="lock-closed" class="size-7" />
                                                                        </div>
                                                                        <flux:badge color="amber" size="sm" variant="solid"
                                                                            class="font-bold px-3 py-1 rounded-full">{{ __('بانتظار المعلم') }}
                                                                        </flux:badge>
                                                                    </div>

                                                                    <div class="mb-6">
                                                                        <div class="flex items-center gap-2 mb-2">
                                                                            <span
                                                                                class="text-xs font-black uppercase tracking-widest text-zinc-500 dark:text-zinc-400 bg-zinc-100 dark:bg-zinc-800 px-3 py-1 rounded-lg">
                                                                                {{ match ($plan->plan_type) {
                                                    'hifz' => __('حفظ'),
                                                    'review' => __('مراجعة'),
                                                    'hifz_review' => __('حفظ ومراجعة'),
                                                    default => __('مسار تعليمي'),
                                                } }}
                                                                            </span>
                                                                        </div>
                                                                        <h4
                                                                            class="font-bold text-zinc-700 dark:text-zinc-300 text-lg line-clamp-1 mb-2">
                                                                            {{ $plan->description }}
                                                                        </h4>
                                                                        <div class="flex items-center gap-2 text-zinc-400">
                                                                            <flux:icon icon="clock" class="size-4" />
                                                                            <span class="text-xs font-medium">{{ __('تم إنشاؤها:') }}
                                                                                {{ $plan->created_at->diffForHumans() }}</span>
                                                                        </div>
                                                                    </div>

                                                                    <div class="pt-5 border-t border-zinc-100 dark:border-zinc-800">
                                                                        <flux:button variant="ghost"
                                                                            class="w-full text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/20 text-lg font-bold py-3 rounded-2xl border border-dashed border-amber-200 dark:border-amber-800/50   duration-300"
                                                                            icon="eye" href="{{ route('student.show-plan', $plan->id) }}">
                                                                            {{ __('معاينة الخطة') }}
                                                                        </flux:button>
                                                                    </div>
                                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @else
                                {{-- Empty State --}}
                                <div
                                    class="flex flex-col items-center justify-center p-12 border-2 border-dashed border-zinc-200 dark:border-zinc-800 rounded-3xl bg-zinc-50/50 dark:bg-zinc-900/20 text-center">
                                    <div
                                        class="p-5 bg-white dark:bg-zinc-900 rounded-2xl shadow-sm border border-zinc-100 dark:border-zinc-800 mb-4 text-zinc-300 dark:text-zinc-700">
                                        <flux:icon icon="map" class="size-12" />
                                    </div>
                                    <h3 class="font-bold text-zinc-800 dark:text-zinc-200 text-lg">
                                        {{ __('لا توجد خطط دراسية حتى الآن') }}
                                    </h3>
                                    <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-2 mb-8 max-w-xs mx-auto">
                                        {{ __('ابدأ رحلتك التعليمية بإنشاء خطة دراسية مخصصة تناسب أهدافك في الحفظ والمراجعة.') }}
                                    </p>
                                    <flux:button variant="primary" icon="plus" href="{{ route('student.plan-creator') }}"
                                        class="bg-indigo-600 hover:bg-indigo-700 shadow-xl shadow-indigo-500/30">
                                        {{ __('إنشاء خطتي الأولى') }}
                                    </flux:button>
                                </div>
                            @endif
                        </div>
                        <div class="w-full h-[2px] bg-zinc-100 dark:bg-zinc-800/50 rounded-full my-10"></div>

                        <!-- Achievements & Discipline Section -->
                        <div class="space-y-10">
                            <flux:heading size="xl" class="flex items-center gap-3">
                                <div class="p-2 bg-rose-500 rounded-lg shadow-lg shadow-rose-500/20">
                                    <flux:icon icon="chart-bar" class="size-6 text-white" variant="solid" />
                                </div>
                                {{ __('مؤشرات الأداء والانضباط') }}
                            </flux:heading>

                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                <!-- Achievement Widgets -->
                                <div class="space-y-4">
                                    <flux:heading size="lg" class="px-1 text-zinc-500 flex items-center gap-2">
                                        <flux:icon icon="star" class="size-5" />
                                        {{ __('مستوى الإنجاز (30 يوماً)') }}
                                    </flux:heading>
                                    <div class="grid grid-cols-3 gap-3">

                                        <div
                                            class="bg-white dark:bg-zinc-900 p-5 rounded-2xl border border-zinc-100 dark:border-zinc-800 flex flex-col items-center justify-center text-center">
                                            <div class="text-blue-500 mb-2">
                                                <flux:icon icon="star" variant="solid" class="size-8" />
                                            </div>
                                            <div class="text-3xl font-bold text-zinc-800 dark:text-zinc-100">
                                                {{ $excellent }}
                                            </div>
                                            <div class="text-xs text-zinc-500 mt-1">{{ __('ممتاز') }}</div>
                                        </div>

                                        <div
                                            class="bg-white dark:bg-zinc-900 p-5 rounded-2xl border border-zinc-100 dark:border-zinc-800 flex flex-col items-center justify-center text-center">
                                            <div class="text-green-500 mb-2">
                                                <flux:icon icon="check-badge" variant="solid" class="size-8" />
                                            </div>
                                            <div class="text-3xl font-bold text-zinc-800 dark:text-zinc-100">{{ $good }}
                                            </div>
                                            <div class="text-xs text-zinc-500 mt-1">{{ __('جيد جداً') }}</div>
                                        </div>

                                        <div
                                            class="bg-white dark:bg-zinc-900 p-5 rounded-2xl border border-zinc-100 dark:border-zinc-800 flex flex-col items-center justify-center text-center">
                                            <div class="text-amber-500 mb-2">
                                                <flux:icon icon="hand-thumb-up" variant="solid" class="size-8" />
                                            </div>
                                            <div class="text-3xl font-bold text-zinc-800 dark:text-zinc-100">
                                                {{ $acceptable }}
                                            </div>
                                            <div class="text-xs text-zinc-500 mt-1">{{ __('مقبول') }}</div>
                                        </div>

                                    </div>
                                </div>

                            </div>

                            <!-- Discipline Widget -->
                            <div class="space-y-4">
                                <flux:heading size="lg" class="px-1 text-zinc-500 flex items-center gap-2">
                                    <flux:icon icon="check-badge" class="size-5" />
                                    {{ __('سجل الحضور والانضباط') }}
                                </flux:heading>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">

                                    <div
                                        class="bg-white dark:bg-zinc-900 p-5 rounded-2xl border border-zinc-100 dark:border-zinc-800 flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="text-red-500 bg-red-50 dark:bg-red-900/40 p-3 rounded-xl">
                                                <flux:icon icon="x-circle" variant="solid" class="size-6" />
                                            </div>
                                            <div>
                                                <div class="font-bold text-zinc-800 dark:text-zinc-100">{{ __('الغيابات') }}
                                                </div>
                                                <div class="text-xs text-zinc-500">{{ __('الحد الأقصى:') }}
                                                    {{ $absenceLimit }}
                                                </div>
                                            </div>
                                        </div>
                                        <div
                                            class="text-3xl font-black {{ $absences >= $absenceLimit ? 'text-red-600' : 'text-zinc-700 dark:text-zinc-300' }}">
                                            {{ $absences }}<span
                                                class="text-lg text-zinc-400 font-normal">/{{ $absenceLimit }}</span>
                                        </div>
                                    </div>

                                    <div
                                        class="bg-white dark:bg-zinc-900 p-5 rounded-2xl border border-zinc-100 dark:border-zinc-800 flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="text-amber-500 bg-amber-50 dark:bg-amber-900/40 p-3 rounded-xl">
                                                <flux:icon icon="clock" variant="solid" class="size-6" />
                                            </div>
                                            <div>
                                                <div class="font-bold text-zinc-800 dark:text-zinc-100">{{ __('التأخر') }}
                                                </div>
                                                <div class="text-xs text-zinc-500">{{ __('الحد الأقصى:') }}
                                                    {{ $latenessLimit }}
                                                </div>
                                            </div>
                                        </div>
                                        <div
                                            class="text-3xl font-black {{ $lateness >= $latenessLimit ? 'text-amber-600' : 'text-zinc-700 dark:text-zinc-300' }}">
                                            {{ $lateness }}<span
                                                class="text-lg text-zinc-400 font-normal">/{{ $latenessLimit }}</span>
                                        </div>
                                    </div>

                                </div>

                                @if ($absences >= $absenceLimit || $lateness >= $latenessLimit)
                                    <div
                                        class="mt-4 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 p-4 rounded-xl border border-red-200 dark:border-red-800/50 flex items-start gap-3">
                                        <flux:icon icon="exclamation-triangle" variant="solid" class="size-5 mt-0.5" />
                                        <div class="text-sm">
                                            <strong>{{ __('تنبيه إداري:') }}</strong>
                                            {{ __('لقد تجاوزت الحد المسموح به للغياب أو التأخر، يرجى الالتزام بالحضور لتفادي الإجراءات الإدارية.') }}
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                        @endunless
    @endif

    @unless($leaderboard)
    <div class="space-y-8 mt-8" dir="rtl">
        {{-- Community: real events from circle-mates within the active competition, honest empty state otherwise --}}
        <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-xs">
            <x-student.partials.section-heading :title="__('المجتمع')" icon="users" />
            @if(!empty($circleNews))
                <div class="space-y-3">
                    @foreach($circleNews as $item)
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/50">
                            <span class="text-xl shrink-0">{{ $item['icon'] }}</span>
                            <div class="min-w-0">
                                <span class="font-bold text-zinc-800 dark:text-zinc-100">{{ $item['student_name'] }}</span>
                                <span class="text-zinc-500 dark:text-zinc-400">{{ $item['title'] }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8 text-sm text-zinc-400">
                    {{ __('لا توجد أنشطة من زملاء الحلقة بعد') }}
                </div>
            @endif
        </div>

        <div class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-xs text-zinc-400 dark:text-zinc-500 pt-4 pb-2">
            <a href="#" class="hover:text-maroon transition-colors">{{ __('الدعم') }}</a>
            <a href="#" class="hover:text-maroon transition-colors">{{ __('سياسة الخصوصية') }}</a>
            <a href="#" class="hover:text-maroon transition-colors">{{ __('الأسئلة الشائعة') }}</a>
            <a href="#" class="hover:text-maroon transition-colors">{{ __('تواصل معنا') }}</a>
        </div>
    </div>
    @endunless
                </div>