<?php

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use Carbon\Carbon;
use Livewire\Attributes\Locked;

new class extends Component {
    use WithPagination;

    #[Locked]
    public $planId;

    public function with()
    {
        $studentId = Auth::guard('student')->id();
        $todayStr = Carbon::now()->format('Y-m-d');

        $plan = StudentPlan::where('id', $this->planId)
            ->where('student_id', $studentId)
            ->firstOrFail();

        $days = StudentPlanDay::with([
            'fromAyah.surah', 
            'toAyah.surah', 
            'reviewFromAyah.surah', 
            'reviewToAyah.surah'
        ])
        ->where('student_plan_id', $this->planId)
        ->orderBy('date', 'asc')
        ->paginate(20);

        return [
            'plan' => $plan,
            'days' => $days,
            'todayStr' => $todayStr,
        ];
    }
    
    protected function getHijriLabel(\DateTimeInterface|string $date)
    {
        $parsed = is_string($date) ? \Carbon\Carbon::parse($date) : $date;
        return \App\Support\HijriDate::full($parsed->getTimestamp());
    }
    
    // Helper mapper for badges
    public function getAchievementBadge($val) {
        return match((int) $val) {
            3 => ['color' => 'green', 'label' => 'ممتاز'],
            2 => ['color' => 'blue', 'label' => 'جيد جداً'],
            1 => ['color' => 'amber', 'label' => 'مقبول'],
            default => ['color' => 'red', 'label' => 'لم يسمّع']
        };
    }
};
?>

<div class="space-y-6" dir="rtl">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            <flux:button variant="ghost" icon="arrow-right" class="rtl:rotate-180" href="{{ route('student.plan') }}" />
            <div>
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">
                    {{ $plan->description ?? __('خطة بدون عنوان') }}
                </flux:heading>
                <div class="flex items-center gap-3 mt-1">
                    @if($plan->is_approved)
                        <flux:badge color="emerald" size="sm" icon="check-circle">{{ __('معتمدة') }}</flux:badge>
                    @else
                        <flux:badge color="amber" size="sm" icon="clock">{{ __('قيد الاعتماد') }}</flux:badge>
                    @endif
                    <span class="text-zinc-500 text-sm">
                        {{ $plan->plan_type === 'hifz' ? __('مسار حفظ') : ($plan->plan_type === 'review' ? __('مسار مراجعة') : __('مسار حفظ ومراجعة')) }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    <flux:card class="p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('التاريخ') }}</flux:table.column>
                    <flux:table.column>{{ __('مقرر الحفظ') }}</flux:table.column>
                    <flux:table.column>{{ __('تقييم الحفظ') }}</flux:table.column>
                    <flux:table.column>{{ __('مقرر المراجعة') }}</flux:table.column>
                    <flux:table.column>{{ __('تقييم المراجعة') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse($days as $day)
                        @php
                            $isToday = $day->date === $todayStr;
                            $isFuture = $day->date > $todayStr;
                        @endphp
                        <flux:table.row class="{{ $isToday ? 'bg-emerald-50/50 dark:bg-emerald-900/20' : '' }}">
                            <flux:table.cell class="first:ps-3" >
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold {{ $isToday ? 'text-emerald-700 dark:text-emerald-400' : 'text-zinc-800 dark:text-zinc-200' }}">
                                            {{ $day->day_name }}
                                        </span>
                                        @if($isToday)
                                            <flux:badge color="emerald" size="sm" class="px-1 py-0! text-[10px]">{{ __('اليوم') }}</flux:badge>
                                        @endif
                                    </div>
                                    <span class="text-xs {{ $isToday ? 'text-emerald-600 dark:text-emerald-500' : 'text-zinc-500 dark:text-zinc-400' }}">
                                        {{ $this->getHijriLabel($day->date) }}
                                    </span>
                                </div>
                            </flux:table.cell>
                            
                            <flux:table.cell class="first:ps-3" >
                                @if($day->from_ayah_id)
                                    <div class="text-sm font-medium">
                                        {{ $day->fromAyah->surah->name_arabic }} ({{ $day->fromAyah->verse_number }}) - {{ $day->toAyah->surah->name_arabic }} ({{ $day->toAyah->verse_number }})
                                    </div>
                                @else
                                    <span class="text-zinc-400 text-sm">-</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="first:ps-3" >
                                @if($day->from_ayah_id)
                                    @if($isFuture && is_null($day->hifz_achievement))
                                        <flux:badge color="zinc" size="sm">{{ __('قادم') }}</flux:badge>
                                    @elseif($isToday && is_null($day->hifz_achievement))
                                        <flux:badge color="emerald" size="sm" variant="outline">{{ __('قيد الانتظار') }}</flux:badge>
                                    @else
                                        @php $b = $this->getAchievementBadge($day->hifz_achievement); @endphp
                                        <flux:badge :color="$b['color']" size="sm">{{ $b['label'] }}</flux:badge>
                                    @endif
                                @else
                                    <span class="text-zinc-400 text-sm">-</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="first:ps-3" >
                                @if($day->review_from_ayah_id)
                                    <div class="text-sm font-medium">
                                        {{ $day->reviewFromAyah->surah->name_arabic }} ({{ $day->reviewFromAyah->verse_number }}) - {{ $day->reviewToAyah->surah->name_arabic }} ({{ $day->reviewToAyah->verse_number }})
                                    </div>
                                @else
                                    <span class="text-zinc-400 text-sm">-</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="first:ps-3" >
                                @if($day->review_from_ayah_id)
                                    @if($isFuture && is_null($day->review_achievement))
                                        <flux:badge color="zinc" size="sm">{{ __('قادم') }}</flux:badge>
                                    @elseif($isToday && is_null($day->review_achievement))
                                        <flux:badge color="emerald" size="sm" variant="outline">{{ __('قيد الانتظار') }}</flux:badge>
                                    @else
                                        @php $b = $this->getAchievementBadge($day->review_achievement); @endphp
                                        <flux:badge :color="$b['color']" size="sm">{{ $b['label'] }}</flux:badge>
                                    @endif
                                @else
                                    <span class="text-zinc-400 text-sm">-</span>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center text-zinc-500 py-6">
                                {{ __('لا توجد مهام مجدولة لهذه الخطة.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
        
        @if($days->hasPages())
            <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
                {{ $days->links() }}
            </div>
        @endif
    </flux:card>
</div>
