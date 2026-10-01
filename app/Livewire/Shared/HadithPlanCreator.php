<?php

namespace App\Livewire\Shared;

use App\Models\AcademicCalendarEvent;
use App\Models\Hadith;
use App\Models\HadithPath;
use App\Models\HadithPathDay;
use App\Models\StudentHadithAchievement;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

class HadithPlanCreator extends Component
{
    public $userRole; // 'supervisor' or 'teacher'

    // Form inputs
    public ?int $hadithPathId = null;

    #[Locked]
    public ?int $hadithTextId = null;

    public string $startDate = '';

    public string $endDate = '';

    public array $activeDays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday'];

    // Default configuration from path
    public string $memorizeType = 'hadiths'; // 'hadiths' or 'lines'

    public int $memorizeAmount = 1;

    // Preview and state
    public array $planDays = [];

    public bool $isGenerated = false;

    // Bulk fill configuration
    public string $bulkType = 'hadiths';

    public int $bulkAmount = 1;

    public bool $selectAll = false;

    public ?int $selectionStart = null;

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
            $this->hadithPathId = (int) $pathId;
            $this->updatedHadithPathId($this->hadithPathId);
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

    public function updatedHadithPathId($value): void
    {
        if ($value) {
            $path = HadithPath::findOrFail($value);
            $this->hadithTextId = $path->hadith_text_id;
            $this->memorizeType = $path->memorize_type;
            $this->memorizeAmount = $path->memorize_amount;
            $this->startDate = $path->start_date->format('Y-m-d');
            $this->endDate = $path->end_date?->format('Y-m-d') ?? '';

            $this->bulkType = $this->memorizeType;
            $this->bulkAmount = $this->memorizeAmount;

            // Load existing template days if any
            $existingDays = HadithPathDay::where('hadith_path_id', $value)->orderBy('day_number')->get();
            if ($existingDays->isNotEmpty()) {
                $this->planDays = $existingDays->map(fn ($d) => [
                    'date' => $d->date ? $d->date->toDateString() : null,
                    'day_name' => $d->day_name,
                    'memorize_type' => $d->memorize_type,
                    'memorize_amount' => $d->memorize_amount,
                    'from_hadith_id' => $d->from_hadith_id,
                    'to_hadith_id' => $d->to_hadith_id,
                    'from_line_number' => $d->from_line_number,
                    'to_line_number' => $d->to_line_number,
                    'selected' => false,
                ])->toArray();
                $this->isGenerated = true;
            } else {
                $this->isGenerated = false;
                $this->planDays = [];
            }
        } else {
            $this->reset(['hadithTextId', 'memorizeType', 'memorizeAmount', 'bulkType', 'bulkAmount', 'isGenerated', 'planDays']);
        }
    }

    public function generatePreview(): void
    {
        $this->validate([
            'hadithPathId' => 'required|exists:hadith_paths,id',
            'startDate' => 'required|date',
            'endDate' => 'nullable|date|after_or_equal:startDate',
            'activeDays' => 'required|array|min:1',
            'memorizeType' => 'required|in:hadiths,lines',
            'memorizeAmount' => 'required|integer|min:1',
        ]);

        $path = HadithPath::findOrFail($this->hadithPathId);

        // Gather all hadiths and their lines for the associated text
        $allHadiths = Hadith::with('lines')->where(function ($query) use ($path) {
            $query->where('hadith_text_id', $path->hadith_text_id)
                ->orWhereHas('chapter', function ($q) use ($path) {
                    $q->where('hadith_text_id', $path->hadith_text_id);
                });
        })->orderBy('hadith_chapter_id', 'asc')->orderBy('id', 'asc')->get();

        if ($allHadiths->isEmpty()) {
            $this->addError('hadithPathId', 'هذا المتن لا يحتوي على أي أحاديث مضافة بعد لتوليد خطة الحفظ.');

            return;
        }

        $chunks = [];
        if ($this->memorizeType === 'hadiths') {
            $count = $allHadiths->count();
            for ($i = 0; $i < $count; $i += $this->memorizeAmount) {
                $slice = $allHadiths->slice($i, $this->memorizeAmount);
                $chunks[] = [
                    'type' => 'hadiths',
                    'amount' => $this->memorizeAmount,
                    'from_hadith_id' => $slice->first()->id,
                    'to_hadith_id' => $slice->last()->id,
                    'from_line_number' => null,
                    'to_line_number' => null,
                ];
            }
        } else {
            foreach ($allHadiths as $hadith) {
                $linesCount = $hadith->lines->count();
                if ($linesCount === 0) {
                    $chunks[] = [
                        'type' => 'lines',
                        'amount' => $this->memorizeAmount,
                        'from_hadith_id' => $hadith->id,
                        'to_hadith_id' => $hadith->id,
                        'from_line_number' => 1,
                        'to_line_number' => 1,
                    ];

                    continue;
                }
                for ($i = 1; $i <= $linesCount; $i += $this->memorizeAmount) {
                    $chunks[] = [
                        'type' => 'lines',
                        'amount' => $this->memorizeAmount,
                        'from_hadith_id' => $hadith->id,
                        'to_hadith_id' => $hadith->id,
                        'from_line_number' => $i,
                        'to_line_number' => min($linesCount, $i + $this->memorizeAmount - 1),
                    ];
                }
            }
        }

        $this->planDays = [];
        $currentDate = Carbon::parse($this->startDate);
        $endDateObj = $this->endDate ? Carbon::parse($this->endDate) : null;
        $scheduledDays = 0;
        $daysCount = count($chunks);

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

        while ($scheduledDays < $daysCount) {
            // Stop at the path end date even if the text is not fully covered.
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
                $chunk = $chunks[$scheduledDays];
                $this->planDays[] = [
                    'date' => $currentDate->toDateString(),
                    'day_name' => $this->translateDay($dayOfWeek),
                    'memorize_type' => $chunk['type'],
                    'memorize_amount' => $chunk['amount'],
                    'from_hadith_id' => $chunk['from_hadith_id'],
                    'to_hadith_id' => $chunk['to_hadith_id'],
                    'from_line_number' => $chunk['from_line_number'],
                    'to_line_number' => $chunk['to_line_number'],
                    'selected' => false,
                ];
                $scheduledDays++;
            }
            $currentDate->addDay();
        }

        if (empty($this->planDays)) {
            $this->addError('endDate', 'تاريخ الانتهاء قريب جداً بحيث لا يسمح بجدولة أي يوم. وسّع المدة أو راجع أيام الأسبوع.');

            return;
        }

        $this->isGenerated = true;
    }

    public function toggleAll(): void
    {
        $this->selectAll = ! $this->selectAll;
        foreach ($this->planDays as &$day) {
            $day['selected'] = $this->selectAll;
        }
    }

    public function toggleDaySelection(int $index): void
    {
        if ($this->selectionStart === null) {
            $this->selectionStart = $index;
            $this->planDays[$index]['selected'] = ! $this->planDays[$index]['selected'];
        } else {
            $start = min($this->selectionStart, $index);
            $end = max($this->selectionStart, $index);
            $target = $this->planDays[$this->selectionStart]['selected'];

            for ($i = $start; $i <= $end; $i++) {
                $this->planDays[$i]['selected'] = $target;
            }
            $this->selectionStart = null;
        }
    }

    public function fillSelected(string $type, int $amount, array $selectedIndices): void
    {
        if (empty($selectedIndices)) {
            return;
        }

        foreach ($selectedIndices as $idx) {
            if (isset($this->planDays[$idx])) {
                $this->planDays[$idx]['memorize_type'] = $type;
                $this->planDays[$idx]['memorize_amount'] = $amount;
            }
        }

        // Cascade calculations starting from the first selected index
        $this->recalculateDaysFrom($selectedIndices[0]);

        // Reset day selection states
        foreach ($this->planDays as &$day) {
            $day['selected'] = false;
        }
        $this->selectAll = false;
    }

    public function updated(string $name, $value): void
    {
        if (preg_match('/^planDays\.(\d+)\.(memorize_type|memorize_amount|from_hadith_id|to_hadith_id|from_line_number|to_line_number)$/', $name, $matches)) {
            $index = (int) $matches[1];
            $property = $matches[2];

            // Fetch all hadiths of the current text to use in index calculations
            $allHadiths = Hadith::with('lines')->where(function ($query) {
                $query->where('hadith_text_id', $this->hadithTextId)
                    ->orWhereHas('chapter', function ($q) {
                        $q->where('hadith_text_id', $this->hadithTextId);
                    });
            })->orderBy('hadith_chapter_id', 'asc')->orderBy('id', 'asc')->get();

            if ($allHadiths->isEmpty()) {
                return;
            }

            $day = &$this->planDays[$index];

            if ($property === 'memorize_type') {
                if ($value === 'lines') {
                    $day['from_line_number'] = 1;
                    $day['review_from_hadith_id'] = null;
                    $day['review_to_hadith_id'] = null;
                    $day['review_from_line_number'] = null;
                    $day['review_to_line_number'] = null;

                    $selectedHadith = $allHadiths->firstWhere('id', $day['from_hadith_id'] ?? null);
                    $maxLines = $selectedHadith ? ($selectedHadith->lines->count() ?: 1) : 1;
                    $amount = (int) ($day['memorize_amount'] ?? 1);
                    $day['to_line_number'] = min($maxLines, $amount);
                    $day['memorize_amount'] = $day['to_line_number'];
                    $day['to_hadith_id'] = $day['from_hadith_id'] ?? null;
                } else {
                    // Changed to hadiths
                    $day['from_line_number'] = null;
                    $day['to_line_number'] = null;
                    $day['review_from_hadith_id'] = null;
                    $day['review_to_hadith_id'] = null;
                    $day['review_from_line_number'] = null;
                    $day['review_to_line_number'] = null;

                    $fromHadithId = $day['from_hadith_id'] ?? null;
                    if ($fromHadithId) {
                        $fromIdx = $allHadiths->search(fn ($h) => $h->id == $fromHadithId);
                        if ($fromIdx !== false) {
                            $amount = (int) ($day['memorize_amount'] ?? 1);
                            $toIdx = min($allHadiths->count() - 1, $fromIdx + $amount - 1);
                            $day['to_hadith_id'] = $allHadiths[$toIdx]->id;
                            $day['memorize_amount'] = $toIdx - $fromIdx + 1;
                        }
                    }
                }
            } elseif ($property === 'memorize_amount') {
                $amount = (int) $value;
                if ($amount < 1) {
                    $amount = 1;
                    $day['memorize_amount'] = 1;
                }

                if (($day['memorize_type'] ?? 'hadiths') === 'hadiths') {
                    $fromHadithId = $day['from_hadith_id'] ?? null;
                    if ($fromHadithId) {
                        $fromIdx = $allHadiths->search(fn ($h) => $h->id == $fromHadithId);
                        if ($fromIdx !== false) {
                            $toIdx = min($allHadiths->count() - 1, $fromIdx + $amount - 1);
                            $day['to_hadith_id'] = $allHadiths[$toIdx]->id;
                            $day['memorize_amount'] = $toIdx - $fromIdx + 1;
                        }
                    }
                } else {
                    $fromHadithId = $day['from_hadith_id'] ?? null;
                    $fromLineNumber = (int) ($day['from_line_number'] ?? 1);
                    if ($fromHadithId) {
                        $selectedHadith = $allHadiths->firstWhere('id', $fromHadithId);
                        $maxLines = $selectedHadith ? ($selectedHadith->lines->count() ?: 1) : 1;
                        $day['to_line_number'] = min($maxLines, $fromLineNumber + $amount - 1);
                        $day['memorize_amount'] = $day['to_line_number'] - $fromLineNumber + 1;
                        $day['to_hadith_id'] = $fromHadithId;
                    }
                }
            } elseif ($property === 'from_hadith_id') {
                $fromHadithId = $value;
                if ($fromHadithId) {
                    if (($day['memorize_type'] ?? 'hadiths') === 'hadiths') {
                        $fromIdx = $allHadiths->search(fn ($h) => $h->id == $fromHadithId);
                        if ($fromIdx !== false) {
                            $amount = (int) ($day['memorize_amount'] ?? 1);
                            $toIdx = min($allHadiths->count() - 1, $fromIdx + $amount - 1);
                            $day['to_hadith_id'] = $allHadiths[$toIdx]->id;
                            $day['memorize_amount'] = $toIdx - $fromIdx + 1;
                        }
                    } else {
                        $day['from_line_number'] = 1;
                        $selectedHadith = $allHadiths->firstWhere('id', $fromHadithId);
                        $maxLines = $selectedHadith ? ($selectedHadith->lines->count() ?: 1) : 1;
                        $amount = (int) ($day['memorize_amount'] ?? 1);
                        $day['to_line_number'] = min($maxLines, $amount);
                        $day['memorize_amount'] = $day['to_line_number'];
                        $day['to_hadith_id'] = $fromHadithId;
                    }
                }
            } elseif ($property === 'from_line_number') {
                if (($day['memorize_type'] ?? 'hadiths') === 'lines') {
                    $fromLineNumber = (int) $value;
                    $fromHadithId = $day['from_hadith_id'] ?? null;
                    if ($fromHadithId) {
                        $selectedHadith = $allHadiths->firstWhere('id', $fromHadithId);
                        $maxLines = $selectedHadith ? ($selectedHadith->lines->count() ?: 1) : 1;
                        $amount = (int) ($day['memorize_amount'] ?? 1);
                        $day['to_line_number'] = min($maxLines, $fromLineNumber + $amount - 1);
                        $day['memorize_amount'] = $day['to_line_number'] - $fromLineNumber + 1;
                    }
                }
            } elseif ($property === 'to_hadith_id') {
                if (($day['memorize_type'] ?? 'hadiths') === 'hadiths') {
                    $toHadithId = $value;
                    $fromHadithId = $day['from_hadith_id'] ?? null;
                    if ($fromHadithId && $toHadithId) {
                        $fromIdx = $allHadiths->search(fn ($h) => $h->id == $fromHadithId);
                        $toIdx = $allHadiths->search(fn ($h) => $h->id == $toHadithId);
                        if ($fromIdx !== false && $toIdx !== false) {
                            if ($toIdx >= $fromIdx) {
                                $day['memorize_amount'] = $toIdx - $fromIdx + 1;
                            } else {
                                $day['to_hadith_id'] = $fromHadithId;
                                $day['memorize_amount'] = 1;
                            }
                        }
                    }
                }
            } elseif ($property === 'to_line_number') {
                if (($day['memorize_type'] ?? 'hadiths') === 'lines') {
                    $toLineNumber = (int) $value;
                    $fromLineNumber = (int) ($day['from_line_number'] ?? 1);
                    if ($toLineNumber >= $fromLineNumber) {
                        $day['memorize_amount'] = $toLineNumber - $fromLineNumber + 1;
                    } else {
                        $day['to_line_number'] = $fromLineNumber;
                        $day['memorize_amount'] = 1;
                    }
                }
            }

            $this->recalculateDaysFrom($index);
        }
    }

    public function recalculateDaysFrom(int $startIndex): void
    {
        if (empty($this->planDays)) {
            return;
        }

        $allHadiths = Hadith::with('lines')->where(function ($query) {
            $query->where('hadith_text_id', $this->hadithTextId)
                ->orWhereHas('chapter', function ($q) {
                    $q->where('hadith_text_id', $this->hadithTextId);
                });
        })->orderBy('hadith_chapter_id', 'asc')->orderBy('id', 'asc')->get();

        if ($allHadiths->isEmpty()) {
            return;
        }

        $totalDays = count($this->planDays);

        for ($i = $startIndex; $i < $totalDays; $i++) {
            if ($i === $startIndex) {
                $fromHadithId = $this->planDays[$i]['from_hadith_id'] ?? null;
                $fromLineNumber = $this->planDays[$i]['from_line_number'] ?? 1;
            } else {
                $prevDay = $this->planDays[$i - 1];
                if (empty($prevDay['from_hadith_id'])) {
                    $this->planDays[$i]['from_hadith_id'] = null;
                    $this->planDays[$i]['to_hadith_id'] = null;
                    $this->planDays[$i]['from_line_number'] = null;
                    $this->planDays[$i]['to_line_number'] = null;

                    continue;
                }

                if ($prevDay['memorize_type'] === 'hadiths') {
                    $lastHadithId = $prevDay['to_hadith_id'];
                    $foundIdx = $allHadiths->search(fn ($h) => $h->id == $lastHadithId);
                    if ($foundIdx !== false && $foundIdx + 1 < $allHadiths->count()) {
                        $fromHadithId = $allHadiths[$foundIdx + 1]->id;
                        $fromLineNumber = 1;
                    } else {
                        $fromHadithId = null;
                        $fromLineNumber = null;
                    }
                } else {
                    $lastHadithId = $prevDay['to_hadith_id'];
                    $lastLine = $prevDay['to_line_number'] ?: 1;
                    $foundIdx = $allHadiths->search(fn ($h) => $h->id == $lastHadithId);
                    if ($foundIdx !== false) {
                        $hadith = $allHadiths[$foundIdx];
                        $maxLines = $hadith->lines->count() ?: 1;
                        if ($lastLine >= $maxLines) {
                            if ($foundIdx + 1 < $allHadiths->count()) {
                                $fromHadithId = $allHadiths[$foundIdx + 1]->id;
                                $fromLineNumber = 1;
                            } else {
                                $fromHadithId = null;
                                $fromLineNumber = null;
                            }
                        } else {
                            $fromHadithId = $hadith->id;
                            $fromLineNumber = $lastLine + 1;
                        }
                    } else {
                        $fromHadithId = null;
                        $fromLineNumber = null;
                    }
                }

                $this->planDays[$i]['from_hadith_id'] = $fromHadithId;
                $this->planDays[$i]['from_line_number'] = $fromLineNumber;
            }

            if (empty($fromHadithId)) {
                $this->planDays[$i]['from_hadith_id'] = null;
                $this->planDays[$i]['to_hadith_id'] = null;
                $this->planDays[$i]['from_line_number'] = null;
                $this->planDays[$i]['to_line_number'] = null;

                continue;
            }

            $dayType = $this->planDays[$i]['memorize_type'] ?? 'hadiths';
            $dayAmount = (int) ($this->planDays[$i]['memorize_amount'] ?? 1);
            if ($dayAmount < 1) {
                $dayAmount = 1;
            }

            $currentHadithIndex = $allHadiths->search(fn ($h) => $h->id == $fromHadithId);
            if ($currentHadithIndex === false) {
                $this->planDays[$i]['from_hadith_id'] = null;
                $this->planDays[$i]['to_hadith_id'] = null;
                $this->planDays[$i]['from_line_number'] = null;
                $this->planDays[$i]['to_line_number'] = null;

                continue;
            }

            if ($dayType === 'hadiths') {
                $toIdx = min($allHadiths->count() - 1, $currentHadithIndex + $dayAmount - 1);
                $this->planDays[$i]['to_hadith_id'] = $allHadiths[$toIdx]->id;
                $this->planDays[$i]['from_line_number'] = null;
                $this->planDays[$i]['to_line_number'] = null;
            } else {
                $hadith = $allHadiths[$currentHadithIndex];
                $maxLines = $hadith->lines->count() ?: 1;
                $fromLineNumber = (int) $fromLineNumber;
                $toLine = min($maxLines, $fromLineNumber + $dayAmount - 1);

                $this->planDays[$i]['to_hadith_id'] = $hadith->id;
                $this->planDays[$i]['from_line_number'] = $fromLineNumber;
                $this->planDays[$i]['to_line_number'] = $toLine;
            }
        }
    }

    public function resetPlan(): void
    {
        $this->isGenerated = false;
        $this->planDays = [];
    }

    public bool $confirmingDeletion = false;

    public int $affectedAchievementsCount = 0;

    public int $affectedFromDayNumber = 0;

    public function savePlan(): void
    {
        $this->validate([
            'hadithPathId' => 'required|exists:hadith_paths,id',
            'startDate' => 'required|date',
            'endDate' => 'nullable|date|after_or_equal:startDate',
        ]);

        if (empty($this->planDays)) {
            $this->addError('planDays', 'لا توجد أيام خطة للحفظ.');

            return;
        }

        $path = HadithPath::findOrFail($this->hadithPathId);

        // Check for existing achievements that may be affected by changes
        $oldDays = HadithPathDay::where('hadith_path_id', $this->hadithPathId)
            ->orderBy('day_number')
            ->get();

        if ($oldDays->isNotEmpty() && ! $this->confirmingDeletion) {
            $firstChangedDayNumber = $this->findFirstChangedDay($oldDays);

            if ($firstChangedDayNumber !== null) {
                // Check if there are achievements on or after the changed day
                $affectedDayIds = $oldDays->where('day_number', '>=', $firstChangedDayNumber)->pluck('id');
                $affectedCount = StudentHadithAchievement::whereIn('hadith_path_day_id', $affectedDayIds)
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
        $path = HadithPath::findOrFail($this->hadithPathId);
        $oldDays = HadithPathDay::where('hadith_path_id', $this->hadithPathId)
            ->orderBy('day_number')
            ->get();

        // Delete achievements from the affected day onwards
        $affectedDayIds = $oldDays->where('day_number', '>=', $this->affectedFromDayNumber)->pluck('id');
        StudentHadithAchievement::whereIn('hadith_path_day_id', $affectedDayIds)->delete();

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

    private function performSave(HadithPath $path, $oldDays): void
    {
        // 1. Update HadithPath start_date, end_date and parameters
        $path->update([
            'start_date' => $this->startDate,
            'end_date' => $this->endDate ?: null,
            'memorize_type' => $this->memorizeType,
            'memorize_amount' => $this->memorizeAmount,
        ]);

        // 2. Save HadithPathDay template records
        // Instead of deleting all days (which cascades and deletes all student achievements),
        // we keep the days that are completely unaffected (before the first changed day number).
        $affectedFrom = $this->affectedFromDayNumber ?: (count($this->planDays) + 1);

        // Delete affected days and any extra days
        HadithPathDay::where('hadith_path_id', $this->hadithPathId)
            ->where('day_number', '>=', $affectedFrom)
            ->delete();

        $newCount = count($this->planDays);
        HadithPathDay::where('hadith_path_id', $this->hadithPathId)
            ->where('day_number', '>', $newCount)
            ->delete();

        foreach ($this->planDays as $index => $day) {
            $dayNumber = $index + 1;
            HadithPathDay::updateOrCreate(
                [
                    'hadith_path_id' => $this->hadithPathId,
                    'day_number' => $dayNumber,
                ],
                [
                    'date' => $day['date'] ?? null,
                    'day_name' => $day['day_name'] ?? null,
                    'memorize_type' => $day['memorize_type'],
                    'memorize_amount' => $day['memorize_amount'],
                    'from_hadith_id' => $day['from_hadith_id'] ?? null,
                    'to_hadith_id' => $day['to_hadith_id'] ?? null,
                    'from_line_number' => $day['from_line_number'] ?? null,
                    'to_line_number' => $day['to_line_number'] ?? null,
                    'review_from_hadith_id' => $day['review_from_hadith_id'] ?? null,
                    'review_to_hadith_id' => $day['review_to_hadith_id'] ?? null,
                    'review_from_line_number' => $day['review_from_line_number'] ?? null,
                    'review_to_line_number' => $day['review_to_line_number'] ?? null,
                ]
            );
        }

        Flux::toast('تم حفظ خطة المسار بنجاح', variant: 'success');

        if ($this->userRole === 'supervisor') {
            $this->redirectRoute('supervisor.hadiths.paths');
        } else {
            $this->redirectRoute('teacher.dashboard');
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
        $oldDaysCount = $oldDays->count();

        foreach ($oldDays as $index => $oldDay) {
            // If the new plan has fewer days, everything from here onwards changed
            if ($index >= $newDaysCount) {
                return $oldDay->day_number;
            }

            $newDay = $this->planDays[$index];

            // Compare relevant fields
            if (
                $oldDay->memorize_type !== ($newDay['memorize_type'] ?? null) ||
                $oldDay->memorize_amount !== (int) ($newDay['memorize_amount'] ?? 0) ||
                $oldDay->from_hadith_id !== ($newDay['from_hadith_id'] ?? null) ||
                $oldDay->to_hadith_id !== ($newDay['to_hadith_id'] ?? null) ||
                $oldDay->from_line_number !== ($newDay['from_line_number'] ?? null) ||
                $oldDay->to_line_number !== ($newDay['to_line_number'] ?? null)
            ) {
                return $oldDay->day_number;
            }
        }

        return null;
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
        $paths = HadithPath::with('text')->orderBy('name')->get();

        // Fetch all hadiths of the selected text for row dropdown selection in blade
        $hadiths = collect();
        if ($this->hadithTextId) {
            $hadiths = Hadith::with('lines')->where(function ($query) {
                $query->where('hadith_text_id', $this->hadithTextId)
                    ->orWhereHas('chapter', function ($q) {
                        $q->where('hadith_text_id', $this->hadithTextId);
                    });
            })->orderBy('hadith_chapter_id', 'asc')->orderBy('id', 'asc')->get();
        }

        return view('livewire.shared.hadith-plan-creator', [
            'paths' => $paths,
            'hadiths' => $hadiths,
        ]);
    }
}
