@props(['exams', 'when'])

{{-- Students ticked for a batch whose teachers expect them ready only after it. --}}
<div class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200" role="alert" data-late-warning>
    <div class="flex items-start gap-2">
        <flux:icon icon="exclamation-triangle" variant="mini" class="size-5 shrink-0 text-amber-500" />
        <div class="space-y-1.5">
            <p class="font-bold">
                {{ __('تنبيه: جاهزية') }} {{ \App\Support\HijriDate::arabicDigits($exams->count()) }} {{ __('من المحددين متوقعة بعد') }} {{ $when }}
            </p>
            <ul class="space-y-0.5">
                @foreach($exams as $exam)
                    <li wire:key="late-{{ $exam->id }}">{{ $exam->student?->name }} — {{ __('متوقعة') }} {{ \App\Support\HijriDate::full($exam->expected_ready_on) }}</li>
                @endforeach
            </ul>
            <p class="text-xs opacity-80">{{ __('يمكنك المتابعة وإدراجهم، أو إلغاء تحديدهم.') }}</p>
        </div>
    </div>
</div>
