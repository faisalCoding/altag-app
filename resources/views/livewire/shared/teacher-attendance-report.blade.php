{{--
    The teachers' attendance over a period: a line per teacher counted against
    the working days of their stage, the days that need a second look under
    each, and a grid of whether the roll call was taken at all, day by day.
--}}
@php
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits((int) $n);

    // One colour per status, the ones the roll call marks with; the classes
    // (.st-*, and the .tg-* of the grid) live in app.css, short, since a
    // month of teachers is sent again with every change of period.
    $statusStyles = [
        'present' => ['label' => 'حاضر', 'badge' => 'st-present'],
        'late' => ['label' => 'متأخر', 'badge' => 'st-late'],
        'excused' => ['label' => 'مستأذن', 'badge' => 'st-excused'],
        'absent' => ['label' => 'غائب', 'badge' => 'st-absent'],
    ];

    $rateTone = fn (?int $rate) => match (true) {
        $rate === null => 'text-zinc-400',
        $rate >= 90 => 'text-emerald-600 dark:text-emerald-400',
        $rate >= 75 => 'text-amber-600 dark:text-amber-400',
        default => 'text-rose-600 dark:text-rose-400',
    };

    $check = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" class="size-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 10.5 3.5 3.5L15 7"/></svg>';
@endphp

<div class="space-y-5">

    {{-- ─────────── الترويسة ─────────── --}}
    <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                <flux:icon icon="chart-bar-square" />
            </div>
            <div>
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">تقرير حضور المعلمين</flux:heading>
                <flux:subheading>من {{ HijriDate::full($from) }} إلى {{ HijriDate::full($to) }}</flux:subheading>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:flex sm:flex-wrap items-center gap-2">
            <flux:button size="sm" icon="clipboard-document-check" :href="route($rollRoute)" wire:navigate>تحضير المعلمين</flux:button>
            <flux:button size="sm" icon="printer" variant="outline" wire:click="downloadPdf">طباعة التقرير</flux:button>
        </div>
    </div>

    {{-- ─────────── الفترة والمراحل ─────────── --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-4 space-y-4">
        <div class="grid grid-cols-2 lg:flex gap-3 items-end">
            <div class="min-w-0 lg:w-56">
                <livewire:manager.hijri-datepicker wire:model.live="fromDate" label="من" :max-date="$today" />
            </div>
            <div class="min-w-0 lg:w-56">
                <livewire:manager.hijri-datepicker wire:model.live="toDate" label="إلى" :max-date="$today" />
            </div>
        </div>

        @if ($stages->count() > 1)
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-medium text-zinc-500 me-1">المراحل:</span>
                @foreach ($stages as $stage)
                    <label wire:key="stage-filter-{{ $stage->id }}"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs transition-colors
                            {{ in_array((string) $stage->id, array_map('strval', $stageIds), true)
                                ? 'border-maroon bg-maroon/10 text-maroon dark:border-red-300/70 dark:bg-red-300/15 dark:text-red-100 font-bold'
                                : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800' }}">
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
        @endif
    </div>

    {{-- A change of period or stages is read on the server: say so while it is. --}}
    <div wire:loading.flex wire:target="fromDate, toDate, stageIds, clearStages, downloadPdf" data-report-loading
        class="items-center gap-2 rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-medium text-sky-800 dark:border-sky-800 dark:bg-sky-900/30 dark:text-sky-200">
        <flux:icon.loading class="size-4" />
        <span wire:loading wire:target="fromDate, toDate, stageIds, clearStages">جارٍ جلب البيانات…</span>
        <span wire:loading wire:target="downloadPdf">جارٍ تجهيز التقرير للطباعة…</span>
    </div>

    <div class="space-y-5 transition-opacity" wire:loading.class="opacity-40 pointer-events-none" wire:target="fromDate, toDate, stageIds, clearStages">

        {{-- ─────────── الأرقام ─────────── --}}
        {{-- One card: the rate, and the counts beside it — six tiles took a phone's
             whole screen before the first teacher. --}}
        <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 flex flex-col sm:flex-row sm:items-center gap-4" data-report-summary>
            <div class="sm:w-40 shrink-0">
                <div class="text-xs text-zinc-500 dark:text-zinc-400">نسبة الحضور</div>
                <div class="text-3xl font-black mt-0.5 {{ $rateTone($totals['rate']) }}">{{ $totals['rate'] === null ? '—' : $ar($totals['rate']).'٪' }}</div>
            </div>
            <div class="grid flex-1 grid-cols-5 gap-2 text-center">
                @foreach (['present', 'late', 'excused', 'absent'] as $status)
                    <div class="rounded-xl px-1 py-2 {{ $statusStyles[$status]['badge'] }}">
                        <div class="text-lg font-black leading-none">{{ $ar($totals[$status]) }}</div>
                        <div class="mt-1 text-[11px] font-medium">{{ $statusStyles[$status]['label'] }}</div>
                    </div>
                @endforeach
                <div class="rounded-xl px-1 py-2 {{ $totals['unrecorded'] > 0 ? 'bg-rose-600 text-white dark:bg-rose-500' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' }}" data-total-unrecorded="{{ $totals['unrecorded'] }}">
                    <div class="text-lg font-black leading-none">{{ $ar($totals['unrecorded']) }}</div>
                    <div class="mt-1 text-[11px] font-medium">لم يُحضَّر</div>
                </div>
            </div>
        </div>

        {{-- ─────────── المعلمون ─────────── --}}
        {{-- A line per teacher, a column per day, like the stages' follow-up
             below: each cell the teacher's day, the teacher's sum at the end of
             the line and the day's at the foot of the column. A cell with more
             to say carries it in its title, and a tap shows it underneath. --}}
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden" x-data="{ picked: '' }">
            <div class="px-4 sm:px-5 py-4 border-b border-zinc-100 dark:border-zinc-800">
                <flux:heading size="sm">المعلمون</flux:heading>
                <flux:subheading class="text-xs">حالة كل معلم في كل يوم دوام. النسبة = الحاضر والمتأخر من الأيام المسجّلة، دون أيام الاستئذان.</flux:subheading>
            </div>

            @if ($rows->isEmpty())
                <x-teacher-roll-empty message="لا يوجد معلمون في المراحل المختارة." />
            @else
                @php
                    $gridDays = $days ?? [];
                    $letters = \App\Livewire\Shared\TeacherAttendanceReport::STATUS_LETTERS;
                @endphp
                <div class="overflow-x-auto">
                    <table class="tg-table text-xs" data-teacher-grid
                        x-on:click="const cell = $event.target.closest('td[title]'); if (cell) picked = cell.title">
                        <thead>
                            <tr class="text-zinc-400">
                                <th class="tg-name text-start font-medium px-3 sm:px-4 py-2">المعلم</th>
                                @foreach ($gridDays as $day)
                                    <th class="font-normal px-0.5 py-2 text-center whitespace-nowrap {{ $day === $today ? 'text-zinc-700 dark:text-zinc-200' : '' }}">
                                        <div>{{ HijriDate::weekday($day) }}</div>
                                        <div class="font-bold text-zinc-600 dark:text-zinc-300">{{ HijriDate::format($day, 'd') }}</div>
                                    </th>
                                @endforeach
                                <th class="tg-sum font-medium px-3 py-2 text-center">الملخّص</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php
                                    // The line's day cells built here, one string: forty teachers
                                    // over a month is a thousand of them.
                                    $line = '';
                                    foreach ($row['cells'] ?? [] as $day => $cell) {
                                        $class = match ($cell['state']) {
                                            'present', 'late', 'excused', 'absent' => 'tg st-'.$cell['state'],
                                            'missing' => 'tg tg-miss',
                                            'pending' => 'tg tg-wait',
                                            default => 'tg tg-off',
                                        };
                                        $line .= '<td class="'.$class.'"'.($cell['title'] ? ' title="'.e($cell['title']).'"' : '').'>'.($letters[$cell['state']] ?? '').'</td>';
                                    }
                                @endphp
                                <tr wire:key="teacher-row-{{ $row['teacher']->id }}" data-teacher-row="{{ $row['teacher']->id }}">
                                    <td class="tg-name px-3 sm:px-4 py-1.5">
                                        <div class="font-medium text-zinc-800 dark:text-zinc-100 truncate max-w-32 sm:max-w-48">{{ $row['teacher']->name }}</div>
                                        <div class="text-[11px] text-zinc-400 truncate max-w-32 sm:max-w-48">{{ $row['circles'] ?: 'بلا حلقة' }}</div>
                                    </td>
                                    {!! $line !!}
                                    <td class="tg-sum px-3 py-1.5 text-center" data-teacher-rate="{{ $row['rate'] ?? 'none' }}">
                                        <div class="text-sm font-black {{ $rateTone($row['rate']) }}">{{ $row['rate'] === null ? '—' : $ar($row['rate']).'٪' }}</div>
                                        <div class="text-[10px] text-zinc-500 whitespace-nowrap">غياب {{ $ar($row['absent']) }}، تأخر {{ $ar($row['late']) }}</div>
                                        @if ($row['unrecorded'] > 0)
                                            <div class="text-[10px] font-bold text-rose-600 dark:text-rose-400 whitespace-nowrap">{{ $ar($row['unrecorded']) }} لم يُحضَّر</div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="tg-name px-3 sm:px-4 py-2 font-bold text-zinc-700 dark:text-zinc-200">نسبة اليوم</td>
                                @foreach ($gridDays as $day)
                                    @php
                                        $total = $dayTotals[$day];
                                    @endphp
                                    <td class="px-0.5 py-2 text-center whitespace-nowrap" data-day-total="{{ $day }}"
                                        title="{{ HijriDate::withWeekday($day) }}: حضر {{ $ar($total['present']) }} من {{ $ar($total['counted']) }}{{ $total['missing'] > 0 ? '، ولم يُحضَّر '.$ar($total['missing']) : '' }}">
                                        <div class="font-black {{ $rateTone($total['rate']) }}">{{ $total['rate'] === null ? '—' : $ar($total['rate']).'٪' }}</div>
                                        @if ($total['missing'] > 0)
                                            <div class="text-[10px] font-bold text-rose-600 dark:text-rose-400">{{ $ar($total['missing']) }}</div>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="tg-sum px-3 py-2 text-center">
                                    <div class="text-base font-black {{ $rateTone($totals['rate']) }}">{{ $totals['rate'] === null ? '—' : $ar($totals['rate']).'٪' }}</div>
                                    <div class="text-[10px] text-zinc-500">الكل</div>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if ($days === null)
                    <p class="px-5 py-3 text-xs text-zinc-400 border-t border-zinc-100 dark:border-zinc-800">
                        الأيام تظهر أعمدةً لفترة لا تزيد على {{ $ar(\App\Livewire\Shared\TeacherAttendanceReport::FOLLOW_UP_MAX_DAYS) }} يوماً.
                    </p>
                @endif

                <div class="px-4 sm:px-5 py-3 border-t border-zinc-100 dark:border-zinc-800 space-y-2">
                    <p class="min-h-5 text-xs text-zinc-700 dark:text-zinc-200" x-show="picked" x-text="picked"></p>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-zinc-500">
                        @foreach ($letters as $status => $letter)
                            <span class="inline-flex items-center gap-1.5"><span class="tg-key st-{{ $status }}">{{ $letter }}</span>{{ $statusStyles[$status]['label'] }}</span>
                        @endforeach
                        <span class="inline-flex items-center gap-1.5"><span class="tg-key border-2 border-dashed border-rose-400"></span>لم يُحضَّر</span>
                        <span class="inline-flex items-center gap-1.5"><span class="tg-key border border-dashed border-zinc-400"></span>اليوم، لم يُحضَّر بعد</span>
                        <span class="inline-flex items-center gap-1.5"><span class="font-bold text-rose-600 dark:text-rose-400">١</span>تحت النسبة: من لم يُحضَّر ذلك اليوم</span>
                        <span x-show="! picked">اضغط خانة لترى تفاصيلها.</span>
                    </div>
                </div>
            @endif
        </div>

        {{-- ─────────── متابعة التحضير اليومي ─────────── --}}
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
            <div class="px-4 sm:px-5 py-4 border-b border-zinc-100 dark:border-zinc-800">
                <flux:heading size="sm">متابعة التحضير اليومي</flux:heading>
                <flux:subheading class="text-xs">كم معلماً حُضِّر من معلمي كل مرحلة في كل يوم دوام.</flux:subheading>
            </div>

            @if ($followUp === null)
                <p class="px-5 py-8 text-sm text-center text-zinc-400">
                    اختر فترة لا تزيد على {{ $ar(\App\Livewire\Shared\TeacherAttendanceReport::FOLLOW_UP_MAX_DAYS) }} يوماً لعرض المتابعة اليومية.
                </p>
            @elseif ($followUp['stages'] === [] || $followUp['days'] === [])
                <p class="px-5 py-8 text-sm text-center text-zinc-400">لا أيام دوام في هذه الفترة.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="text-xs">
                        <thead>
                            <tr class="text-zinc-400">
                                <th class="sticky start-0 z-[1] bg-white dark:bg-zinc-900 text-start font-medium px-4 py-2 min-w-32 sm:min-w-40">المرحلة</th>
                                @foreach ($followUp['days'] as $day)
                                    <th class="font-normal px-1 py-2 text-center whitespace-nowrap {{ $day === $today ? 'text-zinc-700 dark:text-zinc-200' : '' }}">
                                        <div>{{ HijriDate::weekday($day) }}</div>
                                        <div class="font-bold text-zinc-600 dark:text-zinc-300">{{ HijriDate::format($day, 'd') }}</div>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($followUp['stages'] as $stage)
                                <tr wire:key="follow-{{ $loop->index }}">
                                    <td class="sticky start-0 z-[1] bg-white dark:bg-zinc-900 px-4 py-2">
                                        <div class="font-medium text-zinc-800 dark:text-zinc-100">{{ $stage['name'] }}</div>
                                        @if ($role === 'manager' && $stage['supervisors'] !== '')
                                            <div class="text-zinc-400">{{ $stage['supervisors'] }}</div>
                                        @endif
                                    </td>
                                    {{-- Each cell built on one line: a stage's month is dozens of them. --}}
                                    @foreach ($stage['cells'] as $day => $cell)
                                        @php
                                            $title = e(HijriDate::full($day).': حُضِّر '.$ar($cell['marked'] ?? 0).' من '.$ar($cell['expected'] ?? 0));
                                            $inner = match (true) {
                                                $cell === null => '<span class="roll-cell roll-off" title="ليس يوم دوام">—</span>',
                                                $cell['marked'] >= $cell['expected'] => '<span class="roll-cell roll-full" title="'.$title.'">'.$check.'</span>',
                                                // Today's roll may still be called.
                                                $day === $today => '<span class="roll-cell roll-wait" title="'.$title.'">'.($cell['marked'] > 0 ? $ar($cell['marked']) : 'بعد').'</span>',
                                                $cell['marked'] > 0 => '<span class="roll-cell roll-part" title="'.$title.'">'.$ar($cell['marked']).'</span>',
                                                default => '<span class="roll-cell roll-none" title="'.$title.'">'.$ar(0).'</span>',
                                            };
                                        @endphp
                                        <td class="px-0.5 py-2 text-center" data-follow-cell="{{ $day }}">{!! $inner !!}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-5 py-3 border-t border-zinc-100 dark:border-zinc-800 text-xs text-zinc-500">
                    <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-emerald-200 dark:bg-emerald-800"></span> مكتمل</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-amber-200 dark:bg-amber-800"></span> ناقص (الرقم = من حُضِّر)</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-rose-200 dark:bg-rose-800"></span> لم يُحضَّر أحد</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded border border-dashed border-zinc-400"></span> اليوم، لم يكتمل بعد</span>
                    <span class="inline-flex items-center gap-1.5"><span class="text-zinc-400">—</span> ليس يوم دوام</span>
                </div>
            @endif
        </div>
    </div>
</div>
