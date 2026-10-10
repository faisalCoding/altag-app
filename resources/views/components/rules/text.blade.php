@props(['text'])

@php
    use App\Support\HijriDate;
@endphp

{{-- The rules as one person reads them: sections, clauses numbered right
     through. Whom a clause is for stays with the manager: the reader sees only
     their own, so it is never named beside them. --}}
<div {{ $attributes->class('rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 sm:p-7 space-y-6') }}>
    @foreach($text as $section)
        <section class="space-y-3">
            <h2 class="text-base font-bold text-accent dark:text-zinc-100">{{ $section['title'] }}</h2>
            <ol class="space-y-2.5">
                @foreach($section['clauses'] as $clause)
                    <li class="flex gap-3 text-[15px] leading-loose text-zinc-800 dark:text-zinc-200">
                        <span class="shrink-0 font-bold text-accent dark:text-zinc-300 tabular-nums">{{ HijriDate::arabicDigits($clause['number']) }}.</span>
                        <span class="flex-1">{{ $clause['body'] }}</span>
                    </li>
                @endforeach
            </ol>
        </section>
    @endforeach
</div>
