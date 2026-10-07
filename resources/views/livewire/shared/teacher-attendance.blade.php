{{--
    The same shape as the students' register, for the same reason: a roll call
    is thirty taps, and a round trip on each of them is thirty waits.

    Alpine owns everything that is only about the screen — which mode, which
    teacher the walk is on, the search box, the count. Livewire is asked only
    to write: mark, markRemainingPresent, clearDay, and a reload when the day
    or the circle changes.

    The supervisor's and the manager's alike; only the teachers it reaches differ.
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
    // Everyone the day reaches is off: a holiday, not an empty filter.
    $offDay = $teachers->isEmpty() && $offDutyCount > 0;
    $empty = $offDay
        ? ['icon' => 'calendar-days', 'heading' => 'يوم إجازة', 'message' => 'هذا اليوم ليس من أيام الدوام في التقويم الأكاديمي، فلا يُحضَّر فيه أحد.']
        : ['icon' => 'users', 'heading' => 'لا معلمين', 'message' => $emptyMessage];
@endphp

<div class="space-y-6" x-data="{
        mode: 'wizard',
        currentIndex: 0,
        search: '',
        records: @entangle('records'),
        order: @entangle('teacherOrder'),
        syncing: [],
        editor: { id: null, name: '', note: '', arrived: '', substitute: '' },
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

        /* With a search typed, 'the rest' is the rest still showing — never
           the teachers the search has hidden. */
        markRemaining() {
            $wire.markRemainingPresent(this.search ? this.order.filter(id => this.isVisible(id)) : null);
        },

        /* One editor for every row: the reason, and the arrival time or the
           substitute the status calls for. A form on each row came to half a
           megabyte of markup with thirty teachers. */
        openEditor(id, name, note, arrived, substitute) {
            this.editor = { id, name, note, arrived, substitute };
        },

        async saveEditor() {
            const { id, note, arrived, substitute } = this.editor;
            await $wire.saveNote(id, note, arrived, substitute || null);
            this.editor.id = null;
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
            <flux:button size="sm" icon="check-circle" x-show="! isComplete && order.length > 0 && ! $wire.locked"
                x-on:click="markRemaining()">
                <span x-text="search ? 'تحضير الظاهرين' : 'تحضير الباقين'">تحضير الباقين</span>
            </flux:button>

            <flux:button size="sm" variant="ghost" icon="trash" class="text-red-500 hover:text-red-600"
                x-show="markedCount > 0 && ! $wire.locked" wire:click="clearDay"
                wire:confirm="{{ $clearPrompt }}">حذف التحضير</flux:button>
        </div>
    </div>

    {{-- ─────────── اليوم والتصفية والتقدّم ─────────── --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-4 space-y-4">
        <div class="flex flex-col md:flex-row gap-4 items-end">
            <div class="w-full md:w-56">
                <livewire:manager.hijri-datepicker wire:model.live="date" label="اليوم" :max-date="$today" />
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

        @if ($locked)
            <p class="flex items-start gap-1.5 text-sm text-amber-700 dark:text-amber-400">
                <flux:icon icon="lock-closed" class="size-4 shrink-0 mt-0.5" />
                هذا اليوم مقفل: مضى عليه أكثر من {{ $ar($lockDays) }} أيام، فلا يعدّل تحضيره إلا المدير.
            </p>
        @endif

        @if ($offDutyCount > 0 && ! $offDay)
            <p class="flex items-center gap-1.5 text-sm text-amber-700 dark:text-amber-400">
                <flux:icon icon="calendar" class="size-4 shrink-0" />
                لا يظهر {{ $ar($offDutyCount) }} من المعلمين لأن هذا اليوم ليس يوم دوام في مراحلهم.
            </p>
        @endif

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
                            :disabled="syncing.includes({{ $teacher->id }}) || $wire.locked"
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
            <x-teacher-roll-empty :icon="$empty['icon']" :heading="$empty['heading']" :message="$empty['message']" />
        @endif
    </div>

    {{-- ═════════ قائمة ═════════ --}}
    <div x-show="mode === 'list'" x-cloak
        class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($teachers as $teacher)
                @php
                    $detail = $details->get($teacher->id);
                    $minutesLate = $detail?->minutesLate();
                @endphp
                <div wire:key="row-{{ $teacher->id }}" x-show="isVisible({{ $teacher->id }})"
                    class="px-4 py-3 space-y-2">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
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
                                :disabled="syncing.includes({{ $teacher->id }}) || $wire.locked"
                                :class="statusOf({{ $teacher->id }}) === '{{ $value }}'
                                    ? @js($option['chip'])
                                    : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800'"
                                class="px-3 py-1.5 rounded-lg border text-xs font-medium transition-colors disabled:opacity-60">
                                {{ $option['label'] }}
                            </button>
                        @endforeach

                        {{-- The reason, and for a late teacher the arrival time: once the day is marked. --}}
                        <button type="button" x-show="statusOf({{ $teacher->id }}) && ! $wire.locked" x-cloak
                            x-on:click="openEditor({{ $teacher->id }}, @js($teacher->name), @js($detail?->notes ?? ''), @js($detail?->arrivalLabel() ?? ''), @js((string) ($detail?->substitute_teacher_id ?? '')))"
                            class="ms-1 p-1.5 rounded-lg border border-zinc-200 dark:border-zinc-700 text-zinc-500 hover:bg-zinc-50 dark:hover:bg-zinc-800"
                            title="السبب أو ملاحظة" aria-label="السبب أو ملاحظة">
                            <flux:icon icon="chat-bubble-left-ellipsis" class="size-4" />
                        </button>
                    </div>
                </div>

                @if ($detail && ($detail->notes || $detail->arrived_at || $detail->substitute))
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400 sm:ps-12">
                        @if ($detail->status === 'late' && $detail->arrived_at)
                            <span class="inline-flex items-center gap-1 text-amber-700 dark:text-amber-400">
                                <flux:icon icon="clock" class="size-3.5" />
                                حضر الساعة <span dir="ltr">{{ $detail->arrivalLabel() }}</span>
                                @if ($minutesLate)
                                    · متأخراً {{ $ar($minutesLate) }} دقيقة
                                @endif
                            </span>
                        @endif
                        @if ($detail->notes)
                            <span>السبب: {{ $detail->notes }}</span>
                        @endif
                        @if ($detail->substitute && in_array($detail->status, \App\Models\TeacherAttendance::AWAY, true))
                            <span class="inline-flex items-center gap-1">
                                <flux:icon icon="arrows-right-left" class="size-3.5" />
                                البديل: {{ $detail->substitute->name }}
                            </span>
                        @endif
                    </div>
                @endif

                </div>
            @empty
                <x-teacher-roll-empty :icon="$empty['icon']" :heading="$empty['heading']" :message="$empty['message']" />
            @endforelse
        </div>
    </div>

    {{-- ═════════ السبب والتفاصيل ═════════ --}}
    <div x-show="editor.id" x-cloak x-transition.opacity
        class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-4"
        x-on:click.self="editor.id = null" x-on:keydown.escape.window="editor.id = null">
        <form x-on:submit.prevent="saveEditor()"
            class="w-full max-w-md bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xl p-5 space-y-4">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <flux:heading size="lg" class="truncate" x-text="editor.name"></flux:heading>
                    <flux:subheading>{{ $hijri }}</flux:subheading>
                </div>
                <flux:button type="button" size="sm" variant="ghost" icon="x-mark" x-on:click="editor.id = null" aria-label="إغلاق" />
            </div>

            <div x-show="statusOf(editor.id) === 'late'">
                <flux:input type="time" x-model="editor.arrived" label="وقت الحضور" />
            </div>

            <div x-show="['absent', 'excused'].includes(statusOf(editor.id))">
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-200 mb-1.5">المعلم البديل</label>
                <select x-model="editor.substitute"
                    class="w-full h-10 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-sm text-zinc-800 dark:text-zinc-100 px-3">
                    <option value="">بلا بديل</option>
                    @foreach ($substitutes as $candidate)
                        @php
                            $candidateStages = $candidate->circles->pluck('stage.name')->filter()->unique()->implode('، ');
                        @endphp
                        <option value="{{ $candidate->id }}" x-bind:disabled="editor.id === {{ $candidate->id }}">{{ $candidate->name }}{{ $candidateStages !== '' ? ' — '.$candidateStages : '' }}</option>
                    @endforeach
                </select>
                <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400" x-show="editor.substitute">
                    يعمل البديل في حلقات المعلم الغائب في هذا اليوم كمعلمها، لتاريخ اليوم فقط.
                </p>
            </div>

            <flux:input x-model="editor.note" maxlength="500" label="السبب أو ملاحظة"
                x-bind:placeholder="statusOf(editor.id) === 'absent' ? 'سبب الغياب' : (statusOf(editor.id) === 'excused' ? 'سبب الاستئذان' : '')" />

            <div class="flex gap-2 justify-end">
                <flux:button type="button" variant="ghost" x-on:click="editor.id = null">إلغاء</flux:button>
                <flux:button type="submit" variant="primary">حفظ</flux:button>
            </div>
        </form>
    </div>

    {{-- ═════════ سجل التعديلات ═════════ --}}
    @if ($revisions->isNotEmpty())
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden"
            x-data="{ open: false }">
            <button type="button" x-on:click="open = ! open"
                class="w-full flex items-center justify-between gap-3 px-5 py-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                <div class="flex items-center gap-2.5">
                    <flux:icon icon="clock" class="size-5 text-zinc-400" />
                    <flux:heading size="sm">سجل تعديلات هذا اليوم</flux:heading>
                    <flux:badge size="sm" color="zinc">{{ $ar($revisions->count()) }}</flux:badge>
                </div>
                <flux:icon icon="chevron-down" class="size-4 text-zinc-400" x-bind:class="open && 'rotate-180'" />
            </button>

            <div x-cloak x-show="open" x-collapse class="border-t border-zinc-100 dark:border-zinc-800">
                <div class="max-h-80 overflow-auto divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @foreach ($revisions as $revision)
                        <div wire:key="revision-{{ $revision->id }}" class="flex flex-wrap items-start gap-x-3 gap-y-1 px-5 py-3 text-xs">
                            <span class="font-bold text-zinc-800 dark:text-zinc-100 min-w-32">{{ $revision->teacher?->name }}</span>
                            @if ($revision->summary())
                                <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $revision->summary() }}</span>
                            @endif
                            @if ($revision->arrivalChanged())
                                <span class="text-zinc-600 dark:text-zinc-300">
                                    وقت الحضور: <span dir="ltr">{{ $revision->new_arrived_at ? substr($revision->new_arrived_at, 0, 5) : '—' }}</span>
                                </span>
                            @endif
                            @if ($revision->substituteChanged())
                                <span class="text-zinc-600 dark:text-zinc-300">
                                    {{ $revision->newSubstitute ? 'البديل: '.$revision->newSubstitute->name : 'أُزيل البديل' }}
                                </span>
                            @endif
                            @if ($revision->notesChanged())
                                <span class="text-zinc-600 dark:text-zinc-300">
                                    {{ $revision->new_notes ? 'السبب: '.$revision->new_notes : 'حُذف السبب' }}
                                </span>
                            @endif

                            <span class="text-zinc-400 ms-auto whitespace-nowrap">
                                {{ $revision->editorLabel() }} · <span dir="ltr">{{ $revision->created_at?->timezone('Asia/Riyadh')->format('H:i') }}</span>
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
