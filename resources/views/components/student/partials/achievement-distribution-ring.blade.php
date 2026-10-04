@props([
    'excellent' => 0,
    'good' => 0,
    'weak' => 0,
    'notHeard' => 0,
    'size' => 96,
])

@php
    $total = $excellent + $good + $weak + $notHeard;
    $excellentEnd = $total > 0 ? ($excellent / $total) * 360 : 0;
    $goodEnd = $excellentEnd + ($total > 0 ? ($good / $total) * 360 : 0);
    $weakEnd = $goodEnd + ($total > 0 ? ($weak / $total) * 360 : 0);
@endphp

<div class="flex items-center gap-4">
    <div class="relative shrink-0" style="width: {{ $size }}px; height: {{ $size }}px;">
        <div
            class="rounded-full w-full h-full"
            style="background: {{ $total > 0
                ? "conic-gradient(#22c55e 0deg {$excellentEnd}deg, #3b82f6 {$excellentEnd}deg {$goodEnd}deg, #f59e0b {$goodEnd}deg {$weakEnd}deg, #ef4444 {$weakEnd}deg 360deg)"
                : 'none' }}"
            @class(['bg-zinc-100 dark:bg-zinc-800' => $total === 0])
        ></div>
        <div class="absolute inset-[16%] rounded-full bg-white dark:bg-zinc-900 flex flex-col items-center justify-center">
            <span class="text-sm font-bold text-zinc-700 dark:text-zinc-200">{{ $total }}</span>
            <span class="text-[10px] text-zinc-400">{{ __('تقييم') }}</span>
        </div>
    </div>

    <div class="space-y-1 text-xs">
        <div class="flex items-center gap-1.5">
            <span class="size-2.5 rounded-full bg-green-500"></span>
            <span class="text-zinc-500 dark:text-zinc-400">{{ __('ممتاز') }}</span>
            <span class="font-bold text-zinc-700 dark:text-zinc-200">{{ $excellent }}</span>
        </div>
        <div class="flex items-center gap-1.5">
            <span class="size-2.5 rounded-full bg-blue-500"></span>
            <span class="text-zinc-500 dark:text-zinc-400">{{ __('جيد') }}</span>
            <span class="font-bold text-zinc-700 dark:text-zinc-200">{{ $good }}</span>
        </div>
        <div class="flex items-center gap-1.5">
            <span class="size-2.5 rounded-full bg-amber-500"></span>
            <span class="text-zinc-500 dark:text-zinc-400">{{ __('مقبول') }}</span>
            <span class="font-bold text-zinc-700 dark:text-zinc-200">{{ $weak }}</span>
        </div>
        @if ($notHeard > 0)
            <div class="flex items-center gap-1.5">
                <span class="size-2.5 rounded-full bg-red-500"></span>
                <span class="text-zinc-500 dark:text-zinc-400">{{ __('لم يسمع') }}</span>
                <span class="font-bold text-zinc-700 dark:text-zinc-200">{{ $notHeard }}</span>
            </div>
        @endif
    </div>
</div>
