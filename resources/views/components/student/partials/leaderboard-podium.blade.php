@props(['top3'])

@php
    $medals = ['🥇', '🥈', '🥉'];
@endphp

<div class="space-y-2">
    @foreach($top3 as $index => $standing)
        <div class="flex items-center gap-3 p-2.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/50">
            <span class="text-lg shrink-0">{{ $medals[$index] ?? ($index + 1) }}</span>
            <span class="font-semibold text-sm text-zinc-700 dark:text-zinc-300 flex-1 truncate">{{ $standing['student']->name }}</span>
            <span class="text-xs font-bold text-zinc-500 dark:text-zinc-400 shrink-0">{{ __(':points نقطة', ['points' => $standing['score']]) }}</span>
        </div>
    @endforeach
</div>
