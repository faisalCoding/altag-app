{{--
    A badge's icon: the image the supervisor uploaded, or the keyword they
    picked drawn as an icon. The badges grid used to print an emoji for each
    keyword and the claim box an SVG for three of them, so one badge looked
    different in the two places. A keyword this does not know is drawn as a
    plain badge. data-badge-icon names what was drawn.
--}}
@props([
    'icon' => null,
    'locked' => false,
    'alt' => '',
])

@php
    $value = (string) ($icon ?? '');
    $isImage = str_contains($value, '/') || str_contains($value, '.');
    $keyword = match ($value) {
        'sparkles', 'sparkle' => 'sparkles',
        'fire' => 'fire',
        'rocket' => 'rocket',
        'crown' => 'crown',
        'bolt' => 'bolt',
        'trophy' => 'trophy',
        'star' => 'star',
        'shield' => 'shield-check',
        'heart' => 'heart',
        default => 'check-badge',
    };
@endphp

@if($isImage)
    <img src="{{ asset('storage/'.$value) }}" alt="{{ $alt }}" data-badge-icon="image"
        {{ $attributes->class(['object-contain', 'grayscale opacity-40' => $locked]) }} />
@else
    <flux:icon :icon="$keyword" variant="outline" data-badge-icon="{{ $keyword }}" {{ $attributes }} />
@endif
