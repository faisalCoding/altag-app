{{--
The month as a spreadsheet. Alpine owns everything about *editing*:

    baseline  — what the server has saved, re-read after every successful save
    edits     — staged cells, "studentId|date" => status, not yet written
    sel       — the selected rectangle, so a sweep can be filled in one go

Livewire is touched exactly twice: to change month, and to save the batch.
A cell whose date is not today is an off-day edit and cannot be saved without
a reason — enforced here for the prompt, and again on the server for real.
--}}
@php
    $days = $this->days;
    $students = $this->students;
    $grid = $this->grid;
    $editableMap = $this->editable;
    $dayTotals = $this->dayTotals;
    $studentTotals = $this->studentTotals;
    $today = $this->today();

    $statusMeta = [
        'present' => ['letter' => 'ح', 'label' => 'حاضر'],
        'absent' => ['letter' => 'غ', 'label' => 'غائب'],
        'late' => ['letter' => 'ت', 'label' => 'متأخر'],
        'excused' => ['letter' => 'ذ', 'label' => 'مستأذن'],
    ];

    $baseline = [];
    $editableFlat = [];
    foreach ($students as $student) {
        foreach ($days as $day) {
            $key = $student->id.'|'.$day['date'];
            $status = $grid[$student->id][$day['date']] ?? '';
            if ($status !== '') {
                $baseline[$key] = $status;
            }
            $editableFlat[$key] = ($editableMap[$student->id][$day['date']] ?? false) && ! $day['is_future'];
        }
    }
@endphp

<div dir="rtl"
    x-data="{
        today: @js($today),
        baseline: @js((object) $baseline),
        editable: @js((object) $editableFlat),
        rows: @js($students->pluck('id')->values()),
        cols: @js(collect($days)->pluck('date')->values()),
        edits: {},
        sel: null,
        anchor: null,
        dragging: false,
        dragged: false,
        reason: '',
        saving: false,

        /* ── Reading a cell ─────────────────────────────────────── */
        key(row, col) { return row + '|' + col },

        status(row, col) {
            const k = this.key(row, col);
            return k in this.edits ? this.edits[k] : (this.baseline[k] ?? '');
        },

        isDirty(row, col) {
            const k = this.key(row, col);
            return k in this.edits && this.edits[k] !== (this.baseline[k] ?? '');
        },

        canEdit(row, col) { return this.editable[this.key(row, col)] === true },

        letter(status) {
            return { present: 'ح', absent: 'غ', late: 'ت', excused: 'ذ' }[status] ?? '';
        },

        statusClass(status) {
            return {
                present: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
                absent: 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300',
                late: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                excused: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
            }[status] ?? 'bg-white dark:bg-zinc-900 text-zinc-300 dark:text-zinc-700';
        },

        /* ── Writing ────────────────────────────────────────────── */
        setCell(r, c, status) {
            const row = this.rows[r], col = this.cols[c];
            if (!this.canEdit(row, col)) return;

            const k = this.key(row, col);
            if ((this.baseline[k] ?? '') === status) { delete this.edits[k]; }
            else { this.edits[k] = status; }
        },

        cycle(r, c) {
            const order = ['', 'present', 'absent', 'late', 'excused'];
            const current = this.status(this.rows[r], this.cols[c]);
            this.setCell(r, c, order[(order.indexOf(current) + 1) % order.length]);
        },

        applyToSelection(status) {
            if (!this.sel) return;
            for (let r = this.sel.r1; r <= this.sel.r2; r++) {
                for (let c = this.sel.c1; c <= this.sel.c2; c++) { this.setCell(r, c, status); }
            }
        },

        /* ── Selecting ──────────────────────────────────────────── */
        rect(a, b) {
            return {
                r1: Math.min(a.r, b.r), r2: Math.max(a.r, b.r),
                c1: Math.min(a.c, b.c), c2: Math.max(a.c, b.c),
            };
        },

        inSelection(r, c) {
            return this.sel && r >= this.sel.r1 && r <= this.sel.r2 && c >= this.sel.c1 && c <= this.sel.c2;
        },

        startDrag(r, c, shift) {
            /* mousedown is prevented so a sweep does not select text, which also
               costs the cell its focus — the grid takes it instead, so the
               keyboard shortcuts keep working. */
            this.$refs.grid?.focus({ preventScroll: true });

            if (shift && this.anchor) { this.sel = this.rect(this.anchor, { r, c }); return; }
            this.anchor = { r, c };
            this.sel = this.rect(this.anchor, { r, c });
            this.dragging = true;
            this.dragged = false;
        },

        extendDrag(r, c) {
            if (!this.dragging) return;
            this.dragged = true;
            this.sel = this.rect(this.anchor, { r, c });
        },

        endDrag(r, c) {
            const wasDrag = this.dragged;
            this.dragging = false;
            this.dragged = false;
            if (!wasDrag) { this.cycle(r, c); }
        },

        selectColumn(c) {
            this.anchor = { r: 0, c };
            this.sel = { r1: 0, r2: Math.max(0, this.rows.length - 1), c1: c, c2: c };
        },

        selectRow(r) {
            this.anchor = { r, c: 0 };
            this.sel = { r1: r, r2: r, c1: 0, c2: Math.max(0, this.cols.length - 1) };
        },

        selectAll() {
            this.anchor = { r: 0, c: 0 };
            this.sel = { r1: 0, r2: Math.max(0, this.rows.length - 1), c1: 0, c2: Math.max(0, this.cols.length - 1) };
        },

        moveFocus(dr, dc, shift) {
            if (!this.anchor) { this.anchor = { r: 0, c: 0 }; this.sel = this.rect(this.anchor, this.anchor); return; }
            const head = shift && this.sel ? { r: this.sel.r2, c: this.sel.c2 } : this.anchor;
            const r = Math.min(Math.max(head.r + dr, 0), this.rows.length - 1);
            const c = Math.min(Math.max(head.c + dc, 0), this.cols.length - 1);
            if (shift) { this.sel = this.rect(this.anchor, { r, c }); }
            else { this.anchor = { r, c }; this.sel = this.rect(this.anchor, this.anchor); }
        },

        onKey(event) {
            const keyed = {
                '1': 'present', '2': 'absent', '3': 'late', '4': 'excused',
                'ح': 'present', 'غ': 'absent', 'ت': 'late', 'ذ': 'excused',
                '0': '', 'Delete': '', 'Backspace': '',
            };

            if (event.key in keyed) { event.preventDefault(); this.applyToSelection(keyed[event.key]); return; }

            const moves = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowRight: [0, -1], ArrowLeft: [0, 1] };
            if (event.key in moves) {
                event.preventDefault();
                this.moveFocus(moves[event.key][0], moves[event.key][1], event.shiftKey);
            }
        },

        /* ── Saving ─────────────────────────────────────────────── */
        get changes() {
            return Object.entries(this.edits)
                .filter(([k, status]) => status !== (this.baseline[k] ?? ''))
                .map(([k, status]) => {
                    const [student_id, date] = k.split('|');
                    return { student_id: Number(student_id), date, status };
                });
        },

        get dirtyCount() { return this.changes.length },

        get offDayCount() { return this.changes.filter(c => c.date !== this.today).length },

        discard() { this.edits = {}; this.reason = ''; },

        /* Re-read what the server now holds, so saved cells stop reading as edits. */
        syncBaseline() {
            this.baseline = JSON.parse(this.$refs.baseline.dataset.grid);
            this.editable = JSON.parse(this.$refs.baseline.dataset.editable);
            this.rows = JSON.parse(this.$refs.baseline.dataset.rows);
            this.cols = JSON.parse(this.$refs.baseline.dataset.cols);
        },

        async save() {
            if (this.saving || this.dirtyCount === 0) return;

            if (this.offDayCount > 0 && this.reason.trim() === '') {
                $flux.modal('sheet-reason').show();
                return;
            }

            this.saving = true;
            const ok = await $wire.saveChanges(this.changes, this.reason);
            this.saving = false;

            if (ok) {
                this.edits = {};
                this.reason = '';
                this.sel = null;
                $flux.modal('sheet-reason').close();
                this.$nextTick(() => this.syncBaseline());
            }
        },

        async saveWithReason() {
            if (this.reason.trim() === '') return;
            $flux.modal('sheet-reason').close();
            await this.save();
        },
    }"
    x-on:keydown.window="if ($el.contains(document.activeElement)) onKey($event)"
    x-on:mouseup.window="dragging = false"
    class="space-y-4">

    {{-- What the server currently holds, re-read after each save. --}}
    <div x-ref="baseline" class="hidden"
        data-grid="{{ json_encode((object) $baseline) }}"
        data-editable="{{ json_encode((object) $editableFlat) }}"
        data-rows="{{ json_encode($students->pluck('id')->values()) }}"
        data-cols="{{ json_encode(collect($days)->pluck('date')->values()) }}"></div>

    {{-- ══════════════════ TOOLBAR ══════════════════ --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-4 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            {{-- Month navigation --}}
            <div class="flex items-center gap-1.5">
                <flux:button wire:click="previousMonth" size="sm" variant="ghost" icon="chevron-right"
                    x-bind:disabled="dirtyCount > 0" />
                <div class="px-3 py-1.5 rounded-lg bg-zinc-50 dark:bg-zinc-800 text-sm font-bold text-zinc-800 dark:text-zinc-100 min-w-36 text-center">
                    {{ $this->monthLabel() }}
                </div>
                <flux:button wire:click="nextMonth" size="sm" variant="ghost" icon="chevron-left"
                    x-bind:disabled="dirtyCount > 0" />
                <flux:button wire:click="goToCurrentMonth" size="sm" variant="ghost"
                    x-bind:disabled="dirtyCount > 0">الشهر الحالي</flux:button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <flux:button wire:click="exportCsv" size="sm" variant="ghost" icon="arrow-down-tray">تصدير Excel</flux:button>
                <flux:button x-on:click="selectAll()" size="sm" variant="ghost" icon="squares-2x2">تحديد الكل</flux:button>
            </div>
        </div>

        {{-- Status palette — fills whatever is selected --}}
        <div class="flex flex-wrap items-center gap-2 pt-3 border-t border-zinc-100 dark:border-zinc-800">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                حدّد خلايا ثم اختر الحالة:
            </span>

            @foreach ($statusMeta as $value => $meta)
                <button type="button" x-on:click="applyToSelection('{{ $value }}')"
                    x-bind:disabled="!sel"
                    class="px-3 py-1.5 rounded-lg text-xs font-bold border disabled:opacity-40 disabled:cursor-not-allowed
                        @if ($value === 'present') bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-300 dark:border-emerald-800
                        @elseif ($value === 'absent') bg-rose-50 text-rose-700 border-rose-200 hover:bg-rose-100 dark:bg-rose-900/30 dark:text-rose-300 dark:border-rose-800
                        @elseif ($value === 'late') bg-amber-50 text-amber-700 border-amber-200 hover:bg-amber-100 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-800
                        @else bg-sky-50 text-sky-700 border-sky-200 hover:bg-sky-100 dark:bg-sky-900/30 dark:text-sky-300 dark:border-sky-800 @endif">
                    <span class="font-black">{{ $meta['letter'] }}</span>
                    <span class="mr-1">{{ $meta['label'] }}</span>
                    <span class="text-[10px] opacity-60">{{ $loop->iteration }}</span>
                </button>
            @endforeach

            <button type="button" x-on:click="applyToSelection('')" x-bind:disabled="!sel"
                class="px-3 py-1.5 rounded-lg text-xs font-bold border bg-zinc-50 text-zinc-600 border-zinc-200 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300 dark:border-zinc-700 disabled:opacity-40 disabled:cursor-not-allowed">
                مسح <span class="text-[10px] opacity-60">0</span>
            </button>

            <span class="text-[11px] text-zinc-400 dark:text-zinc-500 mr-auto hidden lg:inline">
                نقرة = تبديل الحالة · سحب = تحديد · اسم الطالب = صف كامل · رأس اليوم = عمود كامل · الأسهم للتنقل
            </span>
        </div>
    </div>

    {{-- ══════════════════ THE SHEET ══════════════════ --}}
    @if (! $this->circleId)
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-16 text-center">
            <flux:icon icon="table-cells" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
            <flux:heading size="lg" class="text-zinc-500 dark:text-zinc-400">اختر حلقة لعرض الجدول</flux:heading>
        </div>
    @elseif (empty($days))
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-16 text-center">
            <flux:icon icon="calendar-days" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
            <flux:heading size="lg" class="text-zinc-500 dark:text-zinc-400">لا توجد أيام دراسية في هذا الشهر</flux:heading>
            <flux:subheading class="text-zinc-400">حسب التقويم الدراسي المعتمد لهذه المرحلة</flux:subheading>
        </div>
    @elseif ($students->isEmpty())
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-16 text-center">
            <flux:icon icon="users" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
            <flux:heading size="lg" class="text-zinc-500 dark:text-zinc-400">لا يوجد طلاب معتمدون في هذه الحلقة</flux:heading>
        </div>
    @else
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
            <div class="overflow-auto max-h-[70vh] select-none focus:outline-none" tabindex="0" x-ref="grid">
                <table class="border-collapse text-sm w-max min-w-full">
                    <thead>
                        <tr>
                            <th class="sticky top-0 right-0 z-30 bg-zinc-50 dark:bg-zinc-800 border-b border-l border-zinc-200 dark:border-zinc-700 px-3 py-2 text-right min-w-52">
                                <span class="text-xs font-bold text-zinc-600 dark:text-zinc-300">الطالب</span>
                            </th>

                            @foreach ($days as $index => $day)
                                <th class="sticky top-0 z-20 border-b border-l border-zinc-200 dark:border-zinc-700 p-0 w-12
                                    {{ $day['is_today'] ? 'bg-maroon/10 dark:bg-maroon/25' : 'bg-zinc-50 dark:bg-zinc-800' }}">
                                    <button type="button" x-on:click="selectColumn({{ $index }})"
                                        class="w-full px-1 py-2 hover:bg-zinc-100 dark:hover:bg-zinc-700 leading-tight"
                                        title="{{ \App\Support\HijriDate::withWeekday($day['date']) }}{{ $day['is_today'] ? ' — اليوم' : '' }}">
                                        <span class="block text-[10px] text-zinc-400 dark:text-zinc-500">{{ $day['weekday'] }}</span>
                                        <span class="block text-xs font-bold {{ $day['is_today'] ? 'text-maroon dark:text-white' : 'text-zinc-700 dark:text-zinc-200' }}">
                                            {{ $day['day'] }}
                                        </span>
                                        @if ($day['is_today'])
                                            <span class="block size-1.5 rounded-full bg-maroon mx-auto mt-0.5"></span>
                                        @endif
                                    </button>
                                </th>
                            @endforeach

                            <th class="sticky top-0 z-20 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700 px-3 py-2 min-w-24">
                                <span class="text-xs font-bold text-zinc-600 dark:text-zinc-300">النسبة</span>
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($students as $rowIndex => $student)
                            <tr wire:key="sheet-row-{{ $student->id }}" class="group">
                                <th class="sticky right-0 z-10 bg-white dark:bg-zinc-900 group-hover:bg-zinc-50 dark:group-hover:bg-zinc-800/60 border-b border-l border-zinc-200 dark:border-zinc-700 p-0 text-right">
                                    <button type="button" x-on:click="selectRow({{ $rowIndex }})"
                                        class="w-full flex items-center gap-2 px-3 py-1.5 hover:bg-zinc-100 dark:hover:bg-zinc-700/60">
                                        <span class="text-[10px] font-mono text-zinc-400 w-5 shrink-0">{{ $rowIndex + 1 }}</span>
                                        <span class="size-6 shrink-0 rounded-full flex items-center justify-center font-bold text-[9px]"
                                            style="{{ $student->avatarStyle() }}">{{ $student->initials() }}</span>
                                        <span class="truncate font-medium text-zinc-800 dark:text-zinc-100 text-xs">{{ $student->name }}</span>
                                    </button>
                                </th>

                                @foreach ($days as $colIndex => $day)
                                    @php $isEditable = $editableFlat[$student->id.'|'.$day['date']]; @endphp
                                    <td class="border-b border-l border-zinc-100 dark:border-zinc-800 p-0 w-12 h-9
                                        {{ $day['is_today'] ? 'bg-maroon/5 dark:bg-maroon/10' : '' }}">
                                        @if ($isEditable)
                                            <button type="button"
                                                x-on:mousedown.prevent="startDrag({{ $rowIndex }}, {{ $colIndex }}, $event.shiftKey)"
                                                x-on:mouseenter="extendDrag({{ $rowIndex }}, {{ $colIndex }})"
                                                x-on:mouseup="endDrag({{ $rowIndex }}, {{ $colIndex }})"
                                                x-bind:class="[
                                                    statusClass(status({{ $student->id }}, '{{ $day['date'] }}')),
                                                    inSelection({{ $rowIndex }}, {{ $colIndex }}) ? 'ring-2 ring-inset ring-maroon dark:ring-white' : '',
                                                    isDirty({{ $student->id }}, '{{ $day['date'] }}') ? 'font-black underline decoration-2 underline-offset-2' : '',
                                                ]"
                                                x-text="letter(status({{ $student->id }}, '{{ $day['date'] }}')) || '·'"
                                                class="w-full h-9 text-center text-sm font-bold cursor-pointer"></button>
                                        @else
                                            <div class="w-full h-9 flex items-center justify-center bg-zinc-50 dark:bg-zinc-800/50 text-zinc-300 dark:text-zinc-700 text-xs"
                                                title="{{ $day['is_future'] ? 'يوم لم يأتِ بعد' : 'الطالب غير مقيّد في هذا اليوم' }}">
                                                {{ $day['is_future'] ? '' : '—' }}
                                            </div>
                                        @endif
                                    </td>
                                @endforeach

                                @php $totals = $studentTotals[$student->id]; @endphp
                                <td class="border-b border-zinc-100 dark:border-zinc-800 px-3 py-1.5 text-center">
                                    @if ($totals['marked'] > 0)
                                        <flux:badge size="sm" :color="$totals['percentage'] >= 90 ? 'emerald' : ($totals['percentage'] >= 75 ? 'amber' : 'rose')">
                                            {{ $totals['percentage'] }}%
                                        </flux:badge>
                                    @else
                                        <span class="text-xs text-zinc-300 dark:text-zinc-600">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>

                    <tfoot>
                        <tr>
                            <th class="sticky right-0 z-10 bg-zinc-50 dark:bg-zinc-800 border-l border-t border-zinc-200 dark:border-zinc-700 px-3 py-2 text-right">
                                <span class="text-[11px] font-bold text-zinc-500 dark:text-zinc-400">المُسجَّل / المطلوب</span>
                            </th>
                            @foreach ($days as $day)
                                @php $total = $dayTotals[$day['date']]; @endphp
                                <td class="bg-zinc-50 dark:bg-zinc-800 border-l border-t border-zinc-200 dark:border-zinc-700 px-1 py-2 text-center">
                                    <span class="text-[10px] font-mono {{ $total['total'] > 0 && $total['marked'] === $total['total'] ? 'text-emerald-600 dark:text-emerald-400 font-bold' : 'text-zinc-400' }}">
                                        {{ $total['marked'] }}/{{ $total['total'] }}
                                    </span>
                                </td>
                            @endforeach
                            <td class="bg-zinc-50 dark:bg-zinc-800 border-t border-zinc-200 dark:border-zinc-700"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @endif

    {{-- ══════════════════ STICKY SAVE BAR ══════════════════ --}}
    <div x-cloak x-show="dirtyCount > 0" x-transition.opacity
        class="sticky bottom-4 z-40 mx-auto max-w-3xl">
        <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-maroon/30 dark:border-white/20 bg-white dark:bg-zinc-900 shadow-lg px-4 py-3">
            <div class="flex items-center gap-2">
                <span class="flex size-8 items-center justify-center rounded-full bg-maroon/10 text-maroon dark:bg-white/10 dark:text-white">
                    <flux:icon icon="pencil-square" class="size-4" />
                </span>
                <div class="leading-tight">
                    <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100">
                        <span x-text="dirtyCount"></span> تعديل غير محفوظ
                    </div>
                    <div x-show="offDayCount > 0" class="text-[11px] text-amber-600 dark:text-amber-400">
                        منها <span x-text="offDayCount"></span> في غير يوم الجلسة — يلزم إدخال السبب
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2 mr-auto">
                <flux:button x-on:click="discard()" size="sm" variant="ghost">تجاهل</flux:button>
                <flux:button x-on:click="save()" x-bind:disabled="saving" size="sm" variant="primary"
                    class="!bg-maroon hover:!bg-burgundy">
                    <span x-show="!saving">حفظ التعديلات</span>
                    <span x-show="saving" x-cloak>جارٍ الحفظ…</span>
                </flux:button>
            </div>
        </div>
    </div>

    {{-- ══════════════════ EDIT LOG ══════════════════ --}}
    @if ($this->circleId && $this->revisions->isNotEmpty())
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden"
            x-data="{ open: false }">
            <button type="button" x-on:click="open = !open"
                class="w-full flex items-center justify-between gap-3 px-5 py-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                <div class="flex items-center gap-2.5">
                    <flux:icon icon="clock" class="size-5 text-zinc-400" />
                    <flux:heading size="sm">سجل التعديلات</flux:heading>
                    <flux:badge size="sm" color="zinc">{{ $this->revisions->count() }}</flux:badge>
                </div>
                <flux:icon icon="chevron-down" class="size-4 text-zinc-400" x-bind:class="open && 'rotate-180'" />
            </button>

            <div x-cloak x-show="open" x-collapse class="border-t border-zinc-100 dark:border-zinc-800">
                <div class="max-h-80 overflow-auto divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @foreach ($this->revisions as $revision)
                        <div class="flex flex-wrap items-start gap-x-3 gap-y-1 px-5 py-3 text-xs">
                            <span class="font-bold text-zinc-800 dark:text-zinc-100 min-w-32">{{ $revision->student?->name }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400">{{ $revision->hijriDate() }}</span>
                            <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $revision->summary() }}</span>

                            @if ($revision->is_off_day_edit)
                                <flux:badge size="sm" color="amber">تعديل في غير يوم الجلسة</flux:badge>
                            @endif

                            <span class="text-zinc-400 mr-auto whitespace-nowrap">
                                {{ \App\Support\HijriDate::full($revision->edited_on) }} · {{ $revision->created_at?->format('H:i') }}
                            </span>

                            @if ($revision->reason)
                                <p class="w-full text-[11px] text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 rounded-lg px-2.5 py-1.5">
                                    السبب: {{ $revision->reason }}
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- ══════════════════ MANDATORY REASON ══════════════════ --}}
    <flux:modal name="sheet-reason" class="min-w-[26rem]">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">سبب التعديل في غير يوم الجلسة</flux:heading>
                <flux:subheading>
                    <span x-text="offDayCount"></span> من تعديلاتك تخص أياماً غير اليوم الحالي.
                    إدخال السبب إلزامي قبل الحفظ، وسيُسجَّل مع كل تعديل.
                </flux:subheading>
            </div>

            <flux:field>
                <flux:label>السبب</flux:label>
                <flux:textarea x-model="reason" rows="3"
                    placeholder="مثال: تأخر تسجيل التحضير بسبب انقطاع الشبكة يوم الجلسة" />
                <flux:error name="reason" />
            </flux:field>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">إلغاء</flux:button>
                </flux:modal.close>
                <flux:button x-on:click="saveWithReason()" x-bind:disabled="reason.trim() === '' || saving"
                    variant="primary" class="!bg-maroon hover:!bg-burgundy">
                    حفظ التعديلات
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
