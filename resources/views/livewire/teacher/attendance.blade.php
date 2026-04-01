<div class="space-y-6" dir="rtl">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                <flux:icon icon="clipboard-document-check" />
            </div>
            <div>
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">سجل الحضور والغياب
                </flux:heading>
                <flux:subheading>تسجيل حضور الطلاب اليومي في حلقاتك</flux:subheading>
            </div>
        </div>
    </div>

    {{-- Controls: Circle / Date / Mode --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-5">
        <div class="flex flex-col md:flex-row gap-4 items-end max-md:items-center">
            @if ($circles->count() > 1)
                <div class="w-full md:w-64">
                    <flux:select wire:model.live="selectedCircle" label="اختر الحلقة">
                        <flux:select.option value="">-- اختر حلقة --</flux:select.option>
                        @foreach ($circles as $circle)
                            <flux:select.option :value="$circle->id">{{ $circle->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @else
                @if ($circles->count() === 1)
                    <div class="flex items-center gap-2 px-3 py-2 bg-zinc-50 dark:bg-zinc-800 rounded-lg">
                        <flux:icon icon="users" class="size-4 text-zinc-500" />
                        <span
                            class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $circles->first()->name }}</span>
                    </div>
                @endif
            @endif

            <div class="w-full md:w-48 mt-auto relative z-50">
                @if($selectedCircle)
                    <livewire:teacher.hijri-datepicker wire:model.live="date" :circle-id="$selectedCircle" wire:key="datepicker-{{ $selectedCircle }}" style="justify-items: center;"/>
                @else
                    <flux:input type="date" wire:model.live="date" label="التاريخ" disabled />
                @endif
            </div>

            <div class="flex items-center gap-1 p-1 bg-zinc-100 dark:bg-zinc-800 rounded-lg h-fit">
                <button wire:click="switchMode('wizard')"
                    class="px-3 py-1.5 text-sm font-medium rounded-md transition-all {{ $mode === 'wizard' ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200' }}">
                    <span class="flex items-center gap-1.5">
                        <flux:icon icon="play" class="size-4" />
                        تحضير تفاعلي
                    </span>
                </button>
                <button wire:click="switchMode('list')"
                    class="px-3 py-1.5 text-sm font-medium rounded-md transition-all {{ $mode === 'list' ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200' }}">
                    <span class="flex items-center gap-1.5">
                        <flux:icon icon="list-bullet" class="size-4" />
                        قائمة يدوية
                    </span>
                </button>
            </div>

            @if ($students->count() > 0 && !$isComplete)
                <flux:button wire:click="markAllPresent" size="sm">
                    <span class="flex items-center gap-1">
                        <flux:icon icon="check-circle" class="size-4" />
                        تحضير الكل
                    </span>
                </flux:button>
            @endif
        </div>

        {{-- Progress Bar --}}
        @if ($students->count() > 0)
            <div class="mt-4">
                <div class="flex items-center justify-between text-sm text-zinc-500 dark:text-zinc-400 mb-1.5">
                    <span>التقدم: {{ $this->markedCount }} / {{ count($students) }}</span>
                    @if ($isComplete)
                        <span class="text-green-600 dark:text-green-400 font-medium flex items-center gap-1">
                            <flux:icon icon="check-circle" class="size-4" />
                            مكتمل
                        </span>
                    @endif
                </div>
                <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-2 overflow-hidden">
                    <div class="h-2 rounded-full transition-all duration-500 ease-out {{ $isComplete ? 'bg-green-500' : 'bg-blue-500' }}"
                        style="width: {{ count($students) > 0 ? ($this->markedCount / count($students)) * 100 : 0 }}%">
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if (!$selectedCircle)
        <div
            class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-16 text-center">
            <flux:icon icon="cursor-arrow-ripple" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
            <flux:heading size="lg" class="text-zinc-500 dark:text-zinc-400">اختر حلقة للبدء</flux:heading>
            <flux:subheading class="text-zinc-400 dark:text-zinc-500">حدد الحلقة التي تريد تسجيل حضور طلابها
            </flux:subheading>
        </div>
    @elseif($students->count() === 0)
        <div
            class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-16 text-center">
            <flux:icon icon="users" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
            <flux:heading size="lg" class="text-zinc-500 dark:text-zinc-400">لا يوجد طلاب</flux:heading>
            <flux:subheading class="text-zinc-400 dark:text-zinc-500">لا يوجد طلاب معتمدون في هذه الحلقة
            </flux:subheading>
        </div>
    @elseif($mode === 'wizard')
        {{-- ==================== WIZARD MODE ==================== --}}
        <div
            class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
            @if ($isComplete)
                {{-- Completion Screen --}}
                <div class="p-16 text-center space-y-4">
                    <div class="inline-flex p-4 rounded-full bg-green-50 dark:bg-green-900/20 mb-2">
                        <flux:icon icon="check-circle" class="size-16 text-green-500" />
                    </div>
                    <flux:heading size="xl" class="text-green-600 dark:text-green-400">تم التحضير بنجاح! 🎉
                    </flux:heading>
                    <flux:subheading class="text-zinc-500 dark:text-zinc-400">تم تسجيل حضور جميع الطلاب لهذا اليوم
                    </flux:subheading>
                    <div class="pt-4">
                        <flux:button wire:click="switchMode('list')" variant="primary"
                            class="bg-zinc-800 hover:bg-zinc-700 dark:bg-zinc-200 dark:text-zinc-800 dark:hover:bg-zinc-300">
                            عرض القائمة للمراجعة
                        </flux:button>
                    </div>
                </div>
            @else
                @php $currentStudent = $students[$currentIndex] ?? null; @endphp

                @if ($currentStudent)
                    <div class="p-8 md:p-12 text-center space-y-8">
                        {{-- Student Name --}}
                        <div class="space-y-2">
                            <div
                                class="inline-flex items-center gap-2 px-3 py-1 bg-zinc-100 dark:bg-zinc-800 rounded-full text-sm text-zinc-500 dark:text-zinc-400">
                                <span>{{ $currentIndex + 1 }} من {{ count($students) }}</span>
                            </div>
                            <div class="pt-4">
                                <div class="inline-flex p-5 rounded-full bg-zinc-50 dark:bg-zinc-800 mb-4">
                                    <flux:icon icon="user" class="size-12 text-zinc-400 dark:text-zinc-500" />
                                </div>
                                <h2 class="text-3xl md:text-4xl font-bold text-zinc-900 dark:text-white">
                                    {{ $currentStudent->name }}</h2>
                                @if ($currentStudent->circle)
                                    <p class="text-sm text-zinc-400 dark:text-zinc-500 mt-1">
                                        {{ $currentStudent->circle->name }}</p>
                                @endif
                            </div>
                        </div>

                        {{-- Status Buttons --}}
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4 max-w-2xl mx-auto">
                            <button wire:click="markStatus({{ $currentStudent->id }}, 'present')"
                                class="group flex flex-col items-center justify-center gap-3 p-6 rounded-2xl border-2 transition-all duration-200
                                    {{ ($records[$currentStudent->id] ?? '') === 'present'
                                        ? 'border-green-500 bg-green-50 dark:bg-green-900/20'
                                        : 'border-zinc-200 dark:border-zinc-700 hover:border-green-400 hover:bg-green-50 dark:hover:border-green-600 dark:hover:bg-green-900/10' }}">
                                <span class="font-semibold text-gray-800 dark:text-white">حاضر</span>
                            </button>

                            <button wire:click="markStatus({{ $currentStudent->id }}, 'absent')"
                                class="group flex flex-col items-center justify-center gap-3 p-6 rounded-2xl border-2 transition-all duration-200
                                    {{ ($records[$currentStudent->id] ?? '') === 'absent'
                                        ? 'border-red-500 bg-red-50 dark:bg-red-900/20'
                                        : 'border-zinc-200 dark:border-zinc-700 hover:border-red-400 hover:bg-red-50 dark:hover:border-red-600 dark:hover:bg-red-900/10' }}">
                                <span class="font-semibold text-gray-800 dark:text-white">غائب</span>
                            </button>

                            <button wire:click="markStatus({{ $currentStudent->id }}, 'late')"
                                class="group flex flex-col items-center justify-center gap-3 p-6 rounded-2xl border-2 transition-all duration-200
                                    {{ ($records[$currentStudent->id] ?? '') === 'late'
                                        ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20'
                                        : 'border-zinc-200 dark:border-zinc-700 hover:border-amber-400 hover:bg-amber-50 dark:hover:border-amber-600 dark:hover:bg-amber-900/10' }}">
                                <span class="font-semibold text-gray-800 dark:text-white">متأخر</span>
                            </button>

                            <button wire:click="markStatus({{ $currentStudent->id }}, 'excused')"
                                class="group flex flex-col items-center justify-center gap-3 p-6 rounded-2xl border-2 transition-all duration-200
                                    {{ ($records[$currentStudent->id] ?? '') === 'excused'
                                        ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20'
                                        : 'border-zinc-200 dark:border-zinc-700 hover:border-blue-400 hover:bg-blue-50 dark:hover:border-blue-600 dark:hover:bg-blue-900/10' }}">
                                <span class="font-semibold text-gray-800 dark:text-white">مستأذن</span>
                            </button>
                        </div>

                        {{-- Navigation --}}
                        <div class="flex items-center justify-center gap-4 pt-4">
                            <flux:button wire:click="goToPrevious" variant="ghost" size="sm" icon="chevron-right"
                                :disabled="$currentIndex === 0">
                                السابق
                            </flux:button>

                            {{-- Mini dot indicators --}}
                            <div class="flex items-center gap-1 max-w-xs overflow-hidden">
                                @foreach ($students as $idx => $s)
                                    <div
                                        class="size-2 rounded-full transition-all {{ $idx === $currentIndex ? 'bg-blue-500 scale-125' : (!empty($records[$s->id]) ? 'bg-green-400' : 'bg-zinc-300 dark:bg-zinc-600') }}">
                                    </div>
                                @endforeach
                            </div>

                            <flux:button wire:click="goToNext" variant="ghost" size="sm"
                                icon-trailing="chevron-left" :disabled="$currentIndex === count($students) - 1">
                                التالي
                            </flux:button>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    @elseif($mode === 'list')
        {{-- ==================== LIST MODE ==================== --}}
        <div
            class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($students as $index => $student)
                    <div wire:key="student-{{ $student->id }}"
                        class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-mono text-zinc-400 w-6 text-center">{{ $index + 1 }}</span>
                            <div class="flex items-center gap-2">
                                <div class="p-1.5 rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                    <flux:icon icon="user" class="size-4 text-zinc-400" />
                                </div>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $student->name }}</span>
                            </div>
                        </div>

                        <div class="flex gap-2 mr-9 sm:mr-0">
                            @if ($student->guardian_phone && ($records[$student->id] ?? '') === 'absent')
                                <a class="whatsapp-link"
                                    href="https://wa.me/{{ $student->guardian_phone }}/?text={{ urlencode(' السلام عليكم ورحمة الله وبركاته نود ان نشعركم بأن الطالب ' . $student->name . ' غائب اليوم') }}"
                                    target="_blank" title="تواصل عبر واتساب">
                                    <flux:icon icon="chat-bubble-left-right"
                                        class="size-5 text-green-500 hover:text-green-600 transition-colors" />
                                </a>
                            @endif
                            @php $currentStatus = $records[$student->id] ?? ''; @endphp

                            <button wire:click="updateStatus({{ $student->id }}, 'present')"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg transition-all {{ $currentStatus === 'present' ? 'bg-green-100 text-white border border-green-300 dark:bg-green-900/30 dark:text-green-400 dark:border-green-700' : 'bg-zinc-100 text-zinc-500 border border-zinc-200 hover:bg-green-50 hover:text-green-700 hover:border-green-300 dark:bg-zinc-800 dark:text-zinc-400 dark:border-zinc-700 dark:hover:bg-green-900/20 dark:hover:text-green-400' }}">
                                حاضر
                            </button>
                            <button wire:click="updateStatus({{ $student->id }}, 'absent')"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg transition-all {{ $currentStatus === 'absent' ? 'bg-red-100 text-white border border-red-300 dark:bg-red-900/30 dark:text-red-400 dark:border-red-700' : 'bg-zinc-100 text-zinc-500 border border-zinc-200 hover:bg-red-50 hover:text-red-700 hover:border-red-300 dark:bg-zinc-800 dark:text-zinc-400 dark:border-zinc-700 dark:hover:bg-red-900/20 dark:hover:text-red-400' }}">
                                غائب
                            </button>
                            <button wire:click="updateStatus({{ $student->id }}, 'late')"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg transition-all {{ $currentStatus === 'late' ? 'bg-amber-100 text-white border border-amber-300 dark:bg-amber-900/30 dark:text-amber-400 dark:border-amber-700' : 'bg-zinc-100 text-zinc-500 border border-zinc-200 hover:bg-amber-50 hover:text-amber-700 hover:border-amber-300 dark:bg-zinc-800 dark:text-zinc-400 dark:border-zinc-700 dark:hover:bg-amber-900/20 dark:hover:text-amber-400' }}">
                                متأخر
                            </button>
                            <button wire:click="updateStatus({{ $student->id }}, 'excused')"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg transition-all {{ $currentStatus === 'excused' ? 'bg-blue-100 text-white border border-blue-300 dark:bg-blue-900/30 dark:text-blue-400 dark:border-blue-700' : 'bg-zinc-100 text-zinc-500 border border-zinc-200 hover:bg-blue-50 hover:text-blue-700 hover:border-blue-300 dark:bg-zinc-800 dark:text-zinc-400 dark:border-zinc-700 dark:hover:bg-blue-900/20 dark:hover:text-blue-400' }}">
                                مستأذن
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Stats Summary (when complete) --}}
    @if ($isComplete && $students->count() > 0)
        @php
            $presentCount = collect($records)->filter(fn($s) => $s === 'present')->count();
            $absentCount = collect($records)->filter(fn($s) => $s === 'absent')->count();
            $lateCount = collect($records)->filter(fn($s) => $s === 'late')->count();
            $excusedCount = collect($records)->filter(fn($s) => $s === 'excused')->count();
        @endphp
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div
                class="bg-green-50 dark:bg-green-900/20 rounded-2xl border border-green-200 dark:border-green-800 p-4 text-center">
                <div class="text-2xl font-bold text-green-700 dark:text-green-400">{{ $presentCount }}</div>
                <div class="text-sm text-green-600 dark:text-green-500">حاضر</div>
            </div>
            <div
                class="bg-red-50 dark:bg-red-900/20 rounded-2xl border border-red-200 dark:border-red-800 p-4 text-center">
                <div class="text-2xl font-bold text-red-700 dark:text-red-400">{{ $absentCount }}</div>
                <div class="text-sm text-red-600 dark:text-red-500">غائب</div>
            </div>
            <div
                class="bg-amber-50 dark:bg-amber-900/20 rounded-2xl border border-amber-200 dark:border-amber-800 p-4 text-center">
                <div class="text-2xl font-bold text-amber-700 dark:text-amber-400">{{ $lateCount }}</div>
                <div class="text-sm text-amber-600 dark:text-amber-500">متأخر</div>
            </div>
            <div
                class="bg-blue-50 dark:bg-blue-900/20 rounded-2xl border border-blue-200 dark:border-blue-800 p-4 text-center">
                <div class="text-2xl font-bold text-blue-700 dark:text-blue-400">{{ $excusedCount }}</div>
                <div class="text-sm text-blue-600 dark:text-blue-500">مستأذن</div>
            </div>
        </div>
    @endif
</div>
