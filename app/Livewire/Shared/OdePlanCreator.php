<?php

namespace App\Livewire\Shared;

use App\Models\AcademicCalendarEvent;
use App\Models\OdePath;
use App\Models\OdePathDay;
use App\Models\OdeVerse;
use App\Models\StudentOdeAchievement;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OdePlanCreator extends Component
{
    public $userRole; // 'supervisor' or 'teacher'

    // Form inputs
    public ?int $odePathId = null;

    #[Locked]
    public ?int $odeId = null;

    public string $startDate = '';

    public string $endDate = '';

    public array $activeDays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday'];

    // Hifz configuration
    public int $hifzStart = 1;

    public int $hifzEnd = 1;

    public int $hifzRate = 5;

    // Review configuration (optional)
    public bool $hasReview = false;

    public int $reviewStart = 1;

    public int $reviewEnd = 1;

    public int $reviewRate = 10;

    // Preview and state
    public array $planDays = [];

    public bool $isGenerated = false;

    // Destructive-edit protection
    public bool $confirmingDeletion = false;

    public int $affectedAchievementsCount = 0;

    public int $affectedFromDayNumber = 0;

    public function mount(): void
    {
        if (auth()->guard('supervisor')->check()) {
            $this->userRole = 'supervisor';
        } else {
            $this->userRole = 'teacher';
        }

        $this->startDate = now()->format('Y-m-d');

        $pathId = request()->query('path_id');
        if ($pathId) {
            $this->odePathId = (int) $pathId;
            $this->updatedOdePathId($this->odePathId);
        }

        $this->autoFillActiveDays();
    }

    public function autoFillActiveDays(): void
    {
        $startDateObj = Carbon::parse($this->startDate ?: now());
        $attendancePeriod = AcademicCalendarEvent::where('is_attendance_period', true)
            ->where('start_date', '<=', $startDateObj->format('Y-m-d'))
            ->where('end_date', '>=', $startDateObj->format('Y-m-d'))
            ->first();

        if ($attendancePeriod && ! empty($attendancePeriod->weekdays)) {
            $mapping = [
                1 => 'Sunday',
                2 => 'Monday',
                3 => 'Tuesday',
                4 => 'Wednesday',
                5 => 'Thursday',
                6 => 'Friday',
                7 => 'Saturday',
            ];

            $this->activeDays = array_map(fn ($wd) => $mapping[$wd], $attendancePeriod->weekdays);
        }
    }

    public function updatedOdePathId($value): void
    {
        if ($value) {
            $path = OdePath::findOrFail($value);
            $this->odeId = $path->ode_id;
            $this->startDate = $path->start_date->format('Y-m-d');
            $this->endDate = $path->end_date?->format('Y-m-d') ?? '';

            $maxVerse = OdeVerse::where('ode_id', $this->odeId)->max('verse_number') ?: 1;
            $this->hifzStart = 1;
            $this->hifzEnd = $maxVerse;
            $this->reviewStart = 1;
            $this->reviewEnd = $maxVerse;

            // Load existing template days if any
            $existingDays = OdePathDay::where('ode_path_id', $value)->orderBy('day_number')->get();
            if ($existingDays->isNotEmpty()) {
                $this->hasReview = $existingDays->contains(fn ($d) => $d->review_from_verse_number !== null);
                $this->planDays = $existingDays->map(fn ($d) => [
                    'date' => $d->date ? $d->date->toDateString() : null,
                    'day_name' => $d->day_name,
                    'from_verse_number' => $d->from_verse_number,
                    'to_verse_number' => $d->to_verse_number,
                    'review_from_verse_number' => $d->review_from_verse_number,
                    'review_to_verse_number' => $d->review_to_verse_number,
                ])->toArray();
                $this->isGenerated = true;
            } else {
                $this->isGenerated = false;
                $this->planDays = [];
            }
        } else {
            $this->reset(['odeId', 'isGenerated', 'planDays', 'hasReview']);
        }
    }

    public function generatePreview(): void
    {
        $this->validate([
            'odePathId' => 'required|exists:ode_paths,id',
            'startDate' => 'required|date',
            'endDate' => 'nullable|date|after_or_equal:startDate',
            'activeDays' => 'required|array|min:1',
            'hifzStart' => 'required|integer|min:1',
            'hifzEnd' => 'required|integer|gte:hifzStart',
            'hifzRate' => 'required|integer|min:1',
            'reviewStart' => 'required_if:hasReview,true|nullable|integer|min:1',
            'reviewEnd' => 'required_if:hasReview,true|nullable|integer|gte:reviewStart',
            'reviewRate' => 'required_if:hasReview,true|nullable|integer|min:1',
        ]);

        $maxVerse = OdeVerse::where('ode_id', $this->odeId)->max('verse_number') ?: 1;

        if ($this->hifzEnd > $maxVerse) {
            $this->addError('hifzEnd', "نهاية الحفظ لا يمكن أن تتجاوز عدد أبيات المنظومة وهو {$maxVerse} بيتاً.");

            return;
        }

        if ($this->hasReview && $this->reviewEnd > $maxVerse) {
            $this->addError('reviewEnd', "نهاية المراجعة لا يمكن أن تتجاوز عدد أبيات المنظومة وهو {$maxVerse} بيتاً.");

            return;
        }

        // Calculate total days needed
        $hifzTotalVerses = $this->hifzEnd - $this->hifzStart + 1;
        $hifzDays = (int) ceil($hifzTotalVerses / $this->hifzRate);

        $daysNeeded = $hifzDays;

        if ($this->hasReview) {
            $reviewTotalVerses = $this->reviewEnd - $this->reviewStart + 1;
            $reviewDays = (int) ceil($reviewTotalVerses / $this->reviewRate);
            $daysNeeded = max($hifzDays, $reviewDays);
        }

        $this->planDays = [];
        $currentDate = Carbon::parse($this->startDate);
        $endDateObj = $this->endDate ? Carbon::parse($this->endDate) : null;
        $count = 0;

        $attendancePeriods = AcademicCalendarEvent::where('is_attendance_period', true)
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

        while ($count < $daysNeeded) {
            // Stop at the path end date even if the ode is not fully covered.
            if ($endDateObj && $currentDate->gt($endDateObj)) {
                break;
            }

            $dayOfWeek = $currentDate->format('l');
            $dateStr = $currentDate->toDateString();

            // A path is followed by students of any stage, so it is laid out on
            // the academy-wide calendar: its closures and extra days apply, a
            // stage's own do not.
            $isValid = in_array($dayOfWeek, $this->activeDays)
                && AcademicCalendarEvent::isSchedulable($dateStr);

            if ($isValid) {
                // Calculate Hifz Range for this day
                $hifzFrom = $this->hifzStart + ($count * $this->hifzRate);
                $hifzTo = min($this->hifzEnd, $hifzFrom + $this->hifzRate - 1);

                if ($hifzFrom > $this->hifzEnd) {
                    $hifzFrom = null;
                    $hifzTo = null;
                }

                // Calculate Review Range for this day
                $reviewFrom = null;
                $reviewTo = null;

                if ($this->hasReview) {
                    $reviewFrom = $this->reviewStart + ($count * $this->reviewRate);
                    $reviewTo = min($this->reviewEnd, $reviewFrom + $this->reviewRate - 1);

                    if ($reviewFrom > $this->reviewEnd) {
                        $reviewFrom = null;
                        $reviewTo = null;
                    }
                }

                $this->planDays[] = [
                    'date' => $currentDate->toDateString(),
                    'day_name' => $this->translateDay($dayOfWeek),
                    'from_verse_number' => $hifzFrom,
                    'to_verse_number' => $hifzTo,
                    'review_from_verse_number' => $reviewFrom,
                    'review_to_verse_number' => $reviewTo,
                ];
                $count++;
            }
            $currentDate->addDay();
        }

        if (empty($this->planDays)) {
            $this->addError('endDate', 'تاريخ الانتهاء قريب جداً بحيث لا يسمح بجدولة أي يوم. وسّع المدة أو راجع أيام الأسبوع.');

            return;
        }

        $this->isGenerated = true;
    }

    public function resetPlan(): void
    {
        $this->isGenerated = false;
        $this->planDays = [];
    }

    public function savePlan(): void
    {
        $this->validate([
            'odePathId' => 'required|exists:ode_paths,id',
            'startDate' => 'required|date',
            'endDate' => 'nullable|date|after_or_equal:startDate',
        ]);

        if (empty($this->planDays)) {
            $this->addError('planDays', 'لا توجد أيام خطة للحفظ.');

            return;
        }

        $path = OdePath::findOrFail($this->odePathId);

        // Check for existing achievements that may be affected by changes
        $oldDays = OdePathDay::where('ode_path_id', $this->odePathId)
            ->orderBy('day_number')
            ->get();

        if ($oldDays->isNotEmpty() && ! $this->confirmingDeletion) {
            $firstChangedDayNumber = $this->findFirstChangedDay($oldDays);

            if ($firstChangedDayNumber !== null) {
                // Check if there are achievements on or after the changed day
                $affectedDayIds = $oldDays->where('day_number', '>=', $firstChangedDayNumber)->pluck('id');
                $affectedCount = StudentOdeAchievement::whereIn('ode_path_day_id', $affectedDayIds)
                    ->count();

                if ($affectedCount > 0) {
                    $this->affectedAchievementsCount = $affectedCount;
                    $this->affectedFromDayNumber = $firstChangedDayNumber;
                    $this->confirmingDeletion = true;
                    Flux::modal('confirm-delete-achievements')->show();

                    return;
                }
            }
        }

        $this->performSave($path, $oldDays);
    }

    public function confirmSaveWithDeletion(): void
    {
        $path = OdePath::findOrFail($this->odePathId);
        $oldDays = OdePathDay::where('ode_path_id', $this->odePathId)
            ->orderBy('day_number')
            ->get();

        // Delete achievements from the affected day onwards
        $affectedDayIds = $oldDays->where('day_number', '>=', $this->affectedFromDayNumber)->pluck('id');
        StudentOdeAchievement::whereIn('ode_path_day_id', $affectedDayIds)->delete();

        $this->confirmingDeletion = false;
        $this->affectedAchievementsCount = 0;
        $this->affectedFromDayNumber = 0;
        Flux::modal('confirm-delete-achievements')->close();

        $this->performSave($path, $oldDays);
    }

    public function cancelSave(): void
    {
        $this->confirmingDeletion = false;
        $this->affectedAchievementsCount = 0;
        $this->affectedFromDayNumber = 0;
        Flux::modal('confirm-delete-achievements')->close();
    }

    private function performSave(OdePath $path, $oldDays): void
    {
        // 1. Update OdePath start_date and end_date
        $path->update([
            'start_date' => $this->startDate,
            'end_date' => $this->endDate ?: null,
        ]);

        // 2. Save OdePathDay template records.
        // Instead of deleting all days (which cascades and deletes all student achievements),
        // we keep the days that are completely unaffected (before the first changed day number).
        $affectedFrom = $this->affectedFromDayNumber ?: (count($this->planDays) + 1);

        // Delete affected days and any extra days
        OdePathDay::where('ode_path_id', $this->odePathId)
            ->where('day_number', '>=', $affectedFrom)
            ->delete();

        $newCount = count($this->planDays);
        OdePathDay::where('ode_path_id', $this->odePathId)
            ->where('day_number', '>', $newCount)
            ->delete();

        foreach ($this->planDays as $index => $day) {
            $dayNumber = $index + 1;
            OdePathDay::updateOrCreate(
                [
                    'ode_path_id' => $this->odePathId,
                    'day_number' => $dayNumber,
                ],
                [
                    'date' => $day['date'] ?? null,
                    'day_name' => $day['day_name'] ?? null,
                    'from_verse_number' => $day['from_verse_number'] ?? null,
                    'to_verse_number' => $day['to_verse_number'] ?? null,
                    'review_from_verse_number' => $day['review_from_verse_number'] ?? null,
                    'review_to_verse_number' => $day['review_to_verse_number'] ?? null,
                ]
            );
        }

        Flux::toast('تم حفظ خطة المسار بنجاح', variant: 'success');

        if ($this->userRole === 'supervisor') {
            $this->redirectRoute('supervisor.odes.paths');
        } else {
            $this->redirectRoute('teacher.ode-plans');
        }
    }

    /**
     * Compare old and new plan days to find the first day number that changed.
     *
     * @return int|null Day number of first change, or null if no change
     */
    private function findFirstChangedDay($oldDays): ?int
    {
        $newDaysCount = count($this->planDays);

        foreach ($oldDays as $index => $oldDay) {
            // If the new plan has fewer days, everything from here onwards changed
            if ($index >= $newDaysCount) {
                return $oldDay->day_number;
            }

            $newDay = $this->planDays[$index];

            // Compare relevant fields
            if (
                $oldDay->from_verse_number !== $this->nullableInt($newDay['from_verse_number'] ?? null) ||
                $oldDay->to_verse_number !== $this->nullableInt($newDay['to_verse_number'] ?? null) ||
                $oldDay->review_from_verse_number !== $this->nullableInt($newDay['review_from_verse_number'] ?? null) ||
                $oldDay->review_to_verse_number !== $this->nullableInt($newDay['review_to_verse_number'] ?? null)
            ) {
                return $oldDay->day_number;
            }
        }

        return null;
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function translateDay(string $day): string
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

    public function render()
    {
        $paths = OdePath::with('ode')->orderBy('name')->get();

        return view('livewire.shared.ode-plan-creator', [
            'paths' => $paths,
        ]);
    }
}
