{{--
    The circles the signed-in teacher stands in for today, and for whom — said
    wherever the day's work is done, so a circle that is not theirs does not
    turn up among their own unexplained.
--}}
@php
    $teacher = auth('teacher')->user();
    $assignments = $teacher
        ? \App\Models\SubstituteAssignment::with(['circle:id,name', 'absentTeacher:id,name'])
            ->where('teacher_id', $teacher->id)
            ->whereIn('circle_id', $teacher->substituteCircleIds())
            ->whereDate('date', \App\Services\TeacherSyncSnapshot::today())
            ->get()
        : collect();
@endphp

@if ($assignments->isNotEmpty())
    <div {{ $attributes->class('flex items-start gap-2 rounded-xl bg-sky-50 dark:bg-sky-900/20 border border-sky-100 dark:border-sky-900/40 px-4 py-3 text-sm text-sky-900 dark:text-sky-100') }}>
        <flux:icon icon="arrows-right-left" class="size-4 shrink-0 mt-0.5" />
        <div>
            <span class="font-bold">تنوب اليوم في:</span>
            {{ $assignments->map(fn ($assignment) => $assignment->circle?->name.($assignment->absentTeacher ? ' عن '.$assignment->absentTeacher->name : ''))->implode('، ') }}.
            <span class="text-sky-700 dark:text-sky-300">تعمل فيها كمعلمها لتاريخ اليوم فقط.</span>
        </div>
    </div>
@endif
