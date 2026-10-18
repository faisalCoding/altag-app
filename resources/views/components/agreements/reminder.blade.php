@props(['party'])

@php
    use App\Support\Agreements;
    use App\Support\HijriDate;

    // The agreements a signed-in student, or a guardian's children, still
    // await the word on: a reminder on the dashboard, never a gate. A general
    // agreement owed opens the student's page of them; a send of a template
    // still waiting opens that send.
    $children = collect();

    if ($party === 'student' && ($student = auth('student')->user())) {
        $children = \App\Models\Student::whereKey($student->id)->get();
    }

    if ($party === 'guardian' && ($guardian = auth('guardian')->user())) {
        $children = \App\Models\Student::where('guardian_id', $guardian->id)->get();
    }

    $owed = $children
        ->map(fn ($child) => ['student' => $child, 'count' => Agreements::pending($child, $party)->count()])
        ->filter(fn (array $item) => $item['count'] > 0)
        ->values();

    $requests = $children->flatMap(fn ($child) => Agreements::openRequests($child, $party)->each->setRelation('student', $child));
@endphp

@foreach ($owed as $item)
    <a href="{{ Agreements::link($item['student'], $party) }}" data-agreements-reminder
        class="mb-3 flex items-center gap-3 rounded-xl border border-amber-200 dark:border-amber-900/50 bg-amber-50 dark:bg-amber-900/20 px-4 py-2.5 text-sm text-amber-900 dark:text-amber-200 hover:border-amber-300 dark:hover:border-amber-700 transition-colors">
        <flux:icon icon="document-check" class="size-5 shrink-0" />
        <span class="flex-1">
            @if ($party === 'guardian')
                بانتظار إقرارك {{ $item['count'] > 1 ? HijriDate::arabicDigits($item['count']).' إقرارات' : 'إقرار' }} للطالب {{ $item['student']->name }}.
            @else
                بانتظار إقرارك {{ $item['count'] > 1 ? HijriDate::arabicDigits($item['count']).' إقرارات' : 'إقرار' }} من الحلقة.
            @endif
        </span>
        <span class="font-bold whitespace-nowrap">اطّلع وأقرّ</span>
    </a>
@endforeach

@foreach ($requests as $request)
    <a href="{{ Agreements::requestLink($request) }}" data-agreement-request-reminder="{{ $request->id }}"
        class="mb-3 flex items-center gap-3 rounded-xl border border-amber-200 dark:border-amber-900/50 bg-amber-50 dark:bg-amber-900/20 px-4 py-2.5 text-sm text-amber-900 dark:text-amber-200 hover:border-amber-300 dark:hover:border-amber-700 transition-colors">
        <flux:icon icon="document-check" class="size-5 shrink-0" />
        <span class="flex-1">
            بانتظار إقرارك «{{ $request->version->title }}»{{ $party === 'guardian' ? ' للطالب '.$request->student->name : '' }}
            @if ($request->note)
                <span class="block text-xs opacity-80">{{ $request->note }}</span>
            @endif
        </span>
        <span class="font-bold whitespace-nowrap">اطّلع وأقرّ</span>
    </a>
@endforeach
