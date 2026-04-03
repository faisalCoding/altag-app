<div>
    <div class="m-6 flex flex-col xl:flex-row xl:items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" class="font-bold">تقارير الحضور والغياب</flux:heading>
            <flux:subheading>إحصائيات مجمعة لتسجيلات الحضور والانصراف بحسب الفترات والمراحل.</flux:subheading>
        </div>
    </div>
    <div class="flex flex-col items-center gap-3 flex-1 xl:justify-end">
            <flux:button wire:click="$set('showPrintModal', true)" icon="printer" variant="outline">طباعة تقرير</flux:button>
            <flux:modal wire:model="showPrintModal" class="min-w-[400px] overflow-visible">
                <form wire:submit="downloadPDF" class="space-y-4">
                    <flux:heading size="lg">طباعة تقرير الحضور</flux:heading>
                    <flux:subheading>حدد الفترة لاستخراج جدول الحضور والغياب كملف PDF.</flux:subheading>
                    
                    <div class="space-y-4 my-4">
                        <livewire:manager.hijri-datepicker wire:model="printFrom" label="تاريخ البداية (من)" />
                        <livewire:manager.hijri-datepicker wire:model="printTo" label="تاريخ النهاية (إلى)" />
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:button wire:click="$set('showPrintModal', false)" variant="ghost">إلغاء</flux:button>
                        <flux:button type="submit" variant="primary" icon="document-arrow-down">تحميل PDF</flux:button>
                    </div>
                </form>
            </flux:modal>

            {{-- Filter Toolbar --}}
            <div class="flex items-start gap-3 bg-zinc-50 dark:bg-zinc-800/50 p-3 rounded-xl border border-zinc-200 dark:border-zinc-800">
                <div class="flex flex-col gap-1">
                    <label class="text-xs font-medium text-zinc-500">نوع العرض</label>
                    <div class="flex p-0.5 bg-zinc-200 dark:bg-zinc-700 rounded-lg">
                    <button wire:click="$set('viewType', 'days')" class="px-3 py-1 text-xs font-medium rounded-md transition-all {{ $viewType === 'days' ? 'bg-white dark:bg-zinc-600 shadow-xs text-zinc-900 dark:text-white' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">الأيام</button>
                    <button wire:click="$set('viewType', 'months')" class="px-3 py-1 text-xs font-medium rounded-md transition-all {{ $viewType === 'months' ? 'bg-white dark:bg-zinc-600 shadow-xs text-zinc-900 dark:text-white' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">الأشهر</button>
                </div>
            </div>

            <div class="flex flex-col gap-1 w-44">
                <label class="text-xs font-medium text-zinc-500">من تاريخ</label>
                <livewire:manager.hijri-datepicker wire:model.live="fromDate" label="من تاريخ" />
            </div>

            <div class="flex flex-col gap-1 w-44">
                <label class="text-xs font-medium text-zinc-500">إلى تاريخ</label>
                <livewire:manager.hijri-datepicker wire:model.live="toDate" label="إلى تاريخ" />
            </div>

            <button wire:click="clearFilters" class="p-2 text-zinc-400 hover:text-red-500 transition-colors" title="مسح الفلاتر">
                <flux:icon icon="x-mark" class="size-5" />
            </button>
            </div>
        </div>

    <div class="flex items-center justify-center m-6 gap-2 border-b border-zinc-200 dark:border-zinc-800 pb-3 mb-6">
        <button wire:click="$set('tab', 'by_date')" class="px-4 py-2 text-sm font-medium rounded-lg transition-colors {{ $tab === 'by_date' ? 'bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-white' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
            <div class="flex items-center gap-2">
                <flux:icon icon="calendar" class="size-4" />
                اجمالي الحلقات
            </div>
        </button>
        <button wire:click="$set('tab', 'by_tree')" class="px-4 py-2 text-sm font-medium rounded-lg transition-colors {{ $tab === 'by_tree' ? 'bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-white' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
            <div class="flex items-center gap-2">
                <flux:icon icon="rectangle-stack" class="size-4" />
                حسب المرحلة
            </div>
        </button>
    </div>

    <div class="mt-4">
        {{-- By Period Tab (Overall) --}}
        @if($tab === 'by_date')
            <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-xs border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ $viewType === 'days' ? 'التاريخ' : 'الشهر الهجري' }}</flux:table.column>
                        <flux:table.column>إجمالي المسجلين</flux:table.column>
                        <flux:table.column>الحضور</flux:table.column>
                        <flux:table.column>الغياب</flux:table.column>
                        <flux:table.column>التأخر</flux:table.column>
                        <flux:table.column>الاستئذان</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($reportsByPeriod as $report)
                            <flux:table.row>
                                <flux:table.cell class="font-medium">
                                    @if($viewType === 'days')
                                        {{ $this->formatHijriDate($report->period) }}
                                    @else
                                        {{ $this->formatHijriMonth($report->period) }}
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>{{ $report->total }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge color="green">{{ $report->present_count }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge color="red">{{ $report->absent_count }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge color="amber">{{ $report->late_count }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge color="blue">{{ $report->excused_count }}</flux:badge>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="6" class="text-center text-zinc-500 py-6">
                                    لا توجد بيانات حضور مسجلة لهذا الاختيار.
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif

        {{-- By Tree Tab (Stage -> Period -> Circle) --}}
        @if($tab === 'by_tree')
            <div class="space-y-4">
                @forelse ($reportsTree as $stage)
                    {{-- Stage Accordion --}}
                    <div x-data="{ open: false }" class="bg-white dark:bg-zinc-900 rounded-xl shadow-xs border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                        
                        {{-- Stage Header --}}
                        <div @click="open = !open" class="cursor-pointer bg-zinc-50 dark:bg-zinc-800/50 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors p-4 flex flex-col md:flex-row items-center justify-between gap-4">
                            <div class="flex items-center gap-3 w-full md:w-auto">
                                <flux:icon icon="chevron-down" class="size-5 text-zinc-400 transition-transform duration-200" x-bind:class="open ? 'rotate-180' : ''" />
                                <h3 class="text-lg font-bold text-zinc-900 dark:text-white">{{ $stage['name'] }}</h3>
                            </div>
                            
                            <div class="flex items-center gap-2 text-sm overflow-x-auto w-full md:w-auto justify-center md:justify-end">
                                <flux:badge color="zinc">{{ $stage['stats']['total'] }}</flux:badge>
                                <flux:badge color="green">{{ $stage['stats']['present_count'] }}</flux:badge>
                                <flux:badge color="red">{{ $stage['stats']['absent_count'] }}</flux:badge>
                                <flux:badge color="amber">{{ $stage['stats']['late_count'] }}</flux:badge>
                                <flux:badge color="blue">{{ $stage['stats']['excused_count'] }}</flux:badge>
                            </div>
                        </div>

                        {{-- Stage Body (Periods) --}}
                        <div x-show="open" x-collapse x-cloak class="border-t border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900">
                            <div class="divide-y divide-zinc-100 dark:divide-zinc-800/50">
                                @foreach ($stage['periods'] as $periodNode)
                                    {{-- Period Accordion --}}
                                    <div x-data="{ openPeriod: false }" class="p-2 sm:p-4">
                                        
                                        {{-- Period Header --}}
                                        <div @click="openPeriod = !openPeriod" class="cursor-pointer bg-blue-50/50 dark:bg-blue-900/10 hover:bg-blue-100/50 dark:hover:bg-blue-900/20 rounded-lg p-3 flex items-center justify-between transition-colors border border-transparent dark:border-blue-900/30">
                                            <div class="flex items-center gap-2">
                                                <flux:icon icon="chevron-down" class="size-4 text-blue-500/70 transition-transform duration-200" x-bind:class="openPeriod ? 'rotate-180' : ''" />
                                                <h4 class="font-semibold text-blue-800 dark:text-blue-300">
                                                    @if($viewType === 'days')
                                                        {{ $this->formatHijriDate($periodNode['period']) }}
                                                    @else
                                                        {{ $this->formatHijriMonth($periodNode['period']) }}
                                                    @endif
                                                </h4>
                                            </div>

                                            <div class="flex items-center gap-1 sm:gap-2 text-xs">
                                                <flux:badge size="sm" color="green">{{ $periodNode['stats']['present_count'] }}</flux:badge>
                                                <flux:badge size="sm" color="red">{{ $periodNode['stats']['absent_count'] }}</flux:badge>
                                                <flux:badge size="sm" color="amber">{{ $periodNode['stats']['late_count'] }}</flux:badge>
                                                <flux:badge size="sm" color="blue">{{ $periodNode['stats']['excused_count'] }}</flux:badge>
                                            </div>
                                        </div>

                                        {{-- Period Body (Circles Table) --}}
                                        <div x-show="openPeriod" x-collapse x-cloak class="mt-3 ml-2 sm:ml-6 mb-2 flex justify-end">
                                            <div class="w-full sm:w-11/12 rounded-lg shadow-xs border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                                                <table class="min-w-full text-sm text-right">
                                                    <thead class="bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400">
                                                        <tr>
                                                            <th class="px-4 py-2 font-medium">الحلقة</th>
                                                            <th class="px-4 py-2 font-medium">الحضور</th>
                                                            <th class="px-4 py-2 font-medium">الغياب</th>
                                                            <th class="px-4 py-2 font-medium">تأخر</th>
                                                            <th class="px-4 py-2 font-medium">استئذان</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                                                        @foreach ($periodNode['circles'] as $circleRow)
                                                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                                                <td class="px-4 py-2 font-medium text-zinc-700 dark:text-zinc-300">
                                                                    {{ $circleRow['circle_name'] }}
                                                                </td>
                                                                <td class="px-4 py-2 text-green-600 dark:text-green-400 font-medium">
                                                                    {{ $circleRow['present_count'] }}
                                                                </td>
                                                                <td class="px-4 py-2 text-red-600 dark:text-red-400 font-medium">
                                                                    {{ $circleRow['absent_count'] }}
                                                                </td>
                                                                <td class="px-4 py-2 text-amber-600 dark:text-amber-400 font-medium">
                                                                    {{ $circleRow['late_count'] }}
                                                                </td>
                                                                <td class="px-4 py-2 text-blue-600 dark:text-blue-400 font-medium">
                                                                    {{ $circleRow['excused_count'] }}
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                    </div>
                @empty
                    <div class="text-center bg-white dark:bg-zinc-900 rounded-xl shadow-xs border border-zinc-200 dark:border-zinc-800 p-8 text-zinc-500">
                        لا توجد إحصائيات حضور مسجلة لهذا الاختيار.
                    </div>
                @endforelse
            </div>
        @endif
    </div>
</div>
