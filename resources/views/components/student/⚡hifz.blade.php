<?php

use App\Models\StudentPlanDay;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public function with(): array
    {
        $student = Auth::guard('student')->user();

        $days = StudentPlanDay::with(['fromAyah.surah', 'toAyah.surah'])
            ->whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
            ->whereNotNull('from_ayah_id')
            ->whereNotNull('to_ayah_id')
            ->orderByDesc('date')
            ->paginate(15);

        $gradedCount = StudentPlanDay::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
            ->whereNotNull('hifz_achievement')
            ->count();

        $excellentCount = StudentPlanDay::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
            ->where('hifz_achievement', 3)
            ->count();

        return [
            'days' => $days,
            'memorizedAyahsCount' => $student->getMemorizedRange() ? $student->getMemorizedRange()['max'] - $student->getMemorizedRange()['min'] + 1 : 0,
            'gradedCount' => $gradedCount,
            'excellentCount' => $excellentCount,
        ];
    }
};
?>

<div class="space-y-6" dir="rtl">
    <div>
        <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">
            {{ __('الحفظ') }}
        </flux:heading>
        <flux:subheading class="text-zinc-500 dark:text-zinc-400 mt-1">
            {{ __('سجل حفظك اليومي وتقييماته.') }}
        </flux:subheading>
    </div>

    {{-- Side by side on a phone too: each holds one number, and stacked they filled the screen before the log began. --}}
    <div class="grid grid-cols-3 gap-3 md:gap-6">
        <flux:card class="!p-3 md:!p-6">
            <div class="text-xs text-zinc-500 dark:text-zinc-400 leading-snug">{{ __('إجمالي الآيات المحفوظة') }}</div>
            <div class="text-xl md:text-2xl font-extrabold text-zinc-900 dark:text-white mt-1">{{ $memorizedAyahsCount }}</div>
        </flux:card>
        <flux:card class="!p-3 md:!p-6">
            <div class="text-xs text-zinc-500 dark:text-zinc-400 leading-snug">{{ __('أيام تم تقييمها') }}</div>
            <div class="text-xl md:text-2xl font-extrabold text-zinc-900 dark:text-white mt-1">{{ $gradedCount }}</div>
        </flux:card>
        <flux:card class="!p-3 md:!p-6">
            <div class="text-xs text-zinc-500 dark:text-zinc-400 leading-snug">{{ __('تقييمات ممتاز') }}</div>
            <div class="text-xl md:text-2xl font-extrabold text-emerald-600 mt-1">{{ $excellentCount }}</div>
        </flux:card>
    </div>

    <flux:card class="p-0 overflow-hidden">
        <div class="overflow-x-auto px-4">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('التاريخ') }}</flux:table.column>
                    <flux:table.column>{{ __('المقطع') }}</flux:table.column>
                    <flux:table.column>{{ __('التقييم') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse($days as $day)
                        <flux:table.row>
                            <flux:table.cell class="font-medium whitespace-nowrap">
                                <x-hijri-date :date="\Carbon\Carbon::parse($day->date)" style="weekday" />
                            </flux:table.cell>
                            <flux:table.cell>{{ $day->formatRange('hifz') ?? '-' }}</flux:table.cell>
                            <flux:table.cell>
                                @php
                                    $gradeDetails = match($day->hifz_achievement) {
                                        3 => ['color' => 'emerald', 'label' => 'ممتاز'],
                                        2 => ['color' => 'blue', 'label' => 'جيد'],
                                        1 => ['color' => 'amber', 'label' => 'مقبول'],
                                        default => ['color' => 'zinc', 'label' => 'لم يُقيَّم بعد'],
                                    };
                                @endphp
                                <flux:badge :color="$gradeDetails['color']" size="sm">{{ __($gradeDetails['label']) }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="3" class="text-center text-zinc-500 py-6">
                                {{ __('لا يوجد سجل حفظ بعد.') }}
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
