@php
    use App\Models\FormAssignment;

    $currentUser = collect(['manager', 'supervisor', 'teacher', 'student', 'guardian', 'staff'])
        ->map(fn ($guard) => auth()->guard($guard)->user())
        ->first(fn ($candidate) => $candidate !== null);

    $pending = $currentUser
        ? FormAssignment::owedBy($currentUser->id)->with('form')->get()
        : collect();
@endphp

@if($pending->isNotEmpty())
    <div class="rounded-2xl border border-amber-200 dark:border-amber-900/50 bg-amber-50 dark:bg-amber-900/20 p-5 space-y-4" dir="rtl">
        <div class="flex items-center gap-2.5">
            <flux:icon icon="clipboard-document-list" class="size-5 text-amber-600 dark:text-amber-400" />
            <flux:heading size="sm" class="text-amber-900 dark:text-amber-200">مطلوب منك</flux:heading>
            <flux:badge size="sm" color="amber">{{ $pending->count() }}</flux:badge>
        </div>

        <div class="space-y-2">
            @foreach($pending as $assignment)
                <a href="{{ route('forms.submit', $assignment->form->slug) }}"
                    class="flex items-center justify-between gap-3 p-3 rounded-xl bg-white dark:bg-zinc-900 border border-amber-100 dark:border-amber-900/40 hover:border-amber-300 dark:hover:border-amber-700 transition-colors group">
                    <div class="min-w-0">
                        <div class="font-bold text-sm text-zinc-900 dark:text-white truncate">
                            {{ $assignment->form->title }}
                        </div>
                        <div class="flex items-center gap-2 mt-0.5">
                            @if($assignment->form->is_blocking)
                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300">إلزامية</span>
                            @endif
                            @if($assignment->due_date)
                                <span class="text-[11px] {{ $assignment->isOverdue() ? 'text-rose-600 dark:text-rose-400 font-medium' : 'text-zinc-400' }}">
                                    {{ $assignment->isOverdue() ? 'تأخرت — ' : 'حتى ' }}<x-hijri-date :date="$assignment->due_date" />
                                </span>
                            @endif
                        </div>
                    </div>
                    <flux:icon icon="chevron-left" class="size-4 text-zinc-300 group-hover:text-amber-600 shrink-0" />
                </a>
            @endforeach
        </div>
    </div>
@endif
