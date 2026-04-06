<div class="space-y-6" dir="rtl">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            <flux:button variant="ghost" icon="chevron-right" href="{{ route('manager.yearly-attendance') }}" wire:navigate>عودة</flux:button>
            <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                <flux:icon icon="clipboard-document-check" />
            </div>
            <div>
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">سجل الحضور - {{ $circle->name }}</flux:heading>
                <flux:subheading>مراجعة وتعديل تحضير الطلاب ليوم {{ $this->getHijriDate() }}</flux:subheading>
            </div>
        </div>
    </div>

    {{-- Stats Bar --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-5">
        <div class="flex flex-col md:flex-row gap-6 items-center">
            <div class="flex items-center gap-2 px-4 py-2 bg-zinc-50 dark:bg-zinc-800 rounded-xl border border-zinc-100 dark:border-zinc-700">
                <flux:icon icon="calendar" class="size-4 text-zinc-500" />
                <span class="text-sm font-bold text-zinc-700 dark:text-zinc-300">التاريخ: {{ $this->getHijriDate() }}</span>
            </div>

            <div class="flex-1 w-full">
                <div class="flex items-center justify-between text-sm text-zinc-500 dark:text-zinc-400 mb-1.5">
                    <span>نسبة التحضير: {{ $this->markedCount }} / {{ count($students) }}</span>
                    @if ($isComplete)
                        <span class="text-green-600 dark:text-green-400 font-medium flex items-center gap-1">
                            <flux:icon icon="check-circle" class="size-4" />
                            مكتمل
                        </span>
                    @endif
                </div>
                <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-2 overflow-hidden">
                    <div class="h-2 rounded-full transition-all duration-500 ease-out {{ $isComplete ? 'bg-green-500' : 'bg-indigo-500' }}"
                        style="width: {{ count($students) > 0 ? ($this->markedCount / count($students)) * 100 : 0 }}%">
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- List Mode View --}}
     <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($students as $index => $student)
                <div wire:key="student-{{ $student->id }}"
                    class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-mono text-zinc-400 w-6 text-center">{{ $index + 1 }}</span>
                        <div class="flex items-center gap-2">
                            <div class="p-1.5 rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon icon="user" class="size-4 text-zinc-400" />
                            </div>
                            <span class="font-bold text-zinc-900 dark:text-white">{{ $student->name }}</span>
                        </div>
                    </div>

                    <div class="flex gap-2 mr-9 sm:mr-0">
                         @if ($student->guardian_phone && ($records[$student->id] ?? '') === 'absent')
                             <a class="whatsapp-link flex items-center justify-center p-2 rounded-lg hover:bg-green-50 dark:hover:bg-green-900/10 transition-colors"
                                 href="https://wa.me/{{ $student->guardian_phone }}/?text={{ urlencode($this->getWhatsAppMessage($student)) }}"
                                 target="_blank" title="تواصل مع ولي الأمر">
                                 <flux:icon icon="chat-bubble-left-right"
                                     class="size-5 text-green-500" />
                             </a>
                         @endif

                        @php $currentStatus = $records[$student->id] ?? ''; @endphp

                        <button wire:click="updateStatus({{ $student->id }}, 'present')"
                            class="px-4 py-2 text-xs font-bold rounded-xl border transition-all {{ $currentStatus === 'present' ? 'bg-green-500 text-white border-green-600 shadow-sm' : 'bg-white text-zinc-600 border-zinc-200 hover:border-green-300 hover:bg-green-50 dark:bg-zinc-800 dark:text-zinc-300 dark:border-zinc-700' }}">
                            حاضر
                        </button>
                        <button wire:click="updateStatus({{ $student->id }}, 'absent')"
                            class="px-4 py-2 text-xs font-bold rounded-xl border transition-all {{ $currentStatus === 'absent' ? 'bg-red-500 text-white border-red-600 shadow-sm' : 'bg-white text-zinc-600 border-zinc-200 hover:border-red-300 hover:bg-red-50 dark:bg-zinc-800 dark:text-zinc-300 dark:border-zinc-700' }}">
                            غائب
                        </button>
                        <button wire:click="updateStatus({{ $student->id }}, 'late')"
                            class="px-4 py-2 text-xs font-bold rounded-xl border transition-all {{ $currentStatus === 'late' ? 'bg-amber-500 text-white border-amber-600 shadow-sm' : 'bg-white text-zinc-600 border-zinc-200 hover:border-amber-300 hover:bg-amber-50 dark:bg-zinc-800 dark:text-zinc-300 dark:border-zinc-700' }}">
                            متأخر
                        </button>
                        <button wire:click="updateStatus({{ $student->id }}, 'excused')"
                            class="px-4 py-2 text-xs font-bold rounded-xl border transition-all {{ $currentStatus === 'excused' ? 'bg-blue-500 text-white border-blue-600 shadow-sm' : 'bg-white text-zinc-600 border-zinc-200 hover:border-blue-300 hover:bg-blue-50 dark:bg-zinc-800 dark:text-zinc-300 dark:border-zinc-700' }}">
                            مستأذن
                        </button>
                    </div>
                </div>
            @empty
                <div class="p-12 text-center text-zinc-400">لا يوجد طلاب في هذه الحلقة</div>
            @endforelse
        </div>
    </div>

    {{-- Stats Summary --}}
    @if ($isComplete && count($students) > 0)
        @php
            $presentCount = collect($records)->filter(fn($s) => $s === 'present')->count();
            $absentCount = collect($records)->filter(fn($s) => $s === 'absent')->count();
            $lateCount = collect($records)->filter(fn($s) => $s === 'late')->count();
            $excusedCount = collect($records)->filter(fn($s) => $s === 'excused')->count();
        @endphp
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-green-50 dark:bg-green-900/20 rounded-2xl border border-green-200 dark:border-green-800 p-4 text-center">
                <div class="text-3xl font-black text-green-700 dark:text-green-400">{{ $presentCount }}</div>
                <div class="text-xs font-bold text-green-600 dark:text-green-500 uppercase tracking-widest mt-1">حاضر</div>
            </div>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-2xl border border-red-200 dark:border-red-800 p-4 text-center">
                <div class="text-3xl font-black text-red-700 dark:text-red-400">{{ $absentCount }}</div>
                <div class="text-xs font-bold text-red-600 dark:text-red-500 uppercase tracking-widest mt-1">غائب</div>
            </div>
            <div class="bg-amber-50 dark:bg-amber-900/20 rounded-2xl border border-amber-200 dark:border-amber-800 p-4 text-center">
                <div class="text-3xl font-black text-amber-700 dark:text-amber-400">{{ $lateCount }}</div>
                <div class="text-xs font-bold text-amber-600 dark:text-amber-500 uppercase tracking-widest mt-1">متأخر</div>
            </div>
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-2xl border border-blue-200 dark:border-blue-800 p-4 text-center">
                <div class="text-3xl font-black text-blue-700 dark:text-blue-400">{{ $excusedCount }}</div>
                <div class="text-xs font-bold text-blue-600 dark:text-blue-500 uppercase tracking-widest mt-1">مستأذن</div>
            </div>
        </div>
    @endif
</div>
