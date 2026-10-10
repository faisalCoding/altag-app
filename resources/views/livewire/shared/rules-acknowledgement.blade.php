@php
    use App\Support\CircleRules;
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits((int) $n);
    $version = $this->version;
    $standing = $this->standing;
@endphp

<div class="max-w-3xl mx-auto space-y-5">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">أنظمة الحلقات</flux:heading>
        @if($version)
            <flux:subheading>
                الإصدار {{ $ar($version->number) }} · نُشر {{ HijriDate::full($version->published_at) }}
                @if(count($this->roles) > 1)
                    · تقرؤها بصفتك {{ collect($this->roles)->map(fn ($r) => CircleRules::ROLE_LABELS[$r])->implode(' و') }}
                @endif
            </flux:subheading>
        @endif
    </div>

    @if(! $version)
        <div class="rounded-2xl border border-dashed border-zinc-300 dark:border-zinc-700 p-8 text-center text-zinc-500">
            لم تُنشر أنظمة الحلقات بعد.
        </div>
    @else
        @if($this->owes)
            <div class="flex items-start gap-3 rounded-2xl border border-amber-200 dark:border-amber-900/50 bg-amber-50 dark:bg-amber-900/20 p-4 text-amber-900 dark:text-amber-200" data-rules-owed>
                <flux:icon icon="exclamation-triangle" class="size-5 shrink-0 mt-0.5" />
                <div class="text-sm leading-relaxed">
                    لم تُقِرّ بهذه الأنظمة بعد. اقرأها، ثم ضع علامة الإقرار في آخر الصفحة.
                </div>
            </div>
        @elseif($standing)
            <div class="flex items-start gap-3 rounded-2xl border border-emerald-200 dark:border-emerald-900/50 bg-emerald-50 dark:bg-emerald-900/20 p-4 text-emerald-900 dark:text-emerald-200" data-rules-acknowledged>
                <flux:icon icon="check-badge" class="size-5 shrink-0 mt-0.5" />
                <div class="text-sm leading-relaxed">
                    أقررتَ بالإصدار {{ $ar($standing->version->number) }} في {{ HijriDate::full($standing->acknowledged_at) }}.
                    @if($standing->version->number < $version->number)
                        <br>عُدّل نص الأنظمة بعد إقرارك تعديلاً لفظياً، وإقرارك ما زال سارياً.
                    @endif
                </div>
            </div>
        @endif

        <x-rules.text :text="$this->text" />

        @if($this->owes)
            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 space-y-4">
                {{-- The tick lives on the phone until the word is given, so the
                     button wakes the moment it is ticked, not a round trip later. --}}
                <flux:checkbox wire:model="agreed" label="قرأتُ هذه الأنظمة وأتعهد بالالتزام بها" />
                <flux:error name="agreed" />
                <flux:button variant="primary" icon="check" wire:click="acknowledge" x-bind:disabled="! $wire.agreed" class="w-full sm:w-auto">
                    إقرار
                </flux:button>
            </div>
        @endif
    @endif
</div>
