<?php

use App\Models\StudentHadithPlan;
use App\Models\StudentOdePlan;
use App\Services\StudentNextWird;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/*
 * The top of the student's page: what they owe next — the very portion their
 * teacher grades in the app, a shortfall carried into it — and below, what
 * they did last time: their recitation and its grade, their attendance and
 * the criteria they earned. Under it, the way into each of their plans.
 */
new class extends Component
{
    public function with(): array
    {
        $student = Auth::guard('student')->user();
        $due = StudentNextWird::due($student);

        $quranPlans = $due->pluck('plan')->unique('id');

        $planLinks = collect()
            ->concat(\App\Models\StudentPlan::where('student_id', $student->id)->where('status', 'active')->where('is_approved', true)->get()
                ->map(fn ($plan) => ['kind' => 'quran', 'id' => $plan->id, 'label' => StudentNextWird::PLAN_LABELS[$plan->plan_type] ?? __('الخطة القرآنية')]))
            ->concat($student->memorisesHadith()
                ? StudentHadithPlan::where('student_id', $student->id)->where('status', 'active')->get()
                    ->map(fn ($plan) => ['kind' => 'hadith', 'id' => $plan->id, 'label' => __('خطة المتن')])
                : [])
            ->concat($student->memorisesOdes()
                ? StudentOdePlan::where('student_id', $student->id)->where('status', 'active')->with('path.ode')->get()
                    ->map(fn ($plan) => ['kind' => 'ode', 'id' => $plan->id, 'label' => $plan->path?->ode?->name ?? __('خطة المنظومة')])
                : []);

        return [
            'partLabels' => StudentNextWird::PART_LABELS,
            'planLabels' => StudentNextWird::PLAN_LABELS,
            'due' => $due,
            'manyPlans' => $quranPlans->count() > 1,
            'last' => StudentNextWird::lastSession($student),
            'planLinks' => $planLinks,
        ];
    }
};
?>

@php
    $ar = fn ($n) => \App\Support\HijriDate::arabicDigits((int) $n);

    // Spelled out so Tailwind sees every class.
    $grades = [
        3 => ['label' => 'ممتاز', 'class' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'],
        2 => ['label' => 'جيد', 'class' => 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300'],
        1 => ['label' => 'مقبول', 'class' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
        0 => ['label' => 'لم يسمع', 'class' => 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300'],
    ];
    $attendance = [
        'present' => ['label' => 'حاضر', 'class' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'],
        'late' => ['label' => 'متأخر', 'class' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
        'excused' => ['label' => 'مستأذن', 'class' => 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300'],
        'absent' => ['label' => 'غائب', 'class' => 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300'],
    ];
@endphp

<div class="space-y-3 mb-6" dir="rtl" data-wird-card>
    <div class="rounded-2xl border border-emerald-100 dark:border-emerald-900/40 bg-white dark:bg-zinc-900 shadow-xs overflow-hidden">

        {{-- ─────────── ما عليك ─────────── --}}
        <div class="p-5 bg-gradient-to-br from-emerald-50 to-white dark:from-emerald-950/20 dark:to-zinc-900">
            <div class="flex items-center gap-2 text-emerald-700 dark:text-emerald-400 text-sm font-bold">
                <flux:icon icon="flag" variant="solid" class="size-4" />
                {{ __('واجبك القادم') }}
            </div>

            @forelse ($due as $item)
                <div wire:key="due-{{ $item['plan']->id }}-{{ $item['part'] }}" class="mt-3 flex items-start gap-3">
                    <span class="shrink-0 mt-0.5 rounded-lg px-2 py-0.5 text-xs font-bold {{ $item['part'] === 'hifz' ? 'bg-emerald-600 text-white' : 'bg-indigo-600 text-white' }}">
                        {{ $partLabels[$item['part']] }}
                    </span>
                    <div class="min-w-0">
                        <div class="text-lg font-black text-zinc-900 dark:text-white leading-snug">{{ $item['range'] ?? __('لا يوجد نص محدد') }}</div>
                        @if ($manyPlans || $item['carried'])
                            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-zinc-500 dark:text-zinc-400">
                                @if ($manyPlans)
                                    <span>{{ $planLabels[$item['plan']->plan_type] ?? '' }}</span>
                                @endif
                                @if ($item['carried'])
                                    <span class="text-sky-700 dark:text-sky-300 font-medium">{{ __('يشمل ما بقي عليك من الورد السابق') }}</span>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">{{ __('لا واجب عليك الآن.') }}</p>
            @endforelse
        </div>

        {{-- ─────────── ما أنجزته ─────────── --}}
        <div class="p-5 border-t border-zinc-100 dark:border-zinc-800 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="flex items-center gap-2 text-sm font-bold text-zinc-600 dark:text-zinc-300">
                    <flux:icon icon="check-badge" class="size-4" />
                    {{ __('آخر ما أنجزته') }}
                </span>
                @if ($last)
                    <span class="text-xs text-zinc-400">{{ $last['label'] }}</span>
                @endif
            </div>

            @if (! $last)
                <p class="text-sm text-zinc-400">{{ __('لم تُقيَّم في تسميع بعد.') }}</p>
            @else
                <div class="space-y-2">
                    @foreach ($last['recitations'] as $recitation)
                        <div wire:key="last-{{ $loop->index }}" class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-bold text-zinc-700 dark:text-zinc-200 min-w-16">{{ $partLabels[$recitation['part']] ?? '' }}</span>
                            <span class="text-zinc-600 dark:text-zinc-300">{{ $recitation['range'] ?? '' }}</span>
                            <span class="rounded-md px-2 py-0.5 text-xs font-bold {{ $grades[$recitation['grade']]['class'] ?? '' }}">{{ $grades[$recitation['grade']]['label'] ?? '' }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="font-bold text-zinc-700 dark:text-zinc-200 min-w-16">{{ __('الحضور') }}</span>
                    @if ($last['attendance'] && isset($attendance[$last['attendance']]))
                        <span class="rounded-md px-2 py-0.5 text-xs font-bold {{ $attendance[$last['attendance']]['class'] }}">{{ $attendance[$last['attendance']]['label'] }}</span>
                    @else
                        <span class="text-xs text-zinc-400">{{ __('لم يُسجَّل') }}</span>
                    @endif
                </div>

                @if ($last['criteria'] !== [])
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="font-bold text-zinc-700 dark:text-zinc-200 min-w-16">{{ __('البنود') }}</span>
                        @foreach ($last['criteria'] as $criterion)
                            <span wire:key="criterion-{{ $loop->index }}" class="inline-flex items-center gap-1 rounded-full bg-amber-50 dark:bg-amber-900/20 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-200">
                                <flux:icon icon="star" variant="solid" class="size-3" />
                                {{ $criterion }}
                            </span>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    </div>

    {{-- ─────────── خططك ─────────── --}}
    @if ($planLinks->isNotEmpty())
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            @foreach ($planLinks as $link)
                <a wire:key="plan-link-{{ $link['kind'] }}-{{ $link['id'] }}"
                    href="{{ route('student.plan.print', ['kind' => $link['kind'], 'id' => $link['id']]) }}"
                    class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-4 py-3 shadow-xs hover:border-emerald-300 hover:bg-emerald-50/40 dark:hover:bg-zinc-800 transition-colors">
                    <span class="flex items-center gap-2 min-w-0">
                        <flux:icon icon="book-open" class="size-5 text-emerald-600 shrink-0" />
                        <span class="min-w-0">
                            <span class="block text-sm font-bold text-zinc-800 dark:text-zinc-100 truncate">{{ $link['label'] }}</span>
                            <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ __('خطتك مع تقييماتها') }}</span>
                        </span>
                    </span>
                    <flux:icon icon="chevron-left" class="size-4 text-zinc-400 shrink-0" />
                </a>
            @endforeach
        </div>
    @endif
</div>
