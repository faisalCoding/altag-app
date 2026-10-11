@props(['ids', 'ticked', 'label'])

@php
    $count = count(array_intersect($ids, $ticked));
    $state = $count === 0 ? 'none' : ($count === count($ids) ? 'all' : 'some');
@endphp

{{-- Ticks every student of a group at once, or, when all are ticked, none of them. --}}
<button type="button" role="checkbox" aria-checked="{{ ['none' => 'false', 'all' => 'true', 'some' => 'mixed'][$state] }}"
    wire:click="toggleMany(@js(array_values($ids)))" wire:loading.attr="disabled"
    {{ $attributes->class('inline-flex items-center gap-2 text-xs font-bold text-zinc-600 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-white disabled:opacity-60') }}>
    <span @class([
        'size-4 rounded border flex items-center justify-center',
        'bg-accent border-accent text-accent-foreground' => $state !== 'none',
        'border-zinc-300 dark:border-zinc-600' => $state === 'none',
    ])>
        @if($state === 'all')
            <flux:icon icon="check" variant="micro" class="size-3" />
        @elseif($state === 'some')
            <flux:icon icon="minus" variant="micro" class="size-3" />
        @endif
    </span>
    {{ $label }}
</button>
