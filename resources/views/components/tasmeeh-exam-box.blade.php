@props([
    /** The student's next exam as NextExamBadge reads it, or null when none is pending. */
    'exam',
    /** The student it belongs to, handed to the editor the box opens. */
    'studentId',
    /** Whether the teacher may set exams here (NextExamBadge::canSchedule), read once by the caller for every box. */
    'canSchedule' => false,
    /** 'sm' beside each name in the tasmeeh list, 'lg' in the student card's header. */
    'size' => 'sm',
    /** Read with the label, so thirty boxes in a screen reader's list are thirty students. */
    'studentName' => null,
    /** Hold the box's place when none is drawn, so the rows of the list stay in line. */
    'reserve' => false,
])
{{--
    The square at the end of a student's name on the tasmeeh page, as the
    teacher app draws it: the juz count of their next exam, a dot a week or
    less before it, red once its day passed without a result, and a dashed "+"
    when none is set.

    While the teacher may set exams it is a button: the number opens that exam
    and the "+" sets one, both in the page's one exam editor. Otherwise the
    number only informs, and no "+" is drawn for an exam nobody here can set.

    It stands beside the row's own button, never inside it: a button inside a
    button is no longer two controls.
--}}
@php
    $large = $size === 'lg';
    $state = $exam === null ? 'none' : ($exam['overdue'] ? 'overdue' : ($exam['soon'] ? 'soon' : 'upcoming'));
    $label = ($exam === null
        ? __('إضافة الاختبار القادم')
        : __('الاختبار القادم').': '.$exam['level'].'، '.$exam['date_hijri'].($exam['overdue'] ? '، '.__('مضى موعده') : ''))
        .($studentName ? ' — '.$studentName : '');
    $tag = $canSchedule ? 'button' : 'div';
@endphp

@if ($exam !== null || $canSchedule)
    <{{ $tag }}
        @if ($canSchedule)
            type="button"
            {{-- Busy from the tap until the editor answers, so a slow line never looks like a dead box. --}}
            x-data="{ opening: false }"
            x-on:click="opening = true"
            x-on:exam-editor-ready.window="opening = false"
            x-bind:aria-busy="opening"
            wire:click="$dispatch('open-exam-editor', { studentId: {{ (int) $studentId }} })"
        @else
            role="img"
        @endif
        data-exam-box="{{ $state }}"
        title="{{ $label }}"
        aria-label="{{ $label }}"
        @class([
            'relative shrink-0 border-2 flex flex-col items-center justify-center leading-none transition-colors',
            'size-10 rounded-lg' => ! $large,
            'size-12 rounded-xl' => $large,
            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500 active:scale-95' => $canSchedule,
            'border-dashed border-zinc-300 text-zinc-400 dark:border-zinc-600 dark:text-zinc-500 hover:border-indigo-300 hover:text-indigo-500 dark:hover:border-indigo-500/50 dark:hover:text-indigo-400' => $state === 'none',
            'bg-red-50 border-red-500 text-red-600 dark:bg-red-500/15 dark:text-red-400' => $state === 'overdue',
            'bg-indigo-50 border-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:border-transparent dark:text-indigo-300' => $exam !== null && ! $exam['overdue'],
            'hover:border-indigo-200 dark:hover:border-indigo-500/40' => $canSchedule && $exam !== null && ! $exam['overdue'],
        ])>
        @if ($canSchedule)
            <flux:icon.loading x-show="opening" x-cloak @class(['absolute', 'size-4' => ! $large, 'size-5' => $large]) />
        @endif

        {{-- One wrapper hides the content while busy; only a box that can be tapped has that state. --}}
        <span class="flex flex-col items-center justify-center leading-none" @if ($canSchedule) x-bind:class="opening && 'invisible'" @endif>
        @if ($exam === null)
            <flux:icon icon="plus" @class(['size-4' => ! $large, 'size-5' => $large]) />
        @elseif ($exam['juz'] !== null)
            <span @class(['font-bold', 'text-sm' => ! $large, 'text-lg' => $large])>{{ \App\Support\HijriDate::arabicDigits($exam['juz']) }}</span>
            <span @class(['opacity-80', 'text-[9px] mt-px' => ! $large, 'text-[10px] mt-0.5' => $large])>{{ $exam['word'] }}</span>
        @else
            <flux:icon icon="academic-cap" @class(['size-4' => ! $large, 'size-5' => $large]) />
        @endif
        </span>

        @if ($state === 'soon')
            <span @class([
                'absolute -top-1 -end-1 rounded-full bg-amber-500 ring-2 ring-white dark:ring-zinc-900',
                'size-2.5' => ! $large,
                'size-3' => $large,
            ]) aria-hidden="true"></span>
        @endif
    </{{ $tag }}>
@elseif ($reserve)
    <span @class(['shrink-0', 'size-10' => ! $large, 'size-12' => $large]) aria-hidden="true"></span>
@endif
