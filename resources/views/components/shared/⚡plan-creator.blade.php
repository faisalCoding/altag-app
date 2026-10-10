<?php

use Livewire\Component;
use App\Models\Student;
use App\Models\Surah;
use App\Models\Ayah;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Services\QuranPlanFiller;
use App\Services\QuranPlanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Illuminate\Validation\Rule;

new class extends Component {
    #[Locked]
    public $userLevel; // 'teacher' or 'student'
    #[Url]
    #[Locked]
    public $edit = null;

    #[Url]
    public $studentId;
    public $startDate;
    public $daysCount = 16;
    public $activeDays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday'];
    public $description;
    public $planType = 'hifz_review';

    public $planDays = [];
    protected ?array $quranReferenceDataCache = null;

    /** @var array<string, mixed> Resolved once per request; never sent to the browser. */
    protected array $calendarScopeCache = [];
    public $fillDirection = 'reverse';
    public $reviewDirection = 'reverse';
    public $fillTarget = 'hifz';
    public $bulkStartSurah;
    public $bulkStartVerse;

    public $selectAll = false;
    public $selectionStart = null;

    // Academic Calendar properties
    public $isOutsidePeriod = false;
    public $expectedEndDate = null;
    public $expectedEndDateHijri = null;
    public $totalCalendarDays = null;
    public $lastCheckedDate = null;
    public $periodDistribution = [];
    public $firstAvailableWorkingDate = null;

    // Wizard state
    public $step = 1;
    public $isGenerated = false;
    public $memorizedUpToSurah = 114;
    public $memorizedUpToVerse = 1;

    /** Switch the student's other active plans off once the new one is saved. */
    public bool $deactivatePrevious = false;

    public function mount()
    {
        $this->userLevel = Auth::guard('student')->check() ? 'student' : 'teacher';

        $this->bulkStartSurah = 114;
        $this->bulkStartVerse = 1;
        $this->memorizedUpToSurah = 1;
        $this->memorizedUpToVerse = 7;

        // ?studentId= and ?edit= arrive in the address bar: a teacher may only
        // name a student of their own circles, a student only themselves.
        if ($this->studentId && ! in_array((int) $this->studentId, $this->allowedStudentIds(), true)) {
            $this->studentId = null;
        }

        if ($this->edit) {
            $plan = StudentPlan::with('days.fromAyah', 'days.toAyah', 'days.reviewFromAyah', 'days.reviewToAyah')
                ->whereIn('student_id', $this->allowedStudentIds())
                ->findOrFail($this->edit);
            $this->studentId = $plan->student_id;
            $this->startDate = $plan->start_date->format('Y-m-d');
            $this->daysCount = $plan->days_count;
            $this->activeDays = $plan->active_days ?? [];
            $this->description = $plan->description;
            $this->planType = $plan->plan_type;
            $this->fillDirection = $plan->direction ?? 'reverse';
            $this->reviewDirection = $plan->review_direction ?? 'reverse';

            $dir = ($this->planType === 'review') ? $this->reviewDirection : $this->fillDirection;
            if ($dir === 'reverse') {
                $this->memorizedUpToSurah = 1;
            } else {
                $this->memorizedUpToSurah = 114;
            }
            $surah = Surah::find($this->memorizedUpToSurah);
            $this->memorizedUpToVerse = $surah ? $surah->verses_count : 1;

            $this->isGenerated = true;
            $this->step = 7;

            $this->planDays = $plan->days->map(function ($d) {
                return [
                    'id' => $d->id,
                    'date' => $d->date->toDateString(),
                    'hijri' => $this->getHijriLabel($d->date),
                    'day_name_ar' => $d->day_name,
                    'from_surah_id' => $d->fromAyah?->surah_id,
                    'from_verse' => $d->fromAyah?->verse_number,
                    'to_surah_id' => $d->toAyah?->surah_id,
                    'to_verse' => $d->toAyah?->verse_number,
                    'review_from_surah_id' => $d->reviewFromAyah?->surah_id,
                    'review_from_verse' => $d->reviewFromAyah?->verse_number,
                    'review_to_surah_id' => $d->reviewToAyah?->surah_id,
                    'review_to_verse' => $d->reviewToAyah?->verse_number,
                    'selected' => false,
                ];
            })->toArray();
        } else {
            $this->startDate = now()->format('Y-m-d');
            if ($this->userLevel === 'teacher') {
                $teacher = Auth::guard('teacher')->user();
                if (!$this->studentId) {
                    $this->studentId = Student::where('circle_id', $teacher->circles()->first()?->id ?? 0)->first()->id ?? null;
                }
                $this->step = 1;
            } else {
                $this->studentId = Auth::guard('student')->id();
                $this->step = 2; // skip student selection for student
            }
        }

        $this->checkAttendancePeriod();
    }

    /**
     * The students whose plans this user may make or change.
     *
     * @return array<int, int>
     */
    private function allowedStudentIds(): array
    {
        if ($this->userLevel === 'student') {
            return [(int) Auth::guard('student')->id()];
        }

        $teacher = Auth::guard('teacher')->user();

        return $teacher
            ? Student::whereIn('circle_id', $teacher->circles()->pluck('circles.id'))->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    #[Computed]
    public function allSurahs()
    {
        return app(QuranPlanService::class)->getAllSurahs();
    }

    #[Computed]
    public function juzSurahs(): array
    {
        return $this->quranReferenceData()['juzSurahs'];
    }

    #[Computed]
    public function versesData(): array
    {
        return $this->quranReferenceData()['versesData'];
    }

    /**
     * Request-scoped guard around the app-cached plan reference data, so the two
     * computed properties (juzSurahs/versesData) that depend on it touch the cache
     * store at most once per Livewire request. The heavy build and the persistent
     * cache live in QuranPlanService::getPlanReferenceData().
     */
    protected function quranReferenceData(): array
    {
        if ($this->quranReferenceDataCache !== null) {
            return $this->quranReferenceDataCache;
        }

        return $this->quranReferenceDataCache = app(QuranPlanService::class)->getPlanReferenceData();
    }

    public function checkAttendancePeriod()
    {
        if (!$this->startDate || !$this->daysCount) {
            $this->isOutsidePeriod = true;
            $this->expectedEndDate = null;
            $this->expectedEndDateHijri = null;
            $this->totalCalendarDays = null;
            $this->periodDistribution = [];
            $this->firstAvailableWorkingDate = null;
            return;
        }

        $startDateObj = Carbon::parse($this->startDate);
        
        $attendancePeriods = \App\Models\AcademicCalendarEvent::where('is_attendance_period', true)
            ->forStage($this->planStageId())
            ->where('end_date', '>=', $startDateObj->format('Y-m-d'))
            ->orderBy('start_date', 'asc')
            ->get();

        $mapping = [
            1 => 'Sunday',
            2 => 'Monday',
            3 => 'Tuesday',
            4 => 'Wednesday',
            5 => 'Thursday',
            6 => 'Friday',
            7 => 'Saturday',
        ];

        // 1. Check if the start date itself falls within any attendance period
        $startStr = $startDateObj->format('Y-m-d');
        $startPeriod = $attendancePeriods->first(function ($p) use ($startStr) {
            return $p->start_date->format('Y-m-d') <= $startStr && $p->end_date->format('Y-m-d') >= $startStr;
        });

        if ($startPeriod) {
            $this->isOutsidePeriod = false;
            $this->firstAvailableWorkingDate = null;

            if ($this->lastCheckedDate !== $this->startDate) {
                $weekdays = $startPeriod->weekdays ?? [];
                $this->activeDays = array_map(fn($wd) => $mapping[$wd], $weekdays);
                $this->lastCheckedDate = $this->startDate;
            }
        } else {
            $this->isOutsidePeriod = true;
            $this->lastCheckedDate = $this->startDate;
            
            $firstWorking = null;
            $tempDate = $startDateObj->copy();
            for ($i = 0; $i < 365; $i++) {
                $tempStr = $tempDate->format('Y-m-d');
                $nextPeriod = $attendancePeriods->first(function ($p) use ($tempStr) {
                    return $p->start_date->format('Y-m-d') <= $tempStr && $p->end_date->format('Y-m-d') >= $tempStr;
                });
                if ($nextPeriod && \App\Models\AcademicCalendarEvent::isWorkingDay($tempStr, $this->planStageId())) {
                    $firstWorking = $tempDate->copy();
                    break;
                }
                $tempDate->addDay();
            }
            $this->firstAvailableWorkingDate = $firstWorking ? $firstWorking->format('Y-m-d') : null;
        }

        // 2. Simulate plan days distribution across periods
        $currentSimDate = $startDateObj->copy();
        $scheduledDays = 0;
        $distribution = [];
        $lastScheduled = null;
        $safetyLimit = 0;
        $maxCalendarDays = 365;

        // Count remaining working days in the first/active period (if it exists and covers start date)
        $this->remainingWorkingDays = null;
        if ($startPeriod) {
            $count = 0;
            $tempDate = $startDateObj->copy();
            $endDateObj = Carbon::parse($startPeriod->end_date);
            while ($tempDate->lte($endDateObj)) {
                if (in_array($tempDate->format('l'), $this->activeDays)
                    && \App\Models\AcademicCalendarEvent::isWorkingDay($tempDate, $this->planStageId())) {
                    $count++;
                }
                $tempDate->addDay();
            }
            $this->remainingWorkingDays = $count;
        }

        $hasPeriods = $attendancePeriods->isNotEmpty();

        while ($scheduledDays < $this->daysCount && $safetyLimit < $maxCalendarDays) {
            $dateStr = $currentSimDate->format('Y-m-d');
            $dayOfWeek = $currentSimDate->format('l');

            $period = $attendancePeriods->first(function ($p) use ($dateStr) {
                return $p->start_date->format('Y-m-d') <= $dateStr && $p->end_date->format('Y-m-d') >= $dateStr;
            });
            $isValid = in_array($dayOfWeek, $this->activeDays)
                && \App\Models\AcademicCalendarEvent::isSchedulable($dateStr, $this->planStageId());

            if ($isValid) {
                $pName = $period ? $period->event_name : ($hasPeriods ? 'خارج فترات الدوام' : 'دوام اعتيادي');
                $distribution[$pName] = ($distribution[$pName] ?? 0) + 1;
                $lastScheduled = $currentSimDate->copy();
                $scheduledDays++;
            }
            $currentSimDate->addDay();
            $safetyLimit++;
        }

        if ($scheduledDays < $this->daysCount) {
            $unscheduled = $this->daysCount - $scheduledDays;
            $distribution['خارج فترات الدوام'] = $unscheduled;
        }

        $this->periodDistribution = $distribution;

        if ($lastScheduled) {
            $this->expectedEndDate = $lastScheduled->format('Y-m-d');
            $this->expectedEndDateHijri = $this->getHijriLabel($lastScheduled);
            $this->totalCalendarDays = Carbon::parse($this->startDate)->diffInDays($lastScheduled) + 1;
        } else {
            $this->expectedEndDate = null;
            $this->expectedEndDateHijri = null;
            $this->totalCalendarDays = null;
        }
    }

    public function autoFillActiveDays()
    {
        $this->validate([
            'startDate' => 'required|date',
            'daysCount' => 'required|integer|min:1|max:365',
        ]);

        $startDateObj = Carbon::parse($this->startDate);
        $attendancePeriod = \App\Models\AcademicCalendarEvent::where('is_attendance_period', true)
            ->forStage($this->planStageId())
            ->where('start_date', '<=', $startDateObj->format('Y-m-d'))
            ->where('end_date', '>=', $startDateObj->format('Y-m-d'))
            ->first();

        if ($attendancePeriod && !empty($attendancePeriod->weekdays)) {
            $mapping = [
                1 => 'Sunday',
                2 => 'Monday',
                3 => 'Tuesday',
                4 => 'Wednesday',
                5 => 'Thursday',
                6 => 'Friday',
                7 => 'Saturday',
            ];

            $this->activeDays = array_map(fn($wd) => $mapping[$wd], $attendancePeriod->weekdays);
        }
    }

    /**
     * The stage whose calendar this plan follows. Stages keep their own working
     * days, extra days and closures, so the plan is laid out on the student's.
     */
    protected function planStageId(): ?int
    {
        // Asked once per date while a plan is laid out, so resolved once.
        if (! array_key_exists('stage', $this->calendarScopeCache)) {
            $this->calendarScopeCache['stage'] = $this->studentId
                ? Student::find($this->studentId)?->effective_stage_id
                : null;
        }

        return $this->calendarScopeCache['stage'];
    }

    public function resetPlan()
    {
        $this->isGenerated = false;
        $this->step = $this->userLevel === 'teacher' ? 1 : 2;
        $this->planDays = [];
        $this->deactivatePrevious = false;
    }

    public function updatedBulkStartSurah()
    {
        $this->bulkStartVerse = 1;
    }

    public function updatedMemorizedUpToSurah()
    {
        $surah = Surah::find($this->memorizedUpToSurah);
        $this->memorizedUpToVerse = $surah ? $surah->verses_count : 1;
    }

    public function updatedFillDirection($value)
    {
        $this->reviewDirection = $value;
        if ($value === 'reverse') {
            $this->memorizedUpToSurah = 1;
        } else {
            $this->memorizedUpToSurah = 114;
        }
        $surah = Surah::find($this->memorizedUpToSurah);
        $this->memorizedUpToVerse = $surah ? $surah->verses_count : 1;
    }

    public function updatedReviewDirection($value)
    {
        // Changing review direction does not change the Hifz ceiling, so we don't need to change memorizedUpToSurah here
    }

    public function updatedPlanType($value)
    {
        if ($this->fillDirection === 'reverse') {
            $this->memorizedUpToSurah = 1;
        } else {
            $this->memorizedUpToSurah = 114;
        }
        $surah = Surah::find($this->memorizedUpToSurah);
        $this->memorizedUpToVerse = $surah ? $surah->verses_count : 1;
    }

    public function updatedStartDate()
    {
        $this->checkAttendancePeriod();
        if ($this->isGenerated && !empty($this->planDays)) {
            $this->updateStartDate();
        }
    }

    public function updatedDaysCount()
    {
        $this->checkAttendancePeriod();
    }

    public function updatedActiveDays()
    {
        $this->checkAttendancePeriod();
    }



    public function updatedSelectAll($value)
    {
        foreach ($this->planDays as &$day) {
            $day['selected'] = $value;
        }
    }

    public function toggleDaySelection($index)
    {
        if (!isset($this->planDays[$index]))
            return;

        if ($this->selectionStart === null) {
            $this->selectionStart = $index;
            $this->planDays[$index]['selected'] = !$this->planDays[$index]['selected'];
        } else {
            $start = min($this->selectionStart, $index);
            $end = max($this->selectionStart, $index);
            $targetValue = $this->planDays[$this->selectionStart]['selected'];

            for ($i = $start; $i <= $end; $i++) {
                $this->planDays[$i]['selected'] = $targetValue;
            }
            $this->selectionStart = null;
        }
    }

    public function with()
    {
        $students = [];
        if ($this->userLevel === 'teacher') {
            $teacher = Auth::guard('teacher')->user();
            $circleIds = $teacher->circles->pluck('id');
            $students = Student::whereIn('circle_id', $circleIds)->orderBy('name')->get();
        }

        return [
            'students' => $students,
            'activePlansCount' => $this->activePlansCount(),
        ];
    }

    /**
     * The student's plans running now, which a teacher may switch off as the
     * new one is saved. None to offer while editing, or for a student, whose
     * plan waits for the teacher's approval and must not stop the one running;
     * and read only at the last step, where the choice is made.
     */
    private function activePlansCount(): int
    {
        if ($this->edit || $this->userLevel !== 'teacher' || ! $this->studentId || ! $this->isGenerated) {
            return 0;
        }

        return StudentPlan::where('student_id', $this->studentId)->where('status', 'active')->count();
    }

    public function generateDays()
    {
        $this->validate([
            'studentId' => ['required', Rule::in($this->allowedStudentIds())],
            'startDate' => 'required|date',
            'daysCount' => 'required|integer|min:1|max:100',
            'activeDays' => 'required|array|min:1',
        ]);

        if ($this->planType === 'review') {
            if ($this->fillDirection === 'reverse') {
                // Starts reviewing from the LAST surah (An-Nas) going down to the memorized bound
                $this->bulkStartSurah = 114;
            } else {
                $this->bulkStartSurah = 1;
            }
            $this->bulkStartVerse = 1;
        }

        $this->planDays = [];
        $currentDate = Carbon::parse($this->startDate);
        $count = 0;

        $ayah = Ayah::where('surah_id', $this->bulkStartSurah)
            ->where('verse_number', $this->bulkStartVerse)
            ->first() ?: Ayah::first();

        $surahId = $ayah->surah_id ?? 114;
        $verseNum = $ayah->verse_number ?? 1;

        $attendancePeriods = \App\Models\AcademicCalendarEvent::where('is_attendance_period', true)
            ->orderBy('start_date', 'asc')
            ->get();

        $mapping = [
            1 => 'Sunday',
            2 => 'Monday',
            3 => 'Tuesday',
            4 => 'Wednesday',
            5 => 'Thursday',
            6 => 'Friday',
            7 => 'Saturday',
        ];

        $hasPeriods = $attendancePeriods->isNotEmpty();

        while ($count < $this->daysCount) {
            $dayOfWeek = $currentDate->format('l');
            $dateStr = $currentDate->toDateString();

            $isValid = in_array($dayOfWeek, $this->activeDays)
                && \App\Models\AcademicCalendarEvent::isSchedulable($dateStr, $this->planStageId());

            if ($isValid) {
                $this->planDays[] = [
                    'date' => $currentDate->toDateString(),
                    'hijri' => $this->getHijriLabel($currentDate),
                    'day_name_ar' => $this->translateDay($dayOfWeek),
                    'from_surah_id' => $surahId,
                    'from_verse' => $verseNum,
                    'to_surah_id' => $surahId,
                    'to_verse' => $verseNum,
                    'review_from_surah_id' => $surahId,
                    'review_from_verse' => $verseNum,
                    'review_to_surah_id' => $surahId,
                    'review_to_verse' => $verseNum,
                    'selected' => false,
                ];
                $count++;
            }
            $currentDate->addDay();
        }

        $this->isGenerated = true;
        $this->step = 7;
    }

    public function updateStartDate()
    {
        $this->validate(['startDate' => 'required|date']);
        $currentDate = Carbon::parse($this->startDate);

        $attendancePeriods = \App\Models\AcademicCalendarEvent::where('is_attendance_period', true)
            ->orderBy('start_date', 'asc')
            ->get();

        $mapping = [
            1 => 'Sunday',
            2 => 'Monday',
            3 => 'Tuesday',
            4 => 'Wednesday',
            5 => 'Thursday',
            6 => 'Friday',
            7 => 'Saturday',
        ];

        $hasPeriods = $attendancePeriods->isNotEmpty();

        foreach ($this->planDays as &$day) {
            while (true) {
                $dayOfWeek = $currentDate->format('l');
                $dateStr = $currentDate->toDateString();

                $isValid = in_array($dayOfWeek, $this->activeDays)
                    && \App\Models\AcademicCalendarEvent::isSchedulable($dateStr, $this->planStageId());

                if ($isValid) {
                    break;
                }
                $currentDate->addDay();
            }

            $day['date'] = $currentDate->toDateString();
            $day['hijri'] = $this->getHijriLabel($currentDate);
            $day['day_name_ar'] = $this->translateDay($currentDate->format('l'));

            $currentDate->addDay();
        }
        unset($day);
    }

    public function fillSelected($type, $target = null, array $selectedIndices = [])
    {
        $filler = new QuranPlanFiller(
            planType: (string) $this->planType,
            fillDirection: (string) $this->fillDirection,
            reviewDirection: (string) $this->reviewDirection,
            bulkStartSurah: (int) $this->bulkStartSurah,
            bulkStartVerse: (int) $this->bulkStartVerse,
            memorizedUpToSurah: (int) $this->memorizedUpToSurah,
            memorizedUpToVerse: (int) $this->memorizedUpToVerse,
        );

        $this->planDays = $filler->fill($this->planDays, (string) $type, (string) ($target ?? $this->fillTarget), $selectedIndices);
    }

    public function save()
    {
        $this->validate([
            'studentId' => ['required', Rule::in($this->allowedStudentIds())],
            'planDays' => 'required|array|min:1',
        ]);

        if ($this->edit) {
            $plan = StudentPlan::whereIn('student_id', $this->allowedStudentIds())->findOrFail($this->edit);
            $plan->update([
                'start_date' => $this->startDate,
                'days_count' => $this->daysCount,
                'active_days' => $this->activeDays,
                'description' => $this->description,
                'plan_type' => $this->planType,
                'direction' => $this->fillDirection,
                'review_direction' => $this->reviewDirection,
            ]);

            $existingIds = collect($this->planDays)->pluck('id')->filter()->toArray();
            $plan->days()->whereNotIn('id', $existingIds)->delete();
        } else {
            $student = Student::findOrFail($this->studentId);
            $teacherId = $this->userLevel === 'teacher' ? Auth::guard('teacher')->id() : $student->circle?->teachers()->first()?->id;

            $plan = StudentPlan::create([
                'student_id' => $this->studentId,
                'teacher_id' => $teacherId, // Can be null if the student has no circle
                'start_date' => $this->startDate,
                'days_count' => $this->daysCount,
                'active_days' => $this->activeDays,
                'description' => $this->description,
                'plan_type' => $this->planType,
                'direction' => $this->fillDirection,
                'review_direction' => $this->reviewDirection,
                'status' => 'active',
                'is_approved' => $this->userLevel === 'teacher',
                'created_by_role' => $this->userLevel,
            ]);
        }

        foreach ($this->planDays as $dayData) {
            $from = null;
            $to = null;
            $revFrom = null;
            $revTo = null;

            if (in_array($this->planType, ['hifz', 'hifz_review'])) {
                $from = Ayah::where('surah_id', $dayData['from_surah_id'])->where('verse_number', $dayData['from_verse'])->first();
                $to = Ayah::where('surah_id', $dayData['to_surah_id'])->where('verse_number', $dayData['to_verse'])->first();
            }

            if (in_array($this->planType, ['review', 'hifz_review'])) {
                $revFrom = Ayah::where('surah_id', $dayData['review_from_surah_id'])->where('verse_number', $dayData['review_from_verse'])->first();
                $revTo = Ayah::where('surah_id', $dayData['review_to_surah_id'])->where('verse_number', $dayData['review_to_verse'])->first();
            }

            $dayAttributes = [
                'date' => $dayData['date'],
                'day_name' => $dayData['day_name_ar'],
                'from_ayah_id' => $from?->id,
                'to_ayah_id' => $to?->id,
                'review_from_ayah_id' => $revFrom?->id,
                'review_to_ayah_id' => $revTo?->id,
            ];

            if (isset($dayData['id'])) {
                $plan->days()->where('id', $dayData['id'])->update($dayAttributes);
            } else {
                $plan->days()->create($dayAttributes);
            }
        }

        // The new plan takes over: the student's others stop, as the plans
        // page's own deactivation stops them — kept, and switched on again there.
        if (! $this->edit && $this->userLevel === 'teacher' && $this->deactivatePrevious) {
            StudentPlan::where('student_id', $this->studentId)
                ->whereKeyNot($plan->id)
                ->where('status', 'active')
                ->update(['status' => 'inactive']);
        }

        if ($this->userLevel === 'student') {
            return redirect()->route('student.plan')->with('success', 'تم الحفظ وسيتم عرضها على المعلم للاعتماد');
        }

        $this->dispatch('plan-created');

        if ($this->edit) {
            return redirect()->route('teacher.student-plans')->with('success', 'تم تعديل الخطة بنجاح');
        }

        Flux::toast('تم إنشاء الخطة بنجاح', variant: 'success');
        $this->resetPlan();
        $this->dispatch('switch-tab', tab: 'tasmeeh', url: route('teacher.tasmeeh'));
    }

    protected function getHijriLabel(\DateTimeInterface $date)
    {
        return \App\Support\HijriDate::full($date->getTimestamp());
    }

    protected function translateDay($day)
    {
        $days = [
            'Sunday' => 'الأحد',
            'Monday' => 'الاثنين',
            'Tuesday' => 'الثلاثاء',
            'Wednesday' => 'الأربعاء',
            'Thursday' => 'الخميس',
            'Friday' => 'الجمعة',
            'Saturday' => 'السبت',
        ];

        return $days[$day] ?? $day;
    }
};
?>

<div class="space-y-6" x-data="{
        wizardStep:    $wire.entangle('step'),
        userLevel:     '{{ $userLevel }}',
        days:          $wire.entangle('planDays'),
        planType:      $wire.entangle('planType').live,
        fillDirection: $wire.entangle('fillDirection').live,
        reviewDirection: $wire.entangle('reviewDirection').live,
        fillTarget:    $wire.entangle('fillTarget').live,
        
        selected:       [],
        selectAll:      false,
        selectionStart: null,
        filling:        false,
        customPages:    1,
        customSurahs:   1,
        customJuz:      1,
        
        async goNext() {
            if (this.wizardStep === 1 && this.userLevel === 'teacher' && !$wire.get('studentId')) {
                alert('يرجى اختيار الطالب أولاً.');
                return;
            }
            if (this.wizardStep === 4) {
                if (!$wire.get('startDate') || !$wire.get('daysCount')) {
                    alert('يرجى تحديد تاريخ البدء ومدة الخطة.');
                    return;
                }
            }
            if (this.wizardStep === 5) {
                if (!$wire.get('activeDays') || $wire.get('activeDays').length === 0) {
                    alert('يجب اختيار يوم واحد على الأقل.');
                    return;
                }
            }
            this.wizardStep++;
        },
        goPrev() {
            if (this.wizardStep > 1) {
                this.wizardStep--;
            }
            if (this.wizardStep === 1 && this.userLevel === 'student') {
                this.wizardStep = 2;
            }
        },
        init() {
            this.selected = Array((this.days && this.days.length) ? this.days.length : 0).fill(false);
        },
        toggleAll() {
            if (this.selected.length === 0 && this.days && this.days.length > 0) {
                this.init();
            }
            this.selectAll = ! this.selectAll;
            this.selected = this.selected.map(() => this.selectAll);
        },
        toggleDay(index) {
            if (this.selected.length === 0 && this.days && this.days.length > 0) {
                this.init();
            }
            if (this.selectionStart === null) {
                this.selectionStart = index;
                this.selected[index] = !this.selected[index];
            } else {
                const start   = Math.min(this.selectionStart, index);
                const end     = Math.max(this.selectionStart, index);
                const desired = this.selected[this.selectionStart];
                for (let i = start; i <= end; i++) {
                    this.selected[i] = desired;
                }
                this.selectionStart = null;
            }
        },
        doFill(type) {
            this.filling = true;
            const indices = this.selected.reduce((acc, v, i) => { if (v) acc.push(i); return acc; }, []);
            $wire.fillSelected(type, this.fillTarget, indices).then(() => { this.filling = false; });
        },
    }">

    <!-- WIZARD UI -->
    @if(!$isGenerated)
        <flux:card
            class="max-w-2xl mx-auto p-0 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 shadow-sm relative">
            <!-- Header bar with Progress -->
            <div class="bg-zinc-50 dark:bg-zinc-800/50 p-6 border-b border-zinc-100 dark:border-zinc-800">
                <div class="flex items-center justify-between mb-2">
                    <div>
                        <flux:heading size="xl" level="1">{{ __('إعداد الخطة الدراسية') }}</flux:heading>
                        <flux:subheading>{{ __('معالج إنشاء الجدول بخطوات بسيطة') }}</flux:subheading>
                    </div>
                    <div class="text-xs font-bold text-accent dark:text-zinc-100 bg-accent/10 dark:bg-accent/30 px-3 py-1 rounded-full"
                        x-text="'{{ __('خطوة') }} ' + wizardStep + ' {{ __('من') }} 5'">
                    </div>
                </div>
                <div class="relative w-full h-1.5 bg-zinc-200 dark:bg-zinc-700 rounded overflow-hidden mt-4">
                    <div class="absolute top-0 bottom-0 right-0 bg-accent duration-300"
                        x-bind:style="'width: ' + ((wizardStep / 5) * 100) + '%'"></div>
                </div>
            </div>

            <div class="p-6 min-h-[400px] flex flex-col justify-center">
                <!-- STEP 1: Student -->
                @if($userLevel == 'teacher')
                    <div x-show="wizardStep == 1" class="space-y-6 text-center animate-in fade-in zoom-in duration-300">
                        <div
                            class="mx-auto bg-accent/10 dark:bg-zinc-800 w-16 h-16 rounded-full flex items-center justify-center text-accent dark:text-zinc-200 mb-4">
                            <flux:icon icon="user" class="size-8" />
                        </div>
                        <flux:heading size="lg" class="mb-4">{{ __('لمن تريد إنشاء الخطة؟') }}</flux:heading>
                        <div class="max-w-md mx-auto text-right">
                            <flux:select wire:model="studentId" placeholder="{{ __('اختر الطالب') }}">
                                @foreach($students as $student)
                                    <flux:select.option value="{{ $student->id }}">{{ $student->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    </div>
                @endif

                <!-- STEP 2: Plan Type -->
                <div x-show="wizardStep == 2" x-cloak class="space-y-6 text-center animate-in fade-in zoom-in duration-300">
                    <div
                        class="mx-auto bg-emerald-50 dark:bg-zinc-800 w-16 h-16 rounded-full flex items-center justify-center text-emerald-500 mb-4">
                        <flux:icon icon="rectangle-stack" class="size-8" />
                    </div>
                    <flux:heading size="lg" class="mb-6">{{ __('حدد نوع المسار القرآني') }}</flux:heading>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 max-w-lg mx-auto">
                        <button @click="planType = 'hifz'"
                            :class="planType === 'hifz' ? 'ring-2 ring-emerald-500 shadow-md bg-emerald-50 dark:bg-emerald-900/20' : 'border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800 pointer-events-auto'"
                            class="flex flex-col items-center p-4 rounded-xl   cursor-pointer">
                            <flux:icon icon="book-open" class="size-8 text-emerald-600 dark:text-emerald-400 mb-2" />
                            <span class="font-bold text-zinc-800 dark:text-zinc-200">{{ __('حفظ فقط') }}</span>
                        </button>
                        <button @click="planType = 'hifz_review'"
                            :class="planType === 'hifz_review' ? 'ring-2 ring-indigo-500 shadow-md bg-indigo-50 dark:bg-indigo-900/20' : 'border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800'"
                            class="flex flex-col items-center p-4 rounded-xl   cursor-pointer">
                            <flux:icon icon="document-duplicate" class="size-8 text-indigo-600 dark:text-indigo-400 mb-2" />
                            <span class="font-bold text-zinc-800 dark:text-zinc-200">{{ __('حفظ ومراجعة') }}</span>
                        </button>
                        <button @click="planType = 'review'"
                            :class="planType === 'review' ? 'ring-2 ring-amber-500 shadow-md bg-amber-50 dark:bg-amber-900/20' : 'border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800'"
                            class="flex flex-col items-center p-4 rounded-xl   cursor-pointer">
                            <flux:icon icon="arrow-path" class="size-8 text-amber-600 dark:text-amber-400 mb-2" />
                            <span class="font-bold text-zinc-800 dark:text-zinc-200">{{ __('مراجعة فقط') }}</span>
                        </button>
                    </div>
                </div>

                <!-- STEP 3: Direction -->
                <div x-show="wizardStep == 3" x-cloak class="space-y-6 text-center animate-in fade-in zoom-in duration-300">
                    <div
                        class="mx-auto bg-blue-50 dark:bg-zinc-800 w-16 h-16 rounded-full flex items-center justify-center text-blue-500 mb-4">
                        <flux:icon icon="arrows-up-down" class="size-8" />
                    </div>
                    <!-- Hifz / Overall Direction -->
                    <div>
                        <flux:subheading class="mb-4 font-semibold text-zinc-600 dark:text-zinc-400">
                            {{ __('حدد اتجاه الحفظ') }}
                        </flux:subheading>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-md mx-auto">
                            <button @click="fillDirection = 'forward'"
                                :class="fillDirection === 'forward' ? 'ring-2 ring-blue-500 shadow-md bg-blue-50 dark:bg-blue-900/20' : 'border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800'"
                                class="flex flex-col items-center p-4 rounded-xl cursor-pointer text-right relative overflow-hidden">
                                <div class="w-full flex items-center justify-between mb-2">
                                    <span class="font-bold text-zinc-800 dark:text-zinc-200">{{ __('من الفاتحة إلى الناس') }}</span>
                                    <flux:icon icon="arrow-down" class="size-5 text-blue-600 dark:text-blue-400" />
                                </div>
                                <span class="text-xs text-zinc-500">{{ __('ترتيب المصحف المعتاد') }}</span>
                            </button>
                            <button @click="fillDirection = 'reverse'"
                                :class="fillDirection === 'reverse' ? 'ring-2 ring-blue-500 shadow-md bg-blue-50 dark:bg-blue-900/20' : 'border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800'"
                                class="flex flex-col items-center p-4 rounded-xl cursor-pointer text-right relative overflow-hidden">
                                <div class="w-full flex items-center justify-between mb-2">
                                    <span class="font-bold text-zinc-800 dark:text-zinc-200">{{ __('من الناس إلى الفاتحة') }}</span>
                                    <flux:icon icon="arrow-up" class="size-5 text-blue-600 dark:text-blue-400" />
                                </div>
                                <span class="text-xs text-zinc-500">{{ __('الترتيب العكسي للمصحف') }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Decoupled Review Direction (Only shown if planType is hifz_review or review) -->
                    <template x-if="planType === 'hifz_review' || planType === 'review'">
                        <div class="pt-6 border-t border-zinc-100 dark:border-zinc-800">
                            <flux:subheading class="mb-4 font-semibold text-zinc-600 dark:text-zinc-400">
                                {{ __('حدد اتجاه المراجعة') }}
                            </flux:subheading>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-md mx-auto">
                                <button @click="reviewDirection = 'forward'"
                                    :class="reviewDirection === 'forward' ? 'ring-2 ring-indigo-500 shadow-md bg-indigo-50 dark:bg-indigo-900/20' : 'border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800'"
                                    class="flex flex-col items-center p-4 rounded-xl cursor-pointer text-right relative overflow-hidden">
                                    <div class="w-full flex items-center justify-between mb-2">
                                        <span class="font-bold text-zinc-800 dark:text-zinc-200">{{ __('من الفاتحة إلى الناس') }}</span>
                                        <flux:icon icon="arrow-down" class="size-5 text-indigo-600 dark:text-indigo-400" />
                                    </div>
                                    <span class="text-xs text-zinc-500">{{ __('ترتيب المصحف المعتاد') }}</span>
                                </button>
                                <button @click="reviewDirection = 'reverse'"
                                    :class="reviewDirection === 'reverse' ? 'ring-2 ring-indigo-500 shadow-md bg-indigo-50 dark:bg-indigo-900/20' : 'border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800'"
                                    class="flex flex-col items-center p-4 rounded-xl cursor-pointer text-right relative overflow-hidden">
                                    <div class="w-full flex items-center justify-between mb-2">
                                        <span class="font-bold text-zinc-800 dark:text-zinc-200">{{ __('من الناس إلى الفاتحة') }}</span>
                                        <flux:icon icon="arrow-up" class="size-5 text-indigo-600 dark:text-indigo-400" />
                                    </div>
                                    <span class="text-xs text-zinc-500">{{ __('الترتيب العكسي للمصحف') }}</span>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- STEP 4: Dates & Count -->
                <div x-show="wizardStep == 4" x-cloak class="space-y-6 text-center animate-in fade-in zoom-in duration-300">
                    <div
                        class="mx-auto bg-rose-50 dark:bg-zinc-800 w-16 h-16 rounded-full flex items-center justify-center text-rose-500 mb-4">
                        <flux:icon icon="calendar-days" class="size-8" />
                    </div>
                    <flux:heading size="lg" class="mb-6">{{ __('من متى تبدأ الخطة؟ وما مدتها؟') }}</flux:heading>

                    <div class="max-w-md mx-auto space-y-4 text-right">
                        <div class="space-y-1">
                            <flux:label>{{ __('تاريخ البدء') }}</flux:label>
                            <livewire:shared.hijri-datepicker wire:model.live="startDate" :show-attendance-days="true" />
                        </div>
                        <flux:input type="number" min="1" max="365" label="{{ __('عدد الأيام المراد جدولتها') }}"
                            wire:model.live="daysCount" placeholder="مثال: 16" />

                        <!-- Loading Indicator -->
                        <div wire:loading wire:target="startDate, daysCount" class="w-full">
                            <div class="bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700/50 rounded-xl p-4 text-sm text-zinc-500 dark:text-zinc-400 flex items-center justify-center gap-2 shadow-sm animate-pulse">
                                <svg class="animate-spin h-5 w-5 text-rose-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ __('جاري مراجعة التقويم الأكاديمي وفترات الدوام...') }}</span>
                            </div>
                        </div>

                        <!-- Results/Summary (Hidden during loading) -->
                        <div wire:loading.remove wire:target="startDate, daysCount" class="space-y-3">
                            @if($isOutsidePeriod)
                                <div class="bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-900/50 rounded-xl p-4 text-sm text-amber-700 dark:text-amber-300 flex items-start gap-3 shadow-sm">
                                    <flux:icon icon="exclamation-triangle" class="size-5 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" />
                                    <div>
                                        <p class="font-bold mb-1">{{ __('تنبيه: خارج فترات الدوام') }}</p>
                                        <p class="text-xs leading-relaxed">
                                            {{ __('تاريخ البدء المحدد يقع خارج فترات الدوام المعتمدة.') }}
                                            @if($firstAvailableWorkingDate)
                                                <br>
                                                <span class="font-semibold text-amber-800 dark:text-amber-200">
                                                    {{ __('أول يوم دوام متاح في الفترة القادمة سيبدأ من: ') }} <x-hijri-date :date="\Carbon\Carbon::parse($firstAvailableWorkingDate)" />
                                                </span>
                                            @else
                                                {{ __('جميع الأيام من التاريخ المحدد وصاعداً ليست ضمن أيام الدوام المعتمدة.') }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                            @endif

                            @if(!empty($periodDistribution))
                                <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl p-4 text-right shadow-sm space-y-3">
                                    <div class="flex items-center gap-2 border-b border-zinc-100 dark:border-zinc-800 pb-2">
                                        <flux:icon icon="information-circle" class="size-5 text-indigo-500 shrink-0" />
                                        <span class="font-bold text-sm text-zinc-800 dark:text-zinc-200">{{ __('ملخص الفترة المقترحة للخطة') }}</span>
                                    </div>

                                    <div class="text-xs space-y-2 text-zinc-600 dark:text-zinc-400 font-medium">
                                        <div class="flex justify-between items-center py-1 border-b border-zinc-50 dark:border-zinc-900">
                                            <span>{{ __('تاريخ الانتهاء المتوقع:') }}</span>
                                            <span class="font-bold text-zinc-800 dark:text-zinc-200 dir-ltr text-left">
                                                {{ $expectedEndDate ? \Carbon\Carbon::parse($expectedEndDate)->format('Y-m-d') : '—' }} 
                                                @if($expectedEndDateHijri)
                                                    <span class="text-zinc-500 font-normal">({{ $expectedEndDateHijri }})</span>
                                                @endif
                                            </span>
                                        </div>
                                        <div class="flex justify-between items-center py-1 border-b border-zinc-50 dark:border-zinc-900">
                                            <span>{{ __('إجمالي الأيام بالتقويم (شاملاً الإجازات):') }}</span>
                                            <span class="font-bold text-zinc-800 dark:text-zinc-200">
                                                {{ $totalCalendarDays ? $totalCalendarDays . ' ' . __('يوماً') : '—' }}
                                            </span>
                                        </div>

                                        <!-- Period distribution breakdown -->
                                        <div class="pt-2">
                                            <span class="font-semibold text-zinc-700 dark:text-zinc-300 block mb-1.5">{{ __('توزيع أيام الخطة حسب فترات الدوام:') }}</span>
                                            <div class="space-y-1.5 bg-zinc-50 dark:bg-zinc-800/40 p-2.5 rounded-lg border border-zinc-100 dark:border-zinc-800">
                                                @foreach($periodDistribution as $periodName => $daysCountInPeriod)
                                                    <div class="flex justify-between items-center text-xs">
                                                        <span class="flex items-center gap-1.5">
                                                            <span class="size-1.5 rounded-full {{ $periodName === 'خارج فترات الدوام' || $periodName === 'أيام خارج فترات الدوام' ? 'bg-amber-400' : 'bg-emerald-500' }}"></span>
                                                            {{ $periodName }}
                                                        </span>
                                                        <span class="font-bold text-zinc-800 dark:text-zinc-200">{{ $daysCountInPeriod }} {{ __('يوم') }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>

                                        @if(count(array_keys($periodDistribution)) > 1 && !isset($periodDistribution['خارج فترات الدوام']))
                                            <div class="mt-2.5 bg-indigo-50 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/40 rounded-lg p-2.5 text-[11px] text-indigo-700 dark:text-indigo-300 leading-relaxed">
                                                {{ __('تنبيه: ستمتد هذه الخطة عبر فترات دوام متعددة بالتقويم الأكاديمي، وسيتم تطبيق أيام الدوام المعتمدة لكل فترة تلقائياً.') }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- STEP 5: Active Days -->
                <div x-show="wizardStep == 5" x-cloak class="space-y-6 text-center animate-in fade-in zoom-in duration-300">
                    <div
                        class="mx-auto bg-purple-50 dark:bg-zinc-800 w-16 h-16 rounded-full flex items-center justify-center text-purple-500 mb-4">
                        <flux:icon icon="calendar" class="size-8" />
                    </div>
                    <flux:heading size="lg" class="mb-6">{{ __('أيام التسميع خلال الأسبوع') }}</flux:heading>

                    <div
                        class="max-w-md mx-auto text-right bg-zinc-50 dark:bg-zinc-800 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                            @foreach(['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $d)
                                <div
                                    class="flex items-center gap-2 bg-white dark:bg-zinc-900 p-2 rounded-lg border border-zinc-100 dark:border-zinc-800 shadow-sm">
                                    <flux:checkbox wire:model="activeDays" value="{{ $d }}" id="day-{{ $d }}" />
                                    <flux:label for="day-{{ $d }}" class="text-sm cursor-pointer">{{ $this->translateDay($d) }}
                                    </flux:label>
                                </div>
                            @endforeach
                        </div>
                        @error('activeDays')
                            <div class="text-red-500 text-xs mt-2 text-center">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

            </div>

            <!-- Footer Toolbar -->
            <div
                class="px-6 py-4 bg-zinc-50 dark:bg-zinc-800/50 border-t border-zinc-100 dark:border-zinc-800 flex justify-between items-center">
                <flux:button variant="ghost" icon="arrow-right" class="" @click="goPrev"
                    x-bind:disabled="wizardStep == 1 || (wizardStep == 2 && userLevel == 'student')">
                    {{ __('السابق') }}
                </flux:button>

                <template x-if="wizardStep < 5">
                    <flux:button variant="primary" @click="goNext" class="min-w-[120px]">
                        {{ __('التالي') }}
                    </flux:button>
                </template>
                <template x-if="wizardStep == 5">
                    <flux:button variant="primary" wire:click="generateDays" icon="calendar"
                        class="min-w-[120px] border-none">
                        {{ __('اكمال بيانات الايام') }}
                    </flux:button>
                </template>
            </div>
        </flux:card>
    @else
        <!-- GENERATED STATE -->

        <!-- Summary Bar -->
        <flux:card
            class="flex flex-col md:flex-row md:items-center justify-between gap-4 p-4 border border-emerald-200 dark:border-emerald-800/50 bg-emerald-50/50 dark:bg-emerald-900/10 mb-6">
            <div class="flex items-center gap-4">
                <div
                    class="bg-emerald-100 dark:bg-emerald-800 w-12 h-12 rounded-full flex items-center justify-center text-emerald-600 dark:text-emerald-300 shrink-0">
                    <flux:icon icon="document-check" class="size-6" />
                </div>
                <div>
                    <h3 class="font-bold text-zinc-900 dark:text-zinc-100 text-lg">{{ __('تم توليد مسودة الجدول بنجاح!') }}
                    </h3>
                    <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        <flux:badge color="zinc" size="sm">
                            {{ $planType === 'hifz' ? __('مسار حفظ') : ($planType === 'review' ? __('مسار مراجعة') : __('حفظ ومراجعة')) }}
                        </flux:badge>
                        <flux:badge color="zinc" size="sm">
                            {{ __('اتجاه الحفظ:') }} {{ $fillDirection === 'forward' ? __('من الفاتحة إلى الناس') : __('من الناس إلى الفاتحة') }}
                        </flux:badge>
                        @if($planType !== 'hifz')
                            <flux:badge color="zinc" size="sm">
                                {{ __('اتجاه المراجعة:') }} {{ $reviewDirection === 'forward' ? __('من الفاتحة إلى الناس') : __('من الناس إلى الفاتحة') }}
                            </flux:badge>
                        @endif
                        <span class="flex items-center gap-1">
                            <flux:icon icon="calendar" class="size-3" /> {{ $daysCount }} يوم
                        </span>
                        @if($planType === 'review')
                            <span class="flex items-center gap-1 text-amber-600 dark:text-amber-400 font-medium">
                                <flux:icon icon="flag" class="size-3" /> {{ __('سقف المراجعة:') }} {{ $this->allSurahs->firstWhere('id', $memorizedUpToSurah)?->name_arabic ?? '' }} ({{ $memorizedUpToVerse }})
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3">
                <div
                    class="flex items-center gap-2 bg-white dark:bg-zinc-800 rounded-md border border-zinc-200 dark:border-zinc-700 px-2 py-1">
                    <span class="text-xs text-zinc-500">{{ __('تاريخ البدء:') }}</span>
                    <div class="w-40">
                        <livewire:shared.hijri-datepicker wire:model.live="startDate" :show-attendance-days="true" />
                    </div>
                </div>
                <flux:button wire:click="resetPlan" variant="ghost" icon="arrow-path"
                    class="text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-900/40">
                    {{ __('إعادة ضبط وملء من جديد') }}
                </flux:button>
            </div>
        </flux:card>

        <!-- TABLE SECTION -->
        <div class="space-y-4 lg:h-[80vh] h-auto -mx-2 sm:mx-0 flex flex-col">
            @if(count($planDays) > 0)
                <flux:card class="p-0 overflow-hidden flex flex-col border-x-0 rounded-none sm:border-x sm:rounded-xl h-full flex-1">

                    {{-- Toolbar --}}
                    <div
                        class="p-4 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/90 shrink-0 flex flex-col xl:flex-row xl:items-center justify-between gap-4">
                        <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                            <flux:heading size="sm" class="flex items-center gap-2">
                                <flux:icon icon="bolt" class="size-4 text-indigo-500" />
                                {{ __('أدوات الملء التلقائي (التحديد)') }}
                            </flux:heading>

                            @if($planType === 'review')
                                <div class="flex items-center gap-2 bg-indigo-50/50 dark:bg-indigo-950/20 border border-indigo-200/50 dark:border-indigo-900/40 px-3 py-1.5 rounded-xl">
                                    <flux:icon icon="flag" class="size-4 text-indigo-500 shrink-0" />
                                    <span class="text-xs font-semibold text-zinc-600 dark:text-zinc-400 whitespace-nowrap">
                                        {{ __('سقف المراجعة الحالي:') }}
                                    </span>
                                    <div class="flex items-center gap-2">
                                        <select wire:model.live="memorizedUpToSurah" class="text-xs rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-800 dark:text-zinc-200 px-2 py-1 outline-none">
                                            @foreach($this->allSurahs as $surah)
                                                <option value="{{ $surah->id }}">{{ $surah->name_arabic }}</option>
                                            @endforeach
                                        </select>
                                        <select wire:model.live="memorizedUpToVerse" class="text-xs rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-800 dark:text-zinc-200 px-2 py-1 outline-none">
                                            @php
                                                $ceilingSurah = $this->allSurahs->firstWhere('id', $memorizedUpToSurah);
                                                $versesCount = $ceilingSurah ? $ceilingSurah->verses_count : 1;
                                            @endphp
                                            @for($i = 1; $i <= $versesCount; $i++)
                                                <option value="{{ $i }}">{{ $i }}</option>
                                            @endfor
                                        </select>
                                    </div>
                                </div>
                            @endif

                            {{-- fillTarget — Alpine only --}}
                            <div x-show="planType === 'hifz_review'"
                                class="flex gap-1 bg-zinc-100 dark:bg-zinc-800 rounded-lg p-0.5">
                                <button @click="fillTarget = 'hifz'"
                                    :class="fillTarget === 'hifz' ? 'bg-white dark:bg-zinc-700 shadow-sm text-indigo-600 dark:text-indigo-400' : 'text-zinc-500 dark:text-zinc-400'"
                                    class="px-3 flex-1  py-2 text-lg font-medium rounded-md   s">
                                    {{ __('الحفظ') }}
                                </button>
                                <button @click="fillTarget = 'review'"
                                    :class="fillTarget === 'review' ? 'bg-white dark:bg-zinc-700 shadow-sm text-emerald-600 dark:text-emerald-400' : 'text-zinc-500 dark:text-zinc-400'"
                                    class="px-3 flex-1  py-2 text-lg font-medium rounded-md   s">
                                    {{ __('المراجعة') }}
                                </button>
                            </div>
                        </div>

                        {{-- Fill buttons --}}
                        <div
                            class="flex flex-wrap gap-1 items-center bg-white dark:bg-zinc-900 px-2 py-1.5 rounded border border-zinc-200 dark:border-zinc-700">
                            <div x-show="planType === 'review' || fillTarget === 'review'" class="flex flex-wrap gap-1">
                                <template x-if="planType === 'hifz_review'">
                                    <flux:button size="xs" class="bg-indigo-600 text-white hover:bg-indigo-700"
                                        @click="doFill('all_previous')">{{ __('جميع ما سبق') }}</flux:button>
                                </template>
                                <flux:button size="xs" class="bg-indigo-600 text-white hover:bg-indigo-700"
                                    @click="doFill('juz')">{{ __('جزء') }}</flux:button>
                                <flux:button size="xs" class="bg-indigo-600 text-white hover:bg-indigo-700"
                                    @click="doFill('half_juz')">{{ __('نصف جزء') }}</flux:button>
                                <flux:button size="xs" class="bg-indigo-600 text-white hover:bg-indigo-700"
                                    @click="doFill('5_pages')">{{ __('5 صفحات') }}</flux:button>
                                <flux:button size="xs" class="bg-indigo-600 text-white hover:bg-indigo-700"
                                    @click="doFill('3_surahs')">{{ __('3 سور') }}</flux:button>
                                <flux:button size="xs" class="bg-indigo-600 text-white hover:bg-indigo-700"
                                    @click="doFill('2_surahs')">{{ __('سورتين') }}</flux:button>
                                <flux:button size="xs" class="bg-indigo-600 text-white hover:bg-indigo-700"
                                    @click="doFill('1_surah')">{{ __('سورة واحدة') }}</flux:button>
                            </div>
                            <div x-show="planType === 'hifz' || (planType === 'hifz_review' && fillTarget !== 'review')"
                                class="flex flex-wrap gap-1">
                                <flux:button size="xs" class="bg-indigo-600 text-white hover:bg-indigo-700"
                                    @click="doFill('surah')">{{ __('سورة') }}</flux:button>
                                <flux:button size="xs" variant="ghost" @click="doFill('page')">{{ __('صفحة') }}</flux:button>
                                <flux:button size="xs" variant="ghost" @click="doFill('half')">{{ __('1/2 صفحة') }}
                                </flux:button>
                                <flux:button size="xs" variant="ghost" @click="doFill('third')">{{ __('1/3 صفحة') }}
                                </flux:button>
                            </div>

                            <div class="h-4 w-px bg-zinc-200 dark:bg-zinc-700 mx-2 self-center"></div>

                            {{-- The three custom volumes sit outside both groups,
                                 like the pages one always has, so they serve حفظ
                                 and مراجعة alike. --}}
                            <div class="flex items-center gap-1.5">
                                <input type="number" min="1" max="604" x-model="customPages" class="w-12 text-center text-xs rounded border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-800 dark:text-zinc-200 py-1 outline-none font-mono" />
                                <flux:button size="xs" class="bg-indigo-600 text-white hover:bg-indigo-700"
                                    @click="doFill('custom_pages_' + customPages)">
                                    {{ __('صفحات مخصصة') }}
                                </flux:button>
                            </div>

                            <div class="flex items-center gap-1.5">
                                <input type="number" min="1" max="114" x-model="customSurahs" class="w-12 text-center text-xs rounded border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-800 dark:text-zinc-200 py-1 outline-none font-mono" />
                                <flux:button size="xs" class="bg-indigo-600 text-white hover:bg-indigo-700"
                                    @click="doFill('custom_surahs_' + customSurahs)">
                                    {{ __('سور مخصصة') }}
                                </flux:button>
                            </div>

                            <div class="flex items-center gap-1.5">
                                <input type="number" min="1" max="30" x-model="customJuz" class="w-12 text-center text-xs rounded border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-zinc-800 dark:text-zinc-200 py-1 outline-none font-mono" />
                                <flux:button size="xs" class="bg-indigo-600 text-white hover:bg-indigo-700"
                                    @click="doFill('custom_juz_' + customJuz)">
                                    {{ __('أجزاء مخصصة') }}
                                </flux:button>
                            </div>
                        </div>
                    </div>

                    {{-- Progress bar --}}
                    <div x-show="filling" x-cloak class="relative h-1 bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                        <div class="absolute inset-y-0 w-1/3 bg-gradient-to-r from-transparent via-indigo-500 to-transparent"
                            style="animation: shimmer 1.2s ease-in-out infinite;"></div>
                    </div>

                    <div class="overflow-auto flex-1 h-full min-h-[300px]">
                        <table class="w-full text-sm text-right align-middle whitespace-nowrap relative">
                            <thead
                                class="sticky top-0 z-10 bg-zinc-100 dark:bg-zinc-800 shadow-sm border-b border-zinc-200 dark:border-zinc-700">
                                <tr>
                                    <th class="p-4 w-32 font-bold text-zinc-700 dark:text-zinc-300 cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700 "
                                        @click="toggleAll()">
                                        <div class="flex items-center gap-2">
                                            <flux:icon icon="check-circle" class="size-4 opacity-50" />
                                            <span>{{ __('التاريخ') }}</span>
                                        </div>
                                    </th>

                                    <th x-show="planType === 'hifz' || (planType === 'hifz_review' && fillTarget === 'hifz')"
                                        class="p-3 min-w-[360px] border-r border-zinc-200 dark:border-zinc-700">
                                        <span
                                            class="text-indigo-600 dark:text-indigo-400 font-bold ml-2">{{ __('الحفظ') }}</span>
                                        <div class="grid grid-cols-2 text-xs text-zinc-500 mt-1">
                                            <span>من</span><span>إلى</span>
                                        </div>
                                    </th>

                                    <th x-show="planType === 'review' || (planType === 'hifz_review' && fillTarget === 'review')"
                                        class="p-3 min-w-[360px] border-r border-zinc-200 dark:border-zinc-700">
                                        <span
                                            class="text-emerald-600 dark:text-emerald-400 font-bold ml-2">{{ __('المراجعة') }}</span>
                                        <div class="grid grid-cols-2 text-xs text-zinc-500 mt-1">
                                            <span>من</span><span>إلى</span>
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach($planDays as $index => $day)
                                    <tr wire:key="row-{{ $index }}">
                                        <td class="p-3 cursor-pointer   s hover:bg-indigo-50 dark:hover:bg-indigo-900/40" :class="{
                                                                                                            'bg-indigo-100 dark:bg-indigo-900/60': selected[{{ $index }}],
                                                                                                            'ring-2 ring-inset ring-indigo-500': selectionStart === {{ $index }}
                                                                                                        }"
                                            @click="toggleDay({{ $index }})">
                                            <div class="flex flex-col">
                                                <span class="font-bold whitespace-normal leading-tight"
                                                    :class="selected[{{ $index }}] ? 'text-indigo-700 dark:text-indigo-300' : ''">
                                                    {{ $day['day_name_ar'] }}
                                                </span>
                                                <span class="text-[11px] mt-0.5 whitespace-normal"
                                                    :class="selected[{{ $index }}] ? 'text-indigo-500 dark:text-indigo-400' : 'text-zinc-500 dark:text-zinc-400'">
                                                    {{ $day['hijri'] }}
                                                </span>
                                            </div>
                                        </td>

                                        <td x-show="planType === 'hifz' || (planType === 'hifz_review' && fillTarget === 'hifz')"
                                            class="border-r border-zinc-200 dark:border-zinc-700 p-2">
                                            <div class="grid grid-cols-2 gap-2">
                                                <div x-data="{ surahId: @entangle('planDays.' . $index . '.from_surah_id'), verse: @entangle('planDays.' . $index . '.from_verse'), get versesCount() { return $store.surahsData?.[this.surahId]?.count || 1; } }"
                                                    @surah-selected-{{$index}}-from.window="surahId = $event.detail.surahId; verse = 1;"
                                                    @verse-selected-{{$index}}-from.window="verse = $event.detail.verse;"
                                                    class="flex flex-row items-center gap-1 bg-white dark:bg-zinc-900 p-1.5 rounded border border-zinc-100 dark:border-zinc-800">
                                                    <button type="button"
                                                        @click="$dispatch('open-surah-modal', { index: {{ $index }}, field: 'from', currentSurah: surahId })"
                                                        class="flex-1 min-w-[80px] text-xs p-1 bg-transparent hover:bg-zinc-50 dark:hover:bg-zinc-800 rounded outline-none text-right flex justify-between items-center">
                                                        <span x-text="$store.surahsData?.[surahId]?.name || 'اختر'"></span>
                                                        <flux:icon icon="chevron-down" class="size-3 text-zinc-400" />
                                                    </button>
                                                    <button type="button"
                                                        @click="$dispatch('open-verse-modal', { index: {{ $index }}, field: 'from', surahId: surahId, currentVerse: verse })"
                                                        class="w-16 text-xs p-1 bg-zinc-50 hover:bg-zinc-100 dark:bg-zinc-800 dark:hover:bg-zinc-700 rounded font-mono text-center outline-none flex justify-center items-center gap-1">
                                                        <span x-text="verse"></span>
                                                        <flux:icon icon="chevron-down" class="size-3 text-zinc-400" />
                                                    </button>
                                                </div>
                                                <div x-data="{ surahId: @entangle('planDays.' . $index . '.to_surah_id'), verse: @entangle('planDays.' . $index . '.to_verse'), get versesCount() { return $store.surahsData?.[this.surahId]?.count || 1; } }"
                                                    @surah-selected-{{$index}}-to.window="surahId = $event.detail.surahId; verse = $store.surahsData?.[surahId]?.count || 1;"
                                                    @verse-selected-{{$index}}-to.window="verse = $event.detail.verse;"
                                                    class="flex flex-row items-center gap-1 bg-white dark:bg-zinc-900 p-1.5 rounded border border-zinc-100 dark:border-zinc-800">
                                                    <button type="button"
                                                        @click="$dispatch('open-surah-modal', { index: {{ $index }}, field: 'to', currentSurah: surahId })"
                                                        class="flex-1 min-w-[80px] text-xs p-1 bg-transparent hover:bg-zinc-50 dark:hover:bg-zinc-800 rounded outline-none text-right flex justify-between items-center">
                                                        <span x-text="$store.surahsData?.[surahId]?.name || 'اختر'"></span>
                                                        <flux:icon icon="chevron-down" class="size-3 text-zinc-400" />
                                                    </button>
                                                    <button type="button"
                                                        @click="$dispatch('open-verse-modal', { index: {{ $index }}, field: 'to', surahId: surahId, currentVerse: verse })"
                                                        class="w-16 text-xs p-1 bg-zinc-50 hover:bg-zinc-100 dark:bg-zinc-800 dark:hover:bg-zinc-700 rounded font-mono text-center outline-none flex justify-center items-center gap-1">
                                                        <span x-text="verse"></span>
                                                        <flux:icon icon="chevron-down" class="size-3 text-zinc-400" />
                                                    </button>
                                                </div>
                                            </div>
                                        </td>

                                        <td x-show="planType === 'review' || (planType === 'hifz_review' && fillTarget === 'review')"
                                            class="border-r border-zinc-200 dark:border-zinc-700 p-2">
                                            <div class="grid grid-cols-2 gap-2">
                                                <div x-data="{ surahId: @entangle('planDays.' . $index . '.review_from_surah_id'), verse: @entangle('planDays.' . $index . '.review_from_verse'), get versesCount() { return $store.surahsData?.[this.surahId]?.count || 1; } }"
                                                    @surah-selected-{{$index}}-rfrom.window="surahId = $event.detail.surahId; verse = 1;"
                                                    @verse-selected-{{$index}}-rfrom.window="verse = $event.detail.verse;"
                                                    class="flex flex-row items-center gap-1 bg-white dark:bg-zinc-900 p-1.5 rounded border border-zinc-100 dark:border-zinc-800">
                                                    <button type="button"
                                                        @click="$dispatch('open-surah-modal', { index: {{ $index }}, field: 'rfrom', currentSurah: surahId })"
                                                        class="flex-1 min-w-[80px] text-xs p-1 bg-transparent hover:bg-zinc-50 dark:hover:bg-zinc-800 rounded outline-none text-right flex justify-between items-center">
                                                        <span x-text="$store.surahsData?.[surahId]?.name || 'اختر'"></span>
                                                        <flux:icon icon="chevron-down" class="size-3 text-zinc-400" />
                                                    </button>
                                                    <button type="button"
                                                        @click="$dispatch('open-verse-modal', { index: {{ $index }}, field: 'rfrom', surahId: surahId, currentVerse: verse })"
                                                        class="w-16 text-xs p-1 bg-zinc-50 hover:bg-zinc-100 dark:bg-zinc-800 dark:hover:bg-zinc-700 rounded font-mono text-center outline-none flex justify-center items-center gap-1">
                                                        <span x-text="verse"></span>
                                                        <flux:icon icon="chevron-down" class="size-3 text-zinc-400" />
                                                    </button>
                                                </div>
                                                <div x-data="{ surahId: @entangle('planDays.' . $index . '.review_to_surah_id'), verse: @entangle('planDays.' . $index . '.review_to_verse'), get versesCount() { return $store.surahsData?.[this.surahId]?.count || 1; } }"
                                                    @surah-selected-{{$index}}-rto.window="surahId = $event.detail.surahId; verse = $store.surahsData?.[surahId]?.count || 1;"
                                                    @verse-selected-{{$index}}-rto.window="verse = $event.detail.verse;"
                                                    class="flex flex-row items-center gap-1 bg-white dark:bg-zinc-900 p-1.5 rounded border border-zinc-100 dark:border-zinc-800">
                                                    <button type="button"
                                                        @click="$dispatch('open-surah-modal', { index: {{ $index }}, field: 'rto', currentSurah: surahId })"
                                                        class="flex-1 min-w-[80px] text-xs p-1 bg-transparent hover:bg-zinc-50 dark:hover:bg-zinc-800 rounded outline-none text-right flex justify-between items-center">
                                                        <span x-text="$store.surahsData?.[surahId]?.name || 'اختر'"></span>
                                                        <flux:icon icon="chevron-down" class="size-3 text-zinc-400" />
                                                    </button>
                                                    <button type="button"
                                                        @click="$dispatch('open-verse-modal', { index: {{ $index }}, field: 'rto', surahId: surahId, currentVerse: verse })"
                                                        class="w-16 text-xs p-1 bg-zinc-50 hover:bg-zinc-100 dark:bg-zinc-800 dark:hover:bg-zinc-700 rounded font-mono text-center outline-none flex justify-center items-center gap-1">
                                                        <span x-text="verse"></span>
                                                        <flux:icon icon="chevron-down" class="size-3 text-zinc-400" />
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div
                        class="p-4 border-t border-zinc-100 dark:border-zinc-800 flex flex-col sm:flex-row sm:items-end justify-between gap-4 bg-zinc-50 dark:bg-zinc-800/90 z-20">
                        <div class="space-y-3 pt-2">
                            <div class="text-sm text-zinc-500">
                                {{ __('تأكد من مراجعة النطاقات التلقائية أو تعديلها قبل الحفظ النهائي.') }}
                            </div>
                            @if ($activePlansCount > 0)
                                <flux:checkbox wire:model="deactivatePrevious"
                                    label="{{ __('إلغاء تفعيل الخطط السابقة لهذا الطالب') }}"
                                    description="{{ $activePlansCount === 1 ? __('له خطة مفعّلة واحدة، تتوقف عند حفظ هذه وتبقى في قائمة الخطط.') : __('له :count خطط مفعّلة، تتوقف عند حفظ هذه وتبقى في قائمة الخطط.', ['count' => $activePlansCount]) }}" />
                            @endif
                        </div>
                        <flux:button variant="primary" wire:click="save" icon="check"
                            class="bg-emerald-600 hover:bg-emerald-700 text-white min-w-[200px] border-none">
                            {{ __('اعتماد الخطة وإرسالها') }}
                        </flux:button>
                    </div>
                </flux:card>
            @endif
        </div>
    @endif

    <script>
        (function () {
            const initStores = () => {
                Alpine.store('surahsData', {
                    @foreach($this->allSurahs as $surah)
                        {{ $surah->id }}: { count: {{ $surah->verses_count }}, name: '{{ $surah->name_arabic }}' },
                    @endforeach
                });

            Alpine.store('juzSurahs', {
                @foreach($this->juzSurahs as $juz => $surahs)
                    {{ $juz }}: {{ json_encode($surahs) }},
                @endforeach
                });

        Alpine.store('versesData', @json($this->versesData));
            };

        if (window.Alpine) {
            initStores();
        } else {
            document.addEventListener('alpine:init', initStores);
        }
        }) ();
    </script>

    <!-- Global Surah Selection Modal -->
    <div x-data="{
        isOpen: false,
        showHelp: false,
        targetIndex: null,
        targetField: null,
        activeJuz: null,
        currentSurah: null,
        prevSurah: null,
        
        get orderedJuzs() {
            if (!$store.juzSurahs) return [];
            const keys = Object.keys($store.juzSurahs).map(Number);
            if (fillDirection === 'reverse') {
                return keys.sort((a, b) => b - a);
            }
            return keys.sort((a, b) => a - b);
        },

        getOrderedSurahs(juz) {
            if (!$store.juzSurahs || !$store.juzSurahs[juz]) return [];
            const surahs = [...$store.juzSurahs[juz]];
            if (fillDirection === 'reverse') {
                return surahs.reverse();
            }
            return surahs;
        },
        
        async openModal(e) {
            this.targetIndex = e.detail.index;
            this.targetField = e.detail.field;
            this.currentSurah = e.detail.currentSurah;
            this.prevSurah = null;
            
            try {
                let planDays = await $wire.get('planDays');
                if (this.targetField === 'from' && this.targetIndex > 0) {
                    this.prevSurah = planDays[this.targetIndex - 1]['to_surah_id'];
                } else if (this.targetField === 'to') {
                    this.prevSurah = planDays[this.targetIndex]['from_surah_id'];
                } else if (this.targetField === 'rfrom' && this.targetIndex > 0) {
                    this.prevSurah = planDays[this.targetIndex - 1]['review_to_surah_id'];
                } else if (this.targetField === 'rto') {
                    this.prevSurah = planDays[this.targetIndex]['review_from_surah_id'];
                }
            } catch (err) {
                console.warn('Could not determine previous Surah');
            }

            let targetForScroll = this.prevSurah || this.currentSurah;

            if (targetForScroll && $store.juzSurahs) {
                for (const [juz, surahs] of Object.entries($store.juzSurahs)) {
                    if (surahs.includes(targetForScroll)) {
                        this.activeJuz = Number(juz);
                        break;
                    }
                }
            } else {
                this.activeJuz = fillDirection === 'reverse' ? 30 : 1;
            }

            this.isOpen = true;
            
            $nextTick(() => {
                setTimeout(() => {
                    const el = document.getElementById('surah-btn-' + targetForScroll);
                    if (el) {
                        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }, 100);
            });
        },
        
        selectSurah(surahId) {
            $dispatch('surah-selected-' + this.targetIndex + '-' + this.targetField, { surahId: surahId });
            this.isOpen = false;
        }
    }" @open-surah-modal.window="openModal($event)">
        <flux:modal x-model="isOpen" class="md:w-[500px]">
            <div class="flex items-center justify-center gap-2 mb-4 relative">
                <flux:heading class="!mb-0">{{ __('اختر السورة') }}</flux:heading>
                <button type="button" @click="showHelp = !showHelp"
                    class="text-zinc-400 hover:text-blue-500 outline-none" title="شرح الألوان">
                    <flux:icon icon="question-mark-circle" class="size-5" />
                </button>
            </div>

            <div x-show="showHelp" x-collapse>
                <div
                    class="mb-4 p-3 bg-blue-50/50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800/50 rounded-lg text-xs text-zinc-600 dark:text-zinc-300 space-y-2">
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 rounded bg-blue-100 border border-blue-400 shrink-0"></div>
                        <span><strong>الأزرق:</strong> السورة المحددة حالياً.</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 rounded bg-orange-100 border border-orange-400 shrink-0"></div>
                        <span><strong>البرتقالي:</strong> السورة المرتبطة بالتحديد السابق لتسهيل استكمال
                            الحفظ/المراجعة.</span>
                    </div>
                </div>
            </div>

            <div class="max-h-[60vh] overflow-y-auto space-y-2 px-1 scroll-smooth">
                <template x-for="juz in orderedJuzs" :key="juz">
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                        <button type="button" @click="activeJuz = activeJuz === juz ? null : juz"
                            class="w-full flex items-center justify-between p-3 bg-zinc-50 dark:bg-zinc-800 hover:bg-zinc-100 dark:hover:bg-zinc-700 ">
                            <span class="font-bold text-zinc-700 dark:text-zinc-300">الجزء <span
                                    x-text="juz"></span></span>
                            <flux:icon icon="chevron-down" class="size-4 transition-transform text-zinc-500"
                                ::class="activeJuz === juz ? 'rotate-180' : ''" />
                        </button>
                        <div x-show="activeJuz === juz" x-collapse>
                            <div
                                class="p-2 grid grid-cols-2 sm:grid-cols-3 gap-2 bg-white dark:bg-zinc-900 border-t border-zinc-100 dark:border-zinc-800">
                                <template x-for="surahId in getOrderedSurahs(juz)" :key="surahId">
                                    <button type="button" @click="selectSurah(surahId)" :id="'surah-btn-' + surahId"
                                        class="py-2 px-1 text-sm text-center rounded border " :class="{
                                            'bg-blue-100 border-blue-400 text-blue-700 dark:bg-blue-900/40 dark:border-blue-600 dark:text-blue-300 font-bold ring-2 ring-blue-500 ring-offset-1': surahId === currentSurah,
                                            'bg-orange-100 border-orange-400 text-orange-700 dark:bg-orange-900/40 dark:border-orange-600 dark:text-orange-300 font-bold': surahId === prevSurah && surahId !== currentSurah,
                                            'bg-white border-zinc-200 text-zinc-800 hover:bg-indigo-50 dark:bg-zinc-900 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-indigo-900/30': surahId !== currentSurah && surahId !== prevSurah
                                        }" x-text="$store.surahsData?.[surahId].name"></button>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </flux:modal>
    </div>

    <!-- Global Verse Selection Modal -->
    <div x-data="{
        isOpen: false,
        showHelp: false,
        groupByLine: false,
        targetIndex: null,
        targetField: null,
        surahId: null,
        currentVerse: null,
        prevVerse: null,
        
        async openModal(e) {
            this.targetIndex = e.detail.index;
            this.targetField = e.detail.field;
            this.surahId = e.detail.surahId;
            this.currentVerse = e.detail.currentVerse;
            this.prevVerse = null;
            
            try {
                let planDays = await $wire.get('planDays');
                if (this.targetField === 'from' && this.targetIndex > 0) {
                    if (planDays[this.targetIndex - 1]['to_surah_id'] == this.surahId) {
                        this.prevVerse = planDays[this.targetIndex - 1]['to_verse'];
                    }
                } else if (this.targetField === 'to') {
                    if (planDays[this.targetIndex]['from_surah_id'] == this.surahId) {
                        this.prevVerse = planDays[this.targetIndex]['from_verse'];
                    }
                } else if (this.targetField === 'rfrom' && this.targetIndex > 0) {
                    if (planDays[this.targetIndex - 1]['review_to_surah_id'] == this.surahId) {
                        this.prevVerse = planDays[this.targetIndex - 1]['review_to_verse'];
                    }
                } else if (this.targetField === 'rto') {
                    if (planDays[this.targetIndex]['review_from_surah_id'] == this.surahId) {
                        this.prevVerse = planDays[this.targetIndex]['review_from_verse'];
                    }
                }
            } catch (err) {
                console.warn('Could not determine previous Verse');
            }

            this.isOpen = true;
            
            let targetForScroll = this.prevVerse || this.currentVerse;
            
            $nextTick(() => {
                setTimeout(() => {
                    const el = document.getElementById('verse-btn-' + targetForScroll);
                    if (el) {
                        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }, 100);
            });
        },
        
        selectVerse(verse) {
            $dispatch('verse-selected-' + this.targetIndex + '-' + this.targetField, { verse: verse });
            this.isOpen = false;
        }
    }" @open-verse-modal.window="openModal($event)">
        <flux:modal x-model="isOpen" class="md:w-[500px]">
            <div class="flex items-center justify-center gap-2 mb-4 relative">
                <flux:heading class="!mb-0">{{ __('اختر الآية') }}</flux:heading>
                <button type="button" @click="showHelp = !showHelp"
                    class="text-zinc-400 hover:text-blue-500 outline-none" title="شرح الألوان">
                    <flux:icon icon="question-mark-circle" class="size-5" />
                </button>
            </div>

            <div x-show="showHelp" x-collapse>
                <div
                    class="mb-4 p-3 bg-blue-50/50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800/50 rounded-lg text-xs text-zinc-600 dark:text-zinc-300 space-y-2">
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 rounded-full bg-blue-100 border border-blue-400 shrink-0"></div>
                        <span><strong>الأزرق:</strong> الآية المحددة حالياً.</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 rounded-full bg-orange-100 border border-orange-400 shrink-0"></div>
                        <span><strong>البرتقالي:</strong> آية التوقف السابقة (لتكمل منها مباشرة).</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 rounded-full bg-amber-100 border border-amber-300 relative shrink-0">
                            <div
                                class="absolute -top-1 -right-1 w-1.5 h-1.5 bg-amber-400 rounded-full border border-white dark:border-zinc-900">
                            </div>
                        </div>
                        <span><strong>الذهبي:</strong> الآية التي تقع في منتصف السورة.</span>
                    </div>
                </div>
            </div>

            <div class="max-h-[60vh] overflow-y-auto space-y-4 px-1 scroll-smooth" x-show="surahId">
                <div class="flex items-center justify-between mb-2">
                    <flux:switch x-model="groupByLine" label="تجميع حسب السطر" />
                </div>
                <template x-if="surahId && $store.versesData?.[surahId]">
                    <div>
                        <template x-for="(lines, page) in $store.versesData[surahId].pages" :key="page">
                            <div class="mb-4">
                                <div
                                    class="text-sm font-bold text-zinc-500 dark:text-zinc-400 mb-2 border-b border-zinc-100 dark:border-zinc-800 pb-1">
                                    {{ __('وجه') }} <span x-text="page"></span>
                                </div>
                                <template x-if="groupByLine">
                                    <div class="space-y-2">
                                        <template x-for="(verses, line) in lines" :key="line">
                                            <div
                                                class="flex flex-wrap items-center gap-2 bg-zinc-50 dark:bg-zinc-800/30 p-2 rounded-lg border border-zinc-100 dark:border-zinc-800">
                                                <span
                                                    class="text-[10px] text-zinc-400 font-bold shrink-0 w-8 text-center">{{ __('سطر') }}
                                                    <span x-text="line"></span></span>
                                                <div class="flex flex-wrap gap-2 flex-1">
                                                    <template x-for="v in verses" :key="v">
                                                        <button type="button" @click="selectVerse(v)"
                                                            :id="groupByLine ? 'verse-btn-' + v : ''"
                                                            class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-mono border   relative shrink-0"
                                                            :class="{
                                                                'bg-blue-100 border-blue-400 text-blue-700 dark:bg-blue-900/40 dark:border-blue-600 dark:text-blue-300 font-bold ring-2 ring-blue-500 ring-offset-1': v === currentVerse,
                                                                'bg-orange-100 border-orange-400 text-orange-700 dark:bg-orange-900/40 dark:border-orange-600 dark:text-orange-300 font-bold': v === prevVerse && v !== currentVerse,
                                                                'bg-amber-100 border-amber-300 text-amber-700 dark:bg-amber-900/40 dark:border-amber-700 dark:text-amber-400 font-bold ring-2 ring-amber-400 ring-offset-1': v === $store.versesData[surahId].mid && v !== currentVerse && v !== prevVerse,
                                                                'bg-white border-zinc-200 text-zinc-700 hover:bg-indigo-50 hover:border-indigo-300 dark:bg-zinc-900 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-indigo-900/40': v !== currentVerse && v !== prevVerse && v !== $store.versesData[surahId].mid
                                                            }">
                                                            <span x-text="v"></span>

                                                            <template
                                                                x-if="v === $store.versesData[surahId].mid && (v === currentVerse || v === prevVerse)">
                                                                <div
                                                                    class="absolute -top-1 -right-1 w-3 h-3 bg-amber-400 rounded-full border border-white dark:border-zinc-900">
                                                                </div>
                                                            </template>
                                                        </button>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <template x-if="!groupByLine">
                                    <div class="flex flex-wrap gap-2">
                                        <template x-for="v in Object.values(lines).flat()" :key="v">
                                            <button type="button" @click="selectVerse(v)"
                                                :id="!groupByLine ? 'verse-btn-' + v : ''"
                                                class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-mono border   relative shrink-0"
                                                :class="{
                                                    'bg-blue-100 border-blue-400 text-blue-700 dark:bg-blue-900/40 dark:border-blue-600 dark:text-blue-300 font-bold ring-2 ring-blue-500 ring-offset-1': v === currentVerse,
                                                    'bg-orange-100 border-orange-400 text-orange-700 dark:bg-orange-900/40 dark:border-orange-600 dark:text-orange-300 font-bold': v === prevVerse && v !== currentVerse,
                                                    'bg-amber-100 border-amber-300 text-amber-700 dark:bg-amber-900/40 dark:border-amber-700 dark:text-amber-400 font-bold ring-2 ring-amber-400 ring-offset-1': v === $store.versesData[surahId].mid && v !== currentVerse && v !== prevVerse,
                                                    'bg-white border-zinc-200 text-zinc-700 hover:bg-indigo-50 hover:border-indigo-300 dark:bg-zinc-900 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-indigo-900/40': v !== currentVerse && v !== prevVerse && v !== $store.versesData[surahId].mid
                                                }">
                                                <span x-text="v"></span>

                                                <template
                                                    x-if="v === $store.versesData[surahId].mid && (v === currentVerse || v === prevVerse)">
                                                    <div
                                                        class="absolute -top-1 -right-1 w-3 h-3 bg-amber-400 rounded-full border border-white dark:border-zinc-900">
                                                    </div>
                                                </template>
                                            </button>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </flux:modal>
    </div>
</div>