@php
    use App\Models\AttendanceRevision;
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits($n);
    $labels = AttendanceRevision::statusLabels();
    $rateText = fn (?int $rate) => match (true) {
        $rate === null => 'text-zinc-400',
        $rate >= 90 => 'text-emerald-700 dark:text-emerald-400',
        $rate >= 75 => 'text-lime-700 dark:text-lime-400',
        $rate >= 60 => 'text-amber-700 dark:text-amber-400',
        default => 'text-rose-700 dark:text-rose-400',
    };
    $columns = count($grid['dates']);
    $totals = $grid['totals'];

    // A student's squares, written out tight: hundreds of students by a month
    // of days are thousands of them, and the page's indentation and block
    // markers weighed more than the squares. The square holds no ids — the
    // click reads the student from its row and the day from its column.
    $squares = function (array $student) use ($grid, $labels, $ar): string {
        $html = '';

        foreach ($grid['dates'] as $date) {
            $cell = $student['cells'][$date['date']];
            $html .= $date['week_start'] ? '<td class="rec-day rec-week">' : '<td class="rec-day">';

            if (! $cell['open']) {
                $html .= '<i class="rec-sq rec-off"></i></td>';

                continue;
            }

            $html .= '<button type="button" class="rec-sq'.($cell['status'] ? ' rec-'.$cell['status'] : '').'" aria-label="'.e($cell['status'] ? $labels[$cell['status']] : 'لم يُحضَّر').'">'
                .($cell['graded'] ? $ar($cell['recited']) : '').'</button></td>';
        }

        return $html;
    };

    // The report beside a student, and under the days for the whole table.
    $report = function (array $sums, bool $full = true) use ($ar, $rateText): string {
        $line = 'حضور '.$ar($sums['present'] + $sums['late']).' · غياب '.$ar($sums['absent'])
            .($full && $sums['excused'] ? ' · استئذان '.$ar($sums['excused']) : '');
        $second = 'أوراد '.$ar($sums['recited']).($full && $sums['unmarked'] ? ' · لم يُحضَّر '.$ar($sums['unmarked']) : '');

        return '<div class="flex items-center gap-3 whitespace-nowrap"><span class="w-10 text-base font-bold tabular-nums '.$rateText($sums['rate']).'">'
            .($sums['rate'] === null ? '—' : $ar($sums['rate']).'٪').'</span><span class="text-[11px] leading-4 text-zinc-500">'.e($line).'<br>'.e($second).'</span></div>';
    };
@endphp

{{--
    The x-data holds no server values: Alpine resets an x-data object to its
    expression whenever a redraw changes it. A day opens through one listener
    on the table, its details read without drawing the table again.
--}}
<div dir="rtl" x-data="{
        detail: null,
        failed: false,
        async open(square) {
            const cell = square.closest('td');
            const student = Number(cell.parentElement.dataset.s);
            const date = cell.closest('table').tHead.rows[0].cells[cell.cellIndex].dataset.date;
            this.detail = null;
            this.failed = false;
            this.$flux.modal('record-day').show();
            try {
                this.detail = await this.$wire.day(student, date);
            } catch (error) {
                this.failed = true;
            }
            this.failed = this.failed || this.detail === null;
        },
    }">

    {{-- Header --}}
    <div class="m-6 flex flex-col xl:flex-row xl:items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" class="font-bold">سجل الطلاب</flux:heading>
            <flux:subheading>حضور كل طالب يوماً بيوم، وفي كل يوم عدد ما سمّعه من الأوراد.</flux:subheading>
        </div>

        <div class="flex items-stretch gap-2 flex-wrap bg-zinc-50 dark:bg-zinc-800/50 p-2 rounded-xl border border-zinc-200 dark:border-zinc-800" data-record-filters>
            <div class="w-48">
                <livewire:shared.hijri-datepicker wire:model.live="from" label="من تاريخ" :max-date="$today" />
            </div>
            <div class="w-48">
                <livewire:shared.hijri-datepicker wire:model.live="to" label="إلى تاريخ" :max-date="$today" />
            </div>
            <div class="w-48">
                <flux:select wire:model.live="circle" label="الحلقة">
                    <flux:select.option value="">كل الحلقات</flux:select.option>
                    @foreach ($this->circles as $option)
                        <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>
    </div>

    {{-- The period at a glance --}}
    <div class="mx-6 mb-4 grid grid-cols-2 md:grid-cols-4 gap-3" data-record-summary>
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-4 py-3">
            <div class="text-xs text-zinc-500">الطلاب</div>
            <div class="text-2xl font-bold tabular-nums">{{ $ar($totals['students']) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-4 py-3">
            <div class="text-xs text-zinc-500">نسبة الحضور</div>
            <div class="text-2xl font-bold tabular-nums {{ $rateText($totals['rate']) }}">{{ $totals['rate'] === null ? '—' : $ar($totals['rate']).'٪' }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-4 py-3">
            <div class="text-xs text-zinc-500">{{ $filter === '' ? 'الأوراد المسمَّعة' : 'المسمَّع من '.$this->filters[$filter] }}</div>
            <div class="text-2xl font-bold tabular-nums">{{ $ar($totals['recited']) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-4 py-3">
            <div class="text-xs text-zinc-500">غياب · لم يُحضَّر</div>
            <div class="text-2xl font-bold tabular-nums"><span class="text-rose-600 dark:text-rose-400">{{ $ar($totals['absent']) }}</span> <span class="text-zinc-400 text-lg">·</span> <span class="text-zinc-500">{{ $ar($totals['unmarked']) }}</span></div>
        </div>
    </div>

    {{-- What each square counts: every recitation, or one kind of it. --}}
    <div class="mx-6 mb-3 flex flex-wrap items-center gap-2" role="radiogroup" aria-label="ما يُعدّ في كل يوم" data-record-filter>
        <span class="text-xs text-zinc-500 me-1">يُعدّ في كل يوم:</span>
        @foreach (['' => 'الكل'] + $this->filters as $key => $label)
            <button type="button" role="radio" wire:key="filter-{{ $key ?: 'all' }}" wire:click="$set('filter', @js($key))"
                aria-checked="{{ $filter === $key ? 'true' : 'false' }}"
                class="rounded-full border px-3 py-1 text-xs font-semibold transition-colors {{ $filter === $key ? 'border-accent bg-accent text-accent-foreground' : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Legend --}}
    <div class="mx-6 mb-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-zinc-600 dark:text-zinc-400" data-record-legend>
        @foreach (['present', 'late', 'excused', 'absent'] as $status)
            <span class="flex items-center gap-1.5"><i class="rec-sq rec-{{ $status }} !size-4 !mx-0"></i>{{ $labels[$status] }}</span>
        @endforeach
        <span class="flex items-center gap-1.5"><i class="rec-sq !size-4 !mx-0"></i>لم يُحضَّر</span>
        <span class="flex items-center gap-1.5"><i class="rec-sq rec-off !size-4 !mx-0"></i>ليس من أيامه</span>
        <span class="text-zinc-500">الرقم في اليوم عدد ما سمّعه من {{ $filter === '' ? 'الأوراد' : $this->filters[$filter] }}، واضغط اليوم لترى تسميعه.</span>
    </div>

    <div wire:loading.flex wire:target="from, to, circle, filter"
        class="mx-6 mb-3 items-center gap-2 rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-medium text-sky-800 dark:border-sky-800 dark:bg-sky-900/30 dark:text-sky-200">
        <flux:icon.loading class="size-4" />
        جارٍ جلب السجل…
    </div>

    @if ($grid['circles'] === [])
        <div class="mx-6 mb-6 rounded-2xl border border-dashed border-zinc-300 dark:border-zinc-700 p-10 text-center text-zinc-500" data-record-empty>
            لا يوجد طلاب في هذه الحلقات خلال الفترة المختارة.
        </div>
    @else
        <div class="mx-6 mb-6 overflow-auto max-h-[calc(100dvh-14rem)] rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 transition-opacity"
            wire:loading.class="opacity-50" wire:target="from, to, circle, filter"
            x-on:click="const square = $event.target.closest('button.rec-sq'); if (square) open(square)"
            data-record-table>
            <table class="min-w-full border-separate border-spacing-0 text-sm">
                <thead>
                    <tr>
                        <th scope="col" class="sticky top-0 right-0 z-30 min-w-28 sm:min-w-44 border-b border-zinc-200 bg-zinc-50 px-3 py-2 text-start font-semibold dark:border-zinc-800 dark:bg-zinc-800">الطالب</th>
                        @php $previousMonth = null; @endphp
                        @foreach ($grid['dates'] as $date)
                            <th scope="col" data-date="{{ $date['date'] }}" class="rec-day sticky top-0 z-20 min-w-10 border-b-zinc-200 bg-zinc-50 font-normal dark:bg-zinc-800 {{ $date['week_start'] ? 'rec-week' : '' }} {{ $date['today'] ? '!bg-amber-50 dark:!bg-amber-900/30' : '' }}">
                                <div class="h-4 text-[10px] font-semibold text-zinc-500 whitespace-nowrap">{{ $date['month'] !== $previousMonth ? $date['month'] : '' }}</div>
                                <div class="text-[10px] text-zinc-500">{{ $date['weekday'] }}</div>
                                <div class="font-bold tabular-nums">{{ $date['day'] }}</div>
                            </th>
                            @php $previousMonth = $date['month']; @endphp
                        @endforeach
                        <th scope="col" class="sticky top-0 sm:left-0 z-30 min-w-40 border-b border-s border-zinc-200 bg-zinc-50 px-3 py-2 text-start font-semibold dark:border-zinc-800 dark:bg-zinc-800">التقرير</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($grid['circles'] as $circle)
                        <tr wire:key="c{{ $circle['id'] }}">
                            <th scope="colgroup" colspan="{{ $columns + 2 }}" class="border-b border-zinc-200 bg-zinc-100 py-1.5 text-start dark:border-zinc-800 dark:bg-zinc-800/70">
                                <span class="sticky right-0 inline-flex items-baseline gap-2 px-3">
                                    <span class="font-bold">{{ $circle['name'] }}</span>
                                    @if ($circle['teacher'])
                                        <span class="text-xs font-normal text-zinc-500">{{ $circle['teacher'] }}</span>
                                    @endif
                                </span>
                            </th>
                        </tr>
                        @foreach ($circle['students'] as $student)
                            <tr wire:key="s{{ $student['id'] }}" data-s="{{ $student['id'] }}" class="group">
                                <th scope="row" class="rec-name">{{ $student['name'] }}</th>{!! $squares($student) !!}<td class="rec-report">{!! $report($student['totals']) !!}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row" class="sticky bottom-0 right-0 z-30 border-t border-zinc-200 bg-zinc-50 px-3 py-2 text-start dark:border-zinc-800 dark:bg-zinc-800">
                            <div class="font-semibold">التقرير</div>
                            <div class="text-[10px] font-normal text-zinc-500">نسبة الحضور، ثم الأوراد</div>
                        </th>
                        @foreach ($grid['dates'] as $date)
                            @php $day = $grid['days'][$date['date']]; @endphp
                            <td class="rec-day sticky bottom-0 z-20 border-t border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-800 {{ $date['week_start'] ? 'rec-week' : '' }}"
                                title="حضور {{ $ar($day['present'] + $day['late']) }} من {{ $ar($day['marked']) }} · غياب {{ $ar($day['absent']) }} · أوراد {{ $ar($day['recited']) }}">
                                <div class="text-[11px] font-bold tabular-nums {{ $rateText($day['rate']) }}">{{ $day['rate'] === null ? '—' : $ar($day['rate']) }}</div>
                                <div class="text-[11px] tabular-nums text-zinc-600 dark:text-zinc-300">{{ $ar($day['recited']) }}</div>
                            </td>
                        @endforeach
                        <td class="sticky bottom-0 sm:left-0 z-30 border-t border-s border-zinc-200 bg-zinc-50 px-3 py-2 dark:border-zinc-800 dark:bg-zinc-800">{!! $report($totals, full: false) !!}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    {{-- What one day held, read when it is opened --}}
    <flux:modal name="record-day" class="md:w-[28rem]">
        <div class="space-y-4">
            <template x-if="! detail && ! failed">
                <div class="flex items-center gap-2 text-sm text-zinc-500"><flux:icon.loading class="size-4" /> جارٍ القراءة…</div>
            </template>
            <template x-if="failed">
                <div class="text-sm text-rose-600">تعذّر جلب هذا اليوم، أعد المحاولة.</div>
            </template>
            <template x-if="detail">
                <div class="space-y-4">
                    <div>
                        <flux:heading size="lg" x-text="detail.title"></flux:heading>
                        <span class="mt-2 inline-flex items-center gap-1.5 text-sm">
                            <span class="rec-sq !size-4 !mx-0" x-bind:class="detail.status ? 'rec-' + detail.status : ''"></span>
                            <span x-text="@js($labels)[detail.status] ?? 'لم يُحضَّر'"></span>
                        </span>
                    </div>
                    <template x-if="detail.recitations.length === 0">
                        <p class="text-sm text-zinc-500">لم يُقيَّم له تسميع في هذا اليوم.</p>
                    </template>
                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <template x-for="(recitation, index) in detail.recitations" x-bind:key="index">
                            <li class="flex items-center gap-3 py-2">
                                <span class="flex size-7 shrink-0 items-center justify-center rounded-lg text-xs font-bold text-white"
                                    x-bind:class="{ 3: 'bg-emerald-600', 2: 'bg-sky-600', 1: 'bg-amber-600', 0: 'bg-rose-600' }[recitation.grade]"
                                    x-text="{ 3: 'م', 2: 'ج', 1: 'ق', 0: '×' }[recitation.grade]"></span>
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold" x-text="[recitation.title, recitation.range].filter(Boolean).join(' · ')"></div>
                                    <div class="text-xs text-zinc-500"
                                        x-text="[{ 3: 'ممتاز', 2: 'جيد', 1: 'مقبول', 0: 'لم يسمع' }[recitation.grade], recitation.by].filter(Boolean).join(' · ')"></div>
                                </div>
                            </li>
                        </template>
                    </ul>
                </div>
            </template>
        </div>
    </flux:modal>
</div>
