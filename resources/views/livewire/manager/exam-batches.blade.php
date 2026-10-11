@php
    use App\Models\StudentExam;
    use App\Services\ExamReadiness;
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits((int) $n);
    $batch = $this->batch;
@endphp

<div class="space-y-5">
    @if(! $batch)
        <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse($this->batches as $row)
                @php
                    $past = $row->date_time->toDateString() < $today;
                    $complete = $row->exams_count > 0 && $row->recorded_count === $row->exams_count;
                @endphp
                <button type="button" wire:key="batch-{{ $row->id }}" wire:click="openBatch({{ $row->id }})"
                    class="w-full flex flex-wrap items-center gap-3 p-4 text-start hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                    <div class="size-11 shrink-0 rounded-xl flex items-center justify-center {{ $past ? 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' : 'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300' }}">
                        <flux:icon icon="academic-cap" class="size-5" />
                    </div>
                    <div class="flex-1 min-w-[12rem]">
                        <div class="font-bold text-zinc-900 dark:text-white">{{ ExamReadiness::when($row) }}</div>
                        <div class="text-xs text-zinc-500">{{ $ar($row->exams_count) }} {{ __('طالب') }} · {{ __('رُصدت') }} {{ $ar($row->recorded_count) }}</div>
                    </div>
                    @if($complete)
                        <flux:badge size="sm" color="emerald">{{ __('مكتملة') }}</flux:badge>
                    @elseif($past)
                        <flux:badge size="sm" color="amber">{{ __('بانتظار النتائج') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="indigo">{{ __('قادمة') }}</flux:badge>
                    @endif
                    <flux:icon icon="chevron-left" class="size-4 text-zinc-400" />
                </button>
            @empty
                <p class="p-8 text-center text-sm text-zinc-500">{{ __('لا دفعات بعد. حدّد الجاهزين في تبويب «الجاهزية» ثم أنشئ لهم دفعة.') }}</p>
            @endforelse
        </div>
    @else
        <div class="flex flex-wrap items-start gap-3">
            <flux:button size="sm" variant="ghost" icon="arrow-right" wire:click="back">{{ __('الدفعات') }}</flux:button>
            <div class="flex-1 min-w-[14rem]">
                <div class="text-lg font-bold text-zinc-900 dark:text-white" data-batch-when>{{ ExamReadiness::when($batch) }}</div>
                @if($batch->notes)
                    <div class="text-sm text-zinc-500 mt-0.5">{{ $batch->notes }}</div>
                @endif
            </div>
            <flux:button size="sm" icon="pencil-square" wire:click="openEdit">{{ __('تعديل الموعد') }}</flux:button>
            @if($batch->exams->isEmpty())
                <flux:button size="sm" variant="danger" icon="trash" wire:click="deleteBatch" wire:confirm="{{ __('تُحذف هذه الدفعة الفارغة. متابعة؟') }}">{{ __('حذف') }}</flux:button>
            @endif
        </div>

        <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden">
            <div class="hidden sm:grid grid-cols-[1fr_9rem_8rem_6rem_2.5rem] gap-3 px-4 py-2 text-xs font-bold text-zinc-500 bg-zinc-50 dark:bg-zinc-800/60">
                <span>{{ __('الطالب') }}</span><span>{{ __('النتيجة') }}</span><span>{{ __('الدرجة %') }}</span><span></span><span></span>
            </div>
            @forelse($batch->exams as $exam)
                <div wire:key="seat-{{ $exam->id }}" class="grid grid-cols-2 sm:grid-cols-[1fr_9rem_8rem_6rem_2.5rem] items-center gap-3 px-4 py-3 border-t border-zinc-100 dark:border-zinc-800" data-seat="{{ $exam->id }}">
                    <div class="col-span-2 sm:col-span-1">
                        <div class="font-bold text-zinc-900 dark:text-white">{{ $exam->student?->name }}</div>
                        <div class="text-xs text-zinc-500">{{ $exam->examLevel?->name }} · {{ $exam->student?->circle?->name }}</div>
                    </div>
                    <flux:select wire:model="results.{{ $exam->id }}.status" size="sm" aria-label="{{ __('النتيجة') }}">
                        <flux:select.option value="">{{ __('لم تُرصد') }}</flux:select.option>
                        @foreach(StudentExam::RESULTS as $result)
                            <flux:select.option value="{{ $result }}">{{ StudentExam::STATUS_LABELS[$result] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="results.{{ $exam->id }}.score" size="sm" type="number" step="0.01" min="0" max="100" placeholder="{{ __('الدرجة') }}" aria-label="{{ __('الدرجة') }}" />
                    <span class="text-xs text-zinc-500">
                        @if($exam->status === StudentExam::SCHEDULED)
                            {{ __('مجدول') }}
                        @else
                            {{ StudentExam::STATUS_LABELS[$exam->status] ?? '' }}
                        @endif
                    </span>
                    @if($exam->status === StudentExam::SCHEDULED)
                        <flux:button size="xs" variant="ghost" icon="x-mark" class="text-rose-500" wire:click="unseat({{ $exam->id }})"
                            wire:confirm="{{ __('يُخرج الطالب من الدفعة ويعود إلى الجاهزين، ويُبلَّغ بإلغاء الموعد. متابعة؟') }}" aria-label="{{ __('إخراج من الدفعة') }}" />
                    @else
                        <span></span>
                    @endif
                </div>
                @error('results.'.$exam->id.'.score')
                    <p class="px-4 pb-2 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            @empty
                <p class="p-8 text-center text-sm text-zinc-500">{{ __('لم يبقَ في هذه الدفعة طلاب.') }}</p>
            @endforelse
        </div>

        @if($batch->exams->isNotEmpty())
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="text-xs text-zinc-500">{{ __('الغائب يعود تلقائياً إلى قائمة الجاهزين بالمستوى نفسه.') }}</span>
                <flux:button variant="primary" icon="check" wire:click="saveResults">{{ __('حفظ النتائج') }}</flux:button>
            </div>
        @endif

        <flux:modal name="edit-exam-batch" class="md:w-[30rem]">
            <form wire:submit.prevent="saveEdit" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('موعد الدفعة') }}</flux:heading>
                    <flux:subheading>{{ __('إن تغيّر اليوم أو الساعة أُبلغ طلاب الدفعة وأولياء أمورهم بالموعد الجديد.') }}</flux:subheading>
                </div>
                <flux:field>
                    <flux:label>{{ __('يوم الاختبار') }}</flux:label>
                    <livewire:shared.hijri-datepicker wire:model="editDate" label="" :placeholder="__('اختر التاريخ')" :wire:key="'edit-batch-'.$batch->id" />
                    <flux:error name="editDate" />
                </flux:field>
                <flux:input type="time" wire:model="editTime" label="{{ __('الساعة') }}" />
                <flux:input wire:model="editLocation" label="{{ __('المكان') }}" />
                <flux:textarea wire:model="editNotes" label="{{ __('ملاحظات') }}" rows="2" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('إلغاء') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('حفظ') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
