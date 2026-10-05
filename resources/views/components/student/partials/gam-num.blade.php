{{--
    A number that must read left to right inside Arabic text: a signed one
    («+12», «-30»), a ratio («12 / 40») or a multiplier («×2»). Left bare in a
    right-to-left line, the browser moves the sign to the far side and «+12»
    reads «12+». The bdi isolates it, so it reads the same whatever surrounds it.

    Give a value (signed adds «+» to a positive one) or put the text in the slot.
    Digits stay Western, as everywhere on the page.
--}}
@props([
    'value' => null,
    'signed' => false,
])

@php
    $text = null;
    if ($value !== null) {
        $number = (int) $value;
        $sign = $number < 0 ? '-' : (($signed && $number > 0) ? '+' : '');
        $text = $sign.number_format(abs($number));
    }
@endphp

<bdi {{ $attributes->merge(['dir' => 'ltr']) }}>{{ $text ?? $slot }}</bdi>
