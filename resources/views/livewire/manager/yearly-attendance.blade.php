<div class="space-y-8 pb-10" dir="rtl">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" class="font-bold">متابعة تحضير الحلقات السنوي</flux:heading>
            <flux:subheading>عرض حالة تحضير جميع الحلقات على مدار العام الهجري {{ $currentYear }}</flux:subheading>
        </div>
        <div class="flex items-center gap-2">
            <div class="flex items-center gap-1.5 text-xs">
                <div class="size-3 rounded-full bg-green-100 dark:bg-green-900 border border-green-200"></div>
                <span class="text-zinc-500">مكتمل</span>
            </div>
            <div class="flex items-center gap-1.5 text-xs">
                <div class="size-3 rounded-full bg-blue-50 dark:bg-blue-900/40 border border-blue-100"></div>
                <span class="text-zinc-500">جزئي (>50%)</span>
            </div>
            <div class="flex items-center gap-1.5 text-xs">
                <div class="size-3 rounded-full bg-amber-50 dark:bg-amber-900/40 border border-amber-100"></div>
                <span class="text-zinc-500">تحضير بسيط</span>
            </div>
        </div>
    </div>

    <div class="max-w-3xl mx-auto grid grid-cols-1 gap-8">
        @foreach($months as $month)
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden flex flex-col">
                {{-- Month Header --}}
                <div class="px-4 py-3 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/50 text-center">
                    <div class="font-bold text-zinc-800 dark:text-zinc-100 text-sm">
                        {{ $month['monthName'] }}
                    </div>
                </div>

                {{-- Weekdays Header --}}
                <div class="grid grid-cols-7 gap-1 px-3 pt-3 pb-1 text-center">
                    @foreach(['أحد', 'إثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت'] as $day)
                        <div class="text-[0.6rem] font-bold text-zinc-400 dark:text-zinc-500 uppercase">{{ $day }}</div>
                    @endforeach
                </div>

                {{-- Days Grid --}}
                <div class="grid grid-cols-7 gap-0.5 px-1.5 pb-1.5 grow">
                    @foreach($month['days'] as $day)
                        @if($day === null)
                            <div class="h-16 w-full"></div>
                        @else
                            <button
                                wire:click="selectDate('{{ $day['gregorianDate'] }}', {{ $day['hijriDay'] }}, '{{ $month['monthName'] }}')"
                                type="button"
                                class="relative flex flex-col justify-between p-1.5 rounded-md border h-16 w-full transition-all duration-200 
                                {{ $day['colorClass'] }}
                                {{ $day['isToday'] ? 'ring-2 ring-indigo-500 ring-offset-1 dark:ring-offset-zinc-900 border-transparent shadow-sm' : 'border-zinc-100 dark:border-zinc-700/50' }}
                                ">

                                {{-- Hijri Day Number --}}
                                <div class="flex justify-between items-start w-full leading-none">
                                    <span
                                        class="text-xs font-semibold {{ $day['isToday'] ? 'text-indigo-700 dark:text-indigo-400' : 'text-zinc-700 dark:text-zinc-200' }}">
                                        {{ $day['hijriDay'] }}
                                    </span>

                                    @if ($day['isToday'])
                                        <div class="size-1.5 rounded-full bg-indigo-500 mt-0.5"></div>
                                    @endif
                                </div>

                                {{-- Stats Area: Circles Completed / Total Circles --}}
                                <div class="mt-auto w-full space-y-1">
                                    @if ($day['completedCount'] > 0 || $totalCirclesCount > 0)
                                        <div class="text-[0.65rem] font-medium leading-none text-center">
                                            <span
                                                class="text-indigo-700 dark:text-indigo-400 font-bold">{{ $day['completedCount'] }}</span>
                                            <span class="mx-0.5 text-zinc-400">/</span>
                                            <span
                                                class="text-zinc-500 dark:text-zinc-500 font-semibold">{{ $totalCirclesCount }}</span>
                                        </div>
                                        <div class="w-full bg-black/5 dark:bg-white/10 rounded-full h-1 overflow-hidden">
                                            <div class="h-full rounded-full {{ $day['completionRate'] == 100 ? 'bg-green-500 dark:bg-green-400' : 'bg-indigo-400 dark:bg-indigo-500' }}"
                                                style="width: {{ $day['completionRate'] }}%"></div>
                                        </div>
                                    @endif
                                </div>
                            </button>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    {{-- Day Details Modal --}}
    <flux:modal name="day-details" class="max-w-2xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">تفاصيل التحضير</flux:heading>
                <flux:subheading>يوم {{ $selectedDateHijri }}</flux:subheading>
            </div>

            <div class="divide-y divide-zinc-100 dark:divide-zinc-800 -mx-6 border-t border-zinc-100 dark:border-zinc-800">
                @foreach ($circlesAttendance as $circle)
                    <div
                        class="flex items-center justify-between px-6 py-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/40 transition-colors group">
                        
                        <div class="flex items-center gap-4">
                            {{-- Minimalist status indicator --}}
                            <div class="h-8 w-1 rounded-full {{ $circle['is_completed'] ? 'bg-green-500 shadow-[0_0_10px_rgba(34,197,94,0.2)]' : 'bg-red-500 shadow-[0_0_10px_rgba(239,68,68,0.2)]' }}"></div>
                            
                            <div>
                                <div class="font-bold text-zinc-900 dark:text-white leading-tight">{{ $circle['name'] }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">المعلم: {{ $circle['teacher_name'] }}</div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                             <flux:button.group>
                                @if ($circle['teacher_phone'])
                                    @php
                                        $msg = urlencode(
                                            "السلام عليكم ورحمة الله وبركاته، بخصوص تحضير الحلقة يوم $selectedDateHijri",
                                        );
                                    @endphp
                                    <flux:button size="sm" variant="ghost" square
                                        href="https://wa.me/{{ $circle['teacher_phone'] }}?text={{ $msg }}"
                                        target="_blank">
                                        <flux:icon icon="chat-bubble-left-right" class="size-4 text-green-500" />
                                    </flux:button>
                                @endif
                                <flux:button size="sm" variant="ghost" icon="list-bullet" square
                                    href="{{ route('manager.attendance-list', ['circleId' => $circle['id'], 'date' => $selectedDate]) }}"
                                    wire:navigate />
                             </flux:button.group>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex justify-end pt-2">
                <flux:modal.close>
                    <flux:button variant="ghost" size="sm">إغلاق</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
