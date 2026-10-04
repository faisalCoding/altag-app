<?php

use App\Services\MemorizationJourneyService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public function with(): array
    {
        $student = Auth::guard('student')->user();

        $range = $student->getMemorizedRange();

        return [
            'memorizedAyahsCount' => $range ? $range['max'] - $range['min'] + 1 : 0,
            'completedSurahsCount' => collect(MemorizationJourneyService::surahMap($student))->where('status', 'full')->count(),
            'memorizedJuzCount' => MemorizationJourneyService::memorizedJuzCount($student),
            'memorizationPercentage' => $student->memorizationPercentage(),
            'currentStreak' => $student->currentAttendanceStreakDays(),
            'scoreTrend' => MemorizationJourneyService::scoreTrend($student, 14),
            'attendanceTrend' => MemorizationJourneyService::attendanceTrend($student, 8),
            'monthlyStats' => MemorizationJourneyService::monthlyAyahsMemorized($student),
        ];
    }
};
?>

<div class="space-y-6" dir="rtl">
    <div>
        <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">
            {{ __('التقارير') }}
        </flux:heading>
        <flux:subheading class="text-zinc-500 dark:text-zinc-400 mt-1">
            {{ __('ملخص تقدمك ومؤشراتك على مدار الوقت.') }}
        </flux:subheading>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <x-student.partials.stat-card icon="bookmark" :label="__('آيات محفوظة')" :value="$memorizedAyahsCount" accent-class="bg-emerald-500/10 text-emerald-600" />
        <x-student.partials.stat-card icon="check-badge" :label="__('سور مكتملة')" :value="$completedSurahsCount" accent-class="bg-indigo-500/10 text-indigo-600" />
        <x-student.partials.stat-card icon="square-3-stack-3d" :label="__('أجزاء محفوظة')" :value="$memorizedJuzCount" accent-class="bg-sky-500/10 text-sky-600" />
        <x-student.partials.stat-card icon="fire" :label="__('أيام متتالية')" :value="$currentStreak" :suffix="__('يوم')" accent-class="bg-amber-500/10 text-amber-600" />
    </div>

    <flux:card>
        <flux:heading size="sm" class="mb-4">{{ __('اتجاه التقييم (آخر 14 تقييماً)') }}</flux:heading>
        @if(empty($scoreTrend))
            <p class="text-sm text-zinc-400 text-center py-6">{{ __('لا توجد تقييمات كافية بعد.') }}</p>
        @else
            <div class="flex items-end gap-1.5 h-32">
                @foreach($scoreTrend as $point)
                    @php
                        $barColor = match($point['achievement']) {
                            3 => 'bg-emerald-500', 2 => 'bg-blue-500', 1 => 'bg-amber-500', 0 => 'bg-red-500', default => 'bg-zinc-200',
                        };
                        $barLabel = match($point['achievement']) {
                            3 => __('ممتاز'), 2 => __('جيد'), 1 => __('مقبول'), 0 => __('لم يسمع'), default => '',
                        };
                        $heightPct = max(15, ($point['achievement'] / 3) * 100);
                    @endphp
                    <div class="flex-1 flex flex-col items-center justify-end h-full gap-1" title="{{ $point['date'] }} — {{ $barLabel }}">
                        <div class="w-full rounded-t {{ $barColor }}" style="height: {{ $heightPct }}%"></div>
                    </div>
                @endforeach
            </div>
        @endif
    </flux:card>

    <flux:card>
        <flux:heading size="sm" class="mb-4">{{ __('نسبة الحضور الأسبوعية (آخر 8 أسابيع)') }}</flux:heading>
        <div class="flex items-end gap-2 h-32">
            @foreach($attendanceTrend as $week)
                @php
                    $pct = $week['total'] > 0 ? round($week['present'] / $week['total'] * 100) : 0;
                @endphp
                <div class="flex-1 flex flex-col items-center justify-end h-full gap-1">
                    <div class="w-full rounded-t bg-maroon" style="height: {{ max(4, $pct) }}%"></div>
                    <span class="text-[10px] text-zinc-400">{{ $week['label'] }}</span>
                </div>
            @endforeach
        </div>
    </flux:card>

    <flux:card>
        <flux:heading size="sm" class="mb-4">{{ __('آيات محفوظة هذا الشهر') }}</flux:heading>
        @if(empty($monthlyStats))
            <p class="text-sm text-zinc-400 text-center py-6">{{ __('لا توجد بيانات حفظ لهذا الشهر بعد.') }}</p>
        @else
            <div class="space-y-2">
                @foreach($monthlyStats as $stat)
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-zinc-500 dark:text-zinc-400"><x-hijri-date :date="\Carbon\Carbon::parse($stat['date'])" style="dayMonth" /></span>
                        <span class="font-bold text-zinc-800 dark:text-zinc-100">{{ $stat['count'] }} {{ __('آية') }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </flux:card>
</div>
