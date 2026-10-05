{{--
    The gamification page's small icons, by what they mean rather than by
    which icon draws them: the page used to print emojis here (a flame, a
    snowflake, a bolt, ticks and crosses), which every phone draws in its own
    style and size. The freeze snowflake has no Heroicon, so it is drawn
    inline, as the freeze button always drew it.

    The drawing itself stays hidden from screen readers. When an icon carries
    meaning the text beside it does not (a met or unmet requirement, a claimed
    milestone), pass `label` and it is read out in place of the icon.
--}}
@props([
    'name',
    'variant' => 'outline',
    'label' => null,
])

@php
    $heroicon = match ($name) {
        'streak' => 'fire',
        'xp' => 'sparkles',
        'x' => 'x-mark',
        'up' => 'arrow-up',
        'down' => 'arrow-down',
        default => $name,
    };
@endphp

@if($name === 'freeze')
    <svg {{ $attributes->class('shrink-0') }} data-gam-icon="freeze" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <line x1="12" y1="2" x2="12" y2="22"></line>
        <line x1="2" y1="12" x2="22" y2="12"></line>
        <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
        <line x1="19.07" y1="4.93" x2="4.93" y2="19.07"></line>
        <path d="m20 8-2 2-2-2"></path>
        <path d="m4 16 2-2 2 2"></path>
        <path d="m8 4 2 2-2 2"></path>
        <path d="m16 20-2-2 2-2"></path>
        <path d="m8 20 2-2-2-2"></path>
        <path d="m16 4-2 2 2 2"></path>
        <path d="m4 8 2 2-2 2"></path>
        <path d="m20 16-2-2 2 2"></path>
    </svg>
@else
    <flux:icon :icon="$heroicon" :variant="$variant" data-gam-icon="{{ $name }}" {{ $attributes }} />
@endif
@if(filled($label))
    <span class="sr-only" data-gam-icon-label>{{ $label }}</span>
@endif
