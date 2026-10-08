@php
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits($n);

    // A day's rate as a colour, the same bands on the screen and the sheet;
    // spelled out for Tailwind.
    $band = fn (?int $rate) => match (true) {
        $rate === null => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
        $rate >= 90 => 'bg-emerald-200 text-emerald-900 dark:bg-emerald-800/70 dark:text-emerald-50',
        $rate >= 75 => 'bg-lime-100 text-lime-900 dark:bg-lime-900/50 dark:text-lime-100',
        $rate >= 60 => 'bg-amber-200 text-amber-950 dark:bg-amber-800/60 dark:text-amber-50',
        default => 'bg-rose-200 text-rose-950 dark:bg-rose-800/60 dark:text-rose-50',
    };
    $rateText = fn (?int $rate) => match (true) {
        $rate === null => 'text-zinc-400',
        $rate >= 90 => 'text-emerald-700 dark:text-emerald-400',
        $rate >= 75 => 'text-lime-700 dark:text-lime-400',
        $rate >= 60 => 'text-amber-700 dark:text-amber-400',
        default => 'text-rose-700 dark:text-rose-400',
    };
    $hatch = 'background-image: repeating-linear-gradient(45deg, rgb(161 161 170 / 0.18) 0 4px, transparent 4px 8px)';
@endphp

<div dir="rtl">

    {{-- Header --}}
    <div class="m-6 flex flex-col xl:flex-row xl:items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" class="font-bold">تقارير الحضور والغياب</flux:heading>
            <flux:subheading>نسبة الحضور لكل حلقة في كل يوم، مصنّفة بالمراحل.</flux:subheading>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
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
                <button wire:click="clearFilters" class="p-2 text-zinc-400 hover:text-red-500" title="آخر سبعة أيام" aria-label="آخر سبعة أيام">
                    <flux:icon icon="x-mark" class="size-5" />
                </button>
            </div>
        </div>
    </div>

    {{-- ─────────── تصفية المراحل ─────────── --}}
    {{-- Nothing ticked means the whole academy, so the report opens complete
         and narrows only when the manager asks it to. --}}
    <div class="mx-6 mb-4 rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-800/50">
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

    <div class="mx-6 mb-6">
        @if ($problem)
            <div class="text-center bg-white dark:bg-zinc-900 rounded-xl shadow-xs border border-zinc-200 dark:border-zinc-800 p-12 text-zinc-500" data-range-problem>
                <flux:icon icon="calendar" class="size-10 mx-auto mb-3 text-zinc-300" />
                <p>{{ $problem }}</p>
            </div>
        @elseif (! $grid)
            <div class="text-center bg-amber-50 dark:bg-amber-900/20 rounded-xl border border-amber-200 dark:border-amber-800 p-8 text-amber-700 dark:text-amber-400">
                <flux:icon icon="exclamation-triangle" class="size-10 mx-auto mb-3" />
                <p class="font-medium">النطاق المحدد كبير جداً ({{ $ar($dayCount) }} يوماً)</p>
                <p class="text-sm mt-1">تعرض الصفحة {{ $ar(\App\Livewire\Manager\AttendanceReports::SCREEN_DAYS) }} يوماً على الأكثر؛ للنطاقات الأكبر استعمل «طباعة تقرير».</p>
            </div>
        @else
            @php
                $summary = $grid['summary'];
                $dates = $grid['dates'];
            @endphp

            {{-- ─────────── الملخّص ─────────── --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4" data-report-summary>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
                    <div class="text-xs text-zinc-500">نسبة الحضور</div>
                    <div class="mt-1 text-2xl font-black {{ $rateText($summary['rate']) }}">{{ $summary['rate'] === null ? '—' : $ar($summary['rate']).'٪' }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4" data-summary-missing="{{ $summary['missing'] }}">
                    <div class="text-xs text-zinc-500">أيام دوام بلا تحضير</div>
                    <div class="mt-1 text-2xl font-black {{ $summary['missing'] > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-800 dark:text-zinc-100' }}">{{ $ar($summary['missing']) }}</div>
                    @if ($summary['missing'] > 0)
                        <div class="text-xs text-zinc-400">في {{ $ar($summary['missing_circles']) }} {{ $summary['missing_circles'] === 1 ? 'حلقة' : 'حلقات' }}</div>
                    @endif
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4" data-summary-unmarked="{{ $summary['unmarked'] }}">
                    <div class="text-xs text-zinc-500">طلاب لم يُسجَّلوا</div>
                    <div class="mt-1 text-2xl font-black {{ $summary['unmarked'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-800 dark:text-zinc-100' }}">{{ $ar($summary['unmarked']) }}</div>
                    <div class="text-xs text-zinc-400">في أيام حُضّرت فيها حلقاتهم</div>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
                    <div class="text-xs text-zinc-500">أكثر حلقة غياباً</div>
                    @if ($summary['worst'])
                        <div class="mt-1 font-bold text-zinc-800 dark:text-zinc-100 truncate">{{ $summary['worst']['name'] }}</div>
                        <div class="text-xs {{ $rateText($summary['worst']['rate']) }}">نسبة الحضور {{ $ar($summary['worst']['rate']) }}٪</div>
                    @else
                        <div class="mt-1 text-zinc-400">—</div>
                    @endif
                </div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
                <table class="min-w-full text-sm text-right border-separate border-spacing-0.5 bg-white dark:bg-zinc-900">
                    <thead>
                        <tr>
                            <th class="sticky right-0 z-10 bg-zinc-100 dark:bg-zinc-800 px-3 py-2 min-w-[150px] text-zinc-700 dark:text-zinc-300 font-bold" rowspan="2">
                                الحلقة
                            </th>
                            @php
                                $monthGroups = [];
                                $prevMonth = null;
                                foreach ($dates as $day) {
                                    $m = $this->formatHijriMonthYear($day['date']);
                                    if ($m === $prevMonth) {
                                        $monthGroups[count($monthGroups) - 1]['span']++;
                                    } else {
                                        $monthGroups[] = ['label' => $m, 'span' => 1];
                                        $prevMonth = $m;
                                    }
                                }
                            @endphp
                            @foreach ($monthGroups as $mg)
                                <th colspan="{{ $mg['span'] }}" class="bg-zinc-50 dark:bg-zinc-800/70 px-1 py-1 text-center text-xs text-zinc-500">
                                    {{ $mg['label'] }}
                                </th>
                            @endforeach
                            <th class="bg-zinc-100 dark:bg-zinc-800 px-2 py-2 text-center text-zinc-700 dark:text-zinc-300 font-bold min-w-[90px]" rowspan="2">
                                النسبة
                            </th>
                        </tr>
                        <tr>
                            @foreach ($dates as $day)
                                <th class="bg-zinc-50 dark:bg-zinc-800/70 px-1 py-1 text-center min-w-[52px] {{ $day['today'] ? 'ring-2 ring-inset ring-zinc-400' : '' }}">
                                    <div class="text-[10px] text-zinc-400">{{ $this->formatHijriDayName($day['date']) }}</div>
                                    <div class="text-xs font-bold text-zinc-700 dark:text-zinc-300">{{ $this->formatHijriDayNum($day['date']) }}</div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($grid['groups'] as $group)
                            <tr wire:key="stage-{{ $loop->index }}">
                                <td colspan="{{ count($dates) + 2 }}"
                                    class="sticky right-0 bg-zinc-200 dark:bg-zinc-700 px-4 py-1.5 font-bold text-zinc-800 dark:text-zinc-100 text-sm">
                                    {{ $group['stage'] }}
                                </td>
                            </tr>
                            @foreach ($group['circles'] as $row)
                                @php
                                    $circle = $row['circle'];
                                    $totals = $row['totals'];
                                @endphp
                                <tr wire:key="circle-{{ $circle->id }}">
                                    <td class="sticky right-0 z-[1] bg-white dark:bg-zinc-900 px-3 py-1.5 font-medium text-zinc-700 dark:text-zinc-300">
                                        {{ $circle->name }}
                                    </td>
                                    @foreach ($dates as $day)
                                        @php
                                            $cell = $row['cells'][$day['date']];
                                            $link = route('manager.attendance-list', ['circleId' => $circle->id, 'date' => $day['date']]);
                                            $detail = 'حاضر '.$ar($cell['present'] - $cell['late']).' · متأخر '.$ar($cell['late']).' · غائب '.$ar($cell['absent'])
                                                .' · مستأذن '.$ar($cell['excused']).($cell['unmarked'] > 0 ? ' · لم يُسجَّل '.$ar($cell['unmarked']) : '');
                                        @endphp
                                        <td class="p-0 text-center align-middle" data-cell="{{ $circle->id }}-{{ $day['date'] }}" data-state="{{ $cell['state'] }}">
                                            @switch ($cell['state'])
                                                @case('data')
                                                    <a href="{{ $link }}" wire:navigate title="{{ $detail }}"
                                                        class="block rounded-md px-1 py-1.5 leading-tight hover:ring-2 hover:ring-zinc-400 {{ $band($cell['rate']) }}">
                                                        <span class="block text-[13px] font-bold">{{ $ar($cell['present']) }}/{{ $ar($cell['expected'] - $cell['excused']) }}</span>
                                                        @if ($cell['unmarked'] > 0)
                                                            <span class="block text-[10px] font-medium opacity-80">{{ $ar($cell['unmarked']) }} لم يُسجَّل</span>
                                                        @endif
                                                    </a>
                                                    @break
                                                @case('missing')
                                                    <a href="{{ $link }}" wire:navigate title="يوم دوام لم يُحضَّر فيه"
                                                        class="block rounded-md border-2 border-dashed border-rose-400 px-1 py-1.5 text-[10px] font-bold text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20">
                                                        لم يُحضَّر
                                                    </a>
                                                    @break
                                                @case('pending')
                                                    <a href="{{ $link }}" wire:navigate title="لم يُحضَّر بعد اليوم"
                                                        class="block rounded-md border border-dashed border-zinc-300 dark:border-zinc-600 px-1 py-1.5 text-[10px] text-zinc-400">
                                                        لم يُحضَّر بعد
                                                    </a>
                                                    @break
                                                @case('off')
                                                    <div class="h-9 rounded-md" style="{{ $hatch }}" title="ليس يوم دوام لهذه المرحلة"></div>
                                                    @break
                                                @default
                                                    <span class="text-zinc-300 dark:text-zinc-700">—</span>
                                            @endswitch
                                        </td>
                                    @endforeach
                                    <td class="bg-zinc-50 dark:bg-zinc-800/50 px-2 py-1.5 text-center" data-circle-rate="{{ $totals['rate'] ?? 'none' }}">
                                        <div class="text-base font-black {{ $rateText($totals['rate']) }}">{{ $totals['rate'] === null ? '—' : $ar($totals['rate']).'٪' }}</div>
                                        <div class="text-[10px] text-zinc-500 whitespace-nowrap">غياب {{ $ar($totals['absent']) }} · تأخر {{ $ar($totals['late']) }}</div>
                                        @if ($totals['missing'] > 0)
                                            <div class="text-[10px] font-bold text-rose-600 dark:text-rose-400 whitespace-nowrap">{{ $ar($totals['missing']) }} بلا تحضير</div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="{{ count($dates) + 2 }}" class="text-center text-zinc-500 py-10">لا توجد حلقات مسجلة.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="font-bold">
                            <td class="sticky right-0 z-[1] bg-zinc-100 dark:bg-zinc-800 px-3 py-2 text-zinc-800 dark:text-zinc-100 text-sm">المجمع</td>
                            @foreach ($dates as $day)
                                @php
                                    $total = $grid['days'][$day['date']];
                                @endphp
                                <td class="bg-zinc-100 dark:bg-zinc-800 px-1 py-1.5 text-center">
                                    <div class="text-[13px] {{ $rateText($total['rate']) }}">{{ $total['rate'] === null ? '—' : $ar($total['rate']).'٪' }}</div>
                                    @if ($total['missing'] > 0)
                                        <div class="text-[10px] text-rose-600 dark:text-rose-400">{{ $ar($total['missing']) }} بلا تحضير</div>
                                    @endif
                                </td>
                            @endforeach
                            <td class="bg-zinc-200 dark:bg-zinc-700 px-2 py-1.5 text-center">
                                <div class="text-base font-black {{ $rateText($summary['rate']) }}">{{ $summary['rate'] === null ? '—' : $ar($summary['rate']).'٪' }}</div>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Legend --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-emerald-200"></span>٩٠٪ فأكثر</span>
                <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-lime-100"></span>٧٥–٨٩٪</span>
                <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-amber-200"></span>٦٠–٧٤٪</span>
                <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-rose-200"></span>أقل من ٦٠٪</span>
                <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm border border-zinc-300" style="{{ $hatch }}"></span>ليس يوم دوام</span>
                <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm border-2 border-dashed border-rose-400"></span>يوم دوام لم يُحضَّر</span>
                <span>الخلية: الحاضرون (والمتأخرون) من الطلاب المشاركين، والمستأذن خارجها. اضغطها لترى الأسماء.</span>
            </div>
        @endif
    </div>
</div>
