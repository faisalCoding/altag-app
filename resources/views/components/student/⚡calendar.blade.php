<?php

use App\Models\Attendance;
use App\Models\StudentExam;
use App\Models\StudentPlanDay;
use App\Services\MemorizationJourneyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public int $month;

    public int $year;

    public ?string $selectedDate = null;

    protected const ARABIC_MONTHS = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو',
        7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
    ];

    public function mount(): void
    {
        $this->month = (int) now()->format('n');
        $this->year = (int) now()->format('Y');
        $this->selectedDate = now()->toDateString();
    }

    public function previousMonth(): void
    {
        $date = Carbon::create($this->year, $this->month, 1)->subMonth();
        $this->month = (int) $date->format('n');
        $this->year = (int) $date->format('Y');
    }

    public function nextMonth(): void
    {
        $date = Carbon::create($this->year, $this->month, 1)->addMonth();
        $this->month = (int) $date->format('n');
        $this->year = (int) $date->format('Y');
    }

    public function selectDay(string $date): void
    {
        $this->selectedDate = $date;
    }

    public function with(): array
    {
        $student = Auth::guard('student')->user();
        $activityDates = MemorizationJourneyService::activityDatesForMonth($student, $this->year, $this->month);

        $firstOfMonth = Carbon::create($this->year, $this->month, 1);
        $daysInMonth = $firstOfMonth->daysInMonth;
        $leadingBlanks = (int) $firstOfMonth->copy()->startOfWeek(Carbon::SATURDAY)->diffInDays($firstOfMonth);

        $dayDetail = null;
        if ($this->selectedDate) {
            $attendance = Attendance::where('student_id', $student->id)->whereDate('date', $this->selectedDate)->first();

            $planDays = StudentPlanDay::with(['fromAyah.surah', 'toAyah.surah', 'reviewFromAyah.surah', 'reviewToAyah.surah'])
                ->whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
                ->whereDate('date', $this->selectedDate)
                ->get();

            $exams = StudentExam::with('examLevel')
                ->where('student_id', $student->id)
                ->whereDate('date_time', $this->selectedDate)
                ->get();

            $dayDetail = [
                'attendance' => $attendance,
                'planDays' => $planDays,
                'exams' => $exams,
            ];
        }

        return [
            'monthLabel' => self::ARABIC_MONTHS[$this->month].' '.$this->year,
            'daysInMonth' => $daysInMonth,
            'leadingBlanks' => $leadingBlanks,
            'activityDates' => $activityDates,
            'todayStr' => now()->toDateString(),
            'yearMonth' => sprintf('%04d-%02d', $this->year, $this->month),
            'dayDetail' => $dayDetail,
        ];
    }
};
?>

<div class="space-y-6" dir="rtl">
    <div>
        <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">
            {{ __('التقويم') }}
        </flux:heading>
        <flux:subheading class="text-zinc-500 dark:text-zinc-400 mt-1">
            {{ __('اضغط على أي يوم لعرض تفاصيله: الحضور، الحفظ، المراجعة، والاختبارات.') }}
        </flux:subheading>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[1fr_380px] gap-6 items-start">
        <flux:card>
            <div class="flex items-center justify-between mb-4">
                <button wire:click="previousMonth" class="p-2 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800">
                    <flux:icon icon="chevron-right" class="size-5 text-zinc-400" />
                </button>
                <span class="font-bold text-lg text-zinc-800 dark:text-zinc-100">{{ $monthLabel }}</span>
                <button wire:click="nextMonth" class="p-2 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800">
                    <flux:icon icon="chevron-left" class="size-5 text-zinc-400" />
                </button>
            </div>

            <div class="grid grid-cols-7 gap-1 text-center text-xs text-zinc-400 mb-2">
                @foreach(['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'] as $dayLabel)
                    <div>{{ $dayLabel }}</div>
                @endforeach
            </div>

            <div class="grid grid-cols-7 gap-1 text-center text-sm">
                @for($i = 0; $i < $leadingBlanks; $i++)
                    <div></div>
                @endfor
                @for($day = 1; $day <= $daysInMonth; $day++)
                    @php
                        $dateStr = $yearMonth.'-'.str_pad($day, 2, '0', STR_PAD_LEFT);
                        $isToday = $dateStr === $todayStr;
                        $isSelected = $dateStr === $selectedDate;
                        $hasActivity = in_array($dateStr, $activityDates, true);
                    @endphp
                    <button
                        wire:click="selectDay('{{ $dateStr }}')"
                        wire:key="day-{{ $dateStr }}"
                        class="relative flex flex-col items-center justify-center h-11 rounded-lg transition-colors
                            {{ $isSelected ? 'bg-maroon text-white font-bold' : ($isToday ? 'bg-maroon/10 text-maroon dark:text-red-secondary font-bold' : 'text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800') }}">
                        {{ $day }}
                        @if($hasActivity && !$isSelected)
                            <span class="absolute bottom-1 size-1 rounded-full bg-emerald-500"></span>
                        @endif
                    </button>
                @endfor
            </div>
        </flux:card>

        <flux:card>
            @if($selectedDate)
                <flux:heading size="sm" class="mb-4">
                    <x-hijri-date :date="\Carbon\Carbon::parse($selectedDate)" style="weekday" />
                </flux:heading>

                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('الحضور') }}</span>
                        @php
                            $attStatus = $dayDetail['attendance']?->status;
                            $attDetails = match($attStatus) {
                                'present' => ['color' => 'emerald', 'label' => 'حاضر'],
                                'absent' => ['color' => 'red', 'label' => 'غائب'],
                                'late' => ['color' => 'amber', 'label' => 'متأخر'],
                                'excused' => ['color' => 'blue', 'label' => 'مستأذن'],
                                default => ['color' => 'zinc', 'label' => 'غير مسجل'],
                            };
                        @endphp
                        <flux:badge :color="$attDetails['color']" size="sm">{{ __($attDetails['label']) }}</flux:badge>
                    </div>

                    @forelse($dayDetail['planDays'] as $planDay)
                        @if($planDay->fromAyah && $planDay->toAyah)
                            <div class="flex items-center justify-between border-t border-zinc-50 dark:border-zinc-800/60 pt-3">
                                <div>
                                    <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100">{{ __('حفظ') }}</div>
                                    <div class="text-xs text-zinc-400">{{ $planDay->formatRange('hifz') }}</div>
                                </div>
                                @php
                                    $g = match($planDay->hifz_achievement) {
                                        3 => ['color' => 'emerald', 'label' => 'ممتاز'], 2 => ['color' => 'blue', 'label' => 'جيد'],
                                        1 => ['color' => 'amber', 'label' => 'مقبول'], 0 => ['color' => 'red', 'label' => 'لم يسمع'],
                                        default => ['color' => 'zinc', 'label' => 'لم يُقيَّم'],
                                    };
                                @endphp
                                <flux:badge :color="$g['color']" size="sm">{{ __($g['label']) }}</flux:badge>
                            </div>
                        @endif
                        @if($planDay->reviewFromAyah && $planDay->reviewToAyah)
                            <div class="flex items-center justify-between border-t border-zinc-50 dark:border-zinc-800/60 pt-3">
                                <div>
                                    <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100">{{ __('مراجعة') }}</div>
                                    <div class="text-xs text-zinc-400">{{ $planDay->formatRange('review') }}</div>
                                </div>
                                @php
                                    $g = match($planDay->review_achievement) {
                                        3 => ['color' => 'emerald', 'label' => 'ممتاز'], 2 => ['color' => 'blue', 'label' => 'جيد'],
                                        1 => ['color' => 'amber', 'label' => 'مقبول'], 0 => ['color' => 'red', 'label' => 'لم يسمع'],
                                        default => ['color' => 'zinc', 'label' => 'لم يُقيَّم'],
                                    };
                                @endphp
                                <flux:badge :color="$g['color']" size="sm">{{ __($g['label']) }}</flux:badge>
                            </div>
                        @endif
                    @empty
                    @endforelse

                    @foreach($dayDetail['exams'] as $exam)
                        <div class="flex items-center justify-between border-t border-zinc-50 dark:border-zinc-800/60 pt-3">
                            <div>
                                <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100">{{ __('اختبار') }}</div>
                                <div class="text-xs text-zinc-400">{{ $exam->examLevel?->name }}</div>
                            </div>
                            <flux:badge color="indigo" size="sm">{{ $exam->status }}</flux:badge>
                        </div>
                    @endforeach

                    @if($dayDetail['planDays']->isEmpty() && $dayDetail['exams']->isEmpty() && !$dayDetail['attendance'])
                        <p class="text-sm text-zinc-400 text-center py-6">{{ __('لا يوجد نشاط في هذا اليوم.') }}</p>
                    @endif
                </div>
            @endif
        </flux:card>
    </div>
</div>
