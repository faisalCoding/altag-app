@php
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits($n);

    // A day's rate as a colour, the same bands on the screen and the sheet;
    // the classes live in app.css (.att-*), short, since a month of circles is
    // hundreds of cells sent again with every change of range.
    $band = fn (?int $rate) => match (true) {
        $rate === null => 'att-none',
        $rate >= 90 => 'att-90',
        $rate >= 75 => 'att-75',
        $rate >= 60 => 'att-60',
        default => 'att-low',
    };
    $rateText = fn (?int $rate) => match (true) {
        $rate === null => 'text-zinc-400',
        $rate >= 90 => 'text-emerald-700 dark:text-emerald-400',
        $rate >= 75 => 'text-lime-700 dark:text-lime-400',
        $rate >= 60 => 'text-amber-700 dark:text-amber-400',
        default => 'text-rose-700 dark:text-rose-400',
    };
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
            {{-- The picker carries its own label. Both fields the same width,
                 wide enough that no date wraps onto a second line. --}}
            <div class="flex items-stretch gap-2 bg-zinc-50 dark:bg-zinc-800/50 p-2 rounded-xl border border-zinc-200 dark:border-zinc-800" data-date-filters>
                <div class="w-48">
                    <livewire:manager.hijri-datepicker wire:model.live="fromDate" label="من تاريخ" />
                </div>
                <div class="w-48">
                    <livewire:manager.hijri-datepicker wire:model.live="toDate" label="إلى تاريخ" />
                </div>
                <button wire:click="clearFilters" class="flex items-center p-2 text-zinc-400 hover:text-red-500" title="آخر سبعة أيام" aria-label="آخر سبعة أيام">
                    <flux:icon icon="x-mark" class="size-5" />
                </button>
            </div>
        </div>
    </div>

    {{-- A new range is read on the server: say so while it is, rather than
         leave the old figures standing as though nothing had been asked. --}}
    <div wire:loading.flex wire:target="fromDate, toDate, clearFilters, downloadPDF" data-report-loading
        class="mx-6 mb-3 items-center gap-2 rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-medium text-sky-800 dark:border-sky-800 dark:bg-sky-900/30 dark:text-sky-200">
        <flux:icon.loading class="size-4" />
        <span wire:loading wire:target="fromDate, toDate, clearFilters">جارٍ جلب بيانات الفترة…</span>
        <span wire:loading wire:target="downloadPDF">جارٍ تجهيز التقرير للطباعة…</span>
    </div>

    @php
        $picked = array_map('intval', $stageIds);
        $isShown = fn (int $stageKey) => $picked === [] || in_array($stageKey, $picked, true);
        $chipOn = 'border-maroon bg-maroon/10 text-maroon dark:border-red-300/70 dark:bg-red-300/15 dark:text-red-100 font-bold';
        $chipOff = 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-300 hover:bg-white dark:hover:bg-zinc-800';
    @endphp

    {{-- Every stage of the range is on the page already: picking stages only
         shows and hides rows, and the totals are worked out here, so a pick
         sends no request. The picks reach the server with the next request
         (a print or a new range), deferred. The key follows the range, so a
         new range brings new figures rather than keeping the old ones. --}}
    <div wire:key="report-{{ $fromDate }}-{{ $toDate }}"
        wire:loading.class="opacity-40 pointer-events-none" wire:target="fromDate, toDate, clearFilters"
        class="transition-opacity"
        x-data="{
            stages: $wire.entangle('stageIds'),
            circles: @js($circleTotals),
            days: @js($grid['days_by_stage'] ?? []),
            shown(stage) { return this.stages.length === 0 || this.stages.map(String).includes(String(stage)) },
            ar(n) { return String(n).replace(/[0-9]/g, (d) => '٠١٢٣٤٥٦٧٨٩'[d]) },
            rate(present, counted) { return counted > 0 ? Math.round(present / counted * 100) : null },
            pct(rate) { return rate === null ? '—' : this.ar(rate) + '٪' },
            tone(rate) {
                if (rate === null) return 'text-zinc-400'
                if (rate >= 90) return 'text-emerald-700 dark:text-emerald-400'
                if (rate >= 75) return 'text-lime-700 dark:text-lime-400'
                if (rate >= 60) return 'text-amber-700 dark:text-amber-400'
                return 'text-rose-700 dark:text-rose-400'
            },
            get summary() {
                const rows = this.circles.filter((c) => this.shown(c.stage))
                const sum = (key) => rows.reduce((total, c) => total + c[key], 0)
                const worst = rows.filter((c) => c.rate !== null && c.rate < 100 && c.counted >= 5).sort((a, b) => a.rate - b.rate)[0] ?? null
                return { rate: this.rate(sum('present'), sum('counted')), missing: sum('missing'), missingCircles: rows.filter((c) => c.missing > 0).length, unmarked: sum('unmarked'), worst }
            },
            day(date) {
                let present = 0, counted = 0, missing = 0
                for (const [stage, dates] of Object.entries(this.days)) {
                    if (this.shown(stage)) { present += dates[date].present; counted += dates[date].counted; missing += dates[date].missing }
                }
                return { rate: this.rate(present, counted), missing }
            },
        }">

    {{-- ─────────── تصفية المراحل ─────────── --}}
    {{-- Nothing ticked means the whole academy, so the report opens complete
         and narrows only when the manager asks it to. --}}
    <div class="mx-6 mb-4 rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-800/50">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium text-zinc-500 me-1">المراحل:</span>

            @foreach ($stages as $stage)
                <label wire:key="stage-filter-{{ $stage->id }}" data-stage-chip="{{ $stage->id }}"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs transition-colors {{ in_array($stage->id, $picked, true) ? $chipOn : $chipOff }}"
                    x-bind:class="stages.map(String).includes('{{ $stage->id }}') ? '{{ $chipOn }}' : '{{ $chipOff }}'">
                    <input type="checkbox" x-model="stages" value="{{ $stage->id }}" class="sr-only">
                    {{ $stage->name }}
                </label>
            @endforeach

            <button type="button" class="rounded-lg px-2 py-1 text-xs font-medium text-zinc-600 hover:bg-white dark:text-zinc-300 dark:hover:bg-zinc-800"
                x-show="stages.length > 0" x-on:click="stages = []" @if ($picked === []) style="display: none" @endif>كل المراحل</button>
            <span class="text-xs text-zinc-400" x-show="stages.length === 0" @if ($picked !== []) style="display: none" @endif>— الكل</span>
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
                $summary = $selection['summary'];
                $dates = $grid['dates'];
            @endphp

            {{-- ─────────── الملخّص ─────────── --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4" data-report-summary>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
                    <div class="text-xs text-zinc-500">نسبة الحضور</div>
                    <div class="mt-1 text-2xl font-black {{ $rateText($summary['rate']) }}" x-bind:class="tone(summary.rate)" x-text="pct(summary.rate)">{{ $summary['rate'] === null ? '—' : $ar($summary['rate']).'٪' }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4" data-summary-missing="{{ $summary['missing'] }}">
                    <div class="text-xs text-zinc-500">أيام دوام بلا تحضير</div>
                    <div class="mt-1 text-2xl font-black {{ $summary['missing'] > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-800 dark:text-zinc-100' }}"
                        x-bind:class="summary.missing > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-800 dark:text-zinc-100'" x-text="ar(summary.missing)">{{ $ar($summary['missing']) }}</div>
                    <div class="text-xs text-zinc-400" x-show="summary.missing > 0" @if ($summary['missing'] === 0) style="display: none" @endif>
                        في <span x-text="ar(summary.missingCircles)">{{ $ar($summary['missing_circles']) }}</span> <span x-text="summary.missingCircles === 1 ? 'حلقة' : 'حلقات'">{{ $summary['missing_circles'] === 1 ? 'حلقة' : 'حلقات' }}</span>
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4" data-summary-unmarked="{{ $summary['unmarked'] }}">
                    <div class="text-xs text-zinc-500">طلاب لم يُسجَّلوا</div>
                    <div class="mt-1 text-2xl font-black {{ $summary['unmarked'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-800 dark:text-zinc-100' }}"
                        x-bind:class="summary.unmarked > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-800 dark:text-zinc-100'" x-text="ar(summary.unmarked)">{{ $ar($summary['unmarked']) }}</div>
                    <div class="text-xs text-zinc-400">في أيام حُضّرت فيها حلقاتهم</div>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
                    <div class="text-xs text-zinc-500">أكثر حلقة غياباً</div>
                    <div class="mt-1 font-bold text-zinc-800 dark:text-zinc-100 truncate" x-text="summary.worst ? summary.worst.name : '—'">{{ $summary['worst']['name'] ?? '—' }}</div>
                    <div class="text-xs {{ $rateText($summary['worst']['rate'] ?? null) }}" x-bind:class="tone(summary.worst ? summary.worst.rate : null)" x-show="summary.worst" @if (! $summary['worst']) style="display: none" @endif>
                        نسبة الحضور <span x-text="summary.worst ? pct(summary.worst.rate) : ''">{{ $summary['worst'] ? $ar($summary['worst']['rate']).'٪' : '' }}</span>
                    </div>
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
                            <tr wire:key="stage-{{ $group['stage_id'] }}" data-stage-row="{{ $group['stage_id'] }}"
                                x-show="shown({{ $group['stage_id'] }})" @unless ($isShown($group['stage_id'])) style="display: none" @endunless>
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
                                <tr wire:key="circle-{{ $circle->id }}" data-circle-row="{{ $circle->id }}"
                                    x-show="shown({{ $group['stage_id'] }})" @unless ($isShown($group['stage_id'])) style="display: none" @endunless>
                                    <td class="sticky right-0 z-[1] bg-white dark:bg-zinc-900 px-3 py-1.5 font-medium text-zinc-700 dark:text-zinc-300">
                                        {{ $circle->name }}
                                    </td>
                                    @php
                                        $reminder = \App\Support\RollCallReminder::class;
                                        $teacher = $circle->teachers->first();
                                    @endphp
                                    {{-- Each cell on one line, built here: a month of circles is
                                         hundreds of them, and the indentation and block markers
                                         of a template per cell were most of what went over the
                                         wire with each change of range. --}}
                                    @foreach ($dates as $day)
                                        @php
                                            $cell = $row['cells'][$day['date']];
                                            $link = e(route('manager.attendance-list', ['circleId' => $circle->id, 'date' => $day['date']]));
                                            $inner = match ($cell['state']) {
                                                'data' => '<a href="'.$link.'" wire:navigate class="att-cell '.$band($cell['rate']).'" title="'.e('حاضر '.$ar($cell['present'] - $cell['late']).'، متأخر '.$ar($cell['late']).'، غائب '.$ar($cell['absent']).'، مستأذن '.$ar($cell['excused']).($cell['unmarked'] > 0 ? '، لم يُسجَّل '.$ar($cell['unmarked']) : '')).'">'
                                                    // Spaced, so the two read as two: present, then of how many.
                                                    .'<span class="block text-[13px] font-bold">'.$ar($cell['present']).' / '.$ar($cell['expected'] - $cell['excused']).'</span>'
                                                    .($cell['unmarked'] > 0 ? '<span class="block text-[10px] font-medium opacity-80">'.$ar($cell['unmarked']).' لم يُسجَّل</span>' : '')
                                                    .'</a>',
                                                // A missed roll call asks the circle's teacher to take it,
                                                // over WhatsApp, with a link onto that day's roll call.
                                                'missing' => ($whatsapp = $reminder::whatsappUrl($teacher, $day['date']))
                                                    ? '<a href="'.e($whatsapp).'" target="_blank" rel="noopener" class="att-missing" data-reminder title="'.e('ذكّر '.($teacher?->name ?? 'المعلم').' بالتحضير عبر واتساب').'">لم يُحضَّر</a>'
                                                    : '<a href="'.$link.'" wire:navigate class="att-missing" title="يوم دوام لم يُحضَّر فيه">لم يُحضَّر</a>',
                                                'pending' => '<a href="'.$link.'" wire:navigate class="att-pending" title="لم يُحضَّر بعد اليوم">لم يُحضَّر بعد</a>',
                                                'off' => '<div class="att-off" title="ليس يوم دوام لهذه المرحلة"></div>',
                                                default => '<span class="att-empty">—</span>',
                                            };
                                        @endphp
                                        <td class="att-day" data-cell="{{ $circle->id }}-{{ $day['date'] }}" data-state="{{ $cell['state'] }}">{!! $inner !!}</td>
                                    @endforeach
                                    <td class="bg-zinc-50 dark:bg-zinc-800/50 px-2 py-1.5 text-center" data-circle-rate="{{ $totals['rate'] ?? 'none' }}">
                                        <div class="text-base font-black {{ $rateText($totals['rate']) }}">{{ $totals['rate'] === null ? '—' : $ar($totals['rate']).'٪' }}</div>
                                        <div class="text-[10px] text-zinc-500 whitespace-nowrap">غياب {{ $ar($totals['absent']) }}، تأخر {{ $ar($totals['late']) }}</div>
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
                                    $total = $selection['days'][$day['date']];
                                @endphp
                                <td class="bg-zinc-100 dark:bg-zinc-800 px-1 py-1.5 text-center" x-data="{ get total() { return day('{{ $day['date'] }}') } }">
                                    <div class="text-[13px] {{ $rateText($total['rate']) }}" x-bind:class="tone(total.rate)" x-text="pct(total.rate)">{{ $total['rate'] === null ? '—' : $ar($total['rate']).'٪' }}</div>
                                    <div class="text-[10px] text-rose-600 dark:text-rose-400" x-show="total.missing > 0" @if ($total['missing'] === 0) style="display: none" @endif>
                                        <span x-text="ar(total.missing)">{{ $ar($total['missing']) }}</span> بلا تحضير
                                    </div>
                                </td>
                            @endforeach
                            <td class="bg-zinc-200 dark:bg-zinc-700 px-2 py-1.5 text-center">
                                <div class="text-base font-black {{ $rateText($summary['rate']) }}" x-bind:class="tone(summary.rate)" x-text="pct(summary.rate)">{{ $summary['rate'] === null ? '—' : $ar($summary['rate']).'٪' }}</div>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Legend --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm att-90"></span>٩٠٪ فأكثر</span>
                <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm att-75"></span>٧٥–٨٩٪</span>
                <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm att-60"></span>٦٠–٧٤٪</span>
                <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm att-low"></span>أقل من ٦٠٪</span>
                <span class="flex items-center gap-1.5"><span class="att-off !size-3 !rounded-sm border border-zinc-300"></span>ليس يوم دوام</span>
                <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm border-2 border-dashed border-rose-400"></span>يوم دوام لم يُحضَّر (اضغطه لتذكير المعلم عبر واتساب)</span>
                <span>الخلية: الحاضرون (والمتأخرون) من الطلاب المشاركين، والمستأذن خارجها. اضغطها لترى الأسماء.</span>
            </div>
        @endif
    </div>
    </div>
</div>
