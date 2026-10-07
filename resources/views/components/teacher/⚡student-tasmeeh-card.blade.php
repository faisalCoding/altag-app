<?php

use Livewire\Component;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\StudentOdePlan;
use App\Models\StudentOdeAchievement;
use App\Models\OdePath;
use App\Models\OdePathDay;
use App\Models\StudentHadithPlan;
use App\Models\StudentHadithAchievement;
use App\Models\HadithPath;
use App\Models\HadithPathDay;
use App\Models\GamificationTrack;
use App\Services\GamificationService;
use App\Support\NextExamBadge;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Attributes\Locked;

new class extends Component {
    public $student;

    public $sPlans;

    #[Locked]
    public $activePlanId;

    #[Reactive]
    public $gradedAtDate;

    #[Locked]
    public $selectedPlanId;

    public function mount($student, $sPlans, $activePlanId)
    {
        $this->student = $student;
        $this->sPlans = $sPlans;
        $this->activePlanId = $activePlanId;
        $this->selectedPlanId = $this->activePlanId;
    }

    public function selectPlan($planId)
    {
        if ($planId !== null && ! StudentPlan::where('student_id', $this->student->id)->whereKey($planId)->exists()) {
            return;
        }

        $this->selectedPlanId = $planId;
    }

    /**
     * A grade is one of the buttons — excellent, good, acceptable, or not
     * graded — for one of the two parts. The Quran also has «لم يسمع» (0), kept
     * apart from not graded; odes and mutun store it as not graded. Anything
     * else is an edited request.
     */
    private function ensureValidGrade($type, $value, bool $allowNotHeard = false): void
    {
        abort_unless(in_array($type, ['hifz', 'review'], true), 422);

        $allowed = $allowNotHeard ? ['0', '1', '2', '3'] : ['1', '2', '3'];

        abort_unless($value === null || ((is_int($value) || is_string($value)) && in_array((string) $value, $allowed, true)), 422);
    }

    /**
     * Grade the session of the date picked above the list. A portion marked
     * «لم يسمع» on one day and graded on the next keeps both: each day is a
     * session of its own, and the plan day shows the latest.
     */
    public function saveAchievement($dayId, $type, $value)
    {
        $this->ensureValidGrade($type, $value, allowNotHeard: true);
        $value = $value === null ? null : (int) $value;

        // The day must belong to one of this card's student's plans: the id
        // arrives from the browser, and any day of any student would update.
        abort_unless(
            StudentPlanDay::whereKey($dayId)->whereHas('plan', fn ($plan) => $plan->where('student_id', $this->student->id))->exists(),
            404
        );

        // The academy's day, never one after it: a session cannot be graded
        // before it happens.
        $today = \App\Services\TeacherSyncSnapshot::today();
        $date = is_string($this->gradedAtDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->gradedAtDate)
            ? min($this->gradedAtDate, $today)
            : $today;

        // The teacher app writes plan days under the same per-day lock — both
        // parts share one points row — so a web grade and a phone grade of
        // the same day can never race into duplicate points.
        try {
            \Illuminate\Support\Facades\Cache::lock(\App\Services\TasmeehChangeService::dayLockKey((int) $dayId), 10)->block(5, function () use ($dayId, $type, $date, $today, $value) {
                $day = StudentPlanDay::with('plan.student')->find($dayId);

                if (! $day) {
                    return;
                }

                // The web grades only; a range the app recorded for the
                // session stays with it.
                \App\Services\PlanDayAttempts::adopt($day, $type);
                $attempt = \App\Services\PlanDayAttempts::on($day, $type, $date);
                $recited = $attempt ? \App\Services\TasmeehSnapshot::withoutScheduled($day, $type, $attempt->recitedAyahIds()) : null;

                \App\Services\PlanDayAttempts::record($day, $type, $date, $today, $value, $recited, auth()->id(), $attempt);
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            // The phone is writing this day right now; nothing was saved.
            Flux::toast('لم يُحفظ التقييم: يجري تعديل هذا اليوم من جهاز آخر، أعد المحاولة.', variant: 'danger');

            return;
        }

        Flux::toast('تم حفظ التقييم', variant: 'success');

        // The button already shows the new grade, and nothing else on screen
        // depends on it. Re-rendering would resend every day of every plan.
        $this->skipRender();
    }

    public function saveOdeAchievement($pathDayId, $type, $value)
    {
        $this->ensureValidGrade($type, $value);

        if (! $this->student->memorisesOdes()) {
            Flux::toast('المنظومات مُخفاة في مرحلة هذا الطالب', variant: 'danger');
            return;
        }

        // Find the student's active ode plan
        $activeOdePlan = StudentOdePlan::where('student_id', $this->student->id)
            ->where('status', 'active')
            ->first();

        if (!$activeOdePlan) {
            Flux::toast('لا توجد خطة منظومة نشطة لهذا الطالب', variant: 'danger');
            return;
        }

        abort_unless(OdePathDay::whereKey($pathDayId)->where('ode_path_id', $activeOdePlan->ode_path_id)->exists(), 404);

        $updateData = [];
        $gradeTime = now();
        if ($this->gradedAtDate) {
            if ($this->gradedAtDate === now()->format('Y-m-d')) {
                $gradeTime = now();
            } else {
                $gradeTime = \Carbon\Carbon::parse($this->gradedAtDate)->setHour(12)->setMinute(0);
            }
        }

        if ($type === 'hifz') {
            $updateData['hifz_achievement'] = $value;
            $updateData['hifz_graded_at'] = $value !== null ? $gradeTime : null;
        } elseif ($type === 'review') {
            $updateData['review_achievement'] = $value;
            $updateData['review_graded_at'] = $value !== null ? $gradeTime : null;
        }

        $achievement = StudentOdeAchievement::updateOrCreate(
            [
                'student_ode_plan_id' => $activeOdePlan->id,
                'ode_path_day_id' => $pathDayId,
            ],
            $updateData
        );

        \App\Services\GamificationService::syncStudentOdeAchievementXP($achievement->fresh(['plan.student', 'pathDay']));

        if ($value !== null) {
            $label = $type === 'hifz' ? 'الحفظ' : 'المراجعة';
            \App\Services\NotificationService::notify(
                'student',
                $this->student->id,
                'grading',
                'تقييم جديد',
                "قام معلمك بتقييم {$label} الخاص بك في المنظومة",
                route('student.hifz'),
            );
        }

        Flux::toast('تم حفظ تقييم المنظومة بنجاح', variant: 'success');

        // Same reason as saveAchievement: the button already shows the grade.
        $this->skipRender();
    }

    public function saveHadithAchievement($pathDayId, $type, $value)
    {
        $this->ensureValidGrade($type, $value);

        if (! $this->student->memorisesHadith()) {
            Flux::toast('المتون مُخفاة في مرحلة هذا الطالب', variant: 'danger');
            return;
        }

        // Find the student's active hadith plan
        $activeHadithPlan = StudentHadithPlan::where('student_id', $this->student->id)
            ->where('status', 'active')
            ->first();

        if (!$activeHadithPlan) {
            Flux::toast('لا توجد خطة حديث نشطة لهذا الطالب', variant: 'danger');
            return;
        }

        abort_unless(\App\Models\HadithPathDay::whereKey($pathDayId)->where('hadith_path_id', $activeHadithPlan->hadith_path_id)->exists(), 404);

        $updateData = [];
        $gradeTime = now();
        if ($this->gradedAtDate) {
            if ($this->gradedAtDate === now()->format('Y-m-d')) {
                $gradeTime = now();
            } else {
                $gradeTime = \Carbon\Carbon::parse($this->gradedAtDate)->setHour(12)->setMinute(0);
            }
        }

        if ($type === 'hifz') {
            $updateData['hifz_achievement'] = $value;
            $updateData['hifz_graded_at'] = $value !== null ? $gradeTime : null;
        } elseif ($type === 'review') {
            $updateData['review_achievement'] = $value;
            $updateData['review_graded_at'] = $value !== null ? $gradeTime : null;
        }

        $achievement = StudentHadithAchievement::updateOrCreate(
            [
                'student_hadith_plan_id' => $activeHadithPlan->id,
                'hadith_path_day_id' => $pathDayId,
            ],
            $updateData
        );

        \App\Services\GamificationService::syncStudentHadithAchievementXP($achievement->fresh(['plan.student', 'pathDay']));

        if ($value !== null) {
            $label = $type === 'hifz' ? 'الحفظ' : 'المراجعة';
            \App\Services\NotificationService::notify(
                'student',
                $this->student->id,
                'grading',
                'تقييم جديد',
                "قام معلمك بتقييم {$label} الخاص بك في الحديث",
                route('student.hifz'),
            );
        }

        Flux::toast('تم حفظ تقييم الحديث بنجاح', variant: 'success');

        // Same reason as saveAchievement: the button already shows the grade.
        $this->skipRender();
    }

    // --- Path & track enrollment (teacher, permission-gated) -------------------

    /** @var 'hadith'|'ode'|'' */
    public string $pathModalType = '';

    public bool $showPathModal = false;

    /** @var array<int, array{id:int, name:string, text:?string}> */
    public array $availablePaths = [];

    public ?int $selectedPathId = null;

    public bool $showTrackModal = false;

    /** @var array<int, array{id:int, title:string, tracks:array<int, array{id:int, name:string}>}> */
    public array $availableCompetitions = [];

    /** @var array<int, ?int> map of leaderboard_id => selected track_id (or null) */
    public array $trackSelections = [];

    /**
     * The effective permissions of the acting teacher (empty when not a teacher).
     *
     * @return array<string, bool>
     */
    private function teacherPermissions(): array
    {
        $permissions = auth('teacher')->user()?->effectivePermissions() ?? [];

        // A stage that does not memorise the mutun or the odes cannot be
        // enrolled in them either, whatever the teacher may do elsewhere —
        // which also shuts the enrol and change-path actions below.
        if (! $this->student->memorisesHadith()) {
            $permissions['can_manage_hadith_paths'] = false;
        }

        if (! $this->student->memorisesOdes()) {
            $permissions['can_manage_ode_paths'] = false;
        }

        return $permissions;
    }

    public function openPathModal(string $type): void
    {
        $permKey = $type === 'ode' ? 'can_manage_ode_paths' : 'can_manage_hadith_paths';
        if (empty($this->teacherPermissions()[$permKey])) {
            Flux::toast('لا تملك صلاحية إدارة هذا النوع من المسارات', variant: 'danger');
            return;
        }

        $this->pathModalType = $type;

        if ($type === 'ode') {
            $this->availablePaths = OdePath::with('ode')->orderBy('name')->get()
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'text' => $p->ode?->name])
                ->all();
            $this->selectedPathId = StudentOdePlan::where('student_id', $this->student->id)
                ->where('status', 'active')->value('ode_path_id');
        } else {
            $this->availablePaths = HadithPath::with('text')->orderBy('name')->get()
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'text' => $p->text?->name])
                ->all();
            $this->selectedPathId = StudentHadithPlan::where('student_id', $this->student->id)
                ->where('status', 'active')->value('hadith_path_id');
        }

        $this->showPathModal = true;
    }

    public function enrollInPath(): void
    {
        $type = $this->pathModalType;
        $permKey = $type === 'ode' ? 'can_manage_ode_paths' : 'can_manage_hadith_paths';
        if (empty($this->teacherPermissions()[$permKey])) {
            Flux::toast('لا تملك صلاحية إدارة هذا النوع من المسارات', variant: 'danger');
            return;
        }

        if ($type === 'ode') {
            $current = StudentOdePlan::where('student_id', $this->student->id)->where('status', 'active')->first();
            if ($current && $current->ode_path_id === (int) $this->selectedPathId) {
                $this->showPathModal = false;
                return;
            }
            // Suspend any current active ode plan, then enroll in the chosen path.
            StudentOdePlan::where('student_id', $this->student->id)->where('status', 'active')
                ->update(['status' => 'suspended']);
            if ($this->selectedPathId) {
                $path = OdePath::findOrFail($this->selectedPathId);
                StudentOdePlan::create([
                    'student_id' => $this->student->id,
                    'ode_path_id' => $path->id,
                    'start_date' => $path->start_date,
                    'status' => 'active',
                    'created_by_role' => 'teacher',
                ]);
                Flux::toast('تم تسكين الطالب في مسار المنظومة', variant: 'success');
            } else {
                Flux::toast('تم إلغاء تسكين الطالب من مسار المنظومة', variant: 'success');
            }
        } else {
            $current = StudentHadithPlan::where('student_id', $this->student->id)->where('status', 'active')->first();
            if ($current && $current->hadith_path_id === (int) $this->selectedPathId) {
                $this->showPathModal = false;
                return;
            }
            StudentHadithPlan::where('student_id', $this->student->id)->where('status', 'active')
                ->update(['status' => 'suspended']);
            if ($this->selectedPathId) {
                $path = HadithPath::findOrFail($this->selectedPathId);
                StudentHadithPlan::create([
                    'student_id' => $this->student->id,
                    'hadith_path_id' => $path->id,
                    'start_date' => $path->start_date,
                    'status' => 'active',
                    'created_by_role' => 'teacher',
                ]);
                Flux::toast('تم تسكين الطالب في مسار الحديث', variant: 'success');
            } else {
                Flux::toast('تم إلغاء تسكين الطالب من مسار الحديث', variant: 'success');
            }
        }

        $this->showPathModal = false;
    }

    public function openTrackModal(): void
    {
        if (empty($this->teacherPermissions()['can_manage_gamification_tracks'])) {
            Flux::toast('لا تملك صلاحية تسكين الطلاب في مسارات التلعيب', variant: 'danger');
            return;
        }

        $leaderboards = GamificationService::getActiveLeaderboards($this->student);

        $currentByLeaderboard = GamificationTrack::query()
            ->whereIn('leaderboard_id', $leaderboards->pluck('id'))
            ->whereHas('students', fn ($q) => $q->where('users.id', $this->student->id))
            ->get(['id', 'leaderboard_id'])
            ->keyBy('leaderboard_id');

        $this->availableCompetitions = [];
        $this->trackSelections = [];

        foreach ($leaderboards as $leaderboard) {
            $tracks = GamificationTrack::where('leaderboard_id', $leaderboard->id)
                ->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'name']);

            if ($tracks->isEmpty()) {
                continue;
            }

            $this->availableCompetitions[] = [
                'id' => $leaderboard->id,
                'title' => $leaderboard->title,
                'tracks' => $tracks->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->all(),
            ];
            $this->trackSelections[$leaderboard->id] = $currentByLeaderboard->get($leaderboard->id)?->id;
        }

        $this->showTrackModal = true;
    }

    public function saveTrackEnrollment(): void
    {
        if (empty($this->teacherPermissions()['can_manage_gamification_tracks'])) {
            Flux::toast('لا تملك صلاحية تسكين الطلاب في مسارات التلعيب', variant: 'danger');
            return;
        }

        foreach ($this->availableCompetitions as $competition) {
            $trackIds = collect($competition['tracks'])->pluck('id')->all();
            $selectedTrackId = $this->trackSelections[$competition['id']] ?? null;

            // Enforce one track per competition: detach from all this competition's
            // tracks, then attach the chosen one (if any).
            \Illuminate\Support\Facades\DB::table('gamification_track_student')
                ->where('student_id', $this->student->id)
                ->whereIn('track_id', $trackIds)
                ->delete();

            if ($selectedTrackId && in_array((int) $selectedTrackId, $trackIds, true)) {
                GamificationTrack::find($selectedTrackId)?->students()->syncWithoutDetaching([$this->student->id]);
            }
        }

        $this->showTrackModal = false;
        Flux::toast('تم تحديث تسكين الطالب في مسارات التلعيب', variant: 'success');
    }

    /**
     * The box beside the student's name, as the teacher app draws it: the juz
     * count of their next exam and how near it is, or a "+" to set one.
     *
     * The badge itself is read where the tasmeeh page's list reads its small
     * ones, so the two always show the same exam, and both open the page's
     * one exam editor while the teacher may set exams there.
     *
     * @return array{exam: array{juz: ?int, word: ?string, level: string, date_hijri: string, soon: bool, overdue: bool}|null, canSchedule: bool}
     */
    private function examBox(): array
    {
        return [
            'exam' => NextExamBadge::forStudents([$this->student->id])->get($this->student->id),
            'canSchedule' => NextExamBadge::canSchedule(),
        ];
    }

    /**
     * The exam editor saved this student's exam: draw the card again, so its
     * box shows it. The event carries the student in its name, so a save
     * wakes this card alone rather than every card the teacher has opened.
     */
    #[On('exam-saved.{student.id}')]
    public function examSaved(): void
    {
        //
    }

    public function with()
    {
        // Fetch student's Poetic Ode plans
        $studentOdePlans = collect();
        $activeOdePlan = null;
        if (app()->bound('tasmeeh_ode_plans_cache')) {
            $activeOdePlan = app('tasmeeh_ode_plans_cache')->get($this->student->id);
            if ($activeOdePlan) {
                $studentOdePlans->push($activeOdePlan);
            }
        } else {
            $studentOdePlans = StudentOdePlan::with('path.ode')
                ->where('student_id', $this->student->id)
                ->get();
            $activeOdePlan = $studentOdePlans->firstWhere('status', 'active');
        }

        // Fetch student's Hadith plans
        $studentHadithPlans = collect();
        $activeHadithPlan = null;
        if (app()->bound('tasmeeh_hadith_plans_cache')) {
            $activeHadithPlan = app('tasmeeh_hadith_plans_cache')->get($this->student->id);
            if ($activeHadithPlan) {
                $studentHadithPlans->push($activeHadithPlan);
            }
        } else {
            $studentHadithPlans = StudentHadithPlan::with('path')
                ->where('student_id', $this->student->id)
                ->get();
            $activeHadithPlan = $studentHadithPlans->firstWhere('status', 'active');
        }

        // A stage that switched these off sees neither them nor their days.
        // Emptied here, at the source, so every block that hangs on a plan
        // hides on its own rather than each carrying the same condition.
        if (! $this->student->memorisesOdes()) {
            $activeOdePlan = null;
            $studentOdePlans = collect();
        }

        if (! $this->student->memorisesHadith()) {
            $activeHadithPlan = null;
            $studentHadithPlans = collect();
        }

        // Selected Quranic plan (inactive plans never show on the tasmeeh page)
        $activePlan = null;
        if ($this->selectedPlanId) {
            $activePlan = $this->sPlans->where('status', 'active')->firstWhere('id', $this->selectedPlanId);
        }
        if (!$activePlan) {
            $activePlan = $this->sPlans->firstWhere('status', 'active');
        }

        $days = collect();
        $defaultDayId = null;
        $dayIds = [];

        // Fetch Poetic Ode days for the active Ode plan
        $odeDays = collect();
        if ($activeOdePlan) {
            if (app()->bound('tasmeeh_ode_days_cache')) {
                $odeDays = app('tasmeeh_ode_days_cache')->get($activeOdePlan->id) ?? collect();
            } else {
                $rawOdeDays = OdePathDay::where('ode_path_id', $activeOdePlan->ode_path_id)
                    ->orderBy('day_number', 'asc')
                    ->get();

                $odeAchievements = StudentOdeAchievement::where('student_ode_plan_id', $activeOdePlan->id)
                    ->get()
                    ->keyBy('ode_path_day_id');

                $odeDays = $rawOdeDays->map(function ($day) use ($odeAchievements) {
                    $achievement = $odeAchievements->get($day->id);
                    $day->hifz_achievement = $achievement?->hifz_achievement;
                    $day->review_achievement = $achievement?->review_achievement;
                    $day->hifz_graded_at = $achievement?->hifz_graded_at;
                    $day->review_graded_at = $achievement?->review_graded_at;
                    return $day;
                });
            }
        }

        // Fetch Hadith days for the active Hadith plan
        $hadithDays = collect();
        if ($activeHadithPlan) {
            if (app()->bound('tasmeeh_hadith_days_cache')) {
                $hadithDays = app('tasmeeh_hadith_days_cache')->get($activeHadithPlan->id) ?? collect();
            } else {
                $rawDays = HadithPathDay::with(['fromHadith', 'toHadith', 'reviewFromHadith', 'reviewToHadith'])
                    ->where('hadith_path_id', $activeHadithPlan->hadith_path_id)
                    ->orderBy('day_number', 'asc')
                    ->get();

                $achievements = StudentHadithAchievement::where('student_hadith_plan_id', $activeHadithPlan->id)
                    ->get()
                    ->keyBy('hadith_path_day_id');

                $hadithDays = $rawDays->map(function ($day) use ($achievements) {
                    $achievement = $achievements->get($day->id);
                    $day->hifz_achievement = $achievement?->hifz_achievement;
                    $day->review_achievement = $achievement?->review_achievement;
                    $day->hifz_graded_at = $achievement?->hifz_graded_at;
                    $day->review_graded_at = $achievement?->review_graded_at;
                    return $day;
                });
            }
        }


        // Find the specific Ode plan day matching the selected gradedAtDate
        $odeDayForSelectedDate = null;
        if ($activeOdePlan && $odeDays->isNotEmpty()) {
            $targetDateStr = $this->gradedAtDate ?: now()->format('Y-m-d');
            $odeDayForSelectedDate = $odeDays->first(function ($day) use ($targetDateStr) {
                return $day->date->toDateString() === $targetDateStr;
            });
        }

        // Find the specific Hadith plan day matching the selected gradedAtDate
        $hadithDayForSelectedDate = null;
        if ($activeHadithPlan && $hadithDays->isNotEmpty()) {
            $targetDateStr = $this->gradedAtDate ?: now()->format('Y-m-d');
            $hadithDayForSelectedDate = $hadithDays->first(function ($day) use ($targetDateStr) {
                return $day->date->toDateString() === $targetDateStr;
            });
        }

        // Load Quranic plan days
        if ($activePlan) {
            if (app()->bound('tasmeeh_days_cache')) {
                $days = app('tasmeeh_days_cache')->get($activePlan->id) ?? collect();
            } else {
                $days = StudentPlanDay::with(['fromAyah.surah', 'toAyah.surah', 'reviewFromAyah.surah', 'reviewToAyah.surah', 'hifzRecitedFromAyah.surah', 'hifzRecitedToAyah.surah', 'reviewRecitedFromAyah.surah', 'reviewRecitedToAyah.surah', 'attempts.recitedFromAyah.surah', 'attempts.recitedToAyah.surah', 'plan'])
                    ->where('student_plan_id', $activePlan->id)
                    ->orderBy('date', 'asc')
                    ->get();
            }

            if ($days->isNotEmpty()) {
                $dayIds = $days->pluck('id')->toArray();

                // A day marked «لم يسمع» is still owed, so it stays the one to open.
                $oldestIncomplete = $days->first(function ($day) use ($activePlan) {
                    if ($activePlan->plan_type === 'hifz') {
                        return ! $day->isRecited('hifz');
                    } elseif ($activePlan->plan_type === 'review') {
                        return ! $day->isRecited('review');
                    } else {
                        return ! $day->isRecited('hifz') || ! $day->isRecited('review');
                    }
                });

                if ($oldestIncomplete) {
                    $defaultDayId = $oldestIncomplete->id;
                } else {
                    $defaultDayId = $days->last()->id;
                }
            }
        }

        // Request-static cache to completely avoid N+1 queries for Surah loading inside loops
        static $cachedSurahs = null;
        if ($cachedSurahs === null) {
            $cachedSurahs = \App\Models\Surah::all()->keyBy('id');
        }

        $defaultOdeDayId = null;
        $odeDayIds = [];
        $odeDateToDayIdMap = [];
        if ($activeOdePlan && $odeDays->isNotEmpty()) {
            $odeDayIds = $odeDays->pluck('id')->toArray();
            $odeDateToDayIdMap = $odeDays->mapWithKeys(function ($day) {
                return [$day->date->toDateString() => $day->id];
            })->toArray();

            if ($odeDayForSelectedDate) {
                $defaultOdeDayId = $odeDayForSelectedDate->id;
            } else {
                $oldestIncompleteOde = $odeDays->first(function ($day) {
                    $hasHifz = $day->from_verse_number && $day->to_verse_number;
                    $hasReview = $day->review_from_verse_number && $day->review_to_verse_number;

                    if ($hasHifz && $hasReview) {
                        return is_null($day->hifz_achievement) || is_null($day->review_achievement);
                    } elseif ($hasHifz) {
                        return is_null($day->hifz_achievement);
                    } elseif ($hasReview) {
                        return is_null($day->review_achievement);
                    }
                    return false;
                });

                if ($oldestIncompleteOde) {
                    $defaultOdeDayId = $oldestIncompleteOde->id;
                } else {
                    $defaultOdeDayId = $odeDays->last()->id;
                }
            }
        }

        $defaultHadithDayId = null;
        $hadithDayIds = [];
        $hadithDateToDayIdMap = [];
        if ($activeHadithPlan && $hadithDays->isNotEmpty()) {
            $hadithDayIds = $hadithDays->pluck('id')->toArray();
            $hadithDateToDayIdMap = $hadithDays->mapWithKeys(function ($day) {
                return [$day->date->toDateString() => $day->id];
            })->toArray();

            if ($hadithDayForSelectedDate) {
                $defaultHadithDayId = $hadithDayForSelectedDate->id;
            } else {
                $oldestIncompleteHadith = $hadithDays->first(function ($day) {
                    $hasHifz = $day->from_line_number && $day->to_line_number;
                    $hasReview = $day->review_from_line_number && $day->review_to_line_number;

                    if ($hasHifz && $hasReview) {
                        return is_null($day->hifz_achievement) || is_null($day->review_achievement);
                    } elseif ($hasHifz) {
                        return is_null($day->hifz_achievement);
                    } elseif ($hasReview) {
                        return is_null($day->review_achievement);
                    }
                    return false;
                });

                if ($oldestIncompleteHadith) {
                    $defaultHadithDayId = $oldestIncompleteHadith->id;
                } else {
                    $defaultHadithDayId = $hadithDays->last()->id;
                }
            }
        }

        $quranDateToDayIdMap = [];
        if ($activePlan && $days->isNotEmpty()) {
            $quranDateToDayIdMap = $days->mapWithKeys(function ($day) {
                return [$day->date->toDateString() => $day->id];
            })->toArray();
        }

        // Fetch all verses of the active Ode plan's ode
        $odeVerses = collect();
        if ($activeOdePlan) {
            $odeVerses = \App\Models\OdeVerse::where('ode_id', $activeOdePlan->path->ode_id)
                ->orderBy('verse_number', 'asc')
                ->get();
        }

        // Fetch all hadiths and lines of the active Hadith plan's text
        $allHadiths = collect();
        if ($activeHadithPlan) {
            $allHadiths = \App\Models\Hadith::with('lines')->where(function ($query) use ($activeHadithPlan) {
                $query->where('hadith_text_id', $activeHadithPlan->path->hadith_text_id)
                    ->orWhereHas('chapter', function ($q) use ($activeHadithPlan) {
                        $q->where('hadith_text_id', $activeHadithPlan->path->hadith_text_id);
                    });
            })->orderBy('hadith_chapter_id', 'asc')->orderBy('id', 'asc')->get();
        }

        // Fetch all lines of the active Hadith plan's selected range
        $hadithLines = collect();
        if ($activeHadithPlan && $hadithDayForSelectedDate && $allHadiths->isNotEmpty()) {
            if ($hadithDayForSelectedDate->memorize_type === 'lines') {
                $hadith = $allHadiths->first(fn($h) => $h->id == $hadithDayForSelectedDate->from_hadith_id);
                if ($hadith) {
                    $hadithLines = $hadith->lines;
                }
            } else {
                $startIdx = $allHadiths->search(fn($h) => $h->id == $hadithDayForSelectedDate->from_hadith_id);
                $endIdx = $allHadiths->search(fn($h) => $h->id == $hadithDayForSelectedDate->to_hadith_id);
                if ($startIdx !== false && $endIdx !== false) {
                    $selectedHadiths = $allHadiths->slice($startIdx, $endIdx - $startIdx + 1);
                    foreach ($selectedHadiths as $hadith) {
                        foreach ($hadith->lines as $line) {
                            $hadithLines->push($line);
                        }
                    }
                }
            }
        }

        $perms = $this->teacherPermissions();

        // Lightweight summary of the tracks this student currently belongs to,
        // only when the teacher may manage tracks (keeps the default render lean).
        $currentTracks = collect();
        if (! empty($perms['can_manage_gamification_tracks'])) {
            $currentTracks = GamificationTrack::query()
                ->whereHas('students', fn ($q) => $q->where('users.id', $this->student->id))
                ->with('leaderboard:id,title')
                ->get(['id', 'name', 'leaderboard_id']);
        }

        return [
            'student' => $this->student,
            'sPlans' => $this->sPlans,
            'activePlan' => $activePlan,
            'activeOdePlan' => $activeOdePlan,
            'odeDayForSelectedDate' => $odeDayForSelectedDate,
            'activeHadithPlan' => $activeHadithPlan,
            'hadithDayForSelectedDate' => $hadithDayForSelectedDate,
            'days' => $days,
            'allSurahs' => $cachedSurahs,
            'defaultDayId' => $defaultDayId,
            'dayIds' => $dayIds,
            'odeDays' => $odeDays,
            'defaultOdeDayId' => $defaultOdeDayId,
            'odeDayIds' => $odeDayIds,
            'odeDateToDayIdMap' => $odeDateToDayIdMap,
            'quranDateToDayIdMap' => $quranDateToDayIdMap,
            'odeVerses' => $odeVerses,
            'hadithDays' => $hadithDays,
            'defaultHadithDayId' => $defaultHadithDayId,
            'hadithDayIds' => $hadithDayIds,
            'hadithDateToDayIdMap' => $hadithDateToDayIdMap,
            'hadithLines' => $hadithLines,
            'allHadiths' => $allHadiths,
            'perms' => $perms,
            'currentTracks' => $currentTracks,
            'examBox' => $this->examBox(),
            'peerResult' => $this->peerResult(),
        ];
    }

    /**
     * How the student's mutual recitation went on the day picked, as a hint
     * beside the grading: the mistakes their classmate counted, and whether
     * they were found ready. Null when they recited to no one that day.
     *
     * @return array{mistakes: ?int, ready: ?bool}|null
     */
    private function peerResult(): ?array
    {
        $today = \App\Services\TeacherSyncSnapshot::today();
        $date = is_string($this->gradedAtDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->gradedAtDate) ? $this->gradedAtDate : $today;

        $pair = \App\Models\PeerPair::where('date', $date)
            ->where(fn ($query) => $query->where('first_id', $this->student->id)->orWhere('second_id', $this->student->id))
            ->first();
        $place = $pair?->placeOf($this->student->id);

        return $pair && $place && $pair->recites($place)
            ? ['mistakes' => $pair->{"{$place}_mistakes"}, 'ready' => $pair->{"{$place}_ready"}]
            : null;
    }

    public function placeholder(): string
    {
        return '<div class="animate-pulse h-32 rounded-xl bg-zinc-100 dark:bg-zinc-800 mb-2"></div>';
    }
};
?>

{{--
    Each section below carries its own day data, in its own Alpine scope, keyed
    by the plan it is showing.

    It cannot live here on the root. Alpine evaluates x-data once and hands that
    scope to the children it initialises, and Livewire keys the component root
    by its wire:id, so the root is morphed in place no matter how much its day
    data changed. Holding the days here left a student with two plans grading
    the days of the plan they had switched away from: the section headings came
    from the server and followed the new plan, the days came from this scope and
    did not. A key on a child element is honoured, so a section whose plan
    changed is replaced outright and its scope built again from the new days.
--}}
<div class="space-y-4"
    wire:key="student-tasmeeh-container-{{ $student->id }}-{{ $selectedPlanId ?? 'ode-only' }}"
>
    {{--
        The student's name, with their next exam at its end as the teacher app
        shows it: the juz count, a dot a week or less before, red once its day
        passed without a result, and a dashed "+" when none is set. Both open
        the page's exam editor while the teacher may set exams; otherwise the
        number only informs.
    --}}
    <div class="flex items-center justify-between gap-3 p-3 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm">
        <div class="flex items-center gap-2 min-w-0">
            <div class="p-2 rounded-lg bg-indigo-50 dark:bg-indigo-950/40 text-indigo-500 shrink-0">
                <flux:icon icon="user" class="size-5" />
            </div>
            <div class="min-w-0">
                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('الطالب') }}</div>
                <div class="text-sm font-bold text-zinc-900 dark:text-white truncate">{{ $student->name }}</div>
            </div>
        </div>

        <x-tasmeeh-exam-box :exam="$examBox['exam']" :student-id="$student->id" :student-name="$student->name" :can-schedule="$examBox['canSchedule']" size="lg" />
    </div>

    @if($sPlans->isNotEmpty())
        <div class="bg-white dark:bg-zinc-900 p-4 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm mb-4">
            <flux:select wire:change="selectPlan($event.target.value)" label="{{ __('الخطة القرآنية') }}" placeholder="{{ __('اختر الخطة') }}">
                @foreach($sPlans as $plan)
                    <flux:select.option value="{{ $plan->id }}" :selected="$selectedPlanId == $plan->id">
                        @if($plan->plan_type === 'hifz')
                            {{ __('حفظ (تبدأ من ' . $plan->start_date->format('Y/m/d') . ')') }}
                        @elseif($plan->plan_type === 'review')
                            {{ __('مراجعة (تبدأ من ' . $plan->start_date->format('Y/m/d') . ')') }}
                        @else
                            {{ __('حفظ ومراجعة (تبدأ من ' . $plan->start_date->format('Y/m/d') . ')') }}
                        @endif
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>
    @endif

    @php
        /**
         * The four grades, with the classes for the selected and unselected
         * states. For odes and mutun "لم يسمع" is the null value, which
         * JavaScript compares with === just as happily as the numbers do.
         */
        $gradeChoices = [
            ['js' => '3', 'label' => __('ممتاز'), 'active' => 'border-green-500 bg-green-50 dark:bg-green-500/20 text-green-700 dark:text-green-300', 'inactive' => 'border-zinc-200 dark:border-zinc-700 hover:border-green-200 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300'],
            ['js' => '2', 'label' => __('جيد'), 'active' => 'border-blue-500 bg-blue-50 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300', 'inactive' => 'border-zinc-200 dark:border-zinc-700 hover:border-blue-200 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300'],
            ['js' => '1', 'label' => __('مقبول'), 'active' => 'border-amber-500 bg-amber-50 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300', 'inactive' => 'border-zinc-200 dark:border-zinc-700 hover:border-amber-200 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300'],
            ['js' => 'null', 'label' => __('لم يسمع'), 'active' => 'border-red-500 bg-red-50 dark:bg-red-500/20 text-red-700 dark:text-red-300', 'inactive' => 'border-zinc-200 dark:border-zinc-700 hover:border-red-200 bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300'],
        ];

        /**
         * The Quran keeps "لم يسمع" as a grade of its own (0), apart from a day
         * not graded yet, so an ungraded day highlights nothing. Tapping the
         * selected grade clears it.
         */
        $quranGradeChoices = $gradeChoices;
        $quranGradeChoices[3]['js'] = '0';
    @endphp

    @if($sPlans->isNotEmpty())
        @php
            // All days of one plan share its type, so the sections are decided once.
            $planType = $days->first()?->plan?->plan_type;
        @endphp

        {{--
            One card, rendered in the browser from the day data below. Rendering
            all twenty-five days server-side and hiding them with x-show cost
            half a megabyte; the same days as JSON are a few kilobytes.

            The key is the chosen plan, so picking another plan from the select
            above replaces this element and rebuilds the scope from that plan's
            days rather than leaving the previous plan's on screen.
        --}}
        <div class="mt-2" wire:key="quran-plan-{{ $activePlan?->id }}" x-data="{
            syncing: null,
            activeDayId: @js($defaultDayId),
            studentPlanDayIds: @js($dayIds),
            {{-- The days themselves, as data: the card builds its own markup from these. --}}
            quranDays: @js(\App\Support\TasmeehPayload::quranDays($days)),
            quranDateToDayIdMap: @js($quranDateToDayIdMap),
            today: @js(\App\Services\TeacherSyncSnapshot::today()),

            init() {
                this.$watch('$wire.gradedAtDate', (newVal) => {
                    if (this.quranDateToDayIdMap[newVal]) {
                        this.activeDayId = this.quranDateToDayIdMap[newVal];
                    }
                });
            },
            currentQuranDay() {
                return this.quranDays.find(day => day.id == this.activeDayId) ?? null;
            },
            {{-- The session graded is the one of the date picked above the list. --}}
            gradingDate() {
                const picked = this.$wire.gradedAtDate || this.today;
                return picked > this.today ? this.today : picked;
            },
            session(part) {
                return this.currentQuranDay()?.[part].sessions.find(s => s.date === this.gradingDate()) ?? null;
            },
            grade(part) {
                return this.session(part)?.grade ?? null;
            },
            otherSessions(part) {
                return (this.currentQuranDay()?.[part].sessions ?? []).filter(s => s.date !== this.gradingDate());
            },
            setGrade(part, value) {
                const day = this.currentQuranDay();
                const session = this.session(part);
                const v = this.grade(part) === value ? null : value;

                if (session) {
                    session.grade = v;
                    if (v === null && ! session.recited_range) {
                        day[part].sessions = day[part].sessions.filter(s => s !== session);
                    }
                } else if (v !== null) {
                    day[part].sessions.push({ date: this.gradingDate(), grade: v, recited_range: null });
                }

                this.syncing = part;
                this.$wire.saveAchievement(day.id, part, v).finally(() => this.syncing = null);
            },
            gradeLabel(grade) {
                return { 0: '{{ __('لم يسمع') }}', 1: '{{ __('مقبول') }}', 2: '{{ __('جيد') }}', 3: '{{ __('ممتاز') }}' }[grade] ?? '{{ __('لم يقيَّم') }}';
            },
            sessionDay(date) {
                try {
                    return new Intl.DateTimeFormat('ar-SA-u-ca-islamic-umalqura', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' })
                        .format(new Date(date + 'T12:00:00Z'));
                } catch (e) {
                    return date;
                }
            },
            hasPrevDay() {
                const index = this.studentPlanDayIds.findIndex(id => id == this.activeDayId);
                return index > 0;
            },
            hasNextDay() {
                const index = this.studentPlanDayIds.findIndex(id => id == this.activeDayId);
                return index !== -1 && index < this.studentPlanDayIds.length - 1;
            },
            prevDay() {
                const index = this.studentPlanDayIds.findIndex(id => id == this.activeDayId);
                if (index > 0) {
                    this.activeDayId = this.studentPlanDayIds[index - 1];
                }
            },
            nextDay() {
                const index = this.studentPlanDayIds.findIndex(id => id == this.activeDayId);
                if (index !== -1 && index < this.studentPlanDayIds.length - 1) {
                    this.activeDayId = this.studentPlanDayIds[index + 1];
                }
            }
        }" x-show="currentQuranDay()" x-cloak>
            <flux:card x-bind:class="syncing && 'opacity-70'"
                class="border-zinc-200 dark:border-zinc-700 transition-opacity">

                {{-- How the student's mutual recitation went that day: a hint, not a grade. --}}
                @if ($peerResult)
                    <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
                        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2.5 py-1 font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            <flux:icon icon="arrows-right-left" class="size-3.5" />
                            {{ __('التسميع المتبادل:') }}
                            {{ $peerResult['mistakes'] === null ? __('لم تُسجَّل أخطاؤه') : $peerResult['mistakes'].' '.__('أخطاء') }}
                        </span>
                        @if ($peerResult['ready'])
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                                <flux:icon icon="check" class="size-3.5" />
                                {{ __('جاهز للمعلم') }}
                            </span>
                        @endif
                    </div>
                @endif

                {{-- Day navigation --}}
                <div class="flex items-center justify-between mb-4 md:mb-8 border-b border-zinc-100 dark:border-zinc-800 pb-3 md:pb-4">
                    <flux:button type="button" @click="prevDay()" x-bind:disabled="!hasPrevDay()" icon="chevron-right" variant="subtle" size="sm">
                        {{ __('اليوم السابق') }}
                    </flux:button>

                    <div class="text-center">
                        <div class="font-bold text-base md:text-lg" x-text="currentQuranDay()?.day_name"></div>
                        <div class="text-zinc-500 text-xs md:text-sm dir-ltr" x-text="currentQuranDay()?.date"></div>
                    </div>

                    <flux:button type="button" @click="nextDay()" x-bind:disabled="!hasNextDay()" icon-trailing="chevron-left" variant="subtle" size="sm">
                        {{ __('اليوم التالي') }}
                    </flux:button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-8">
                    @foreach ([
                        ['part' => 'hifz', 'label' => __('الحفظ'), 'shown' => in_array($planType, ['hifz', 'hifz_review'], true), 'box' => 'bg-indigo-50/50 dark:bg-indigo-500/5 border-indigo-100 dark:border-indigo-500/20', 'heading' => 'text-indigo-600 dark:text-indigo-400', 'chip' => 'bg-indigo-100 dark:bg-indigo-500/20 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-200 dark:hover:bg-indigo-500/30'],
                        ['part' => 'review', 'label' => __('المراجعة'), 'shown' => in_array($planType, ['review', 'hifz_review'], true), 'box' => 'bg-emerald-50/50 dark:bg-emerald-500/5 border-emerald-100 dark:border-emerald-500/20', 'heading' => 'text-emerald-600 dark:text-emerald-400', 'chip' => 'bg-emerald-100 dark:bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-200 dark:hover:bg-emerald-500/30'],
                    ] as $section)
                        @continue(! $section['shown'])
                        @php $part = $section['part']; @endphp

                        <div x-data="{ linksOpen: false }" class="{{ $section['box'] }} rounded-xl border p-3.5 md:p-5 space-y-3 md:space-y-5">
                            <div>
                                <flux:heading size="lg" class="{{ $section['heading'] }} mb-1 md:mb-2">{{ $section['label'] }}</flux:heading>
                                <p class="text-zinc-700 dark:text-zinc-300 font-medium text-base md:text-lg leading-snug md:leading-relaxed"
                                    x-text="currentQuranDay()?.{{ $part }}.range || '{{ __('لا يوجد نص محدد') }}'"></p>
                                {{-- Recorded from the teacher app when the student recited something other than the wird. --}}
                                <p x-show="session('{{ $part }}')?.recited_range" x-cloak
                                    class="text-xs md:text-sm font-medium text-amber-700 dark:text-amber-400 mt-1"
                                    x-text="'{{ __('المُسمَّع فعلياً:') }} ' + session('{{ $part }}')?.recited_range"></p>

                                {{-- One surah opens directly; several fold into a list. --}}
                                <template x-if="currentQuranDay()?.{{ $part }}.links.length === 1">
                                    <a :href="currentQuranDay().{{ $part }}.links[0].url" target="_blank"
                                        class="inline-flex items-center gap-1.5 mt-3 px-2.5 py-1 rounded-lg {{ $section['chip'] }} text-xs font-medium transition-colors">
                                        <flux:icon icon="book-open" class="size-3.5" />
                                        <span x-text="'{{ __('افتح') }} ' + currentQuranDay().{{ $part }}.links[0].name"></span>
                                    </a>
                                </template>

                                <template x-if="currentQuranDay()?.{{ $part }}.links.length > 1">
                                    <div class="mt-3">
                                        <button type="button" @click="linksOpen = ! linksOpen"
                                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg {{ $section['chip'] }} text-xs font-medium transition-colors">
                                            <flux:icon icon="book-open" class="size-3.5" />
                                            <span x-text="'{{ __('افتح الآيات في القرآن') }} (' + currentQuranDay().{{ $part }}.links.length + ')'"></span>
                                            <flux:icon icon="chevron-down" class="size-3.5 transition-transform" x-bind:class="linksOpen ? 'rotate-180' : ''" />
                                        </button>
                                        <div x-show="linksOpen" x-collapse class="flex flex-wrap gap-2 mt-2">
                                            <template x-for="link in currentQuranDay().{{ $part }}.links" :key="link.url">
                                                <a :href="link.url" target="_blank"
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg {{ $section['chip'] }} text-xs font-medium transition-colors">
                                                    <flux:icon icon="book-open" class="size-3.5" />
                                                    <span x-text="link.name"></span>
                                                </a>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <flux:separator />

                            <div>
                                <flux:label class="mb-2 md:mb-3 text-xs md:text-sm font-semibold">{{ __('تقييم الإنجاز (التسميع)') }}</flux:label>
                                <div class="grid grid-cols-4 gap-1.5 md:gap-2">
                                    @foreach ($quranGradeChoices as $choice)
                                        <button type="button"
                                            @click="setGrade('{{ $part }}', {{ $choice['js'] }})"
                                            class="px-1 py-2.5 md:p-3 rounded-lg md:rounded-xl border-2 transition-colors font-bold text-center text-xs md:text-base"
                                            :class="grade('{{ $part }}') === {{ $choice['js'] }} ? '{{ $choice['active'] }}' : '{{ $choice['inactive'] }}'">{{ $choice['label'] }}</button>
                                    @endforeach
                                </div>

                                {{-- The part's other sessions: a «لم يسمع» given on an earlier day stays there. --}}
                                <template x-if="otherSessions('{{ $part }}').length">
                                    <div class="mt-3 space-y-1">
                                        <div class="text-xs font-semibold text-zinc-500 dark:text-zinc-400">{{ __('جلسات أخرى') }}</div>
                                        <template x-for="other in otherSessions('{{ $part }}')" :key="other.date">
                                            <div class="flex flex-wrap items-baseline gap-x-2 text-xs text-zinc-600 dark:text-zinc-300">
                                                <span x-text="sessionDay(other.date)"></span>
                                                <span class="font-bold" x-text="gradeLabel(other.grade)"></span>
                                                <span x-show="other.recited_range" class="text-amber-700 dark:text-amber-400" x-text="other.recited_range"></span>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    @endforeach
                </div>
            </flux:card>
        </div>
    @endif

    {{-- Ode path enrollment bar (visible when teacher may manage ode paths, or a plan exists) --}}
    @if(($perms['can_manage_ode_paths'] ?? false) || $activeOdePlan)
        <div class="mt-4 flex items-center justify-between gap-3 p-3 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm">
            <div class="flex items-center gap-2 min-w-0">
                <div class="p-2 rounded-lg bg-indigo-50 dark:bg-indigo-950/40 text-indigo-500 shrink-0">
                    <flux:icon icon="book-open" class="size-5" />
                </div>
                <div class="min-w-0">
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('مسار المنظومة') }}</div>
                    <div class="text-sm font-bold text-zinc-900 dark:text-white truncate">
                        {{ $activeOdePlan?->path?->name ?? __('غير مُسكَّن في مسار منظومة') }}
                    </div>
                </div>
            </div>
            @if($perms['can_manage_ode_paths'] ?? false)
                <flux:button size="sm" variant="{{ $activeOdePlan ? 'ghost' : 'primary' }}" icon="{{ $activeOdePlan ? 'arrow-path' : 'plus' }}" wire:click="openPathModal('ode')">
                    {{ $activeOdePlan ? __('تغيير المسار') : __('تسكين في مسار') }}
                </flux:button>
            @endif
        </div>
    @endif

    @if($activeOdePlan && $odeDays->isNotEmpty())
        {{--
            One card rendered in the browser from the day data, as with the Quran
            and mutun days. Ten server-rendered days cost 233 KB to show one.

            Keyed by the plan, so moving the student to another ode path rebuilds
            this scope from the new path's days instead of keeping the old ones.
        --}}
        <div class="mt-4" wire:key="ode-plan-{{ $activeOdePlan->id }}" x-data="{
            syncing: null, showHifzModal: false, showReviewModal: false,
            textData: null, textLoading: false, textError: null, prevShown: 0,
            activeOdeDayId: @js($defaultOdeDayId),
            studentOdePlanDayIds: @js($odeDayIds),
            odeDateToDayIdMap: @js($odeDateToDayIdMap),
            {{-- The ode days as data; the card builds its own markup from these. --}}
            odeDays: @js(\App\Support\TasmeehPayload::odeDays($odeDays)),

            init() {
                this.$watch('$wire.gradedAtDate', (newVal) => {
                    if (this.odeDateToDayIdMap[newVal]) {
                        this.activeOdeDayId = this.odeDateToDayIdMap[newVal];
                    }
                });
            },
            currentOdeDay() {
                return this.odeDays.find(day => day.id == this.activeOdeDayId) ?? null;
            },
            hasPrevOdeDay() {
                const index = this.studentOdePlanDayIds.findIndex(id => id == this.activeOdeDayId);
                return index > 0;
            },
            hasNextOdeDay() {
                const index = this.studentOdePlanDayIds.findIndex(id => id == this.activeOdeDayId);
                return index !== -1 && index < this.studentOdePlanDayIds.length - 1;
            },
            prevOdeDay() {
                const index = this.studentOdePlanDayIds.findIndex(id => id == this.activeOdeDayId);
                if (index > 0) {
                    this.activeOdeDayId = this.studentOdePlanDayIds[index - 1];
                }
            },
            nextOdeDay() {
                const index = this.studentOdePlanDayIds.findIndex(id => id == this.activeOdeDayId);
                if (index !== -1 && index < this.studentOdePlanDayIds.length - 1) {
                    this.activeOdeDayId = this.studentOdePlanDayIds[index + 1];
                }
            },
            async loadText(part) { const d = this.currentOdeDay(); if (! d) return; this.textData = null; this.textError = null; this.prevShown = 0; this.textLoading = true; try { const r = await fetch(`{{ route('teacher.tasmeeh.text', $student) }}?kind=ode&id=${d.id}&part=${part}`, { headers: { 'Accept': 'application/json' } }); if (! r.ok) throw new Error(); this.textData = await r.json(); } catch (e) { this.textError = '{{ __('تعذّر تحميل النص، أعد المحاولة.') }}'; } finally { this.textLoading = false; } }
        }" x-show="currentOdeDay()" x-cloak>

            <flux:card x-bind:class="syncing && 'opacity-70'" class="flex flex-col border-zinc-200 dark:border-zinc-700 min-h-[350px] h-full justify-between transition-opacity">

                {{-- Day navigation --}}
                <div class="flex items-center justify-between mb-6 border-b border-zinc-100 dark:border-zinc-800 pb-4 shrink-0">
                    <flux:button type="button" @click="prevOdeDay()" x-bind:disabled="!hasPrevOdeDay()" icon="chevron-right" variant="subtle" size="sm">
                        {{ __('اليوم السابق') }}
                    </flux:button>

                    <div class="text-center">
                        <div class="font-bold text-lg leading-none" x-text="currentOdeDay()?.day_name"></div>
                        <div class="text-zinc-500 text-sm dir-ltr mt-1 leading-none" x-text="currentOdeDay()?.date"></div>
                        <div class="text-xs text-indigo-600 dark:text-indigo-400 mt-1.5 font-semibold">
                            {{ __('تسميع المنظومة') }}: {{ $activeOdePlan->path->ode->name }}
                        </div>
                    </div>

                    <flux:button type="button" @click="nextOdeDay()" x-bind:disabled="!hasNextOdeDay()" icon-trailing="chevron-left" variant="subtle" size="sm">
                        {{ __('اليوم التالي') }}
                    </flux:button>
                </div>

                <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @foreach ([
                        ['part' => 'hifz', 'label' => __('حفظ المنظومة'), 'gradeLabel' => __('تقييم الحفظ'), 'has' => 'has_hifz', 'box' => 'bg-indigo-50/50 dark:bg-indigo-500/5 border-indigo-100 dark:border-indigo-500/20', 'heading' => 'text-indigo-600 dark:text-indigo-400', 'date' => 'text-indigo-600/70 dark:text-indigo-400/70', 'modal' => 'showHifzModal'],
                        ['part' => 'review', 'label' => __('مراجعة المنظومة'), 'gradeLabel' => __('تقييم المراجعة'), 'has' => 'has_review', 'box' => 'bg-emerald-50/50 dark:bg-emerald-500/5 border-emerald-100 dark:border-emerald-500/20', 'heading' => 'text-emerald-600 dark:text-emerald-400', 'date' => 'text-emerald-600/70 dark:text-emerald-400/70', 'modal' => 'showReviewModal'],
                    ] as $section)
                        @php $part = $section['part']; @endphp
                        <div x-show="currentOdeDay()?.{{ $section['has'] }}"
                            class="{{ $section['box'] }} rounded-xl border p-3 md:p-4 space-y-2.5 md:space-y-4 flex flex-col justify-between">
                            <div>
                                <flux:heading size="sm" class="{{ $section['heading'] }} mb-1">{{ $section['label'] }}</flux:heading>
                                <p class="text-zinc-700 dark:text-zinc-300 font-bold text-sm" x-text="currentOdeDay()?.{{ $part }}.range"></p>
                                <div x-show="currentOdeDay()?.{{ $part }}.graded_on" class="text-xs {{ $section['date'] }} font-semibold mt-1"
                                    x-text="'{{ __('تاريخ الإنجاز') }}: ' + (currentOdeDay()?.{{ $part }}.graded_on ?? '')"></div>
                            </div>

                            <flux:separator class="opacity-50 shrink-0" />

                            <div>
                                <flux:button type="button" @click="{{ $section['modal'] }} = true; loadText('{{ $part }}')" icon="book-open" variant="subtle" class="w-full justify-center">
                                    {{ __('إظهار نص المنظومة') }}
                                </flux:button>
                            </div>

                            <flux:separator class="opacity-50 shrink-0" />

                            <div class="shrink-0">
                                <flux:label class="mb-2 text-xs font-semibold">{{ $section['gradeLabel'] }}</flux:label>
                                <div class="grid grid-cols-4 gap-1.5">
                                    @foreach ($gradeChoices as $choice)
                                        <button type="button"
                                            @click="const d = currentOdeDay(); d.{{ $part }}.achievement = {{ $choice['js'] }}; syncing = '{{ $part }}'; $wire.saveOdeAchievement(d.id, '{{ $part }}', {{ $choice['js'] }}).finally(() => syncing = null)"
                                            class="py-2.5 rounded-lg border-2 transition-all font-bold text-center text-xs"
                                            :class="currentOdeDay()?.{{ $part }}.achievement === {{ $choice['js'] }} ? '{{ $choice['active'] }}' : '{{ $choice['inactive'] }}'">{{ $choice['label'] }}</button>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </flux:card>

            @foreach ([
                ['part' => 'hifz', 'modal' => 'showHifzModal', 'title' => __('حفظ المنظومة'), 'accent' => 'text-indigo-600 dark:text-indigo-400'],
                ['part' => 'review', 'modal' => 'showReviewModal', 'title' => __('مراجعة المنظومة'), 'accent' => 'text-emerald-600 dark:text-emerald-400'],
            ] as $modal)
                @php $part = $modal['part']; @endphp
                <div x-show="{{ $modal['modal'] }}"
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 translate-y-4"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-200"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 translate-y-4"
                    class="fixed inset-0 z-50 bg-white dark:bg-zinc-900 flex flex-col w-full h-full" x-cloak>

                    <div class="flex items-center justify-between p-5 border-b border-zinc-100 dark:border-zinc-900 bg-zinc-50/50 dark:bg-zinc-900/50 shrink-0">
                        <div>
                            <h3 class="font-bold text-lg text-zinc-900 dark:text-white leading-tight">{{ $modal['title'] }}</h3>
                            <p class="text-xs {{ $modal['accent'] }} mt-1 font-semibold" x-text="currentOdeDay()?.{{ $part }}.range"></p>
                        </div>
                        <button type="button" @click="{{ $modal['modal'] }} = false" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-900 transition-colors">
                            <flux:icon icon="x-mark" class="size-5" />
                        </button>
                    </div>

                    {{-- Fetched when the modal opens; see TasmeehDataController::text --}}
                    <div class="flex-1 overflow-y-auto p-6 md:p-12 space-y-6 bg-zinc-50/30 dark:bg-zinc-950/30">
                        <div class="max-w-4xl mx-auto space-y-6 text-right">
                            <template x-if="textLoading">
                                <p class="text-center text-zinc-400 py-12">{{ __('جارٍ التحميل...') }}</p>
                            </template>

                            <template x-if="!textLoading && textError">
                                <p class="text-center text-red-500 py-12" x-text="textError"></p>
                            </template>

                            <template x-if="!textLoading && textData">
                                <div class="space-y-6">
                                    <div x-show="textData.previous.length && prevShown < textData.previous.length" class="flex justify-center">
                                        <flux:button type="button" @click="prevShown++" icon="arrow-up" variant="subtle" class="w-full sm:w-auto font-bold">
                                            {{ __('إظهار البيت السابق') }}
                                        </flux:button>
                                    </div>

                                    <template x-for="(v, i) in textData.previous" :key="'prev-'+v.number">
                                        <div x-show="prevShown >= textData.previous.length - i" x-cloak
                                            class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-center bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-4 opacity-60 hover:opacity-100 transition-opacity">
                                            <p class="text-lg md:text-xl font-bold text-zinc-700 dark:text-zinc-200 font-serif leading-loose" x-text="v.sadr"></p>
                                            <p class="text-lg md:text-xl font-bold text-zinc-700 dark:text-zinc-200 font-serif leading-loose sm:text-left" x-text="v.ajuz"></p>
                                        </div>
                                    </template>

                                    <template x-for="v in textData.verses" :key="v.number">
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-center bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 border-r-4 border-r-indigo-500 dark:border-r-indigo-400 p-4">
                                            <p class="text-lg md:text-2xl font-bold text-zinc-900 dark:text-zinc-50 font-serif leading-loose" x-text="v.sadr"></p>
                                            <p class="text-lg md:text-2xl font-bold text-zinc-900 dark:text-zinc-50 font-serif leading-loose sm:text-left" x-text="v.ajuz"></p>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="p-4 border-t border-zinc-100 dark:border-zinc-900 bg-zinc-50/50 dark:bg-zinc-900/50 flex justify-end shrink-0">
                        <flux:button type="button" @click="{{ $modal['modal'] }} = false" variant="ghost">{{ __('إغلاق') }}</flux:button>
                    </div>
                </div>
            @endforeach
        </div>
    @elseif($activeOdePlan && $odeDays->isEmpty())
        <flux:card class="mt-4 border-zinc-200 dark:border-zinc-700">
            <div class="flex items-center gap-2 mb-6 pb-4 border-b border-zinc-100 dark:border-zinc-800">
                <flux:icon icon="book-open" class="size-5 text-indigo-500" />
                <div>
                    <flux:heading size="md" class="font-bold text-zinc-900 dark:text-white">
                        {{ __('تسميع المنظومة') }}: {{ $activeOdePlan->path->ode->name }}
                    </flux:heading>
                </div>
            </div>
            <div class="flex flex-col items-center justify-center p-6 bg-zinc-50/50 dark:bg-zinc-900/50 border border-dashed border-zinc-200 dark:border-zinc-800 rounded-xl text-center">
                <flux:icon icon="document-text" class="size-8 text-zinc-300 dark:text-zinc-600 mb-2" />
                <p class="text-zinc-500 dark:text-zinc-400 text-sm">
                    {{ __('لا توجد أبيات مجدولة لهذه المنظومة.') }}
                </p>
            </div>
        </flux:card>
    @endif

    {{-- Hadith path enrollment bar (visible when teacher may manage hadith paths, or a plan exists) --}}
    @if(($perms['can_manage_hadith_paths'] ?? false) || $activeHadithPlan)
        <div class="mt-4 flex items-center justify-between gap-3 p-3 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm">
            <div class="flex items-center gap-2 min-w-0">
                <div class="p-2 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 text-emerald-500 shrink-0">
                    <flux:icon icon="document-text" class="size-5" />
                </div>
                <div class="min-w-0">
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('مسار الحديث') }}</div>
                    <div class="text-sm font-bold text-zinc-900 dark:text-white truncate">
                        {{ $activeHadithPlan?->path?->name ?? __('غير مُسكَّن في مسار حديث') }}
                    </div>
                </div>
            </div>
            @if($perms['can_manage_hadith_paths'] ?? false)
                <flux:button size="sm" variant="{{ $activeHadithPlan ? 'ghost' : 'primary' }}" icon="{{ $activeHadithPlan ? 'arrow-path' : 'plus' }}" wire:click="openPathModal('hadith')">
                    {{ $activeHadithPlan ? __('تغيير المسار') : __('تسكين في مسار') }}
                </flux:button>
            @endif
        </div>
    @endif

    {{-- Hadith plans daily rendering --}}
    @if($activeHadithPlan && $hadithDays->isNotEmpty())
        {{--
            One card rendered in the browser from the day data, as with the Quran
            days. Fifteen server-rendered days cost 336 KB to show one.
        --}}
        <div class="mt-4" wire:key="hadith-plan-{{ $activeHadithPlan->id }}" x-data="{
            syncing: null, showHadithHifzModal: false, showHadithReviewModal: false,
            textData: null, textLoading: false, textError: null, prevShown: 0,
            activeHadithDayId: @js($defaultHadithDayId),
            studentHadithPlanDayIds: @js($hadithDayIds),
            hadithDateToDayIdMap: @js($hadithDateToDayIdMap),
            {{-- The hadith days as data; the card builds its own markup from these. --}}
            hadithDays: @js(\App\Support\TasmeehPayload::hadithDays($hadithDays)),

            init() {
                this.$watch('$wire.gradedAtDate', (newVal) => {
                    if (this.hadithDateToDayIdMap[newVal]) {
                        this.activeHadithDayId = this.hadithDateToDayIdMap[newVal];
                    }
                });
            },
            currentHadithDay() {
                return this.hadithDays.find(day => day.id == this.activeHadithDayId) ?? null;
            },
            hasPrevHadithDay() {
                const index = this.studentHadithPlanDayIds.findIndex(id => id == this.activeHadithDayId);
                return index > 0;
            },
            hasNextHadithDay() {
                const index = this.studentHadithPlanDayIds.findIndex(id => id == this.activeHadithDayId);
                return index !== -1 && index < this.studentHadithPlanDayIds.length - 1;
            },
            prevHadithDay() {
                const index = this.studentHadithPlanDayIds.findIndex(id => id == this.activeHadithDayId);
                if (index > 0) {
                    this.activeHadithDayId = this.studentHadithPlanDayIds[index - 1];
                }
            },
            nextHadithDay() {
                const index = this.studentHadithPlanDayIds.findIndex(id => id == this.activeHadithDayId);
                if (index !== -1 && index < this.studentHadithPlanDayIds.length - 1) {
                    this.activeHadithDayId = this.studentHadithPlanDayIds[index + 1];
                }
            },
            async loadText(part) { const d = this.currentHadithDay(); if (! d) return; this.textData = null; this.textError = null; this.prevShown = 0; this.textLoading = true; try { const r = await fetch(`{{ route('teacher.tasmeeh.text', $student) }}?kind=hadith&id=${d.id}&part=${part}`, { headers: { 'Accept': 'application/json' } }); if (! r.ok) throw new Error(); this.textData = await r.json(); } catch (e) { this.textError = '{{ __('تعذّر تحميل النص، أعد المحاولة.') }}'; } finally { this.textLoading = false; } }
        }" x-show="currentHadithDay()" x-cloak>

            <flux:card x-bind:class="syncing && 'opacity-70'" class="flex flex-col border-zinc-200 dark:border-zinc-700 min-h-[350px] h-full justify-between transition-opacity">

                {{-- Day navigation --}}
                <div class="flex items-center justify-between mb-6 border-b border-zinc-100 dark:border-zinc-800 pb-4 shrink-0">
                    <flux:button type="button" @click="prevHadithDay()" x-bind:disabled="!hasPrevHadithDay()" icon="chevron-right" variant="subtle" size="sm">
                        {{ __('اليوم السابق') }}
                    </flux:button>

                    <div class="text-center">
                        <div class="font-bold text-lg leading-none" x-text="currentHadithDay()?.day_name"></div>
                        <div class="text-zinc-500 text-sm dir-ltr mt-1 leading-none" x-text="currentHadithDay()?.date"></div>
                        <div class="text-xs text-rose-600 dark:text-rose-400 mt-1.5 font-semibold"
                            x-text="'{{ __('تسميع الحديث') }}: ' + (currentHadithDay()?.from_hadith_name ?? '')"></div>
                    </div>

                    <flux:button type="button" @click="nextHadithDay()" x-bind:disabled="!hasNextHadithDay()" icon-trailing="chevron-left" variant="subtle" size="sm">
                        {{ __('اليوم التالي') }}
                    </flux:button>
                </div>

                <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @foreach ([
                        ['part' => 'hifz', 'label' => __('حفظ الحديث'), 'gradeLabel' => __('تقييم الحفظ'), 'has' => 'has_hifz', 'box' => 'bg-indigo-50/50 dark:bg-indigo-500/5 border-indigo-100 dark:border-indigo-500/20', 'heading' => 'text-indigo-600 dark:text-indigo-400', 'modal' => 'showHadithHifzModal'],
                        ['part' => 'review', 'label' => __('مراجعة الحديث'), 'gradeLabel' => __('تقييم المراجعة'), 'has' => 'has_review', 'box' => 'bg-emerald-50/50 dark:bg-emerald-500/5 border-emerald-100 dark:border-emerald-500/20', 'heading' => 'text-emerald-600 dark:text-emerald-400', 'modal' => 'showHadithReviewModal'],
                    ] as $section)
                        @php $part = $section['part']; @endphp
                        <div x-show="currentHadithDay()?.{{ $section['has'] }}"
                            class="{{ $section['box'] }} rounded-xl border p-3 md:p-4 space-y-2.5 md:space-y-4 flex flex-col justify-between">
                            <div>
                                <flux:heading size="sm" class="{{ $section['heading'] }} mb-1">{{ $section['label'] }}</flux:heading>
                                <p class="text-zinc-700 dark:text-zinc-300 font-bold text-sm" x-text="currentHadithDay()?.{{ $part }}.range"></p>
                            </div>

                            <flux:separator class="opacity-50 shrink-0" />

                            <div>
                                <flux:button type="button" @click="{{ $section['modal'] }} = true; loadText('{{ $part }}')" icon="book-open" variant="subtle" class="w-full justify-center">
                                    {{ __('إظهار نص الحديث') }}
                                </flux:button>
                            </div>

                            <flux:separator class="opacity-50 shrink-0" />

                            <div class="shrink-0">
                                <flux:label class="mb-2 text-xs font-semibold">{{ $section['gradeLabel'] }}</flux:label>
                                <div class="grid grid-cols-4 gap-1.5">
                                    @foreach ($gradeChoices as $choice)
                                        <button type="button"
                                            @click="const d = currentHadithDay(); d.{{ $part }}.achievement = {{ $choice['js'] }}; syncing = '{{ $part }}'; $wire.saveHadithAchievement(d.id, '{{ $part }}', {{ $choice['js'] }}).finally(() => syncing = null)"
                                            class="py-2.5 rounded-lg border-2 transition-all font-bold text-center text-xs"
                                            :class="currentHadithDay()?.{{ $part }}.achievement === {{ $choice['js'] }} ? '{{ $choice['active'] }}' : '{{ $choice['inactive'] }}'">{{ $choice['label'] }}</button>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </flux:card>

            @foreach ([
                ['part' => 'hifz', 'modal' => 'showHadithHifzModal', 'title' => __('حفظ الحديث'), 'accent' => 'text-indigo-600 dark:text-indigo-400', 'nameKey' => 'from_hadith_name'],
                ['part' => 'review', 'modal' => 'showHadithReviewModal', 'title' => __('مراجعة الحديث'), 'accent' => 'text-emerald-600 dark:text-emerald-400', 'nameKey' => 'review_hadith_name'],
            ] as $modal)
                @php $part = $modal['part']; @endphp
                <div x-show="{{ $modal['modal'] }}"
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 translate-y-4"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-200"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 translate-y-4"
                    class="fixed inset-0 z-50 bg-white dark:bg-zinc-900 flex flex-col w-full h-full" x-cloak>

                    <div class="flex items-center justify-between p-5 border-b border-zinc-100 dark:border-zinc-900 bg-zinc-50/50 dark:bg-zinc-900/50 shrink-0">
                        <div>
                            <h3 class="font-bold text-lg text-zinc-900 dark:text-white leading-tight">{{ $modal['title'] }}</h3>
                            <p class="text-xs {{ $modal['accent'] }} mt-1 font-semibold"
                                x-text="(currentHadithDay()?.{{ $modal['nameKey'] }} ?? '') + ' (' + (currentHadithDay()?.{{ $part }}.range ?? '') + ')'"></p>
                        </div>
                        <button type="button" @click="{{ $modal['modal'] }} = false" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-900 transition-colors">
                            <flux:icon icon="x-mark" class="size-5" />
                        </button>
                    </div>

                    {{-- Fetched when the modal opens; see TasmeehDataController::text --}}
                    <div class="flex-1 overflow-y-auto p-6 md:p-12 space-y-6 bg-zinc-50/30 dark:bg-zinc-950/30">
                        <div class="max-w-4xl mx-auto space-y-8 text-right">
                            <template x-if="textLoading">
                                <p class="text-center text-zinc-400 py-12">{{ __('جارٍ التحميل...') }}</p>
                            </template>

                            <template x-if="!textLoading && textError">
                                <p class="text-center text-red-500 py-12" x-text="textError"></p>
                            </template>

                            <template x-if="!textLoading && textData">
                                <div class="space-y-8">
                                    <div x-show="textData.previous.length && prevShown < textData.previous.length" class="flex justify-center">
                                        <flux:button type="button" @click="prevShown++" icon="arrow-up" variant="subtle" class="w-full sm:w-auto font-bold">
                                            {{ __('إظهار الحديث السابق') }}
                                        </flux:button>
                                    </div>

                                    <template x-for="(h, i) in textData.previous" :key="'prev-'+i">
                                        <div x-show="prevShown >= textData.previous.length - i" x-cloak
                                            class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 border-r-4 border-r-zinc-400 dark:border-r-zinc-500 shadow-sm p-5 md:p-7 space-y-4 opacity-60 hover:opacity-100 transition-opacity">
                                            <div class="flex items-center gap-2 text-base font-bold text-zinc-600 dark:text-zinc-300">
                                                <span class="truncate" x-text="h.name"></span>
                                                <span class="shrink-0 px-2 py-0.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('سابق') }}</span>
                                            </div>
                                            <p x-show="h.sanad" class="text-lg text-zinc-600 dark:text-zinc-300 leading-relaxed">
                                                <span class="font-bold">{{ __('السند') }}: </span><span x-text="h.sanad"></span>
                                            </p>
                                            <div class="space-y-3">
                                                <template x-for="line in h.lines" :key="line.number">
                                                    <div class="flex items-start gap-3">
                                                        <span x-show="h.lines.length > 1" class="shrink-0 mt-2 flex items-center justify-center size-7 rounded-lg bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 font-bold text-xs" x-text="line.number"></span>
                                                        <p class="flex-1 text-xl md:text-2xl font-bold text-zinc-700 dark:text-zinc-200 leading-loose font-serif" x-text="line.text"></p>
                                                    </div>
                                                </template>
                                            </div>
                                            <div x-show="h.ruling" class="pt-4 border-t border-zinc-100 dark:border-zinc-800">
                                                <p class="text-sm text-zinc-500 dark:text-zinc-400"><span class="font-bold">{{ __('حكم الحديث') }}: </span><span x-text="h.ruling"></span></p>
                                            </div>
                                        </div>
                                    </template>

                                    <template x-for="(h, i) in textData.hadiths" :key="'cur-'+i">
                                        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 border-r-4 border-r-indigo-500 dark:border-r-indigo-400 shadow-sm p-5 md:p-7 space-y-4">
                                            <div x-show="textData.hadiths.length > 1" class="text-base font-bold text-indigo-600 dark:text-indigo-400 truncate" x-text="h.name"></div>
                                            <p x-show="h.sanad" class="text-lg md:text-xl text-zinc-600 dark:text-zinc-300 leading-relaxed">
                                                <span class="font-bold text-indigo-600 dark:text-indigo-400">{{ __('السند') }}: </span><span x-text="h.sanad"></span>
                                            </p>
                                            <div class="space-y-3">
                                                <template x-for="line in h.lines" :key="line.number">
                                                    <div class="flex items-start gap-3">
                                                        <span x-show="h.lines.length > 1" class="shrink-0 mt-2 flex items-center justify-center size-7 rounded-lg bg-indigo-100 dark:bg-indigo-900/50 text-indigo-700 dark:text-indigo-200 font-bold text-xs" x-text="line.number"></span>
                                                        <p class="flex-1 text-xl md:text-2xl font-bold text-zinc-900 dark:text-zinc-50 leading-loose font-serif" x-text="line.text"></p>
                                                    </div>
                                                </template>
                                            </div>
                                            <div x-show="h.ruling" class="pt-4 border-t border-zinc-100 dark:border-zinc-800">
                                                <p class="text-sm text-zinc-500 dark:text-zinc-400"><span class="font-bold text-indigo-600 dark:text-indigo-400">{{ __('حكم الحديث') }}: </span><span x-text="h.ruling"></span></p>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="p-4 border-t border-zinc-100 dark:border-zinc-900 bg-zinc-50/50 dark:bg-zinc-900/50 flex justify-end shrink-0">
                        <flux:button type="button" @click="{{ $modal['modal'] }} = false" variant="ghost">{{ __('إغلاق') }}</flux:button>
                    </div>
                </div>
            @endforeach
        </div>
    @elseif($activeHadithPlan && $hadithDays->isEmpty())
        <flux:card class="mt-4 border-zinc-200 dark:border-zinc-700">
            <div class="flex items-center gap-2 mb-6 pb-4 border-b border-zinc-100 dark:border-zinc-800">
                <flux:icon icon="document-text" class="size-5 text-rose-500" />
                <div>
                    <flux:heading size="md" class="font-bold text-zinc-900 dark:text-white">
                        {{ __('تسميع مسار الحديث') }}: {{ $activeHadithPlan->path?->name }}
                    </flux:heading>
                </div>
            </div>
            <div class="flex flex-col items-center justify-center p-6 bg-zinc-50/50 dark:bg-zinc-900/50 border border-dashed border-zinc-200 dark:border-zinc-800 rounded-xl text-center">
                <flux:icon icon="document-text" class="size-8 text-zinc-300 dark:text-zinc-600 mb-2" />
                <p class="text-zinc-500 dark:text-zinc-400 text-sm">
                    {{ __('لا توجد أسطر مجدولة لهذا المسار.') }}
                </p>
            </div>
        </flux:card>
    @endif

    {{-- Gamification track enrollment bar (visible when teacher may manage tracks) --}}
    @if($perms['can_manage_gamification_tracks'] ?? false)
        <div class="mt-4 flex items-center justify-between gap-3 p-3 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm">
            <div class="flex items-center gap-2 min-w-0">
                <div class="p-2 rounded-lg bg-amber-50 dark:bg-amber-950/40 text-amber-500 shrink-0">
                    <flux:icon icon="trophy" class="size-5" />
                </div>
                <div class="min-w-0">
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('مسارات التلعيب') }}</div>
                    @if($currentTracks->isNotEmpty())
                        <div class="flex flex-wrap gap-1 mt-1">
                            @foreach($currentTracks as $t)
                                <flux:badge size="sm" color="amber">{{ $t->leaderboard?->title }} — {{ $t->name }}</flux:badge>
                            @endforeach
                        </div>
                    @else
                        <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('غير مُسكَّن في أي مسار تلعيب') }}</div>
                    @endif
                </div>
            </div>
            <flux:button size="sm" variant="{{ $currentTracks->isNotEmpty() ? 'ghost' : 'primary' }}" icon="{{ $currentTracks->isNotEmpty() ? 'arrow-path' : 'plus' }}" wire:click="openTrackModal">
                {{ $currentTracks->isNotEmpty() ? __('تعديل') : __('تسكين في مسار') }}
            </flux:button>
        </div>
    @endif

    @if($sPlans->isEmpty())
        <div class="mt-4 flex flex-col items-center justify-center p-8 bg-zinc-50/50 dark:bg-zinc-900/50 border border-dashed border-zinc-200 dark:border-zinc-800 rounded-2xl text-center">
            <flux:icon icon="document-text" class="size-12 text-zinc-300 dark:text-zinc-600 mb-3" />
            <flux:heading size="md" class="text-zinc-500 dark:text-zinc-400 mb-1">{{ __('لا توجد خطة قرآنية لهذا الطالب') }}</flux:heading>
            <p class="text-zinc-400 dark:text-zinc-500 text-sm max-w-sm mb-4">{{ __('أنشئ خطة قرآنية للطالب للبدء بتقييم التسميع والمراجعة القرآنية.') }}</p>
            <flux:button href="{{ route('teacher.plan-creator', ['studentId' => $student->id]) }}" variant="primary" icon="plus">{{ __('خطة قرآنية جديدة') }}</flux:button>
        </div>
    @endif

    {{-- Path enrollment modal (hadith / ode) --}}
    <flux:modal wire:model="showPathModal" class="md:w-[500px]">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">
                    {{ $pathModalType === 'ode' ? __('تسكين في مسار منظومة') : __('تسكين في مسار حديث') }}
                </flux:heading>
                <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('اختر المسار الذي تريد تسكين الطالب فيه. سيتم إيقاف المسار الحالي (إن وجد) والاحتفاظ بإنجازاته.') }}
                </flux:text>
            </div>

            <flux:select wire:model="selectedPathId">
                <flux:select.option value="">{{ __('بدون مسار (إلغاء التسكين)') }}</flux:select.option>
                @foreach($availablePaths as $p)
                    <flux:select.option value="{{ $p['id'] }}">{{ $p['name'] }}{{ $p['text'] ? ' — '.$p['text'] : '' }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showPathModal', false)">{{ __('إلغاء') }}</flux:button>
                <flux:button variant="primary" wire:click="enrollInPath">{{ __('حفظ') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Gamification track enrollment modal --}}
    <flux:modal wire:model="showTrackModal" class="md:w-[550px]">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('تسكين الطالب في مسارات التلعيب') }}</flux:heading>
                <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('يمكن تسكين الطالب في مسار واحد لكل مسابقة نشطة تغطي حلقته.') }}
                </flux:text>
            </div>

            @forelse($availableCompetitions as $competition)
                <div class="space-y-1.5">
                    <flux:label>{{ $competition['title'] }}</flux:label>
                    <flux:select wire:model="trackSelections.{{ $competition['id'] }}">
                        <flux:select.option value="">{{ __('بدون مسار') }}</flux:select.option>
                        @foreach($competition['tracks'] as $track)
                            <flux:select.option value="{{ $track['id'] }}">{{ $track['name'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @empty
                <flux:callout variant="secondary" icon="information-circle">
                    {{ __('لا توجد مسابقات تلعيب نشطة فيها مسارات تغطي حلقة هذا الطالب.') }}
                </flux:callout>
            @endforelse

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showTrackModal', false)">{{ __('إلغاء') }}</flux:button>
                @if(count($availableCompetitions) > 0)
                    <flux:button variant="primary" wire:click="saveTrackEnrollment">{{ __('حفظ') }}</flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
</div>
