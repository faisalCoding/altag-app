<?php

use Livewire\Component;
use Livewire\Attributes\Modelable;

new class extends Component {
    #[Modelable]
    public $date;

    public $label;
    public $placeholder;
    public $buttonClass = "w-full flex items-center justify-between text-right bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 shadow-sm rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500";
    public $currentViewTimestamp;
    public $open = false;
    public $showEvents = false;
    public $showAttendanceDays = false;

    /**
     * Days the caller will actually accept, as 1=Sunday … 7=Saturday. Empty
     * means every weekday. Days outside it are shown dimmed and refuse the tap,
     * so a picker never offers a date the page behind it will reject.
     */
    public $allowedWeekdays = [];

    public $minDate = null;
    public $maxDate = null;

    public function mount($label = 'التاريخ (هجري)', $placeholder = 'حدد التاريخ', $buttonClass = null, $showEvents = false, $showAttendanceDays = false, $allowedWeekdays = [], $minDate = null, $maxDate = null)
    {
        $this->label = $label;
        $this->placeholder = $placeholder;
        $this->showEvents = $showEvents;
        $this->showAttendanceDays = $showAttendanceDays;
        $this->allowedWeekdays = array_map('intval', $allowedWeekdays ?? []);
        $this->minDate = $minDate;
        $this->maxDate = $maxDate;
        if ($buttonClass) {
            $this->buttonClass = $buttonClass;
        }

        $viewDate = $this->date ?: now()->format('Y-m-d');

        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->setTime(strtotime($viewDate) * 1000);
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $this->currentViewTimestamp = $cal->getTime() / 1000;
    }

    public function getHijriFormattedDateProperty()
    {
        if (!$this->date)
            return '';
        return \App\Support\HijriDate::full($this->date);
    }

    public function previousMonth()
    {
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->setTime($this->currentViewTimestamp * 1000);
        $cal->add(\IntlCalendar::FIELD_MONTH, -1);
        $this->currentViewTimestamp = $cal->getTime() / 1000;
    }

    public function nextMonth()
    {
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->setTime($this->currentViewTimestamp * 1000);
        $cal->add(\IntlCalendar::FIELD_MONTH, 1);
        $this->currentViewTimestamp = $cal->getTime() / 1000;
    }

    /**
     * Whether this picker will hand a date back.
     */
    public function allows($gregorianDate): bool
    {
        if ($this->minDate && $gregorianDate < $this->minDate) {
            return false;
        }

        if ($this->maxDate && $gregorianDate > $this->maxDate) {
            return false;
        }

        if ($this->allowedWeekdays === []) {
            return true;
        }

        return in_array((int) date('w', strtotime($gregorianDate)) + 1, $this->allowedWeekdays, true);
    }

    public function selectDate($gregorianDate)
    {
        if (! $this->allows($gregorianDate)) {
            return;
        }

        $this->date = $gregorianDate;
        $this->open = false;

        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->setTime(strtotime($this->date) * 1000);
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $this->currentViewTimestamp = $cal->getTime() / 1000;
    }

    public function clearDate()
    {
        $this->date = null;
        $this->open = false;
    }
};
?>

<div class="relative" x-data="{ open: @entangle('open') }" @click.away="open = false">
    <button @click="open = !open" type="button" class="{{ $buttonClass }}">
        <div class="flex flex-col text-right">
            @if($label)
                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</span>
            @endif
            <span class="{{ $label ? 'font-medium mt-0.5' : 'text-sm' }} text-zinc-900 dark:text-zinc-100">
                {{ $this->hijriFormattedDate ?: $placeholder }}
            </span>
        </div>
        <flux:icon icon="calendar" class="size-5 text-zinc-400" />
    </button>

    <div x-show="open" x-transition style="display: none;"
        class="absolute z-[100] top-full mt-2 w-72 bg-white dark:bg-zinc-900 rounded-xl shadow-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden"
        dir="rtl">

        <div
            class="flex items-center justify-between px-4 py-3 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/50">
            <button wire:click="previousMonth" type="button"
                class="p-1.5 rounded-md hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-500">
                <flux:icon icon="chevron-right" class="size-5" />
            </button>
            <div class="font-bold text-zinc-800 dark:text-zinc-100 text-sm">
                {{ \App\Support\HijriDate::monthYear($currentViewTimestamp) }}
            </div>
            <button wire:click="nextMonth" type="button"
                class="p-1.5 rounded-md hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-500">
                <flux:icon icon="chevron-left" class="size-5" />
            </button>
        </div>

        <div class="grid grid-cols-7 gap-1 px-2 pt-3 pb-1 text-center">
            @foreach(['أحد', 'إثن', 'ثلا', 'أرب', 'خمي', 'جمعة', 'سبت'] as $day)
                <div class="text-[0.6rem] font-bold text-zinc-400 dark:text-zinc-500">{{ $day }}</div>
            @endforeach
        </div>

        <div class="grid grid-cols-7 gap-1 px-2 pb-2">
            @php
                $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
                $cal->setTime($currentViewTimestamp * 1000);
                $monthLength = $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH);
                $startDayOfWeek = $cal->get(\IntlCalendar::FIELD_DAY_OF_WEEK);
                $emptySlots = $startDayOfWeek - 1;

                $allEvents = collect();
                if ($showEvents && auth()->check()) {
                    $allEvents = \App\Models\AcademicCalendarEvent::visibleTo(auth()->user())->get();
                }

                $attendancePeriods = collect();
                if ($showAttendanceDays) {
                    $attendancePeriods = \App\Models\AcademicCalendarEvent::where('is_attendance_period', true)->get();
                }
            @endphp

            @for ($i = 0; $i < $emptySlots; $i++)
                <div></div>
            @endfor

            @for ($i = 1; $i <= $monthLength; $i++)
                @php
                    $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $i);
                    $dayOfWeek = $cal->get(\IntlCalendar::FIELD_DAY_OF_WEEK);
                    $gregDate = date('Y-m-d', $cal->getTime() / 1000);
                    $isSelected = $gregDate === $date;
                    $isToday = $gregDate === date('Y-m-d');
                    $isAllowed = $this->allows($gregDate);
                    $isRestricted = $allowedWeekdays !== [] || $minDate || $maxDate;

                    $isWorkingDay = false;
                    if ($showAttendanceDays) {
                        if ($attendancePeriods->isNotEmpty()) {
                            $period = $attendancePeriods->first(function ($p) use ($gregDate) {
                                return $gregDate >= $p->start_date->format('Y-m-d') &&
                                    $gregDate <= $p->end_date->format('Y-m-d');
                            });
                            if ($period && $period->weekdays) {
                                $isWorkingDay = in_array($dayOfWeek, $period->weekdays);
                            }
                        } else {
                            $isWorkingDay = in_array($dayOfWeek, [1, 2, 3, 4, 5]); // Sun-Thu fallback
                        }
                    }

                    $dayEvents = collect();
                    if ($showEvents) {
                        $dayEvents = $allEvents->filter(function ($event) use ($gregDate, $dayOfWeek) {
                            $dateMatch = $gregDate >= $event->start_date->format('Y-m-d') &&
                                $gregDate <= $event->end_date->format('Y-m-d');
                            if (!$dateMatch)
                                return false;
                            if ($event->is_attendance_period && $event->weekdays) {
                                return in_array($dayOfWeek, $event->weekdays);
                            }
                            return true;
                        });
                    }
                @endphp
                <button wire:click="selectDate('{{ $gregDate }}')" type="button" @disabled(! $isAllowed)
                    class="relative h-8 w-8 flex flex-col items-center justify-center rounded-lg text-xs
                                {{ $isSelected
                                    ? 'bg-indigo-500 text-white font-bold'
                                    : (! $isAllowed
                                        ? 'text-zinc-300 dark:text-zinc-700 cursor-not-allowed line-through decoration-1'
                                        : ($isRestricted
                                            ? 'bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/30 dark:hover:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300 font-bold ring-1 ring-emerald-300 dark:ring-emerald-800'
                                            : ($isToday
                                                ? 'border border-indigo-300 text-indigo-600'
                                                : ($isWorkingDay
                                                    ? 'bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/20 dark:hover:bg-emerald-900/30 text-emerald-800 dark:text-emerald-300 font-medium'
                                                    : 'hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-700 dark:text-zinc-300')))) }}">
                    <span class="{{ $showEvents && $dayEvents->isNotEmpty() ? '-mt-1' : '' }}">{{ $i }}</span>

                    @if($isWorkingDay && !$isSelected)
                        <span class="absolute top-0.5 right-0.5 size-1 rounded-full bg-emerald-500" title="{{ __('يوم دوام معتمد') }}"></span>
                    @endif

                    @if($showEvents && $dayEvents->isNotEmpty())
                        <div class="absolute bottom-1 flex justify-center gap-[1px] w-full px-1">
                            @foreach($dayEvents->take(3) as $ev)
                                <div
                                    class="w-1 h-1 rounded-full {{ ['indigo' => 'bg-indigo-500', 'blue' => 'bg-blue-500', 'green' => 'bg-green-500', 'emerald' => 'bg-emerald-500', 'zinc' => 'bg-zinc-500', 'orange' => 'bg-orange-500', 'sky' => 'bg-sky-500', 'rose' => 'bg-rose-500', 'amber' => 'bg-amber-500', 'teal' => 'bg-teal-500', 'red' => 'bg-red-500', 'lime' => 'bg-lime-500'][$ev->color ?? 'indigo'] ?? 'bg-indigo-500' }}">
                                </div>
                            @endforeach
                            @if($dayEvents->count() > 3)
                                <div class="w-1 h-1 rounded-full bg-zinc-400"></div>
                            @endif
                        </div>
                    @endif
                </button>
            @endfor
        </div>

        <div class="p-2 border-t border-zinc-100 dark:border-zinc-800 flex justify-between gap-2">
            <button wire:click="clearDate" type="button"
                class="flex-1 py-1.5 text-xs font-medium text-zinc-500 hover:bg-zinc-50 dark:hover:bg-zinc-900/20 rounded-lg   s">
                {{ __('مسح') }}
            </button>
            <button @click="open = false" type="button"
                class="flex-1 py-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 rounded-lg   s">
                {{ __('إغلاق') }}
            </button>
        </div>
    </div>
</div>