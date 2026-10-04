@props([
    /** The student's next exam as NextExamBadge reads it, or null when none is pending. */
    'exam',
    /** The student whose row it sits in, so the dot can follow the row's selected look. */
    'studentId',
    /** The ring cut around the dot, in the row's background while it is not selected. */
    'ring' => 'ring-white dark:ring-zinc-800',
])
{{--
    The student's next exam, small, beside their name in the tasmeeh page's
    list, as the teacher app shows it beside each name: the juz count, red once
    its day passed without a result, and a dot a week or less before.

    It only informs. It sits inside the button that opens the student's card,
    and a link there would be a link inside a button; the card's own box is the
    one that leads to the exams page.
--}}
@if ($exam)
    @php
        $label = __('الاختبار القادم').': '.$exam['level'].'، '.$exam['date_hijri'].($exam['overdue'] ? '، '.__('مضى موعده') : '');
    @endphp

    <span data-exam-chip="{{ $exam['overdue'] ? 'overdue' : ($exam['soon'] ? 'soon' : 'upcoming') }}"
        title="{{ $label }}"
        aria-label="{{ $label }}"
        @class([
            'relative shrink-0 inline-flex items-center h-6 px-1.5 rounded-md border text-[11px] font-bold leading-none whitespace-nowrap',
            'bg-red-50 border-red-500 text-red-600 dark:bg-red-500/15 dark:text-red-400' => $exam['overdue'],
            'bg-indigo-50 border-transparent text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' => ! $exam['overdue'],
        ])>
        @if ($exam['juz'] !== null)
            {{ \App\Support\HijriDate::arabicDigits($exam['juz']) }} {{ $exam['word'] }}
        @else
            <flux:icon icon="academic-cap" variant="micro" class="size-3.5" />
        @endif

        @if ($exam['soon'])
            <span class="absolute -top-1 -end-1 size-2 rounded-full bg-amber-500 ring-2"
                :class="activeStudentId == {{ $studentId }} ? 'ring-indigo-50 dark:ring-indigo-950' : '{{ $ring }}'"
                aria-hidden="true"></span>
        @endif
    </span>
@endif
