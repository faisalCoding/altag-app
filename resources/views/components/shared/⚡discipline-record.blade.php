<?php

use App\Models\Student;
use App\Support\StudentDisciplineRecord;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * A student's discipline record as they or their guardian read it: where the
 * student stands against the absence and lateness limits, a Hijri month of
 * working days coloured by what was marked, the weekday that keeps going
 * wrong, the run of days attended, and the days off the usual with their
 * notes. The page is drawn so the colours say it before the words do.
 */
new class extends Component
{
    #[Locked]
    public string $role = 'student';

    #[Locked]
    public int $studentId = 0;

    /** The first Gregorian day of the Hijri month on show. */
    public string $month = '';

    public function mount(string $role = 'student', ?int $studentId = null): void
    {
        abort_unless(in_array($role, ['student', 'guardian'], true), 403);

        $user = Auth::guard($role)->user();
        abort_unless($user !== null, 403);

        // A guardian reads only their own child; a student only themself.
        $this->studentId = $role === 'student' ? $user->id : $user->students()->findOrFail($studentId)->id;
        $this->role = $role;
        $this->month = $this->read()['month'];
    }

    public function previousMonth(): void
    {
        $this->month = $this->read()['previous'];
    }

    public function nextMonth(): void
    {
        $this->month = $this->read()['next'] ?? $this->month;
    }

    #[Computed]
    public function student(): Student
    {
        return Student::with('circle:id,stage_id')->findOrFail($this->studentId);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->month) ? $this->month : now('Asia/Riyadh')->format('Y-m-d');

        return StudentDisciplineRecord::month($this->student, $day);
    }

    public function with(): array
    {
        return [
            'calendar' => $this->read(),
            'limits' => StudentDisciplineRecord::limits($this->student),
            'habits' => StudentDisciplineRecord::habits($this->student),
        ];
    }
};
?>

@php
    use App\Support\ArabicCount;
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits($n);
    $count = fn (int $n, array $forms) => HijriDate::arabicDigits(ArabicCount::of($n, $forms));

    // One colour per status everywhere on the page, spelled out for Tailwind.
    $statuses = [
        'present' => ['label' => 'حاضر', 'cell' => 'bg-emerald-500 text-white', 'dot' => 'bg-emerald-500', 'bar' => 'bg-emerald-500'],
        'late' => ['label' => 'متأخر', 'cell' => 'bg-amber-400 text-amber-950', 'dot' => 'bg-amber-400', 'bar' => 'bg-amber-400'],
        'excused' => ['label' => 'مستأذن', 'cell' => 'bg-sky-400 text-white', 'dot' => 'bg-sky-400', 'bar' => 'bg-sky-400'],
        'absent' => ['label' => 'غائب', 'cell' => 'bg-rose-500 text-white', 'dot' => 'bg-rose-500', 'bar' => 'bg-rose-500'],
    ];
    $states = [
        'good' => ['label' => 'منضبط', 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:ring-emerald-800', 'icon' => 'shield-check'],
        'near' => ['label' => 'قريب من الحد', 'class' => 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:ring-amber-800', 'icon' => 'exclamation-triangle'],
        'over' => ['label' => 'بلغ الحد', 'class' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-900/30 dark:text-rose-300 dark:ring-rose-800', 'icon' => 'exclamation-circle'],
    ];
    $weekdayInitials = ['س', 'ح', 'ن', 'ث', 'ر', 'خ', 'ج'];
    $meters = [
        'absence' => ['label' => 'الغياب', 'icon' => 'x-circle', 'forms' => ArabicCount::ABSENCES, 'none' => 'لا غياب', 'fill' => 'bg-rose-500'],
        'lateness' => ['label' => 'التأخر', 'icon' => 'clock', 'forms' => ArabicCount::LATENESSES, 'none' => 'لا تأخر', 'fill' => 'bg-amber-400'],
    ];
    $window = $count($limits['window'], ArabicCount::DAYS);
    $mostIncidents = max(1, collect($habits['weekdays'])->map(fn ($w) => $w['late'] + $w['absent'])->max() ?? 0);
@endphp

<div class="space-y-5" dir="rtl">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">سجل الانضباط</flux:heading>
            <flux:subheading class="mt-1">الحضور والتأخر والغياب في أيام الدوام.</flux:subheading>
        </div>
        <span data-discipline-state="{{ $limits['state'] }}" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-bold ring-1 {{ $states[$limits['state']]['class'] }}">
            <flux:icon :icon="$states[$limits['state']]['icon']" variant="micro" />
            {{ $states[$limits['state']]['label'] }}
        </span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-4 items-start">

        {{-- ─────────── الرصيد مقابل الحد ─────────── --}}
        <section class="lg:col-span-2 rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 space-y-5" aria-label="الرصيد مقابل الحد">
            @foreach ($meters as $key => $meter)
                @php
                    $standing = $limits[$key];
                    $left = $standing['limit'] - $standing['used'];
                    $over = max(0, -$left);
                @endphp
                <div wire:key="meter-{{ $key }}" data-meter="{{ $key }}">
                    <div class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-2 font-bold text-zinc-800 dark:text-zinc-100">
                            <flux:icon :icon="$meter['icon']" class="size-5 text-zinc-400" />
                            {{ $meter['label'] }}
                        </span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">
                            <span class="text-lg font-black text-zinc-900 dark:text-white">{{ $ar($standing['used']) }}</span>
                            من {{ $ar($standing['limit']) }}
                        </span>
                    </div>

                    {{-- A box for each one the limit allows, filled as they are used. --}}
                    @if ($standing['limit'] <= 12)
                        <div class="mt-2 flex gap-1.5" role="img" aria-label="{{ $ar($standing['used']) }} من {{ $ar($standing['limit']) }}">
                            @for ($i = 0; $i < $standing['limit']; $i++)
                                <span class="h-3 flex-1 rounded {{ $i < $standing['used'] ? $meter['fill'] : 'bg-zinc-100 dark:bg-zinc-800' }}"></span>
                            @endfor
                            @if ($over > 0)
                                <span class="-my-0.5 rounded bg-rose-100 px-1.5 text-xs font-bold text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">+{{ $ar($over) }}</span>
                            @endif
                        </div>
                    @else
                        <div class="mt-2 h-3 rounded bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                            <div class="h-full {{ $meter['fill'] }}" style="width: {{ min(100, round($standing['used'] / $standing['limit'] * 100)) }}%"></div>
                        </div>
                    @endif

                    <p class="mt-2 text-sm {{ $left <= 0 ? 'text-rose-700 dark:text-rose-400 font-bold' : ($left === 1 ? 'text-amber-700 dark:text-amber-400 font-bold' : 'text-zinc-600 dark:text-zinc-300') }}">
                        @if ($standing['used'] === 0)
                            {{ $meter['none'] }} في آخر {{ $window }}
                        @elseif ($left <= 0)
                            بلغ الحد المسموح
                        @else
                            يتبقى {{ $count($left, $meter['forms']) }} قبل الحد
                        @endif
                    </p>
                    @if ($standing['frees_on'])
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                            يخرج أقدمها من الحساب في {{ HijriDate::dayMonth($standing['frees_on']) }}
                        </p>
                    @endif
                </div>
            @endforeach

            <p class="border-t border-zinc-100 dark:border-zinc-800 pt-3 text-xs text-zinc-400">
                يُحسب الغياب والتأخر في آخر {{ $window }}، وما قبلها لا يُعد.
            </p>
        </section>

        {{-- ─────────── الشهر ─────────── --}}
        <section class="lg:col-span-3 lg:row-span-2 rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5"
            aria-label="تقويم الحضور" x-data="{ picked: null }">
            {{-- Right-to-left: the previous month sits to the right. --}}
            <div class="flex items-center justify-between gap-2">
                <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="previousMonth" aria-label="الشهر السابق" />
                <div class="text-center">
                    <div class="font-bold text-zinc-900 dark:text-white">{{ $calendar['title'] }}</div>
                    @if ($calendar['rate'] !== null)
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">نسبة الحضور {{ $ar($calendar['rate']) }}٪</div>
                    @endif
                </div>
                <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="nextMonth" :disabled="$calendar['next'] === null" aria-label="الشهر التالي" />
            </div>

            <div class="mt-4 grid grid-cols-7 gap-1.5 text-center text-xs font-medium text-zinc-400">
                @foreach ($weekdayInitials as $initial)
                    <div>{{ $initial }}</div>
                @endforeach
            </div>

            <div class="mt-1.5 grid grid-cols-7 gap-1.5" data-calendar>
                @for ($i = 0; $i < $calendar['offset']; $i++)
                    <div></div>
                @endfor
                @foreach ($calendar['cells'] as $cell)
                    @php
                        $style = match (true) {
                            $cell['status'] !== null => $statuses[$cell['status']]['cell'] ?? '',
                            ! $cell['working'] => 'text-zinc-300 dark:text-zinc-600',
                            $cell['future'] || $cell['today'] => 'border border-zinc-200 dark:border-zinc-700 text-zinc-400',
                            default => 'border-2 border-dashed border-zinc-300 dark:border-zinc-600 text-zinc-500',
                        };
                        $meaning = match (true) {
                            $cell['status'] !== null => $statuses[$cell['status']]['label'] ?? '',
                            ! $cell['working'] => 'ليس يوم دوام',
                            $cell['future'] => 'لم يأتِ بعد',
                            $cell['today'] => 'لم يُسجَّل بعد',
                            default => 'لم يُسجَّل',
                        };
                    @endphp
                    <button type="button" wire:key="day-{{ $cell['date'] }}" data-day="{{ $cell['date'] }}" data-status="{{ $cell['status'] ?? ($cell['working'] ? 'none' : 'off') }}"
                        x-on:click="picked = {{ \Illuminate\Support\Js::from(['date' => HijriDate::withWeekday($cell['date']), 'meaning' => $meaning, 'notes' => $cell['notes']]) }}"
                        aria-label="{{ HijriDate::withWeekday($cell['date']) }}: {{ $meaning }}"
                        class="aspect-square rounded-lg text-sm font-bold flex items-center justify-center transition-transform active:scale-95 {{ $style }} {{ $cell['today'] ? 'ring-2 ring-offset-2 ring-zinc-800 dark:ring-white dark:ring-offset-zinc-900' : '' }}">
                        {{ $ar($cell['day']) }}
                    </button>
                @endforeach
            </div>

            <div class="mt-3 min-h-12 rounded-xl bg-zinc-50 dark:bg-zinc-800/60 px-3 py-2 text-sm">
                <template x-if="picked">
                    <div>
                        <span class="font-bold text-zinc-800 dark:text-zinc-100" x-text="picked.date"></span>
                        <span class="text-zinc-500 dark:text-zinc-400"> · </span>
                        <span class="text-zinc-700 dark:text-zinc-200" x-text="picked.meaning"></span>
                        <div x-show="picked.notes" class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5" x-text="picked.notes"></div>
                    </div>
                </template>
                <p x-show="! picked" class="text-zinc-400">اضغط على يوم لترى تفاصيله.</p>
            </div>

            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                @foreach ($statuses as $status => $style)
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm {{ $style['dot'] }}"></span>{{ $style['label'] }} {{ $ar($calendar['counts'][$status]) }}</span>
                @endforeach
                <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm border border-dashed border-zinc-400"></span>لم يُسجَّل {{ $ar($calendar['unrecorded']) }}</span>
            </div>
        </section>

        {{-- ─────────── العادات ─────────── --}}
        <section class="lg:col-span-2 rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 space-y-5" aria-label="نمط الحضور">
            <div class="flex items-center gap-3" data-streak="{{ $habits['streak'] }}">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl {{ $habits['streak'] > 0 ? 'bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400' : 'bg-zinc-100 text-zinc-400 dark:bg-zinc-800' }}">
                    <flux:icon icon="fire" variant="solid" class="size-6" />
                </span>
                <div>
                    <div class="font-bold text-zinc-900 dark:text-white">
                        @if ($habits['streak'] > 0)
                            {{ $count($habits['streak'], ArabicCount::DAYS) }} من الحضور المتتالي
                        @else
                            لا حضور متتالي الآن
                        @endif
                    </div>
                    @if ($habits['best'] > 0)
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">أطول سلسلة هذا الفصل: {{ $count($habits['best'], ArabicCount::DAYS) }}</div>
                    @endif
                </div>
            </div>

            @if ($habits['weekdays'] !== [])
                <div>
                    <div class="text-sm font-bold text-zinc-700 dark:text-zinc-200">أيام الأسبوع هذا الفصل</div>
                    <div class="mt-3 flex h-20 items-end gap-2" role="img" aria-label="التأخر والغياب حسب أيام الأسبوع">
                        @foreach ($habits['weekdays'] as $weekday)
                            <div wire:key="weekday-{{ $loop->index }}" class="flex h-full flex-1 flex-col justify-end gap-0.5" title="{{ $weekday['label'] }}: تأخر {{ $ar($weekday['late']) }}، غياب {{ $ar($weekday['absent']) }}">
                                @if ($weekday['late'] + $weekday['absent'] === 0)
                                    <span class="h-1 rounded-sm bg-zinc-100 dark:bg-zinc-800"></span>
                                @else
                                    @if ($weekday['absent'] > 0)
                                        <span class="rounded-t-sm bg-rose-500" style="height: {{ round($weekday['absent'] / $mostIncidents * 100) }}%"></span>
                                    @endif
                                    @if ($weekday['late'] > 0)
                                        <span class="{{ $weekday['absent'] > 0 ? '' : 'rounded-t-sm' }} bg-amber-400" style="height: {{ round($weekday['late'] / $mostIncidents * 100) }}%"></span>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-1.5 flex gap-2 text-center text-[11px] text-zinc-400">
                        @foreach ($habits['weekdays'] as $weekday)
                            <span class="flex-1 truncate">{{ $weekday['label'] }}</span>
                        @endforeach
                    </div>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300" data-worst-weekday>
                        @if ($habits['worst'])
                            يتكرر {{ $habits['worst']['status'] === 'late' ? 'التأخر' : 'الغياب' }} يوم {{ $habits['worst']['label'] }} أكثر من غيره.
                        @elseif (collect($habits['weekdays'])->sum(fn ($w) => $w['late'] + $w['absent']) === 0)
                            لا تأخر ولا غياب هذا الفصل.
                        @else
                            لا يتكرر التأخر أو الغياب في يوم بعينه.
                        @endif
                    </p>
                </div>
            @endif
        </section>

        {{-- ─────────── ما خرج عن المعتاد ─────────── --}}
        <section class="lg:col-span-5 rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5" aria-label="ما خرج عن المعتاد">
            <div class="text-sm font-bold text-zinc-700 dark:text-zinc-200">ما خرج عن المعتاد في {{ $calendar['title'] }}</div>
            @if ($calendar['exceptions']->isEmpty())
                <p class="mt-3 text-sm text-zinc-400">لا تأخر ولا غياب ولا استئذان في هذا الشهر.</p>
            @else
                <div class="mt-2 divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($calendar['exceptions'] as $record)
                        <div wire:key="exception-{{ $record->id }}" class="flex items-stretch gap-3 py-2.5">
                            <span class="w-1 shrink-0 rounded-full {{ $statuses[$record->status]['bar'] ?? 'bg-zinc-300' }}"></span>
                            <div class="min-w-0">
                                <div class="text-sm text-zinc-800 dark:text-zinc-100">
                                    <span class="font-bold">{{ $statuses[$record->status]['label'] ?? '' }}</span>
                                    <span class="text-zinc-500 dark:text-zinc-400">· {{ HijriDate::withWeekday($record->date) }}</span>
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $record->notes ?: 'بلا ملاحظة' }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</div>
