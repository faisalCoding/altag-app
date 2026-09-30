{{--
    The same shape as the students' register, for the same reason: a roll call
    is thirty taps, and a round trip on each of them is thirty waits.

    Alpine owns everything that is only about the screen — which mode, which
    teacher the walk is on, the search box, the count. Livewire is asked only
    to write: mark, markRemainingPresent, clearDay, and a reload when the day
    or the circle changes.
--}}
@php
    // The classes are spelled out rather than built from a colour name:
    // Tailwind scans this file, not the compiled output, and never sees a class
    // that Blade assembles — the buttons would have come out colourless.
    $options = [
        'present' => [
            'label' => 'حاضر',
            'on' => 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20',
            'off' => 'border-zinc-200 dark:border-zinc-700 hover:border-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/10',
            'chip' => 'bg-emerald-500 text-white border-emerald-500',
        ],
        'late' => [
            'label' => 'متأخر',
            'on' => 'border-amber-500 bg-amber-50 dark:bg-amber-900/20',
            'off' => 'border-zinc-200 dark:border-zinc-700 hover:border-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/10',
            'chip' => 'bg-amber-500 text-white border-amber-500',
        ],
        'excused' => [
            'label' => 'مستأذن',
            'on' => 'border-sky-500 bg-sky-50 dark:bg-sky-900/20',
            'off' => 'border-zinc-200 dark:border-zinc-700 hover:border-sky-400 hover:bg-sky-50 dark:hover:bg-sky-900/10',
            'chip' => 'bg-sky-500 text-white border-sky-500',
        ],
        'absent' => [
            'label' => 'غائب',
            'on' => 'border-rose-500 bg-rose-50 dark:bg-rose-900/20',
            'off' => 'border-zinc-200 dark:border-zinc-700 hover:border-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/10',
            'chip' => 'bg-rose-500 text-white border-rose-500',
        ],
    ];
    $ar = fn ($n) => App\Support\HijriDate::arabicDigits((int) $n);
@endphp

<div class="space-y-6" x-data="{
        mode: 'wizard',
        currentIndex: 0,
        search: '',
        records: @entangle('records'),
        order: @entangle('teacherOrder'),
        syncing: [],
        names: { @foreach ($teachers as $t) {{ $t->id }}: @js($t->name), @endforeach },

        init() {
            $wire.on('teachersLoaded', () => { this.currentIndex = 0; this.search = ''; });
        },

        get markedCount() {
            return this.order.filter(id => this.records[id]).length;
        },

        get isComplete() {
            return this.order.length > 0 && this.order.every(id => this.records[id]);
        },

        statusOf(id) { return this.records[id] || ''; },

        isVisible(id) {
            return ! this.search || (this.names[id] || '').includes(this.search);
        },

        /* The visual change and the walk happen at once; the write follows on
           its own time, so a slow connection never holds up the next tap. */
        async markAndAdvance(id, status) {
            this.records[id] = status;
            this.syncing.push(id);
            this.advance();

            await $wire.mark(id, status);

            this.syncing = this.syncing.filter(x => x !== id);
        },

        async setStatus(id, status) {
            this.records[id] = status;
            this.syncing.push(id);

            await $wire.mark(id, status);

            this.syncing = this.syncing.filter(x => x !== id);
        },

        advance() {
            for (let i = this.currentIndex + 1; i < this.order.length; i++) {
                if (! this.records[this.order[i]]) { this.currentIndex = i; return; }
            }
            for (let i = 0; i <= this.currentIndex; i++) {
                if (! this.records[this.order[i]]) { this.currentIndex = i; return; }
            }
        },
    }">

    {{-- ─────────── الترويسة ─────────── --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                <flux:icon icon="clipboard-document-check" />
            </div>
            <div>
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">تحضير المعلمين</flux:heading>
                <flux:subheading>{{ $hijri }}</flux:subheading>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <flux:button size="sm" icon="check-circle" x-show="! isComplete && order.length > 0"
                wire:click="markRemainingPresent">تحضير الباقين</flux:button>

            <flux:button size="sm" variant="ghost" icon="trash" class="text-red-500 hover:text-red-600"
                x-show="markedCount > 0" wire:click="clearDay"
                wire:confirm="حذف تحضير هذا اليوم كاملاً؟">حذف التحضير</flux:button>
        </div>
    </div>

    {{-- ─────────── اليوم والتصفية والتقدّم ─────────── --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-4 space-y-4">
        <div class="flex flex-col md:flex-row gap-4 items-end">
            <div class="w-full md:w-56">
                <livewire:manager.hijri-datepicker wire:model.live="date" label="اليوم" />
            </div>

            <div class="w-full md:w-52">
                <flux:select wire:model.live="circleFilter" label="الحلقة">
                    <flux:select.option value="">كل الحلقات</flux:select.option>
                    @foreach ($circles as $circle)
                        <flux:select.option :value="$circle->id">{{ $circle->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            {{-- Client-side: the whole list is already here, and a round trip per
                 keystroke is what this page was rebuilt to stop doing. --}}
            <div class="flex-1">
                <flux:input icon="magnifying-glass" x-model="search" label="بحث" placeholder="اسم المعلم..." />
            </div>

            <div class="flex rounded-lg bg-zinc-100 dark:bg-zinc-800 p-0.5 shrink-0">
                <button type="button" @click="mode = 'wizard'"
                    :class="mode === 'wizard' ? 'bg-white dark:bg-zinc-700 shadow-sm text-maroon dark:text-white' : 'text-zinc-500'"
                    class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors">واحداً تلو الآخر</button>
                <button type="button" @click="mode = 'list'"
                    :class="mode === 'list' ? 'bg-white dark:bg-zinc-700 shadow-sm text-maroon dark:text-white' : 'text-zinc-500'"
                    class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors">قائمة</button>
            </div>
        </div>

        <div x-show="order.length > 0">
            <div class="flex items-center justify-between text-sm text-zinc-500 dark:text-zinc-400 mb-1.5">
                <span>حُضِّر <span class="font-bold" x-text="markedCount"></span> من <span x-text="order.length"></span></span>
                <span x-show="isComplete" class="text-green-600 dark:text-green-400 font-medium flex items-center gap-1">
                    <flux:icon icon="check-circle" class="size-4" />
                    مكتمل
                </span>
            </div>
            <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-2 overflow-hidden">
                <div class="h-2 rounded-full transition-all duration-500 ease-out"
                    :class="isComplete ? 'bg-green-500' : 'bg-maroon'"
                    :style="`width: ${order.length ? (markedCount / order.length) * 100 : 0}%`"></div>
            </div>
        </div>
    </div>

    {{-- ═════════ واحداً تلو الآخر ═════════ --}}
    <div x-show="mode === 'wizard'"
        class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">

        <div x-show="isComplete" x-cloak class="p-14 text-center space-y-4">
            <div class="inline-flex p-4 rounded-full bg-green-50 dark:bg-green-900/20">
                <flux:icon icon="check-circle" class="size-14 text-green-500" />
            </div>
            <flux:heading size="xl" class="text-green-600 dark:text-green-400">تمّ تحضير الجميع</flux:heading>
            <flux:subheading>سُجِّل حضور كل المعلمين في هذا اليوم.</flux:subheading>
            <flux:button @click="mode = 'list'" variant="primary">عرض القائمة للمراجعة</flux:button>
        </div>

        @foreach ($teachers as $index => $teacher)
            <div x-show="! isComplete && currentIndex === {{ $index }}" x-cloak
                wire:key="wizard-{{ $teacher->id }}" class="p-8 md:p-12 text-center space-y-8">

                <div>
                    <span class="inline-flex items-center px-3 py-1 rounded-full bg-zinc-100 dark:bg-zinc-800 text-sm text-zinc-500">
                        {{ $ar($index + 1) }} من {{ $ar(count($teachers)) }}
                    </span>

                    <div class="pt-5">
                        <span class="inline-flex size-20 items-center justify-center rounded-full mb-4 font-bold text-2xl"
                            style="{{ $teacher->avatarStyle() }}">{{ $teacher->initials() }}</span>
                        <h2 class="text-3xl md:text-4xl font-bold text-zinc-900 dark:text-white">{{ $teacher->name }}</h2>
                        <p class="text-sm text-zinc-400 mt-1">
                            {{ $teacher->circles->pluck('name')->implode('، ') ?: 'بلا حلقة' }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 max-w-2xl mx-auto">
                    @foreach ($options as $value => $option)
                        <button type="button" wire:key="w-{{ $teacher->id }}-{{ $value }}"
                            @click="markAndAdvance({{ $teacher->id }}, '{{ $value }}')"
                            :disabled="syncing.includes({{ $teacher->id }})"
                            :class="statusOf({{ $teacher->id }}) === '{{ $value }}' ? @js($option['on']) : @js($option['off'])"
                            class="p-6 rounded-2xl border-2 font-semibold text-zinc-800 dark:text-white transition-colors disabled:opacity-60">
                            {{ $option['label'] }}
                        </button>
                    @endforeach
                </div>

                <div class="flex items-center justify-center gap-2">
                    <flux:button size="sm" variant="ghost" icon="chevron-right"
                        @click="currentIndex = Math.max(0, currentIndex - 1)"
                        x-bind:disabled="currentIndex === 0">السابق</flux:button>
                    <flux:button size="sm" variant="ghost" icon="chevron-left"
                        @click="currentIndex = Math.min(order.length - 1, currentIndex + 1)"
                        x-bind:disabled="currentIndex >= order.length - 1">التالي</flux:button>
                </div>
            </div>
        @endforeach

        @if ($teachers->isEmpty())
            <x-teacher-roll-empty />
        @endif
    </div>

    {{-- ═════════ قائمة ═════════ --}}
    <div x-show="mode === 'list'" x-cloak
        class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($teachers as $teacher)
                <div wire:key="row-{{ $teacher->id }}" x-show="isVisible({{ $teacher->id }})"
                    class="flex flex-col sm:flex-row sm:items-center gap-3 px-4 py-3">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <span class="size-9 shrink-0 rounded-full flex items-center justify-center font-bold text-[11px]"
                            style="{{ $teacher->avatarStyle() }}">{{ $teacher->initials() }}</span>
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-zinc-800 dark:text-zinc-100 truncate">{{ $teacher->name }}</div>
                            <div class="text-xs text-zinc-400 truncate">
                                {{ $teacher->circles->pluck('name')->implode('، ') ?: 'بلا حلقة' }}
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-1 shrink-0">
                        @foreach ($options as $value => $option)
                            <button type="button" wire:key="l-{{ $teacher->id }}-{{ $value }}"
                                @click="setStatus({{ $teacher->id }}, '{{ $value }}')"
                                :disabled="syncing.includes({{ $teacher->id }})"
                                :class="statusOf({{ $teacher->id }}) === '{{ $value }}'
                                    ? @js($option['chip'])
                                    : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800'"
                                class="px-3 py-1.5 rounded-lg border text-xs font-medium transition-colors disabled:opacity-60">
                                {{ $option['label'] }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @empty
                <x-teacher-roll-empty />
            @endforelse
        </div>
    </div>
</div>
