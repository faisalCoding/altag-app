<div style="justify-items: center;" class="relative items-center justify-center align-middle w-full" x-data="{ open: @entangle('open') }" @click.away="open = false">
    {{-- Trigger button disguised as an input --}}
    <button @click="open = !open" type="button" class="w-full flex items-center justify-between text-right bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 shadow-sm rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 disabled:opacity-50 transition-colors">
        <div class="flex flex-col text-right">
            <span class="text-xs text-zinc-500 dark:text-zinc-400">التاريخ (هجري)</span>
            <span class="font-medium text-zinc-900 dark:text-zinc-100 mt-0.5">
                {{ $this->hijriFormattedDate }}
            </span>
        </div>
        <flux:icon icon="calendar" class="size-5 text-zinc-400" />
    </button>

    {{-- Dropdown Calendar --}}
    <div x-show="open" x-transition.opacity.duration.200ms style="display: none;" 
         class="absolute z-50 top-full mt-2 w-96 sm:w-120 bg-white dark:bg-zinc-900 rounded-xl shadow-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden" dir="rtl">
        
        {{-- Header: Month Navigation --}}
        <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/50">
            <button wire:click="previousMonth" type="button" class="p-1.5 rounded-md hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-500 transition-colors">
                <flux:icon icon="chevron-right" class="size-5" />
            </button>
            <div class="font-bold text-zinc-800 dark:text-zinc-100 text-sm">
                {{ $monthName }}
            </div>
            <button wire:click="nextMonth" type="button" class="p-1.5 rounded-md hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-500 transition-colors">
                <flux:icon icon="chevron-left" class="size-5" />
            </button>
        </div>

        {{-- Weekdays Header --}}
        <div class="grid grid-cols-7 gap-1 px-4 pt-3 pb-1 text-center">
            @foreach(['أحد', 'إثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت'] as $day)
                <div class="text-[0.65rem] font-bold text-zinc-400 dark:text-zinc-500 uppercase">{{ $day }}</div>
            @endforeach
        </div>

        {{-- Days Grid --}}
        <div class="grid grid-cols-7 gap-0.5 px-1.5 pb-1.5">
            @foreach($days as $day)
                @if($day === null)
                    <div class="h-16 w-full"></div>
                @else
                    <button wire:click="selectDate('{{ $day['gregorianDate'] }}')" 
                            type="button"
                            class="relative flex flex-col justify-between p-1.5 rounded-md border h-16 w-full transition-all duration-200 
                            {{ $day['colorClass'] }}
                            {{ $day['isSelected'] ? 'ring-2 ring-blue-500 ring-offset-1 dark:ring-offset-zinc-900 border-transparent shadow-sm' : 'border-zinc-100 dark:border-zinc-700/50' }}
                            {{ $day['isToday'] && !$day['isSelected'] ? 'border-blue-300 dark:border-blue-600' : '' }}
                            ">
                        
                        {{-- Hijri Day Number --}}
                        <div class="flex justify-between items-start w-full leading-none">
                            <span class="text-xs font-semibold {{ $day['isSelected'] ? 'text-blue-700 dark:text-blue-400' : 'text-zinc-700 dark:text-zinc-200' }}">
                                {{ $day['hijriDay'] }}
                            </span>
                            
                            @if($day['isToday'])
                                <div class="size-1.5 rounded-full bg-blue-500 mt-0.5"></div>
                            @endif
                        </div>

                        {{-- Stats Area --}}
                        <div class="mt-auto w-full space-y-1">
                            @if($day['processedCount'] > 0)
                                <div class="text-[0.65rem] font-medium leading-none text-center">
                                    <span class="text-green-700 dark:text-green-500 font-bold">{{ $day['presentCount'] }}</span>
                                    <span class="mx-0.5 text-zinc-400">/</span>
                                    <span class="text-red-600 dark:text-red-500 font-bold">{{ $day['absentCount'] }}</span>
                                </div>
                                <div class="w-full bg-black/5 dark:bg-white/10 rounded-full h-1 overflow-hidden">
                                    <div class="h-full rounded-full {{ $day['completionRate'] == 100 ? 'bg-green-500 dark:bg-green-400' : 'bg-zinc-400 dark:bg-zinc-500' }}" 
                                         style="width: {{ $day['completionRate'] }}%"></div>
                                </div>
                            @endif
                        </div>
                    </button>
                @endif
            @endforeach
        </div>
        
        @if($totalStudentsCount == 0)
        <div class="px-4 py-2 text-xs text-center text-zinc-500 border-t border-zinc-100 dark:border-zinc-800">
            تنبيه: لا يوجد طلاب معتمدون في هذه الحلقة
        </div>
        @endif
    </div>
</div>
