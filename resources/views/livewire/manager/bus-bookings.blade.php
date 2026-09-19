@php
    $weekdayNames = [1 => 'الأحد', 2 => 'الاثنين', 3 => 'الثلاثاء', 4 => 'الأربعاء', 5 => 'الخميس', 6 => 'الجمعة', 7 => 'السبت'];
    $statusLabels = ['pending' => 'بانتظار الدفع', 'confirmed' => 'مؤكَّد', 'received' => 'استُلم', 'cancelled' => 'ملغى'];
    $statusColors = ['pending' => 'amber', 'confirmed' => 'emerald', 'received' => 'zinc', 'cancelled' => 'red'];
@endphp

<div class="space-y-6">
    <div class="flex items-center gap-3">
        <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
            <flux:icon icon="truck" />
        </div>
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">حجز الباصات</flux:heading>
            <flux:subheading>الأسطول، ومتى يُحجز، وما تلتزم به المرحلة</flux:subheading>
        </div>
    </div>

    {{-- ─────────── الروابط ─────────── --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-4 sm:p-5 space-y-4">
        <div>
            <flux:heading size="lg">الروابط</flux:heading>
            <flux:subheading>تُفتح بلا تسجيل دخول. من يملك الرابط يستطيع استعماله — جدّده متى تغيّر الشخص.</flux:subheading>
        </div>

        @foreach ([
            ['supervisor', 'رابط المشرفين', 'يحجزون به', url('/bus-booking/'.$supervisorToken)],
            ['officer', 'رابط مسؤول الباصات', 'يستلم به ويقرّر', url('/bus-officer/'.$officerToken)],
        ] as [$which, $title, $hint, $url])
            <div wire:key="link-{{ $which }}" class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-3 space-y-2">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div class="min-w-0">
                        <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100">{{ $title }}</div>
                        <div class="text-xs text-zinc-400">{{ $hint }}</div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <flux:button size="sm" variant="ghost" icon="clipboard-document"
                            x-on:click="navigator.clipboard.writeText(@js($url)); $dispatch('toast', { message: 'نُسخ الرابط', variant: 'success' })">
                            نسخ
                        </flux:button>
                        <flux:button size="sm" variant="ghost" class="text-red-500 hover:text-red-600"
                            wire:click="regenerateLink('{{ $which }}')"
                            wire:confirm="تجديد الرابط يُبطل القديم فوراً. متأكد؟">تجديد</flux:button>
                    </div>
                </div>
                <div class="text-[11px] font-mono text-zinc-400 break-all dir-ltr text-left bg-zinc-50 dark:bg-zinc-800/50 rounded-lg px-2 py-1.5">{{ $url }}</div>
            </div>
        @endforeach
    </div>

    {{-- ─────────── الباصات ─────────── --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <div class="px-4 sm:px-5 py-4 border-b border-zinc-100 dark:border-zinc-800">
            <flux:heading size="lg">الباصات</flux:heading>
            <flux:subheading>المبلغ هو ما تدفعه المرحلة الموضوعة تحت الرسوم المقدَّمة، لكل باص.</flux:subheading>
        </div>

        <form wire:submit="saveBus" class="px-4 sm:px-5 py-4 grid grid-cols-1 sm:grid-cols-4 gap-3 items-end border-b border-zinc-100 dark:border-zinc-800">
            <flux:input wire:model="busName" size="sm" label="الاسم" placeholder="هايس ١" />
            <flux:input wire:model="busType" size="sm" label="النوع" placeholder="هايس" />
            <flux:input wire:model="busFee" type="number" min="0" size="sm" label="الرسوم (﷼)" />
            <div class="flex gap-2">
                <flux:button type="submit" size="sm" variant="primary" class="flex-1">
                    {{ $editingBusId ? 'حفظ' : 'إضافة' }}
                </flux:button>
                @if ($editingBusId)
                    <flux:button type="button" size="sm" variant="ghost" wire:click="cancelBus">إلغاء</flux:button>
                @endif
            </div>
            <div class="sm:col-span-4">
                <flux:error name="busName" />
                <flux:error name="busFee" />
            </div>
        </form>

        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($buses as $bus)
                <div wire:key="bus-{{ $bus->id }}"
                    class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 px-4 sm:px-5 py-3">
                    <div class="min-w-0">
                        <div class="text-sm font-medium text-zinc-800 dark:text-zinc-100 truncate">
                            {{ $bus->name }}
                            @unless ($bus->is_active)
                                <flux:badge size="sm" color="zinc">معطَّل</flux:badge>
                            @endunless
                        </div>
                        <div class="text-xs text-zinc-400">
                            {{ $bus->type ?: 'بلا نوع' }} · {{ $bus->fee_amount }} ﷼
                            @if ($bus->bookings_count > 0) · {{ $bus->bookings_count }} حجز @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                        <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editBus({{ $bus->id }})" />
                        <flux:button size="xs" variant="ghost" icon="{{ $bus->is_active ? 'eye-slash' : 'eye' }}"
                            wire:click="toggleBus({{ $bus->id }})" />
                        <flux:button size="xs" variant="ghost" icon="trash" class="text-red-400 hover:text-red-600"
                            wire:click="deleteBus({{ $bus->id }})" wire:confirm="حذف هذا الباص؟" />
                    </div>
                </div>
            @empty
                <div class="px-5 py-10 text-center text-sm text-zinc-400">لا باصات بعد.</div>
            @endforelse
        </div>
    </div>

    {{-- ─────────── بنود التسليم ─────────── --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <div class="px-4 sm:px-5 py-4 border-b border-zinc-100 dark:border-zinc-800">
            <flux:heading size="lg">بنود التسليم</flux:heading>
            <flux:subheading>يقرّ بها المشرف عند الحجز، ويؤشّرها المسؤول عند الاستلام.</flux:subheading>
        </div>

        <form wire:submit="saveItem" class="px-4 sm:px-5 py-4 flex gap-2 items-end border-b border-zinc-100 dark:border-zinc-800">
            <div class="flex-1">
                <flux:input wire:model="itemLabel" size="sm" label="نص البند" placeholder="البنزين فل" />
            </div>
            <flux:button type="submit" size="sm" variant="primary">{{ $editingItemId ? 'حفظ' : 'إضافة' }}</flux:button>
            @if ($editingItemId)
                <flux:button type="button" size="sm" variant="ghost" wire:click="cancelItem">إلغاء</flux:button>
            @endif
        </form>

        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($items as $item)
                <div wire:key="item-{{ $item->id }}"
                    class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 px-4 sm:px-5 py-3">
                    <div class="text-sm text-zinc-800 dark:text-zinc-100 min-w-0">
                        {{ $item->label }}
                        @unless ($item->is_active)
                            <flux:badge size="sm" color="zinc">معطَّل</flux:badge>
                        @endunless
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                        <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveItem({{ $item->id }}, -1)" />
                        <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveItem({{ $item->id }}, 1)" />
                        <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editItem({{ $item->id }})" />
                        <flux:button size="xs" variant="ghost" icon="{{ $item->is_active ? 'eye-slash' : 'eye' }}"
                            wire:click="toggleItem({{ $item->id }})" />
                    </div>
                </div>
            @empty
                <div class="px-5 py-10 text-center text-sm text-zinc-400">لا بنود بعد.</div>
            @endforelse
        </div>
    </div>

    {{-- ─────────── الإعدادات ─────────── --}}
    <form wire:submit="saveSettings" class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-4 sm:p-5 space-y-5">
        <flux:heading size="lg">الإعدادات</flux:heading>

        <div>
            <flux:label>أيام الأسبوع المتاحة للحجز</flux:label>
            <div class="flex flex-wrap gap-2 mt-2">
                @foreach ($weekdayNames as $value => $label)
                    <label class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800">
                        <input type="checkbox" wire:model="weekdays" value="{{ $value }}"
                            class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500">
                        <span class="text-sm">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            <p class="text-xs text-zinc-400 mt-2">اتركها كلها دون تحديد ليُسمح بالحجز في أي يوم.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <flux:input wire:model="lockDays" type="number" min="0" max="30" label="تثبيت الحجز قبل الموعد بـ (أيام)"
                description="بعدها لا يستطيع المشرف الإلغاء. المسؤول يستطيع دائماً." />

            <flux:input wire:model="officerPhone" label="واتساب مسؤول الباصات" placeholder="9665xxxxxxxx"
                description="يفتحه زر «تواصل مع المسؤول» للمرحلة المحرومة." />
        </div>

        <flux:textarea wire:model="penaltyText" rows="6" label="نصّ العقوبات"
            description="يُعرض للمشرف عند الحجز. تحذير يُقرأ، لا بند يُؤشَّر." />

        <flux:error name="lockDays" />

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">حفظ الإعدادات</flux:button>
        </div>
    </form>

    {{-- ─────────── الحجوزات القادمة ─────────── --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <div class="px-4 sm:px-5 py-4 border-b border-zinc-100 dark:border-zinc-800">
            <flux:heading size="lg">الحجوزات القادمة</flux:heading>
            <flux:subheading>للاطّلاع. الاستلام والقرارات من رابط المسؤول.</flux:subheading>
        </div>

        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($upcoming as $booking)
                <div wire:key="upcoming-{{ $booking->id }}" class="flex items-center justify-between gap-3 px-4 sm:px-5 py-3">
                    <div class="min-w-0">
                        <div class="text-sm font-medium text-zinc-800 dark:text-zinc-100 truncate">
                            {{ $booking->stage?->name ?? '—' }}
                        </div>
                        <div class="text-xs text-zinc-400 truncate">
                            <x-hijri-date :date="$booking->date" /> · {{ $booking->buses->pluck('name')->implode('، ') }}
                            @if ($booking->fee_total > 0) · {{ $booking->fee_total }} ﷼ @endif
                        </div>
                    </div>
                    <flux:badge size="sm" :color="$statusColors[$booking->status] ?? 'zinc'" class="shrink-0">
                        {{ $statusLabels[$booking->status] ?? $booking->status }}
                    </flux:badge>
                </div>
            @empty
                <div class="px-5 py-10 text-center text-sm text-zinc-400">لا حجوزات قادمة.</div>
            @endforelse
        </div>
    </div>
</div>
