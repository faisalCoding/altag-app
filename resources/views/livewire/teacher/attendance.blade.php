{{--
Alpine owns all pure-UI state:
mode — wizard | list (no round-trip to switch)
currentIndex — wizard navigation (no round-trip)
isComplete — computed from records keys
markedCount — computed from records values
filterStatus / search — client-side filtering of the list view
records — @entangle so Alpine visual = Livewire DB state
studentOrder — @entangle ordered IDs for navigation

Livewire fires only on: markStatus | updateStatus | markAllPresent | loadStudents | exportCsv
--}}
<div class="space-y-6" dir="rtl" x-data="{
         mode: 'list',
         currentIndex: 0,
         filterStatus: 'all',
         search: '',
         records: @entangle('records'),
         studentOrder: @entangle('studentOrder'),
         syncing: [],
         studentsMeta: {
             @foreach($students as $student)
                 {{ $student->id }}: { name: @js($student->name) },
             @endforeach
         },

         init() {
             /* After loadStudents() — reset navigation */
             $wire.on('studentsLoaded', () => {
                 this.currentIndex = 0;
                 this.mode         = 'list';
                 this.filterStatus = 'all';
                 this.search       = '';
             });
         },

         /* ── Computed ──────────────────────────────────────────── */
         get markedCount() {
             return Object.values(this.records).filter(v => v && v !== '').length;
         },

         get isComplete() {
             if (!this.studentOrder.length) return false;
             return this.studentOrder.every(id => this.records[id] && this.records[id] !== '');
         },

         getStatus(studentId) {
             return this.records[studentId] || '';
         },

         isVisible(studentId) {
             const statusMatch = this.filterStatus === 'all' || this.getStatus(studentId) === this.filterStatus;
             const name = (this.studentsMeta[studentId]?.name || '');
             const searchMatch = !this.search || name.includes(this.search);
             return statusMatch && searchMatch;
         },

         /* ── Wizard ─────────────────────────────────────────────── */
         /**
          * Immediately update Alpine state + navigate,
          * then fire the Livewire save in the background (no await = non-blocking).
          */
         async markAndAdvance(studentId, status) {
             this.records[studentId] = status;   /* instant visual feedback */
             this.syncing.push(studentId);       /* purple mode */
             this.moveToNextUnmarked();

             await $wire.markStatus(studentId, status); /* async DB save */

             this.syncing = this.syncing.filter(id => id !== studentId);
         },

         async updateRecord(studentId, status) {
             this.records[studentId] = status;
             this.syncing.push(studentId);

             await $wire.updateStatus(studentId, status);

             this.syncing = this.syncing.filter(id => id !== studentId);
         },

         moveToNextUnmarked() {
             const total = this.studentOrder.length;
             for (let i = this.currentIndex + 1; i < total; i++) {
                 if (!this.records[this.studentOrder[i]]) { this.currentIndex = i; return; }
             }
             /* wrap around */
             for (let i = 0; i <= this.currentIndex; i++) {
                 if (!this.records[this.studentOrder[i]]) { this.currentIndex = i; return; }
             }
             /* all marked — isComplete will be true via computed */
         },

         goToPrevious() { if (this.currentIndex > 0) this.currentIndex--; },
         goToNext() {
             if (this.currentIndex < this.studentOrder.length - 1) this.currentIndex++;
         },
     }">

    {{-- ══════════════════ HEADER ══════════════════ --}}
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="p-2.5 rounded-xl bg-maroon/10 text-maroon dark:bg-white/10 dark:text-white">
                <flux:icon icon="calendar-days" />
            </div>
            <div>
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">سجل الحضور والغياب</flux:heading>
                <flux:subheading class="text-zinc-400">
                    <a href="{{ route('teacher.dashboard') }}" wire:navigate class="hover:text-maroon">الرئيسية</a>
                    <span class="mx-1">/</span>
                    <span>سجل الحضور والغياب</span>
                </flux:subheading>
            </div>
        </div>
    </div>

    {{-- ══════════════════ OVERVIEW + CONTROLS ══════════════════ --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-5">
        <div class="flex flex-col lg:flex-row lg:items-end gap-5">
            <div class="w-full lg:w-56 shrink-0">
                <div class="text-xs text-zinc-500 dark:text-zinc-400 mb-1">نسبة الحضور الكلية (هذا الأسبوع)</div>
                <div class="flex items-center gap-1.5">
                    <span class="text-3xl font-black text-emerald-600">{{ $this->weeklyAttendancePercentage() }}%</span>
                    <flux:icon icon="arrow-trending-up" class="size-4 text-emerald-500" />
                </div>
                <div class="w-full bg-zinc-100 dark:bg-zinc-800 rounded-full h-1.5 mt-2 overflow-hidden">
                    <div class="h-full bg-emerald-500 rounded-full" style="width: {{ $this->weeklyAttendancePercentage() }}%"></div>
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-3 flex-1">
                <flux:button wire:click="$set('date', '{{ now()->format('Y-m-d') }}')" variant="primary" class="!bg-maroon hover:!bg-burgundy" icon="plus">
                    إضافة جلسة
                </flux:button>
                <flux:button wire:click="exportCsv" variant="ghost" icon="arrow-down-tray">
                    تصدير تقرير
                </flux:button>

                @if ($circles->count() > 1)
                    <div class="w-full sm:w-52">
                        <flux:select wire:model.live="selectedCircle" label="تحديد الحلقة">
                            <flux:select.option value="">-- اختر حلقة --</flux:select.option>
                            @foreach ($circles as $circle)
                                <flux:select.option :value="$circle->id">{{ $circle->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @elseif ($circles->count() === 1)
                    <div class="flex items-center gap-2 px-3 py-2 bg-zinc-50 dark:bg-zinc-800 rounded-lg">
                        <flux:icon icon="users" class="size-4 text-zinc-500" />
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $circles->first()->name }}</span>
                    </div>
                @endif

                <div class="w-full sm:w-44 relative">
                    @if($selectedCircle)
                        <livewire:teacher.hijri-datepicker wire:model.live="date" :circle-id="$selectedCircle"
                            wire:key="datepicker-{{ $selectedCircle }}" />
                    @else
                        {{-- Until a circle is chosen there is no calendar to pick from. --}}
                        <flux:input value="{{ \App\Support\HijriDate::full($date) }}" label="تاريخ الجلسة (هجري)" disabled />
                    @endif
                </div>

                {{-- Working times as the calendar holds them for this stage. --}}
                @if($this->todaysSessions)
                    <div class="flex flex-wrap items-center gap-1.5 self-end pb-1.5">
                        <flux:icon icon="clock" class="size-4 text-zinc-400" />
                        @foreach($this->todaysSessions as $session)
                            <span class="px-2 py-1 rounded-lg bg-zinc-100 dark:bg-zinc-800 text-xs font-medium text-zinc-600 dark:text-zinc-300 dir-ltr">
                                {{ $session['from'] }}–{{ $session['to'] }}{{ ($session['label'] ?? '') !== '' ? ' · '.$session['label'] : '' }}
                            </span>
                        @endforeach
                    </div>
                @endif

                <div class="flex items-center gap-1 p-1 bg-zinc-100 dark:bg-zinc-800 rounded-lg h-fit">
                    <button @click="mode = 'wizard'" :class="mode === 'wizard'
                                ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm'
                                : 'text-zinc-500 dark:text-zinc-400'" class="px-3 py-1.5 text-sm font-medium rounded-md ">
                        <span class="flex items-center gap-1.5">
                            <flux:icon icon="play" class="size-4" />
                            تحضير تفاعلي
                        </span>
                    </button>
                    <button @click="mode = 'list'" :class="mode === 'list'
                                ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm'
                                : 'text-zinc-500 dark:text-zinc-400'" class="px-3 py-1.5 text-sm font-medium rounded-md ">
                        <span class="flex items-center gap-1.5">
                            <flux:icon icon="list-bullet" class="size-4" />
                            قائمة يدوية
                        </span>
                    </button>
                </div>

                <div x-show="studentOrder.length > 0" class="flex items-center gap-2">
                    <flux:button x-show="!isComplete" wire:click="markAllPresent" size="sm">
                        <span class="flex items-center gap-1">
                            <flux:icon icon="check-circle" class="size-4" />
                            تحضير الكل
                        </span>
                    </flux:button>

                    <button x-show="markedCount > 0" x-on:click="$flux.modal('confirm-clear-attendance').show()" size="sm"
                        class=" border-red-600 rounded-md px-2 py-1 text-red-600 bg-red-600/20 hover:bg-red-600/70 hover:text-white"
                        title="حذف التحضير">
                        <span class="flex items-center gap-1">
                            <flux:icon icon="trash" class="size-4" />
                            حذف التحضير
                        </span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Progress bar — Alpine computed, no round-trip --}}
        <div x-show="studentOrder.length > 0" class="mt-4">
            <div class="flex items-center justify-between text-sm text-zinc-500 dark:text-zinc-400 mb-1.5">
                <span>الجلسة: <span x-text="markedCount"></span> / <span x-text="studentOrder.length"></span></span>
                <span x-show="isComplete"
                    class="text-green-600 dark:text-green-400 font-medium flex items-center gap-1">
                    <flux:icon icon="check-circle" class="size-4" />
                    مكتمل
                </span>
            </div>
            <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-2 overflow-hidden">
                <div class="h-2 rounded-full  duration-500 ease-out"
                    :class="isComplete ? 'bg-green-500' : 'bg-maroon'"
                    :style="{ width: (studentOrder.length > 0 ? (markedCount / studentOrder.length) * 100 : 0) + '%' }">
                </div>
            </div>
        </div>
    </div>

    {{-- ══════════════════ MAIN CONTENT ══════════════════ --}}

    @if (!$selectedCircle)
        {{-- No circle selected --}}
        <div
            class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-16 text-center">
            <flux:icon icon="cursor-arrow-ripple" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
            <flux:heading size="lg" class="text-zinc-500 dark:text-zinc-400">اختر حلقة للبدء</flux:heading>
            <flux:subheading class="text-zinc-400 dark:text-zinc-500">حدد الحلقة التي تريد تسجيل حضور طلابها
            </flux:subheading>
        </div>

    @elseif($students->count() === 0)
        {{-- No students --}}
        <div
            class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-16 text-center">
            <flux:icon icon="users" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
            <flux:heading size="lg" class="text-zinc-500 dark:text-zinc-400">لا يوجد طلاب</flux:heading>
            <flux:subheading class="text-zinc-400 dark:text-zinc-500">لا يوجد طلاب معتمدون في هذه الحلقة</flux:subheading>
        </div>

    @else
        {{-- ────────── WIZARD MODE ────────── --}}
        <div x-show="mode === 'wizard'"
            class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">

            {{-- Completion screen --}}
            <div x-show="isComplete" class="p-16 text-center space-y-4">
                <div class="inline-flex p-4 rounded-full bg-green-50 dark:bg-green-900/20 mb-2">
                    <flux:icon icon="check-circle" class="size-16 text-green-500" />
                </div>
                <flux:heading size="xl" class="text-green-600 dark:text-green-400">تم التحضير بنجاح! 🎉</flux:heading>
                <flux:subheading class="text-zinc-500 dark:text-zinc-400">تم تسجيل حضور جميع الطلاب لهذا اليوم
                </flux:subheading>
                <div class="pt-4">
                    <flux:button @click="mode = 'list'" variant="primary" class="!bg-maroon hover:!bg-burgundy">
                        عرض القائمة للمراجعة
                    </flux:button>
                </div>
            </div>

            {{-- Student cards — one per student, Alpine shows the current --}}
            @foreach($students as $index => $student)
                <div x-show="!isComplete && currentIndex === {{ $index }}" wire:key="wizard-{{ $student->id }}"
                    class="p-8 md:p-12 text-center space-y-8">

                    {{-- Counter + name --}}
                    <div class="space-y-2">
                        <div
                            class="inline-flex items-center gap-2 px-3 py-1 bg-zinc-100 dark:bg-zinc-800 rounded-full text-sm text-zinc-500 dark:text-zinc-400">
                            <span>{{ $index + 1 }} من {{ count($students) }}</span>
                        </div>
                        <div class="pt-4">
                            <div class="inline-flex size-20 items-center justify-center rounded-full mb-4 font-bold text-2xl" style="{{ $student->avatarStyle() }}">
                                {{ $student->initials() }}
                            </div>
                            <h2 class="text-3xl md:text-4xl font-bold text-zinc-900 dark:text-white">{{ $student->name }}</h2>
                            @if ($student->circle)
                                <p class="text-sm text-zinc-400 dark:text-zinc-500 mt-1">{{ $student->circle->name }}</p>
                            @endif
                        </div>
                    </div>

                    {{-- Status buttons — Alpine visual, Livewire saves in background --}}
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4 max-w-2xl mx-auto">
                        <button @click="markAndAdvance({{ $student->id }}, 'present')"
                            :disabled="syncing.includes({{ $student->id }})"
                            :class="syncing.includes({{ $student->id }}) && getStatus({{ $student->id }}) === 'present'
                                ? 'border-zinc-300 bg-zinc-200 text-zinc-700 dark:border-white dark:bg-white dark:text-zinc-900'
                                : (getStatus({{ $student->id }}) === 'present'
                                    ? 'border-green-500 bg-green-50 dark:bg-green-900/20 text-gray-800 dark:text-white'
                                    : 'border-zinc-200 dark:border-zinc-700 hover:border-green-400 hover:bg-green-50 dark:hover:border-green-600 dark:hover:bg-green-900/10 text-gray-800 dark:text-white')"
                            class="group flex flex-col items-center justify-center gap-3 p-6 rounded-2xl border-2 duration-200">
                            <span class="font-semibold">حاضر</span>
                        </button>
                        <button @click="markAndAdvance({{ $student->id }}, 'absent')"
                            :disabled="syncing.includes({{ $student->id }})"
                            :class="syncing.includes({{ $student->id }}) && getStatus({{ $student->id }}) === 'absent'
                                ? 'border-zinc-300 bg-zinc-200 text-zinc-700 dark:border-white dark:bg-white dark:text-zinc-900'
                                : (getStatus({{ $student->id }}) === 'absent'
                                    ? 'border-red-500 bg-red-50 dark:bg-red-900/20 text-gray-800 dark:text-white'
                                    : 'border-zinc-200 dark:border-zinc-700 hover:border-red-400 hover:bg-red-50 dark:hover:border-red-600 dark:hover:bg-red-900/10 text-gray-800 dark:text-white')"
                            class="group flex flex-col items-center justify-center gap-3 p-6 rounded-2xl border-2 duration-200">
                            <span class="font-semibold">غائب</span>
                        </button>
                        <button @click="markAndAdvance({{ $student->id }}, 'late')"
                            :disabled="syncing.includes({{ $student->id }})"
                            :class="syncing.includes({{ $student->id }}) && getStatus({{ $student->id }}) === 'late'
                                ? 'border-zinc-300 bg-zinc-200 text-zinc-700 dark:border-white dark:bg-white dark:text-zinc-900'
                                : (getStatus({{ $student->id }}) === 'late'
                                    ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20 text-gray-800 dark:text-white'
                                    : 'border-zinc-200 dark:border-zinc-700 hover:border-amber-400 hover:bg-amber-50 dark:hover:border-amber-600 dark:hover:bg-amber-900/10 text-gray-800 dark:text-white')"
                            class="group flex flex-col items-center justify-center gap-3 p-6 rounded-2xl border-2 duration-200">
                            <span class="font-semibold">متأخر</span>
                        </button>
                        <button @click="markAndAdvance({{ $student->id }}, 'excused')"
                            :disabled="syncing.includes({{ $student->id }})"
                            :class="syncing.includes({{ $student->id }}) && getStatus({{ $student->id }}) === 'excused'
                                ? 'border-zinc-300 bg-zinc-200 text-zinc-700 dark:border-white dark:bg-white dark:text-zinc-900'
                                : (getStatus({{ $student->id }}) === 'excused'
                                    ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20 text-gray-800 dark:text-white'
                                    : 'border-zinc-200 dark:border-zinc-700 hover:border-blue-400 hover:bg-blue-50 dark:hover:border-blue-600 dark:hover:bg-blue-900/10 text-gray-800 dark:text-white')"
                            class="group flex flex-col items-center justify-center gap-3 p-6 rounded-2xl border-2 duration-200">
                            <span class="font-semibold">مستأذن</span>
                        </button>
                    </div>

                    {{-- Navigation — Alpine only, zero round-trips --}}
                    <div class="flex items-center justify-between mt-4">
                        <button @click="goToPrevious()" :disabled="currentIndex === 0"
                            class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium rounded-lg text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 disabled:opacity-40 disabled:cursor-not-allowed ">
                            <flux:icon icon="chevron-right" class="size-4" />
                            السابق
                        </button>

                        {{-- Dot indicators --}}
                        <div class="flex items-center gap-1 max-w-xs overflow-hidden">
                            @foreach ($students as $idx => $s)
                                <div :class="{
                                        'bg-maroon scale-125': currentIndex === {{ $idx }},
                                        'bg-green-400': currentIndex !== {{ $idx }} && records[{{ $s->id }}],
                                        'bg-zinc-300 dark:bg-zinc-600': currentIndex !== {{ $idx }} && !records[{{ $s->id }}]
                                    }"
                                    class="size-2 rounded-full ">
                                </div>
                            @endforeach
                        </div>

                        <button @click="goToNext()" :disabled="currentIndex >= studentOrder.length - 1"
                            class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium rounded-lg text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 disabled:opacity-40 disabled:cursor-not-allowed ">
                            التالي
                            <flux:icon icon="chevron-left" class="size-4" />
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ────────── LIST MODE ────────── --}}
        <div x-show="mode === 'list'"
            class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">

            {{-- Filter chips + search --}}
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 border-b border-zinc-100 dark:border-zinc-800">
                <div class="flex items-center gap-2 overflow-x-auto">
                    <button @click="filterStatus = 'all'" :class="filterStatus === 'all' ? '!bg-maroon !text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300'"
                        class="px-4 py-1.5 rounded-full text-sm font-bold whitespace-nowrap">الكل</button>
                    <button @click="filterStatus = 'present'" :class="filterStatus === 'present' ? '!bg-emerald-500 !text-white' : 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600'"
                        class="px-4 py-1.5 rounded-full text-sm font-bold whitespace-nowrap">حاضر</button>
                    <button @click="filterStatus = 'late'" :class="filterStatus === 'late' ? '!bg-amber-500 !text-white' : 'bg-amber-50 dark:bg-amber-900/20 text-amber-600'"
                        class="px-4 py-1.5 rounded-full text-sm font-bold whitespace-nowrap">متأخر</button>
                    <button @click="filterStatus = 'absent'" :class="filterStatus === 'absent' ? '!bg-rose-500 !text-white' : 'bg-rose-50 dark:bg-rose-900/20 text-rose-600'"
                        class="px-4 py-1.5 rounded-full text-sm font-bold whitespace-nowrap">غائب</button>
                    <button @click="filterStatus = 'excused'" :class="filterStatus === 'excused' ? '!bg-zinc-500 !text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500'"
                        class="px-4 py-1.5 rounded-full text-sm font-bold whitespace-nowrap">مستأذن</button>
                </div>

                <div class="w-full sm:w-64">
                    <flux:input x-model="search" placeholder="ابحث عن طالب..." icon="magnifying-glass" size="sm" />
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 divide-y lg:divide-y-0 divide-zinc-100 dark:divide-zinc-800">
                @foreach ($students as $index => $student)
                    <div x-show="isVisible({{ $student->id }})" wire:key="student-{{ $student->id }}"
                        class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 lg:border-b lg:border-zinc-100 dark:lg:border-zinc-800">
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-mono text-zinc-400 w-6 text-center">{{ $index + 1 }}</span>
                            <div class="flex items-center gap-2">
                                @if($student->avatar_path)
                                    <img src="{{ \Illuminate\Support\Facades\Storage::url($student->avatar_path) }}" class="size-9 rounded-full object-cover" />
                                @else
                                    <div class="size-9 rounded-full flex items-center justify-center font-bold text-xs" style="{{ $student->avatarStyle() }}">
                                        {{ $student->initials() }}
                                    </div>
                                @endif
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $student->name }}</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 mr-9 sm:mr-0 min-w-[32px] justify-center">
                            {{-- Loading spinner while syncing --}}
                            <div x-cloak x-show="syncing.includes({{ $student->id }})" class="flex items-center justify-center">
                                <flux:icon icon="arrow-path" class="size-5 text-current animate-spin" />
                            </div>

                            {{-- Actual buttons hide while syncing --}}
                            <div x-show="!syncing.includes({{ $student->id }})" class="flex gap-2">
                                {{-- WhatsApp link — server-rendered on updateStatus re-render --}}
                                @if (in_array($records[$student->id] ?? '', ['absent', 'late']))
                                    @php $msg = $this->getWhatsAppMessage($student, $records[$student->id]); @endphp
                                    @if ($student->guardian_phone)
                                        <a class="whatsapp-link"
                                            href="https://wa.me/{{ $student->guardian_phone }}/?text={{ urlencode($msg) }}"
                                            target="_blank" title="تواصل عبر واتساب">
                                            <flux:icon icon="chat-bubble-left-right"
                                                class="size-5 text-green-500 hover:text-green-600" />
                                        </a>
                                    @else
                                        <button x-data="{ copied: false }" data-msg="{{ $msg }}"
                                            x-on:click="navigator.clipboard.writeText($el.dataset.msg).then(() => { copied = true; setTimeout(() => copied = false, 2000); })"
                                            title="لا يوجد رقم - نسخ الرسالة"
                                            class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 flex items-center justify-center">
                                            <flux:icon x-show="!copied" icon="clipboard-document" class="size-5" />
                                            <flux:icon x-cloak x-show="copied" icon="check" class="size-5 text-green-500" />
                                        </button>
                                    @endif
                                @endif
                            </div>

                            {{-- Status buttons — Alpine instant color, Livewire re-renders for WhatsApp --}}
                            <button @click="updateRecord({{ $student->id }}, 'present')"
                                :disabled="syncing.includes({{ $student->id }})"
                                :class="syncing.includes({{ $student->id }}) && getStatus({{ $student->id }}) === 'present'
                                    ? 'bg-zinc-200 text-zinc-700 border border-zinc-300 dark:bg-white dark:text-zinc-900 dark:border-white'
                                    : (getStatus({{ $student->id }}) === 'present'
                                        ? 'bg-green-100 text-green-700 border border-green-300 dark:bg-green-900/30 dark:text-green-400 dark:border-green-700'
                                        : 'bg-zinc-100 text-zinc-500 border border-zinc-200 hover:bg-green-50 hover:text-green-700 hover:border-green-300 dark:bg-zinc-800 dark:text-zinc-400 dark:border-zinc-700 dark:hover:bg-green-900/20 dark:hover:text-green-400')"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg">حاضر</button>
                            <button @click="updateRecord({{ $student->id }}, 'absent')"
                                :disabled="syncing.includes({{ $student->id }})"
                                :class="syncing.includes({{ $student->id }}) && getStatus({{ $student->id }}) === 'absent'
                                    ? 'bg-zinc-200 text-zinc-700 border border-zinc-300 dark:bg-white dark:text-zinc-900 dark:border-white'
                                    : (getStatus({{ $student->id }}) === 'absent'
                                        ? 'bg-red-100 text-red-700 border border-red-300 dark:bg-red-900/30 dark:text-red-400 dark:border-red-700'
                                        : 'bg-zinc-100 text-zinc-500 border border-zinc-200 hover:bg-red-50 hover:text-red-700 hover:border-red-300 dark:bg-zinc-800 dark:text-zinc-400 dark:border-zinc-700 dark:hover:bg-red-900/20 dark:hover:text-red-400')"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg">غائب</button>
                            <button @click="updateRecord({{ $student->id }}, 'late')"
                                :disabled="syncing.includes({{ $student->id }})"
                                :class="syncing.includes({{ $student->id }}) && getStatus({{ $student->id }}) === 'late'
                                    ? 'bg-zinc-200 text-zinc-700 border border-zinc-300 dark:bg-white dark:text-zinc-900 dark:border-white'
                                    : (getStatus({{ $student->id }}) === 'late'
                                        ? 'bg-amber-100 text-amber-700 border border-amber-300 dark:bg-amber-900/30 dark:text-amber-400 dark:border-amber-700'
                                        : 'bg-zinc-100 text-zinc-500 border border-zinc-200 hover:bg-amber-50 hover:text-amber-700 hover:border-amber-300 dark:bg-zinc-800 dark:text-zinc-400 dark:border-zinc-700 dark:hover:bg-amber-900/20 dark:hover:text-amber-400')"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg">متأخر</button>
                            <button @click="updateRecord({{ $student->id }}, 'excused')"
                                :disabled="syncing.includes({{ $student->id }})"
                                :class="syncing.includes({{ $student->id }}) && getStatus({{ $student->id }}) === 'excused'
                                    ? 'bg-zinc-200 text-zinc-700 border border-zinc-300 dark:bg-white dark:text-zinc-900 dark:border-white'
                                    : (getStatus({{ $student->id }}) === 'excused'
                                        ? 'bg-blue-100 text-blue-700 border border-blue-300 dark:bg-blue-900/30 dark:text-blue-400 dark:border-blue-700'
                                        : 'bg-zinc-100 text-zinc-500 border border-zinc-200 hover:bg-blue-50 hover:text-blue-700 hover:border-blue-300 dark:bg-zinc-800 dark:text-zinc-400 dark:border-zinc-700 dark:hover:bg-blue-900/20 dark:hover:text-blue-400')"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg">مستأذن</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ══════════════════ STATS WITH 7-DAY SPARKLINES ══════════════════ --}}
    @if($selectedCircle)
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach([
                ['status' => 'present', 'label' => 'حاضر', 'color' => 'emerald', 'icon' => 'user-group'],
                ['status' => 'late', 'label' => 'متأخر', 'color' => 'amber', 'icon' => 'clock'],
                ['status' => 'absent', 'label' => 'غائب', 'color' => 'rose', 'icon' => 'user-minus'],
                ['status' => 'excused', 'label' => 'مستأذن', 'color' => 'blue', 'icon' => 'user-plus'],
            ] as $card)
                @php
                    $series = $this->sparklineFor($card['status']);
                    $max = max(1, max($series));
                    $points = collect($series)->map(fn ($v, $i) => ($i * (100 / 6)).','.(30 - ($v / $max) * 26))->implode(' ');
                @endphp
                <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 overflow-hidden" wire:key="stat-{{ $card['status'] }}">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl font-bold text-{{ $card['color'] }}-600 dark:text-{{ $card['color'] }}-400" x-text="Object.values(records).filter(v => v === '{{ $card['status'] }}').length"></div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $card['label'] }}</div>
                        </div>
                        <div class="text-{{ $card['color'] }}-500">
                            <flux:icon :icon="$card['icon']" class="size-6" />
                        </div>
                    </div>
                    <svg viewBox="0 0 100 30" class="w-full h-6 mt-2 text-{{ $card['color'] }}-400" preserveAspectRatio="none">
                        <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
            @endforeach
        </div>

        {{-- ══════════════════ WEEKLY DONUT + RECENT SESSIONS ══════════════════ --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            @php
                $breakdown = $this->weeklyBreakdown();
                $weeklyTotal = max(1, array_sum($breakdown));
            @endphp
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5">
                <flux:heading size="sm" class="mb-4">هذا الأسبوع</flux:heading>
                <div class="flex items-center gap-6">
                    <div class="flex-1 space-y-2.5">
                        @foreach([
                            ['key' => 'present', 'label' => 'حاضر', 'dot' => 'bg-emerald-500'],
                            ['key' => 'late', 'label' => 'متأخر', 'dot' => 'bg-amber-500'],
                            ['key' => 'absent', 'label' => 'غائب', 'dot' => 'bg-rose-500'],
                            ['key' => 'excused', 'label' => 'مستأذن', 'dot' => 'bg-zinc-400'],
                        ] as $legend)
                            <div class="flex items-center gap-2 text-sm">
                                <span class="size-2.5 rounded-full {{ $legend['dot'] }}"></span>
                                <span class="text-zinc-500 dark:text-zinc-400">{{ $legend['label'] }}</span>
                                <span class="font-bold text-zinc-800 dark:text-zinc-100 mr-auto">({{ $breakdown[$legend['key']] }} طالب)</span>
                            </div>
                        @endforeach
                    </div>

                    <div class="relative shrink-0" style="width:120px;height:120px">
                        @php
                            $radius = 50; $circumference = 2 * M_PI * $radius; $offsetAcc = 0;
                            $colors = ['present' => '#10b981', 'late' => '#f59e0b', 'absent' => '#f43f5e', 'excused' => '#a1a1aa'];
                        @endphp
                        <svg width="120" height="120" viewBox="0 0 120 120" class="-rotate-90">
                            <circle cx="60" cy="60" r="{{ $radius }}" fill="none" stroke="currentColor" stroke-width="14" class="text-zinc-100 dark:text-zinc-800" />
                            @foreach($breakdown as $key => $count)
                                @if($count > 0)
                                    @php
                                        $fraction = $count / $weeklyTotal;
                                        $dash = $fraction * $circumference;
                                        $gap = $circumference - $dash;
                                    @endphp
                                    <circle cx="60" cy="60" r="{{ $radius }}" fill="none" stroke="{{ $colors[$key] }}" stroke-width="14"
                                        stroke-dasharray="{{ $dash }} {{ $gap }}" stroke-dashoffset="{{ -$offsetAcc }}" />
                                    @php $offsetAcc += $dash; @endphp
                                @endif
                            @endforeach
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-xl font-black text-zinc-900 dark:text-white">{{ $this->weeklyAttendancePercentage() }}%</span>
                            <span class="text-[10px] text-zinc-400">نسبة الحضور</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5">
                <flux:heading size="sm" class="mb-4">آخر الجلسات</flux:heading>
                @php $sessions = $this->recentSessions(5); @endphp
                @if(empty($sessions))
                    <p class="text-sm text-zinc-400 text-center py-6">لا توجد جلسات سابقة بعد</p>
                @else
                    <div class="space-y-3">
                        @foreach($sessions as $session)
                            @php
                                $badgeColor = $session['percentage'] >= 90 ? 'emerald' : ($session['percentage'] >= 75 ? 'amber' : 'rose');
                            @endphp
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2.5">
                                    <flux:badge :color="$badgeColor" size="sm">{{ $session['percentage'] }}%</flux:badge>
                                    <div>
                                        <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100">{{ $circles->firstWhere('id', $selectedCircle)?->name }}</div>
                                        <div class="text-xs text-zinc-400">
                                            <x-hijri-date :date="\Carbon\Carbon::parse($session['date'])" />
                                            @if($session['time'])
                                                - {{ $session['time'] }}
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Clear Attendance Modal --}}
    <flux:modal name="confirm-clear-attendance" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg" class="text-red-600 dark:text-red-400">تأكيد مسح التحضير</flux:heading>
                <flux:subheading>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        هل أنت متأكد من مسح كافة بيانات التحضير لطلاب هذه الحلقة لهذا اليوم؟
                        <br>
                        <strong>هذا الإجراء سيحذف السجلات ولا يمكن التراجع عنه.</strong>
                    </p>
                </flux:subheading>
            </div>

            <div class="flex gap-2 mt-4">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">إلغاء</flux:button>
                </flux:modal.close>

                <flux:button wire:click="clearDayAttendance"
                    x-on:click="$flux.modal('confirm-clear-attendance').close()" variant="danger">
                    نعم، امسح التحضير
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
