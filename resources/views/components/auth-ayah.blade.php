{{--
    The one ornament on the public pages, and it is a line of text.

    Set in the Quranic face the application already carries, at a weight that
    reads as a seal rather than as a heading — it is the last thing the eye
    rests on, not the first thing it meets.
--}}
<div {{ $attributes->merge(['class' => 'text-center select-none']) }}>
    <p class="font-zain text-xl text-maroon/70 dark:text-red-secondary/70 leading-loose">
        ﴿ وَرَتِّلِ الْقُرْآنَ تَرْتِيلًا ﴾
    </p>
    <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
        سورة المزمل · الآية {{ App\Support\HijriDate::arabicDigits(4) }}
    </p>
</div>
