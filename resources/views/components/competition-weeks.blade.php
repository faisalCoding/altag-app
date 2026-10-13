@props([
    'weeks',
    'model',
    'selected' => '',
    'island' => null,
    'active' => 'bg-accent text-accent-foreground border-accent shadow-sm',
])

@php
    $options = collect($weeks)
        ->map(fn (array $week) => ['value' => (string) $week['number'], 'title' => $week['title'], 'range' => $week['range'], 'current' => $week['current']])
        ->prepend(['value' => '', 'title' => __('كل المسابقة'), 'range' => __('جميع الأيام'), 'current' => false])
        ->all();
    $idle = 'bg-white dark:bg-zinc-900 border-zinc-200 dark:border-zinc-700 text-zinc-700 dark:text-zinc-200 hover:border-zinc-300 dark:hover:border-zinc-600';
    // Only the island holding the standings is drawn again, where there is one.
    $target = $island ? '$wire.$island('.\Illuminate\Support\Js::from($island).')' : '$wire';
@endphp

{{--
    The standings over the whole competition, or one week of its days, picked
    from a strip of chips that scrolls sideways on a phone. The pick shows at
    once; the standings under it fade until they arrive (App\Support\CompetitionWeeks).
--}}
<div
    x-data="{
        chosen: @js((string) $selected),
        busy: false,
        pick(value) {
            if (this.chosen === value) return;
            this.chosen = value;
            this.busy = true;
            Promise.resolve({{ $target }}.$set(@js($model), value)).finally(() => this.busy = false);
        },
    }"
    x-init="$nextTick(() => $el.querySelector('[role=radio][aria-checked=true]')?.scrollIntoView({ block: 'nearest', inline: 'center' }))"
    {{ $attributes->class('space-y-3') }}
    data-week-filter
>
    <div role="radiogroup" aria-label="{{ __('ترتيب المتصدرين خلال') }}"
        class="flex gap-2 overflow-x-auto snap-x pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        @foreach ($options as $option)
            <button type="button" role="radio" data-week="{{ $option['value'] }}"
                x-on:click="pick(@js($option['value']))"
                x-bind:aria-checked="chosen === @js($option['value'])"
                aria-checked="{{ (string) $selected === $option['value'] ? 'true' : 'false' }}"
                x-bind:class="chosen === @js($option['value']) ? @js($active) : @js($idle)"
                class="snap-start shrink-0 min-w-24 rounded-xl border px-3 py-1.5 text-start transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                <span class="flex items-center gap-1.5 text-xs font-bold leading-5 whitespace-nowrap">
                    {{ $option['title'] }}
                    @if ($option['current'])
                        <span class="size-1.5 rounded-full bg-emerald-500" title="{{ __('الأسبوع الحالي') }}"></span>
                    @endif
                </span>
                <span class="block text-[11px] leading-4 opacity-75 whitespace-nowrap">{{ $option['range'] }}</span>
            </button>
        @endforeach
    </div>

    <div class="transition-opacity duration-150" x-bind:class="busy && 'opacity-50 pointer-events-none'" x-bind:aria-busy="busy">
        {{ $slot }}
    </div>
</div>
