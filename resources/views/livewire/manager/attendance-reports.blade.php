<div dir="rtl">

    {{-- Header --}}
    <div class="m-6 flex flex-col xl:flex-row xl:items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" class="font-bold">تقارير الحضور والغياب</flux:heading>
            <flux:subheading>جدول الحضور اليومي مصنف بالمراحل والحلقات.</flux:subheading>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            {{-- Print Button --}}
            <flux:button wire:click="downloadPDF" icon="printer" variant="outline">طباعة تقرير</flux:button>

            {{-- Date Filters --}}
            <div class="flex items-end gap-2 bg-zinc-50 dark:bg-zinc-800/50 p-2 rounded-xl border border-zinc-200 dark:border-zinc-800">
                <div class="flex flex-col gap-1 w-36">
                    <label class="text-xs font-medium text-zinc-500">من تاريخ</label>
                    <livewire:manager.hijri-datepicker wire:model.live="fromDate" label="من تاريخ" />
                </div>
                <div class="flex flex-col gap-1 w-36">
                    <label class="text-xs font-medium text-zinc-500">إلى تاريخ</label>
                    <livewire:manager.hijri-datepicker wire:model.live="toDate" label="إلى تاريخ" />
                </div>
                <button wire:click="clearFilters" class="p-2 text-zinc-400 hover:text-red-500   s" title="مسح الفلاتر">
                    <flux:icon icon="x-mark" class="size-5" />
                </button>
            </div>
        </div>
    </div>

    {{-- ─────────── تصفية المراحل ─────────── --}}
    {{-- Nothing ticked means the whole academy, so the report opens complete
         and narrows only when the manager asks it to. --}}
    <div class="mx-6 mb-6 rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-800/50">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium text-zinc-500 me-1">المراحل:</span>

            @foreach ($stages as $stage)
                <label wire:key="stage-filter-{{ $stage->id }}"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs transition-colors
                        {{ in_array((string) $stage->id, array_map('strval', $stageIds), true)
                            ? 'border-maroon bg-maroon/10 text-maroon dark:text-red-secondary font-bold'
                            : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-300 hover:bg-white dark:hover:bg-zinc-800' }}">
                    <input type="checkbox" wire:model.live="stageIds" value="{{ $stage->id }}" class="sr-only">
                    {{ $stage->name }}
                </label>
            @endforeach

            @if (count($stageIds) > 0)
                <flux:button size="xs" variant="ghost" wire:click="clearStages">كل المراحل</flux:button>
            @else
                <span class="text-xs text-zinc-400">— الكل</span>
            @endif
        </div>
    </div>


    {{-- Main Grid Table --}}
    <div class="mx-6 mb-6">
        @if(count($dates) === 0)
            <div class="text-center bg-white dark:bg-zinc-900 rounded-xl shadow-xs border border-zinc-200 dark:border-zinc-800 p-12 text-zinc-500">
                <flux:icon icon="calendar" class="size-10 mx-auto mb-3 text-zinc-300" />
                <p>حدد نطاق التاريخ لعرض تقرير الحضور.</p>
            </div>
        @elseif(count($dates) > 30)
            <div class="text-center bg-amber-50 dark:bg-amber-900/20 rounded-xl border border-amber-200 dark:border-amber-800 p-8 text-amber-700 dark:text-amber-400">
                <flux:icon icon="exclamation-triangle" class="size-10 mx-auto mb-3" />
                <p class="font-medium">النطاق المحدد كبير جداً ({{ count($dates) }} يوماً)</p>
                <p class="text-sm mt-1">يُنصح بعرض 30 يوماً كحد أقصى للحصول على تجربة أفضل. استخدم زر طباعة PDF لنطاقات أكبر.</p>
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                <table class="min-w-full text-sm text-right border-collapse">
                    <thead>
                        {{-- Month Row --}}
                        <tr>
                            <th class="sticky right-0 z-10 bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 px-3 py-2 min-w-[160px] text-zinc-700 dark:text-zinc-300 font-bold" rowspan="2">
                                الحلقة / المرحلة
                            </th>
                            @php
                                $monthGroups = [];
                                $prevMonth = null;
                                foreach ($dates as $d) {
                                    $m = $this->formatHijriMonthYear($d);
                                    if ($m === $prevMonth) {
                                        $monthGroups[count($monthGroups) - 1]['span']++;
                                    } else {
                                        $monthGroups[] = ['label' => $m, 'span' => 1];
                                        $prevMonth = $m;
                                    }
                                }
                            @endphp
                            @foreach($monthGroups as $mg)
                                <th colspan="{{ $mg['span'] }}" class="bg-zinc-50 dark:bg-zinc-800/70 border border-zinc-200 dark:border-zinc-700 px-1 py-1 text-center text-xs text-zinc-500">
                                    {{ $mg['label'] }}
                                </th>
                            @endforeach
                            <th class="bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 px-2 py-2 text-center text-zinc-700 dark:text-zinc-300 font-bold min-w-[80px]" rowspan="2">
                                الإجمالي
                            </th>
                        </tr>
                        {{-- Day Row --}}
                        <tr>
                            @foreach($dates as $date)
                                <th class="bg-zinc-50 dark:bg-zinc-800/70 border border-zinc-200 dark:border-zinc-700 px-1 py-1 text-center">
                                    {{-- Weekday above the number, as the month sheet
                                         already reads. --}}
                                    <div class="text-[10px] text-zinc-400">{{ $this->formatHijriDayName($date) }}</div>
                                    <div class="text-xs font-bold text-zinc-700 dark:text-zinc-300">{{ $this->formatHijriDayNum($date) }}</div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($groupedCircles as $stageName => $circles)
                            {{-- Stage Row --}}
                            <tr>
                                <td colspan="{{ count($dates) + 2 }}"
                                    class="sticky right-0 bg-zinc-200 dark:bg-zinc-700 border border-zinc-300 dark:border-zinc-600 px-4 py-2 font-bold text-zinc-800 dark:text-zinc-100 text-sm">
                                    {{ $stageName }}
                                </td>
                            </tr>
                            {{-- Circle Rows --}}
                            @foreach($circles as $circle)
                                @php
                                    $circleTotalPresent = 0;
                                    $circleGlobalTotal  = 0;
                                    $daysWithData       = 0;
                                @endphp
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40   s">
                                    <td class="sticky right-0 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 px-3 py-2 font-medium text-zinc-700 dark:text-zinc-300">
                                        {{ $circle->name }}
                                    </td>
                                    @foreach($dates as $date)
                                        @php
                                            $cell = $attendanceData[$circle->id][$date] ?? null;
                                            if ($cell) {
                                                $circleTotalPresent += $cell['present'];
                                                $circleGlobalTotal  += $cell['total'];
                                                $daysWithData++;
                                            }
                                        @endphp
                                        <td class="border border-zinc-100 dark:border-zinc-800 px-1 py-1 text-center">
                                            @if($cell)
                                                <div class="text-[15px] leading-snug flex flex-col">
                                                    <div class="text-emerald-600 dark:text-emerald-400 font-semibold">{{ $cell['present'] }}</div>
                                                    <div class="text-zinc-500 dark:text-zinc-400">{{ $cell['total'] }}</div>
                                                </div>
                                            @else
                                                <span class="text-zinc-300 dark:text-zinc-700">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="border border-zinc-200 dark:border-zinc-700 px-2 py-2 text-center bg-zinc-50 dark:bg-zinc-800/50">
                                        @php
                                            $avgTotal = $daysWithData > 0 ? round($circleGlobalTotal / $daysWithData) : 0;
                                        @endphp
                                        <div class="text-[15px] leading-snug">
                                            <div class="text-emerald-600 dark:text-emerald-400 font-bold">{{ $circleTotalPresent }}</div>
                                            <div class="text-zinc-400 text-[10px]">متوسط: {{ $avgTotal }}</div>
                                            <div class="text-blue-500 dark:text-blue-400 text-[10px]">المشاركون: {{ $circleGlobalTotal }}</div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="{{ count($dates) + 2 }}" class="text-center text-zinc-500 py-10">
                                    لا توجد حلقات مسجلة.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    {{-- Grand Total Footer Row --}}
                    <tfoot>
                        <tr class="bg-zinc-100 dark:bg-zinc-800 font-bold">
                            <td class="sticky right-0 bg-zinc-100 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-600 px-3 py-2 text-zinc-800 dark:text-zinc-100 text-sm">
                                الإجمالي الكلي
                            </td>
                            @php
                                $grandTotalPresent = 0;
                                $grandTotalParticipants = 0;
                            @endphp
                            @foreach($dates as $date)
                                @php
                                    $dayPresent = 0;
                                    $dayTotal   = 0;
                                    foreach($groupedCircles->flatten() as $c) {
                                        $cell = $attendanceData[$c->id][$date] ?? null;
                                        if ($cell) {
                                            $dayPresent += $cell['present'];
                                            $dayTotal   += $cell['total'];
                                        }
                                    }
                                    $grandTotalPresent      += $dayPresent;
                                    $grandTotalParticipants += $dayTotal;
                                @endphp
                                <td class="border border-zinc-300 dark:border-zinc-600 px-1 py-1 text-center">
                                    @if($dayTotal > 0)
                                        <div class="text-[15px] leading-snug flex flex-col">
                                            <div class="text-emerald-600 dark:text-emerald-400 font-bold">{{ $dayPresent }}</div>
                                            <div class="text-zinc-600 dark:text-zinc-300">{{ $dayTotal }}</div>
                                        </div>
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-700">—</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="border border-zinc-300 dark:border-zinc-600 px-2 py-2 text-center bg-zinc-200 dark:bg-zinc-700">
                                <div class="text-[15px] leading-snug">
                                    <div class="text-emerald-600 dark:text-emerald-400 font-bold">{{ $grandTotalPresent }}</div>
                                    <div class="text-blue-500 dark:text-blue-400 text-[10px]">المشاركون: {{ $grandTotalParticipants }}</div>
                                </div>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Legend --}}
            <div class="flex items-center gap-4 mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                <div class="flex items-center gap-1">
                    <span class="text-emerald-600 font-bold">الرقم الأول (أخضر):</span>
                    <span>عدد الحضور (حاضر + متأخر)</span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="text-zinc-500 font-medium">الرقم الثاني:</span>
                    <span>إجمالي المشاركين في هذا اليوم</span>
                </div>
            </div>
        @endif
    </div>
</div>
