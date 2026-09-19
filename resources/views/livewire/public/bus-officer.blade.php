@php
    $statusLabels = ['pending' => 'بانتظار الرسوم', 'confirmed' => 'مؤكَّد', 'received' => 'استُلم', 'cancelled' => 'ملغى'];
    $statusColors = ['pending' => 'amber', 'confirmed' => 'emerald', 'received' => 'zinc', 'cancelled' => 'red'];
    $standingLabels = ['ok' => 'عادية', 'prepay' => 'رسوم مقدماً', 'banned' => 'محرومة'];
    $standingColors = ['ok' => 'emerald', 'prepay' => 'amber', 'banned' => 'red'];
@endphp

<div class="min-h-screen bg-zinc-50 dark:bg-zinc-950 py-8 px-4">
    <div class="max-w-3xl mx-auto space-y-6">

        <div class="text-center">
            <flux:heading size="xl" class="font-bold">مسؤول الباصات</flux:heading>
            <flux:subheading>الاستلام، والرسوم، وحالة المراحل</flux:subheading>
        </div>

        {{-- ─────────── المراحل وحالاتها ─────────── --}}
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-5">
            <flux:heading size="lg" class="mb-3">المراحل</flux:heading>

            <div class="flex flex-wrap gap-2">
                @foreach ($stages as $stage)
                    <button type="button" wire:key="stage-{{ $stage->id }}" wire:click="openRuling({{ $stage->id }})"
                        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800 text-sm">
                        <span class="text-zinc-700 dark:text-zinc-200">{{ $stage->name }}</span>
                        <flux:badge size="sm" :color="$standingColors[$stage->bus_standing] ?? 'zinc'">
                            {{ $standingLabels[$stage->bus_standing] ?? $stage->bus_standing }}
                        </flux:badge>
                    </button>
                @endforeach
            </div>

            {{-- لوحة القرار، سواء تبع استلاماً أو بدأه المسؤول من هنا --}}
            @if ($rulingStage)
                <div class="mt-4 rounded-xl border border-maroon/40 dark:border-white/20 p-4 space-y-3">
                    <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100">
                        قرار بشأن «{{ $rulingStage->name }}»
                    </div>

                    <flux:select wire:model="rulingStanding" size="sm" label="الحالة الجديدة">
                        <flux:select.option value="ok">عادية — تحجز بلا قيد</flux:select.option>
                        <flux:select.option value="prepay">رسوم مقدماً — يُسجَّل الحجز ولا يُؤكَّد حتى تُدفع</flux:select.option>
                        <flux:select.option value="banned">محرومة — لا تحجز إطلاقاً</flux:select.option>
                    </flux:select>

                    <flux:input wire:model="rulingReason" size="sm" label="السبب"
                        placeholder="يظهر للمرحلة عند محاولة الحجز" />

                    <div class="flex justify-end gap-2">
                        <flux:button size="sm" variant="ghost" wire:click="closePanels">إلغاء</flux:button>
                        @if ($rulingBookingId)
                            <flux:button size="sm" variant="ghost" wire:click="overlook">تجاوز هذه المرة</flux:button>
                        @endif
                        <flux:button size="sm" variant="primary" wire:click="saveRuling">حفظ القرار</flux:button>
                    </div>
                </div>
            @endif
        </div>

        {{-- ─────────── الحجوزات ─────────── --}}
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
            <div class="flex items-center gap-1 p-1 m-4 bg-zinc-100 dark:bg-zinc-800 rounded-lg">
                @foreach ([
                    'waiting' => 'بانتظار الاستلام'.($waitingCount > 0 ? " ({$waitingCount})" : ''),
                    'upcoming' => 'القادمة',
                    'all' => 'الكل',
                ] as $key => $label)
                    <button type="button" wire:key="filter-{{ $key }}" wire:click="setFilter('{{ $key }}')"
                        @class([
                            'flex-1 px-3 py-1.5 text-sm font-medium rounded-md',
                            'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm' => $filter === $key,
                            'text-zinc-500 dark:text-zinc-400' => $filter !== $key,
                        ])>{{ $label }}</button>
                @endforeach
            </div>

            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($bookings as $booking)
                    <div wire:key="booking-{{ $booking->id }}" class="px-5 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100 truncate">
                                    {{ $booking->stage?->name ?? '—' }}
                                </div>
                                <div class="text-xs text-zinc-400 mt-0.5">
                                    {{ $this->hijri($booking->date->format('Y-m-d')) }}
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                                    {{ $booking->buses->pluck('name')->implode('، ') }}
                                </div>
                            </div>

                            <div class="flex flex-col items-end gap-2 shrink-0">
                                <flux:badge size="sm" :color="$statusColors[$booking->status] ?? 'zinc'">
                                    {{ $statusLabels[$booking->status] ?? $booking->status }}
                                </flux:badge>

                                @if ($booking->isPending())
                                    <flux:button size="xs" variant="primary" wire:click="markPaid({{ $booking->id }})">
                                        استلمت {{ $booking->fee_total }} ﷼
                                    </flux:button>
                                @endif

                                @if ($this->canReceive($booking) && $receivingId !== $booking->id)
                                    <flux:button size="xs" variant="filled" wire:click="startReceive({{ $booking->id }})">
                                        استلام
                                    </flux:button>
                                @endif

                                @unless ($booking->isCancelled())
                                    <flux:button size="xs" variant="ghost" class="text-red-400 hover:text-red-600"
                                        wire:click="cancel({{ $booking->id }})"
                                        wire:confirm="إلغاء حجز «{{ $booking->stage?->name }}»؟">إلغاء</flux:button>
                                @endunless
                            </div>
                        </div>

                        {{-- الخطوة الثانية: تأشير البنود --}}
                        @if ($receivingId === $booking->id)
                            <div class="mt-4 rounded-xl border border-maroon/40 dark:border-white/20 p-4 space-y-3">
                                <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100">
                                    أشّر ما أُنجز عند التسليم
                                </div>

                                @forelse ($items as $item)
                                    <label wire:key="check-{{ $item->id }}"
                                        class="flex items-center gap-3 p-2.5 rounded-lg border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                        <input type="checkbox" wire:model="doneItems" value="{{ $item->id }}"
                                            class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500">
                                        <span class="text-sm text-zinc-700 dark:text-zinc-200">{{ $item->label }}</span>
                                    </label>
                                @empty
                                    <div class="text-sm text-zinc-400">لا بنود معرَّفة. أضفها من لوحة المدير.</div>
                                @endforelse

                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost" wire:click="closePanels">إلغاء</flux:button>
                                    <flux:button size="sm" variant="primary" wire:click="saveReceive">تسجيل الاستلام</flux:button>
                                </div>
                            </div>
                        @endif

                        {{-- ما سُجّل عند استلام سابق --}}
                        @if ($booking->checks->isNotEmpty() && $receivingId !== $booking->id)
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                @foreach ($booking->checks as $check)
                                    <span wire:key="done-{{ $check->id }}"
                                        @class([
                                            'inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px]',
                                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' => $check->is_done,
                                            'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300' => ! $check->is_done,
                                        ])>
                                        <flux:icon :icon="$check->is_done ? 'check' : 'x-mark'" class="size-3" />
                                        {{ $check->label }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="px-5 py-16 text-center">
                        <flux:icon icon="truck" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                        <div class="text-sm text-zinc-400">
                            @if ($filter === 'waiting') لا شيء بانتظار الاستلام. @else لا حجوزات. @endif
                        </div>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
