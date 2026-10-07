{{--
    The teachers' attendance over a period: a line per teacher counted against
    the working days of their stage, the days that need a second look under
    each, and a grid of whether the roll call was taken at all, day by day.
--}}
@php
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits((int) $n);

    // Spelled out so Tailwind sees every class: one colour per status, the same
    // ones the roll call marks with.
    $statusStyles = [
        'present' => ['label' => 'حاضر', 'badge' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'],
        'late' => ['label' => 'متأخر', 'badge' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
        'excused' => ['label' => 'مستأذن', 'badge' => 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300'],
        'absent' => ['label' => 'غائب', 'badge' => 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300'],
    ];

    $rateTone = fn (?int $rate) => match (true) {
        $rate === null => 'text-zinc-400',
        $rate >= 90 => 'text-emerald-600 dark:text-emerald-400',
        $rate >= 75 => 'text-amber-600 dark:text-amber-400',
        default => 'text-rose-600 dark:text-rose-400',
    };
@endphp

<div class="space-y-6">

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

        <div class="flex flex-wrap items-center gap-2">
            <flux:button size="sm" icon="clipboard-document-check" :href="route($rollRoute)" wire:navigate>تحضير المعلمين</flux:button>
            <flux:button size="sm" icon="printer" variant="outline" wire:click="downloadPdf">طباعة التقرير</flux:button>
        </div>
    </div>

    {{-- ─────────── الفترة والمراحل ─────────── --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-4 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:flex gap-4 items-end">
            <div class="w-full lg:w-56">
                <livewire:manager.hijri-datepicker wire:model.live="fromDate" label="من" :max-date="$today" />
            </div>
            <div class="w-full lg:w-56">
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
                                ? 'border-maroon bg-maroon/10 text-maroon dark:text-red-secondary font-bold'
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

    {{-- ─────────── الأرقام ─────────── --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <div class="col-span-2 sm:col-span-1 rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
            <div class="text-xs text-zinc-500 dark:text-zinc-400">نسبة الحضور</div>
            <div class="text-2xl font-black mt-1 {{ $rateTone($totals['rate']) }}">
                {{ $totals['rate'] === null ? '—' : $ar($totals['rate']).'٪' }}
            </div>
        </div>
        @foreach (['present', 'late', 'excused', 'absent'] as $status)
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $statusStyles[$status]['label'] }}</div>
                <div class="text-2xl font-black mt-1 text-zinc-800 dark:text-zinc-100">{{ $ar($totals[$status]) }}</div>
            </div>
        @endforeach
        <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
            <div class="text-xs text-zinc-500 dark:text-zinc-400">أيام دوام لم تُحضَّر</div>
            <div class="text-2xl font-black mt-1 {{ $totals['unrecorded'] > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-800 dark:text-zinc-100' }}">
                {{ $ar($totals['unrecorded']) }}
            </div>
        </div>
    </div>

    {{-- ─────────── المعلمون ─────────── --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <div class="px-5 py-4 border-b border-zinc-100 dark:border-zinc-800">
            <flux:heading size="sm">المعلمون</flux:heading>
            <flux:subheading class="text-xs">نسبة الحضور = الحاضر والمتأخر من الأيام المسجّلة، دون أيام الاستئذان.</flux:subheading>
        </div>

        @if ($rows->isEmpty())
            <x-teacher-roll-empty message="لا يوجد معلمون في المراحل المختارة." />
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50 text-xs text-zinc-500 dark:text-zinc-400">
                        <tr>
                            <th class="text-start font-medium px-4 py-2.5">المعلم</th>
                            <th class="font-medium px-3 py-2.5">أيام الدوام</th>
                            @foreach (['present', 'late', 'excused', 'absent'] as $status)
                                <th class="font-medium px-3 py-2.5">{{ $statusStyles[$status]['label'] }}</th>
                            @endforeach
                            <th class="font-medium px-3 py-2.5">لم يُحضَّر</th>
                            <th class="font-medium px-3 py-2.5">النسبة</th>
                        </tr>
                    </thead>

                    @foreach ($rows as $row)
                        <tbody wire:key="teacher-row-{{ $row['teacher']->id }}" x-data="{ open: false }"
                            class="border-t border-zinc-100 dark:border-zinc-800">
                            <tr class="{{ $row['away']->isNotEmpty() ? 'cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/40' : '' }}"
                                @if ($row['away']->isNotEmpty()) x-on:click="open = ! open" @endif>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        @if ($row['away']->isNotEmpty())
                                            <flux:icon icon="chevron-left" class="size-3.5 text-zinc-400 transition-transform" x-bind:class="open && '-rotate-90'" />
                                        @else
                                            <span class="size-3.5"></span>
                                        @endif
                                        <div class="min-w-0">
                                            <div class="font-medium text-zinc-800 dark:text-zinc-100">{{ $row['teacher']->name }}</div>
                                            <div class="text-xs text-zinc-400 truncate">{{ $row['circles'] ?: 'بلا حلقة' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-center text-zinc-600 dark:text-zinc-300">{{ $ar($row['working']) }}</td>
                                @foreach (['present', 'late', 'excused', 'absent'] as $status)
                                    <td class="px-3 py-3 text-center">
                                        @if ($row[$status] > 0)
                                            <span class="inline-flex min-w-7 justify-center rounded-md px-1.5 py-0.5 text-xs font-bold {{ $statusStyles[$status]['badge'] }}">{{ $ar($row[$status]) }}</span>
                                        @else
                                            <span class="text-zinc-300 dark:text-zinc-600">٠</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-3 py-3 text-center {{ $row['unrecorded'] > 0 ? 'text-rose-600 dark:text-rose-400 font-bold' : 'text-zinc-300 dark:text-zinc-600' }}">
                                    {{ $ar($row['unrecorded']) }}
                                </td>
                                <td class="px-3 py-3 text-center font-black {{ $rateTone($row['rate']) }}">
                                    {{ $row['rate'] === null ? '—' : $ar($row['rate']).'٪' }}
                                </td>
                            </tr>

                            @if ($row['away']->isNotEmpty())
                                <tr x-show="open" x-cloak>
                                    <td colspan="8" class="px-4 pb-4">
                                        <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 divide-y divide-zinc-100 dark:divide-zinc-800">
                                            @foreach ($row['away'] as $day)
                                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-xs">
                                                    <span class="text-zinc-600 dark:text-zinc-300 min-w-40">{{ HijriDate::withWeekday($day->date) }}</span>
                                                    <span class="rounded-md px-1.5 py-0.5 font-bold {{ $statusStyles[$day->status]['badge'] ?? '' }}">{{ $statusStyles[$day->status]['label'] ?? $day->status }}</span>
                                                    @if ($day->status === 'late' && $day->arrived_at)
                                                        <span class="text-amber-700 dark:text-amber-400">
                                                            حضر الساعة <span dir="ltr">{{ $day->arrivalLabel() }}</span>
                                                            @if ($minutes = $day->minutesLate())
                                                                · متأخراً {{ $ar($minutes) }} دقيقة
                                                            @endif
                                                        </span>
                                                    @endif
                                                    @if ($day->notes)
                                                        <span class="text-zinc-500 dark:text-zinc-400">السبب: {{ $day->notes }}</span>
                                                    @endif
                                                    @if ($day->substitute && in_array($day->status, \App\Models\TeacherAttendance::AWAY, true))
                                                        <span class="text-zinc-500 dark:text-zinc-400">البديل: {{ $day->substitute->name }}</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    @endforeach
                </table>
            </div>
        @endif
    </div>

    {{-- ─────────── متابعة التحضير اليومي ─────────── --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <div class="px-5 py-4 border-b border-zinc-100 dark:border-zinc-800">
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
                            <th class="sticky start-0 bg-white dark:bg-zinc-900 text-start font-medium px-4 py-2 min-w-40">المرحلة</th>
                            @foreach ($followUp['days'] as $day)
                                <th class="font-normal px-1 py-2 text-center whitespace-nowrap">
                                    <div>{{ HijriDate::weekday($day) }}</div>
                                    <div class="font-bold text-zinc-600 dark:text-zinc-300">{{ HijriDate::format($day, 'd') }}</div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($followUp['stages'] as $stage)
                            <tr wire:key="follow-{{ $loop->index }}">
                                <td class="sticky start-0 bg-white dark:bg-zinc-900 px-4 py-2">
                                    <div class="font-medium text-zinc-800 dark:text-zinc-100">{{ $stage['name'] }}</div>
                                    @if ($role === 'manager' && $stage['supervisors'] !== '')
                                        <div class="text-zinc-400">{{ $stage['supervisors'] }}</div>
                                    @endif
                                </td>
                                @foreach ($stage['cells'] as $day => $cell)
                                    <td class="px-0.5 py-2 text-center">
                                        @if ($cell === null)
                                            <span class="inline-flex size-8 items-center justify-center rounded-md text-zinc-300 dark:text-zinc-600" title="ليس يوم دوام">—</span>
                                        @else
                                            @php
                                                $tone = match (true) {
                                                    $cell['marked'] >= $cell['expected'] => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
                                                    $cell['marked'] > 0 => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                                    default => 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300',
                                                };
                                            @endphp
                                            <span class="inline-flex size-8 items-center justify-center rounded-md font-bold {{ $tone }}"
                                                title="{{ HijriDate::full($day) }}: حُضِّر {{ $ar($cell['marked']) }} من {{ $ar($cell['expected']) }}">
                                                @if ($cell['marked'] >= $cell['expected'])
                                                    <flux:icon icon="check" class="size-4" />
                                                @else
                                                    {{ $ar($cell['marked']) }}
                                                @endif
                                            </span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center gap-4 px-5 py-3 border-t border-zinc-100 dark:border-zinc-800 text-xs text-zinc-500">
                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-emerald-200 dark:bg-emerald-800"></span> مكتمل</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-amber-200 dark:bg-amber-800"></span> ناقص (الرقم = من حُضِّر)</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-rose-200 dark:bg-rose-800"></span> لم يُحضَّر أحد</span>
                <span class="inline-flex items-center gap-1.5"><span class="text-zinc-400">—</span> ليس يوم دوام</span>
            </div>
        @endif
    </div>
</div>
