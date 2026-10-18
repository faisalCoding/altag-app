@php
    use App\Support\Agreements as AgreementRules;
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits($n);
@endphp

<div dir="rtl" class="space-y-5">
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" class="font-bold">إقرارات الطلاب</flux:heading>
            <flux:subheading>ما يُقرّ به طلاب حلقتك وأولياء أمورهم. أرسل لكل واحد رابطه الخاص، وتابع من أقرّ.</flux:subheading>
        </div>
        <div class="w-full md:w-72">
            <flux:radio.group wire:model.live="tab" variant="segmented" aria-label="نوع الإقرارات">
                <flux:radio value="general" label="الإقرارات العامة" />
                <flux:radio value="templates" label="النماذج" />
            </flux:radio.group>
        </div>
    </div>

    @if ($tab === 'templates')
        @include('livewire.teacher.partials.agreement-templates')
    @elseif ($this->agreements->isEmpty())
        <div class="rounded-2xl border border-dashed border-zinc-300 dark:border-zinc-700 p-10 text-center text-zinc-500">
            لا توجد إقرارات منشورة لحلقتك.
        </div>
    @else
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="الإقرارات">
            @foreach ($this->agreements as $item)
                <button type="button" role="tab" wire:key="tab-{{ $item->id }}" wire:click="$set('selected', {{ $item->id }})"
                    aria-selected="{{ $item->id === $agreement->id ? 'true' : 'false' }}"
                    class="rounded-xl border px-3 py-2 text-start text-sm transition-colors {{ $item->id === $agreement->id ? 'border-accent bg-accent text-accent-foreground' : 'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200' }}">
                    <span class="block font-semibold">{{ $item->currentVersion->title }}</span>
                    <span class="block text-xs opacity-75">{{ AgreementRules::AUDIENCES[$item->currentVersion->audience] }}</span>
                </button>
            @endforeach
        </div>

        @php $version = $agreement->currentVersion; @endphp
        <div class="rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 space-y-4" data-teacher-agreement="{{ $agreement->id }}">
            <div class="flex flex-col md:flex-row md:items-start justify-between gap-4">
                <div class="space-y-1">
                    <flux:heading size="lg">{{ $version->title }}</flux:heading>
                    <p class="text-xs text-zinc-500">{{ AgreementRules::AUDIENCES[$version->audience] }} · الإصدار {{ $ar($version->number) }} · {{ $ar(count($version->topics ?? [])) }} {{ count($version->topics ?? []) === 1 ? 'موضوع' : 'موضوعات' }}</p>
                </div>

                {{-- The kind the agreement goes by in each of the teacher's circles. --}}
                <div class="flex flex-wrap gap-3">
                    @foreach ($circlesOfAgreement as $circle)
                        @php $own = $agreement->circleTypes->firstWhere('circle_id', $circle->id)?->type; @endphp
                        <div class="w-52" wire:key="type-{{ $agreement->id }}-{{ $circle->id }}">
                            <flux:select label="نوع الإقرار في {{ $circle->name }}"
                                x-on:change="$wire.setType({{ $agreement->id }}, {{ $circle->id }}, $event.target.value)">
                                @foreach (AgreementRules::TYPES as $key => $label)
                                    <flux:select.option value="{{ $key }}" :selected="($own ?? $version->type) === $key">{{ $label }}{{ $key === $version->type ? ' (كما حدده المشرف)' : '' }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    @endforeach
                </div>
            </div>

            <details class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 px-4 py-3">
                <summary class="cursor-pointer text-sm font-medium">نص الإقرار</summary>
                <div class="mt-3 space-y-4">
                    @foreach (AgreementRules::topicsOf($version->topics) as $t => $topic)
                        <div wire:key="topic-{{ $agreement->id }}-{{ $t }}">
                            <p class="text-sm font-bold">{{ $ar($t + 1) }}. {{ \App\Support\AgreementText::format($topic['title']) }}</p>
                            <ol class="mt-1.5 list-[arabic-indic] space-y-1.5 ps-6 text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                                @foreach ($topic['clauses'] as $clause)
                                    <li>{{ \App\Support\AgreementText::format($clause) }}</li>
                                @endforeach
                            </ol>
                        </div>
                    @endforeach
                </div>
            </details>
        </div>

        <div wire:loading.class="opacity-50" wire:target="selected" class="transition-opacity">
            <x-agreements.students :rows="$rows" :parties="$parties" :show-circle="$circlesOfAgreement->count() > 1" />
        </div>
    @endif
</div>
