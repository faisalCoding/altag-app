@php
    $weekdayNames = [1 => 'الأحد', 2 => 'الاثنين', 3 => 'الثلاثاء', 4 => 'الأربعاء', 5 => 'الخميس', 6 => 'الجمعة', 7 => 'السبت'];
    $steps = [1 => 'المرحلة', 2 => 'الحالة', 3 => 'اليوم', 4 => 'الباصات', 5 => 'التأكيد'];
    // The dates here read ٨ ربيع الآخر ١٤٤٨; a Latin 150 beside them looks like a
    // different language rather than the same sentence.
    $ar = fn ($n) => App\Support\HijriDate::arabicDigits($n);
@endphp

<div class="min-h-screen bg-zinc-50 dark:bg-zinc-950 py-6 px-4 sm:py-8">
    <div class="max-w-2xl mx-auto space-y-5">

        <div class="text-center">
            <flux:heading size="xl" class="font-bold">حجز باصات المجمع</flux:heading>
            <flux:subheading>{{ $stage?->name ?? 'اختر مرحلتك للبدء' }}</flux:subheading>
        </div>

        {{-- ─────────── التقدّم ─────────── --}}
        {{-- A bar and a sentence rather than five chips: at phone width the chips
             wrapped their own labels and read as clutter instead of progress.
             Going back is what the «رجوع» button in each step is for. --}}
        <div>
            <div class="flex items-baseline justify-between mb-2">
                <span class="text-sm font-bold text-zinc-800 dark:text-zinc-100">{{ $steps[$step] }}</span>
                <span class="text-xs text-zinc-400">
                    الخطوة {{ $ar($step) }} من {{ $ar(count($steps)) }}
                </span>
            </div>
            <div class="h-1.5 w-full rounded-full bg-zinc-200 dark:bg-zinc-800 overflow-hidden">
                <div class="h-full rounded-full bg-maroon transition-all duration-300"
                    style="width: {{ ($step / count($steps)) * 100 }}%"></div>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-4 sm:p-6">

            {{-- ═════════ ١ · المرحلة ═════════ --}}
            @if ($step === 1)
                <flux:heading size="lg" class="mb-4">أي مرحلة؟</flux:heading>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    @forelse ($stages as $one)
                        <flux:button wire:key="stage-{{ $one->id }}" variant="filled"
                            wire:click="chooseStage({{ $one->id }})" class="!h-12 text-base">
                            {{ $one->name }}
                        </flux:button>
                    @empty
                        <div class="text-sm text-zinc-400">لا مراحل معرَّفة بعد.</div>
                    @endforelse
                </div>

            {{-- ═════════ ٢ · الحالة ═════════ --}}
            @elseif ($step === 2)
                @if ($standing === 'banned')
                    <div class="text-center space-y-4">
                        <div class="inline-flex p-4 rounded-full bg-red-50 dark:bg-red-950/30">
                            <flux:icon icon="no-symbol" class="size-12 text-red-500" />
                        </div>
                        <flux:heading size="lg" class="text-red-600 dark:text-red-400">
                            هذه المرحلة محرومة من حجز الباصات
                        </flux:heading>

                        @if ($ruling?->reason)
                            <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 px-4 py-3 text-sm text-zinc-600 dark:text-zinc-300">
                                السبب: {{ $ruling->reason }}
                            </div>
                        @endif

                        @if ($officerPhone)
                            <flux:button variant="primary" icon="chat-bubble-left-right"
                                href="https://wa.me/{{ $officerPhone }}" target="_blank">
                                تواصل مع المسؤول
                            </flux:button>
                        @else
                            <div class="text-sm text-zinc-400">راجع مسؤول الباصات.</div>
                        @endif
                    </div>
                @else
                    <div class="space-y-4">
                        @if ($standing === 'prepay')
                            <div class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300">
                                <flux:icon icon="exclamation-triangle" class="size-4 mt-0.5 shrink-0" />
                                <div>
                                    <div class="font-bold">الحجز لهذه المرحلة برسوم تُدفع مقدماً</div>
                                    <div class="mt-1">
                                        @if ($ruling?->reason) السبب: {{ $ruling->reason }}. @endif
                                        يُسجَّل الحجز، ولا يصير مؤكَّداً حتى تُسلّم الرسوم للمسؤول.
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300">
                                <flux:icon icon="check-circle" class="size-5 shrink-0" />
                                <span>لا مانع لدى هذه المرحلة من الحجز.</span>
                            </div>
                        @endif

                        {{-- حجوزات المرحلة القادمة، وإلغاؤها ما دام ممكناً --}}
                        @if ($upcoming->isNotEmpty())
                            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 divide-y divide-zinc-100 dark:divide-zinc-800">
                                <div class="px-4 py-2 text-xs font-bold text-zinc-500">حجوزات هذه المرحلة</div>
                                @foreach ($upcoming as $booking)
                                    <div wire:key="up-{{ $booking->id }}" class="flex items-center justify-between gap-3 px-4 py-2.5">
                                        <div class="min-w-0">
                                            <div class="text-sm text-zinc-800 dark:text-zinc-100 truncate">
                                                {{ $this->hijri($booking->date->format('Y-m-d')) }}
                                            </div>
                                            <div class="text-xs text-zinc-400 truncate">
                                                {{ $booking->buses->pluck('name')->implode('، ') }}
                                                @if ($booking->isPending()) · بانتظار دفع {{ $ar($booking->fee_total) }} ﷼ @endif
                                            </div>
                                        </div>
                                        @if ($booking->isCancellableBySupervisor())
                                            <flux:button size="xs" variant="ghost" class="text-red-500 hover:text-red-600 shrink-0"
                                                wire:click="cancel({{ $booking->id }})"
                                                wire:confirm="إلغاء هذا الحجز؟">إلغاء</flux:button>
                                        @else
                                            <flux:badge size="sm" color="zinc" class="shrink-0">مثبَّت</flux:badge>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex justify-end">
                            <flux:button variant="primary" class="w-full sm:w-auto !h-12" wire:click="startDate">التالي — اختيار اليوم</flux:button>
                        </div>
                    </div>
                @endif

            {{-- ═════════ ٣ · اليوم ═════════ --}}
            @elseif ($step === 3)
                <flux:heading size="lg" class="mb-1">أي يوم؟</flux:heading>
                <flux:subheading class="mb-4">
                    @if ($weekdays !== [])
                        الحجز متاح في: {{ collect($weekdays)->map(fn ($d) => $weekdayNames[$d] ?? $d)->implode('، ') }}
                    @else
                        الحجز متاح في أي يوم.
                    @endif
                </flux:subheading>

                @if ($windowTo)
                    <div class="flex items-start gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800 dark:border-sky-900/50 dark:bg-sky-950/30 dark:text-sky-300 mb-4">
                        <flux:icon icon="information-circle" class="size-4 mt-0.5 shrink-0" />
                        <span>الحجز متاح من {{ $this->hijriShort($windowFrom) }} إلى {{ $this->hijriShort($windowTo) }}، ويُفتح ما بعده صباح السبت.</span>
                    </div>
                @endif

                <div class="text-xs text-zinc-400 mb-3">الأيام المتاحة مميّزة بالأخضر في التقويم.</div>

                <div class="max-w-xs">
                    {{-- The picker is given the same rule the service enforces, so a
                         day it offers is never a day the next step refuses. --}}
                    <livewire:shared.hijri-datepicker wire:model="date" label="تاريخ الرحلة"
                        :allowed-weekdays="$weekdays" :min-date="$windowFrom" :max-date="$windowTo" />
                </div>

                @error('date') <p class="text-sm text-red-600 mt-2">{{ $message }}</p> @enderror

                <div class="flex flex-col-reverse sm:flex-row sm:justify-between gap-2 mt-6">
                    <flux:button variant="ghost" class="w-full sm:w-auto" wire:click="toStep(2)">رجوع</flux:button>
                    <flux:button variant="primary" class="w-full sm:w-auto !h-12" wire:click="chooseDate">التالي — الباصات</flux:button>
                </div>

            {{-- ═════════ ٤ · الباصات ═════════ --}}
            @elseif ($step === 4)
                <flux:heading size="lg" class="mb-1">الباصات المتاحة</flux:heading>
                <flux:subheading class="mb-4">{{ $this->hijri($date) }}</flux:subheading>

                <div class="space-y-2">
                    @forelse ($buses as $bus)
                        <label wire:key="bus-{{ $bus->id }}"
                            class="flex items-center gap-3 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50 has-[:checked]:border-maroon has-[:checked]:bg-maroon/5">
                            <input type="checkbox" wire:model="busIds" value="{{ $bus->id }}"
                                class="size-5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500">
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $bus->name }}</div>
                                <div class="text-xs text-zinc-400">
                                    {{ $bus->type ?: 'باص' }}
                                    @if ($standing === 'prepay') · الرسوم {{ $ar($bus->fee_amount) }} ﷼ @endif
                                </div>
                            </div>
                            @if ($bus->pending_elsewhere ?? false)
                                <flux:badge size="sm" color="amber" class="shrink-0">عليه حجز معلّق</flux:badge>
                            @endif
                        </label>
                    @empty
                        <div class="p-8 text-center text-sm text-zinc-400">لا باصات متاحة في هذا اليوم.</div>
                    @endforelse
                </div>

                @error('busIds') <p class="text-sm text-red-600 mt-2">{{ $message }}</p> @enderror

                <div class="flex flex-col-reverse sm:flex-row sm:justify-between gap-2 mt-6">
                    <flux:button variant="ghost" class="w-full sm:w-auto" wire:click="toStep(3)">رجوع</flux:button>
                    <flux:button variant="primary" class="w-full sm:w-auto !h-12" wire:click="chooseBuses">التالي — البنود</flux:button>
                </div>

            {{-- ═════════ ٥ · التأكيد ═════════ --}}
            @elseif ($step === 5)
                <flux:heading size="lg" class="mb-4">تأكيد الحجز</flux:heading>

                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-4 text-sm space-y-1 mb-5">
                    <div><span class="text-zinc-400">المرحلة:</span> <strong>{{ $stage?->name }}</strong></div>
                    <div><span class="text-zinc-400">اليوم:</span> <strong>{{ $this->hijri($date) }}</strong></div>
                    <div><span class="text-zinc-400">الباصات:</span> <strong>{{ $chosen->pluck('name')->implode('، ') }}</strong></div>
                    @if ($this->feeTotal() > 0)
                        <div class="text-amber-700 dark:text-amber-400 pt-1">
                            <span class="text-zinc-400">الرسوم:</span>
                            <strong>{{ $ar($this->feeTotal()) }} ﷼</strong> — تُدفع للمسؤول ليُؤكَّد الحجز
                        </div>
                    @endif
                </div>

                @if ($items->isNotEmpty())
                    <div class="mb-4">
                        <div class="text-sm font-bold text-zinc-700 dark:text-zinc-200 mb-2">عند تسليم الباص يلزم:</div>
                        <ul class="space-y-1.5">
                            @foreach ($items as $item)
                                <li class="flex items-start gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                                    <flux:icon icon="check-circle" class="size-4 mt-0.5 shrink-0 text-zinc-400" />
                                    <span>{{ $item->label }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($penaltyText !== '')
                    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300 whitespace-pre-line mb-4">
                        {{ $penaltyText }}
                    </div>
                @endif

                @php
                    // Arabic counts in threes, not in digits: one, two, then a plural.
                    $window = match (true) {
                        $lockDays === 0 => 'حتى يوم الموعد',
                        $lockDays === 1 => 'قبل الموعد بيوم',
                        $lockDays === 2 => 'قبل الموعد بيومين',
                        default => 'قبل الموعد بـ'.$ar($lockDays).' أيام',
                    };
                @endphp
                <div class="text-xs text-zinc-400 mb-4">
                    يمكنك إلغاء الحجز {{ $window }} — وبعدها يثبت ولا يُلغى إلا عبر المسؤول.
                </div>

                <label class="flex items-start gap-3 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700 cursor-pointer has-[:checked]:border-maroon has-[:checked]:bg-maroon/5">
                    <input type="checkbox" wire:model="agreed" class="mt-0.5 size-5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500">
                    <span class="text-sm text-zinc-700 dark:text-zinc-200">أقرّ نيابة عن المرحلة بالالتزام بالبنود أعلاه.</span>
                </label>

                @error('agreed') <p class="text-sm text-red-600 mt-2">{{ $message }}</p> @enderror

                <div class="flex flex-col-reverse sm:flex-row sm:justify-between gap-2 mt-6">
                    <flux:button variant="ghost" class="w-full sm:w-auto" wire:click="toStep(4)">رجوع</flux:button>
                    <flux:button variant="primary" class="w-full sm:w-auto !h-12" wire:click="confirm">تأكيد الحجز</flux:button>
                </div>
            @endif
        </div>
    </div>
</div>
