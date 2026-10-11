@php
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits((int) $n);
    $mayBatch = $this->mayBatch();
@endphp

<div class="space-y-5">
    <div class="flex flex-wrap gap-3">
        <flux:select wire:model.live="levelId" class="max-w-xs" placeholder="{{ __('كل المستويات') }}">
            <flux:select.option value="">{{ __('كل المستويات') }}</flux:select.option>
            @foreach($this->levels as $level)
                <flux:select.option value="{{ $level->id }}">{{ $level->name }}</flux:select.option>
            @endforeach
        </flux:select>
        @if($this->stages->count() > 1)
            <flux:select wire:model.live="stageId" class="max-w-xs" placeholder="{{ __('كل المراحل') }}">
                <flux:select.option value="">{{ __('كل المراحل') }}</flux:select.option>
                @foreach($this->stages as $stage)
                    <flux:select.option value="{{ $stage->id }}">{{ $stage->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    {{-- Ready: confirmed by their teachers, the only ones a batch takes. --}}
    <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden" data-ready-list>
        <div class="flex flex-wrap items-center gap-3 p-4 border-b border-zinc-100 dark:border-zinc-800">
            <flux:icon icon="check-badge" class="size-5 text-emerald-600" />
            <span class="font-bold text-zinc-900 dark:text-white">{{ __('جاهزون للاختبار') }}</span>
            <flux:badge size="sm" color="emerald">{{ $ar($this->ready->count()) }}</flux:badge>
            @if($mayBatch && $this->ready->isNotEmpty())
                <span class="flex-1"></span>
                <span class="text-xs text-zinc-500">{{ __('حدّد الطلاب ثم أنشئ لهم دفعة أو أضفهم إلى دفعة قائمة.') }}</span>
            @endif
        </div>

        @forelse($this->ready as $exam)
            <label wire:key="ready-{{ $exam->id }}" @class([
                'flex flex-wrap items-center gap-3 px-4 py-3 border-b border-zinc-50 dark:border-zinc-800/60 last:border-0',
                'cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50' => $mayBatch,
            ])>
                @if($mayBatch)
                    <input type="checkbox" wire:model.live="selected" value="{{ $exam->id }}" class="size-4 rounded border-zinc-300 text-accent focus:ring-accent">
                @endif
                <div class="flex-1 min-w-[12rem]">
                    <div class="font-bold text-zinc-900 dark:text-white">{{ $exam->student?->name }}</div>
                    <div class="text-xs text-zinc-500">
                        {{ $exam->student?->circle?->name }}{{ $exam->student?->circle?->stage ? ' · '.$exam->student->circle->stage->name : '' }}
                        @if($exam->nominator) · {{ __('رشّحه') }} {{ $exam->nominator->name }} @endif
                    </div>
                </div>
                <flux:badge color="indigo">{{ $exam->examLevel?->name }}</flux:badge>
                <span class="text-xs text-zinc-500 w-36 text-end">{{ __('جاهز منذ') }} {{ HijriDate::full($exam->ready_at) }}</span>
            </label>
        @empty
            <p class="p-6 text-center text-sm text-zinc-500">{{ __('لا طلاب جاهزون الآن.') }}</p>
        @endforelse
    </div>

    {{-- Expected: a day named, and waiting on the teacher to confirm. --}}
    <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden" data-expected-list>
        <div class="flex flex-wrap items-center gap-3 p-4 border-b border-zinc-100 dark:border-zinc-800">
            <flux:icon icon="calendar-days" class="size-5 text-zinc-500" />
            <span class="font-bold text-zinc-900 dark:text-white">{{ __('جاهزيتهم متوقعة') }}</span>
            <flux:badge size="sm" color="zinc">{{ $ar($this->expected->count()) }}</flux:badge>
            <span class="flex-1"></span>
            <span class="text-xs text-zinc-500">{{ __('يُدرجون في الدفعات بعد أن يؤكد معلموهم جاهزيتهم.') }}</span>
        </div>

        @forelse($this->expected as $exam)
            @php $due = $exam->expected_ready_on?->toDateString() <= $today; @endphp
            <div wire:key="expected-{{ $exam->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-zinc-50 dark:border-zinc-800/60 last:border-0">
                <div class="flex-1 min-w-[12rem]">
                    <div class="font-bold text-zinc-900 dark:text-white">{{ $exam->student?->name }}</div>
                    <div class="text-xs text-zinc-500">
                        {{ $exam->student?->circle?->name }}{{ $exam->student?->circle?->stage ? ' · '.$exam->student->circle->stage->name : '' }}
                        @if($exam->nominator) · {{ __('رشّحه') }} {{ $exam->nominator->name }} @endif
                    </div>
                </div>
                <flux:badge color="zinc">{{ $exam->examLevel?->name }}</flux:badge>
                <span @class(['text-xs w-36 text-end', 'text-amber-600 dark:text-amber-400 font-medium' => $due, 'text-zinc-500' => ! $due])>
                    {{ $due ? __('حان موعده، بانتظار تأكيد المعلم') : __('متوقع').' '.HijriDate::full($exam->expected_ready_on) }}
                </span>
            </div>
        @empty
            <p class="p-6 text-center text-sm text-zinc-500">{{ __('لا أحد.') }}</p>
        @endforelse
    </div>

    @if($mayBatch && count($selected) > 0)
        {{-- What to do with the ticked students, held at the foot of the screen. --}}
        <div class="sticky bottom-20 lg:bottom-4 z-10 rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-lg p-3 flex flex-wrap items-center gap-2" data-batch-bar>
            <span class="font-bold text-sm text-zinc-900 dark:text-white px-1">{{ __('حُدّد') }} {{ $ar(count($selected)) }}</span>
            <span class="flex-1"></span>
            @if($this->upcomingBatches->isNotEmpty())
                <flux:select wire:model="targetBatchId" class="max-w-64" placeholder="{{ __('دفعة قائمة…') }}">
                    @foreach($this->upcomingBatches as $batch)
                        <flux:select.option value="{{ $batch->id }}">{{ HijriDate::full($batch->date_time->toDateString()) }} · {{ $ar($batch->exams_count) }} {{ __('طالب') }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button size="sm" wire:click="addToBatch">{{ __('إضافة إليها') }}</flux:button>
            @endif
            <flux:button size="sm" variant="primary" icon="plus" wire:click="openNewBatch">{{ __('إنشاء دفعة') }}</flux:button>
        </div>
    @endif

    @if($mayBatch)
        <flux:modal name="new-exam-batch" class="md:w-[30rem]">
            <form wire:submit.prevent="createBatch" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('دفعة اختبار جديدة') }}</flux:heading>
                    <flux:subheading>{{ __('يُبلَّغ') }} {{ $ar(count($selected)) }} {{ __('طالب وأولياء أمورهم بالموعد عند الحفظ.') }}</flux:subheading>
                </div>

                <flux:field>
                    <flux:label>{{ __('يوم الاختبار') }}</flux:label>
                    <livewire:shared.hijri-datepicker wire:model="batchDate" label="" :min-date="$today" :placeholder="__('اختر التاريخ')" wire:key="batch-date" />
                    <flux:error name="batchDate" />
                </flux:field>

                <flux:input type="time" wire:model="batchTime" label="{{ __('الساعة') }}" />
                <flux:input wire:model="batchLocation" label="{{ __('المكان') }}" placeholder="{{ __('مثل: قاعة الجمعية') }}" />
                <flux:textarea wire:model="batchNotes" label="{{ __('ملاحظات') }}" rows="2" placeholder="{{ __('اختياري') }}" />
                <flux:error name="selected" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('إلغاء') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('إنشاء الدفعة') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
