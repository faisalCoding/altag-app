<?php

use App\Models\ScheduleTrack;
use App\Services\ProgramScheduleService;
use App\Support\ArabicDigits;
use App\Support\HijriDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The evening programme's schedule as a guardian or a student reads it: one
 * day, one week (the printed poster or a card per day) or a month at a glance.
 * Only the tracks linked to the reader's school stages are offered, and only
 * published weeks are shown.
 */
new class extends Component
{
    #[Locked]
    public string $role = 'guardian';

    #[Url]
    public string $view = 'day';

    #[Url(as: 'track')]
    public ?int $trackId = null;

    #[Url]
    public string $date = '';

    #[Url(as: 'week')]
    public int $weekNumber = 0;

    #[Url]
    public string $month = '';

    /**
     * '' until the reader picks one: then the week is the poster on a wide
     * screen and the cards on a phone, where the poster only scrolled sideways.
     */
    #[Url]
    public string $layout = '';

    public function mount(string $role = 'guardian'): void
    {
        abort_unless(in_array($role, ['guardian', 'student'], true) && Auth::guard($role)->check(), 403);

        $this->role = $role;
        $this->view = in_array($this->view, ['day', 'week', 'month'], true) ? $this->view : 'day';
        $this->layout = in_array($this->layout, ['poster', 'cards'], true) ? $this->layout : '';
        $this->date = $this->isDate($this->date) ? $this->date : CarbonImmutable::today()->toDateString();

        if ($this->weekNumber < 1) {
            $this->weekNumber = max(1, $this->schedule()->weekNumberFor($this->day()));
        }

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
            $this->month = $this->day()->format('Y-m');
        }
    }

    /**
     * The guardian's children, or the student themself.
     *
     * @return EloquentCollection<int, \App\Models\Student>
     */
    #[Computed]
    public function readers(): EloquentCollection
    {
        return $this->schedule()->readerStudents($this->role);
    }

    /**
     * @return Collection<int, ScheduleTrack>
     */
    #[Computed]
    public function tracks(): Collection
    {
        return $this->schedule()->tracksForStages($this->schedule()->stageIdsFor($this->readers));
    }

    #[Computed]
    public function track(): ?ScheduleTrack
    {
        return $this->tracks->firstWhere('id', $this->trackId) ?? $this->tracks->first();
    }

    /**
     * The guardian's children attending each track, by track id.
     *
     * @return array<int, array<int, string>>
     */
    #[Computed]
    public function childrenByTrack(): array
    {
        if ($this->role !== 'guardian') {
            return [];
        }

        return $this->tracks->mapWithKeys(function (ScheduleTrack $track) {
            $stageIds = $track->stages->pluck('id')->all();

            return [$track->id => $this->readers
                ->filter(fn ($student) => in_array($student->stage_id ?? $student->circle?->stage_id, $stageIds))
                ->pluck('name')
                ->all()];
        })->all();
    }

    /**
     * @return array{is_program_day: bool, week_number: int, week: ?\App\Models\ScheduleWeek, items: Collection<int, \App\Models\ScheduleCell>, memo: ?array<string, string>}
     */
    #[Computed]
    public function dayData(): array
    {
        $schedule = $this->schedule();
        $day = $this->day();
        $weekNumber = $schedule->weekNumberFor($day);
        $isProgramDay = $schedule->isProgramDay($day);
        $week = ($this->track && $isProgramDay && $weekNumber >= 1) ? $schedule->publishedWeek($this->track, $weekNumber) : null;

        return [
            'is_program_day' => $isProgramDay,
            'week_number' => $weekNumber,
            'week' => $week,
            'items' => $week ? $week->cells->where('weekday', $day->dayOfWeek)->reject->isEmpty()->sortBy('position')->values() : collect(),
            'memo' => $week?->memoFor($day->dayOfWeek),
        ];
    }

    #[Computed]
    public function weekData(): ?array
    {
        $week = $this->track ? $this->schedule()->publishedWeek($this->track, $this->weekNumber) : null;

        return $week ? $this->schedule()->posterData($week) : null;
    }

    /**
     * @return array<int, int>
     */
    #[Computed]
    public function publishedWeeks(): array
    {
        return $this->track ? $this->schedule()->publishedWeekNumbers($this->track) : [];
    }

    /**
     * @return array{first: CarbonImmutable, lead: int, days: array<int, array<string, mixed>>}
     */
    #[Computed]
    public function monthData(): array
    {
        $schedule = $this->schedule();
        $first = CarbonImmutable::parse($this->month.'-01');
        $last = $first->endOfMonth()->startOfDay();
        $weeks = $this->track
            ? $schedule->publishedWeeksBetween($this->track, $schedule->weekNumberFor($first), $schedule->weekNumberFor($last))
            : collect();
        $firstWeekday = $schedule->weekdays()[0];
        $days = [];

        for ($date = $first; $date->lte($last); $date = $date->addDay()) {
            $week = $weeks->get($schedule->weekNumberFor($date));
            $isProgramDay = $schedule->isProgramDay($date);

            $days[] = [
                'date' => $date,
                'is_program_day' => $isProgramDay,
                'has_week' => $week !== null,
                'week_label' => ($week && $date->dayOfWeek === $firstWeekday) ? $week->displayTitle() : null,
                'chips' => ($week && $isProgramDay)
                    ? $week->cells->where('weekday', $date->dayOfWeek)
                        ->reject(fn ($cell) => $cell->isEmpty() || $cell->activity?->is_routine)
                        ->sortBy('position')->values()
                    : collect(),
            ];
        }

        return ['first' => $first, 'lead' => $first->dayOfWeek, 'days' => $days];
    }

    public function show(string $view): void
    {
        if (! in_array($view, ['day', 'week', 'month'], true)) {
            return;
        }

        $this->view = $view;

        if ($view === 'week') {
            $this->weekNumber = max(1, $this->schedule()->weekNumberFor($this->day()));
        }

        if ($view === 'month') {
            $this->month = $this->day()->format('Y-m');
        }
    }

    public function selectTrack(int $trackId): void
    {
        if ($this->tracks->contains('id', $trackId)) {
            $this->trackId = $trackId;
        }
    }

    public function stepDay(int $direction): void
    {
        $this->date = $this->schedule()->stepProgramDay($this->day(), $direction < 0 ? -1 : 1)->toDateString();
    }

    public function backToToday(): void
    {
        $this->date = CarbonImmutable::today()->toDateString();
        $this->month = $this->day()->format('Y-m');
        $this->weekNumber = max(1, $this->schedule()->weekNumberFor($this->day()));
    }

    public function openDay(string $date): void
    {
        if ($this->isDate($date)) {
            $this->date = $date;
            $this->view = 'day';
        }
    }

    /**
     * Moves to the nearest published week in a direction, so a reader never
     * lands on a week that has not been written yet.
     */
    public function stepWeek(int $direction): void
    {
        $candidates = collect($this->publishedWeeks)
            ->filter(fn (int $number) => $direction < 0 ? $number < $this->weekNumber : $number > $this->weekNumber);
        $target = $direction < 0 ? $candidates->max() : $candidates->min();

        if ($target === null) {
            return;
        }

        $this->weekNumber = $target;
        $today = CarbonImmutable::today();
        $this->date = $this->schedule()->weekNumberFor($today) === $target && $this->schedule()->isProgramDay($today)
            ? $today->toDateString()
            : $this->schedule()->dateFor($target, $this->schedule()->weekdays()[0])->toDateString();
    }

    public function stepMonth(int $direction): void
    {
        $this->month = CarbonImmutable::parse($this->month.'-01')->addMonths($direction < 0 ? -1 : 1)->format('Y-m');
    }

    public function setLayout(string $layout): void
    {
        if (in_array($layout, ['poster', 'cards'], true)) {
            $this->layout = $layout;
        }
    }

    private function schedule(): ProgramScheduleService
    {
        return app(ProgramScheduleService::class);
    }

    private function day(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->date);
    }

    private function isDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false;
    }
};
?>

@php
    $schedule = app(\App\Services\ProgramScheduleService::class);
    $settings = $schedule->settings();
    $weekdayNames = \App\Services\ProgramScheduleService::WEEKDAY_NAMES;
    $day = \Carbon\CarbonImmutable::parse($date);
    $segment = 'rounded-lg px-4 py-1.5 text-sm font-bold transition';
    $segmentOn = 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white';
    $segmentOff = 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white';
    // The same "on" look, for a layout chosen by the screen rather than the reader.
    $segmentOnFromMd = 'md:bg-white md:text-zinc-900 md:shadow-sm md:dark:bg-zinc-700 md:dark:text-white';
    $segmentOnBelowMd = 'max-md:bg-white max-md:text-zinc-900 max-md:shadow-sm max-md:dark:bg-zinc-700 max-md:dark:text-white';
@endphp

<div class="space-y-6" dir="rtl">
    <div class="flex items-center gap-3">
        <div class="rounded-xl bg-maroon/10 p-2.5 text-maroon dark:bg-white/10 dark:text-white">
            <flux:icon icon="calendar-days" />
        </div>
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">جدول البرنامج</flux:heading>
            <flux:subheading class="text-zinc-400">{{ $settings['title'] }}</flux:subheading>
        </div>
    </div>

    @if ($this->tracks->isEmpty())
        <div class="rounded-2xl border border-zinc-100 bg-white p-10 text-center dark:border-zinc-800 dark:bg-zinc-900">
            <flux:icon icon="calendar" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
            <p class="font-bold text-zinc-700 dark:text-zinc-200">
                {{ $role === 'guardian' ? 'لا يوجد جدول مرتبط بمراحل أبنائك بعد.' : 'لا يوجد جدول مرتبط بمرحلتك بعد.' }}
            </p>
            <p class="mt-1 text-sm text-zinc-500">سيظهر هنا حين تربط الإدارة مرحلة البرنامج بمرحلتك الدراسية.</p>
        </div>
    @else
        <div class="flex flex-wrap items-center gap-3">
            @if ($this->tracks->count() > 1)
                <div class="flex flex-wrap gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800" role="group" aria-label="مرحلة البرنامج">
                    @foreach ($this->tracks as $option)
                        <button type="button" wire:key="track-{{ $option->id }}" wire:click="selectTrack({{ $option->id }})"
                                aria-pressed="{{ $this->track?->id === $option->id ? 'true' : 'false' }}"
                                @class([$segment, $segmentOn => $this->track?->id === $option->id, $segmentOff => $this->track?->id !== $option->id])>
                            {{ $option->shortName() }}
                            @if (! empty($this->childrenByTrack[$option->id]))
                                <span class="block text-[11px] font-medium text-zinc-400">{{ implode('، ', $this->childrenByTrack[$option->id]) }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @else
                <flux:badge color="sky" size="lg">
                    {{ $this->track->name }}
                    @if (! empty($this->childrenByTrack[$this->track->id]))
                        · {{ implode('، ', $this->childrenByTrack[$this->track->id]) }}
                    @endif
                </flux:badge>
            @endif

            <div class="flex gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800 max-sm:w-full" role="group" aria-label="طريقة العرض">
                @foreach (['day' => 'اليوم', 'week' => 'الأسبوع', 'month' => 'الشهر'] as $key => $label)
                    <button type="button" wire:key="view-{{ $key }}" wire:click="show('{{ $key }}')" aria-pressed="{{ $view === $key ? 'true' : 'false' }}"
                            @class([$segment, 'max-sm:flex-1', $segmentOn => $view === $key, $segmentOff => $view !== $key])>{{ $label }}</button>
                @endforeach
            </div>
        </div>

        {{-- ================= Day ================= --}}
        @if ($view === 'day')
            @php $data = $this->dayData; @endphp
            <div class="flex flex-wrap items-center gap-3">
                <flux:button icon="chevron-right" variant="ghost" square wire:click="stepDay(-1)" aria-label="اليوم الدراسي السابق" />
                <div class="min-w-0">
                    <p class="text-lg font-extrabold text-zinc-900 dark:text-white">{{ $weekdayNames[$day->dayOfWeek] }} {{ $schedule->gregorianLabel($day, true) }}</p>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ \App\Support\HijriDate::full($day) }} هـ
                        @if ($data['week']) · {{ $data['week']->displayTitle() }} @endif
                        · {{ $this->track->name }}
                    </p>
                </div>
                <flux:button icon="chevron-left" variant="ghost" square wire:click="stepDay(1)" aria-label="اليوم الدراسي التالي" />
                @if ($day->isToday())
                    <flux:badge color="sky">اليوم</flux:badge>
                @else
                    <flux:button size="sm" wire:click="backToToday">العودة إلى اليوم</flux:button>
                @endif
            </div>

            @if (! $data['is_program_day'])
                <div class="rounded-2xl border border-zinc-100 bg-white p-10 text-center dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="mb-4 font-bold text-zinc-700 dark:text-zinc-200">لا يوجد برنامج في هذا اليوم.</p>
                    <flux:button variant="primary" wire:click="stepDay(1)">اليوم الدراسي التالي</flux:button>
                </div>
            @elseif ($data['week_number'] < 1)
                <div class="rounded-2xl border border-zinc-100 bg-white p-10 text-center dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="mb-4 font-bold text-zinc-700 dark:text-zinc-200">البرنامج لم يبدأ بعد في هذا التاريخ.</p>
                    <flux:button variant="primary" wire:click="openDay('{{ $schedule->dateFor(1, $schedule->weekdays()[0])->toDateString() }}')">أول يوم في البرنامج</flux:button>
                </div>
            @elseif (! $data['week'])
                <div class="rounded-2xl border border-zinc-100 bg-white p-10 text-center dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="font-bold text-zinc-700 dark:text-zinc-200">لم يُنشر جدول هذا الأسبوع بعد.</p>
                </div>
            @else
                <article class="mx-auto max-w-3xl overflow-hidden rounded-2xl border bg-white dark:bg-zinc-900 {{ $day->isToday() ? 'border-sky-300 ring-2 ring-sky-100 dark:border-sky-700 dark:ring-sky-900/40' : 'border-zinc-100 dark:border-zinc-800' }}">
                    <ul class="divide-y divide-zinc-100 px-4 dark:divide-zinc-800">
                        @forelse ($data['items'] as $cell)
                            <li wire:key="item-{{ $cell->id }}" class="flex items-center gap-3 py-3.5">
                                <span class="grid size-12 shrink-0 place-items-center rounded-xl" style="background: {{ \App\Models\ScheduleActivity::hexFor($cell->activity?->color) }}1c">
                                    <x-schedule.icon :name="$cell->activity?->icon" :color="$cell->activity?->color" class="size-7" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block font-bold text-zinc-900 dark:text-white">{{ $cell->displayName() }}</span>
                                    @if ($cell->detail)
                                        <span class="block text-sm text-rose-600 dark:text-rose-400">{{ $cell->detail }}</span>
                                    @endif
                                </span>
                                @if ($cell->displayTime())
                                    <span class="whitespace-nowrap font-extrabold text-cyan-700 dark:text-cyan-400">{{ ArabicDigits::toEastern($cell->displayTime()) }}</span>
                                @endif
                            </li>
                        @empty
                            <li class="py-6 text-center text-sm text-zinc-500">لا توجد أنشطة مسجّلة لهذا اليوم.</li>
                        @endforelse
                    </ul>

                    @if ($data['memo'] && ($data['memo']['line'] || $data['memo']['wird']))
                        <div class="m-4 flex items-start gap-3 rounded-xl border border-orange-100 bg-orange-50 p-4 dark:border-orange-500/20 dark:bg-orange-500/10">
                            <x-schedule.icon name="book" color="red" class="mt-0.5 size-6" />
                            <div class="space-y-0.5 text-sm">
                                <p class="font-extrabold text-orange-700 dark:text-orange-300">محفوظ اليوم</p>
                                @if ($data['memo']['line'])
                                    <p class="font-bold text-zinc-800 dark:text-zinc-100">{{ $data['memo']['line'] }}</p>
                                @endif
                                @if ($data['memo']['wird'])
                                    <p class="text-zinc-600 dark:text-zinc-300">{{ $data['memo']['wird'] }}</p>
                                @endif
                                @if ($data['memo']['reps'])
                                    <flux:badge size="sm" color="orange" class="mt-1">{{ $data['memo']['reps'] }}</flux:badge>
                                @endif
                            </div>
                        </div>
                    @endif
                </article>
            @endif
        @endif

        {{-- ================= Week ================= --}}
        @if ($view === 'week')
            @php
                $poster = $this->weekData;
                $published = collect($this->publishedWeeks);
            @endphp
            <div class="flex flex-wrap items-center gap-3">
                <flux:button icon="chevron-right" variant="ghost" square wire:click="stepWeek(-1)" :disabled="! $published->contains(fn ($n) => $n < $weekNumber)" aria-label="الأسبوع السابق" />
                <div class="min-w-0">
                    <p class="text-lg font-extrabold text-zinc-900 dark:text-white">{{ $poster ? $poster['week']->displayTitle() : \App\Models\ScheduleWeek::ordinalName($weekNumber) }}</p>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $schedule->rangeLabel($weekNumber) }} · {{ $this->track->name }}</p>
                </div>
                <flux:button icon="chevron-left" variant="ghost" square wire:click="stepWeek(1)" :disabled="! $published->contains(fn ($n) => $n > $weekNumber)" aria-label="الأسبوع التالي" />
                <div class="flex-1"></div>
                @if ($poster)
                    <div class="flex gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800" role="group" aria-label="شكل العرض">
                        @foreach (['poster' => 'الجدول', 'cards' => 'البطاقات'] as $key => $label)
                            <button type="button" wire:key="layout-{{ $key }}" wire:click="setLayout('{{ $key }}')" aria-pressed="{{ $layout === $key ? 'true' : 'false' }}"
                                    @class([
                                        $segment,
                                        $segmentOn => $layout === $key,
                                        $segmentOff => $layout !== $key,
                                        $segmentOnFromMd => $layout === '' && $key === 'poster',
                                        $segmentOnBelowMd => $layout === '' && $key === 'cards',
                                    ])>{{ $label }}</button>
                        @endforeach
                    </div>
                    <flux:button size="sm" icon="printer" :href="route($role.'.schedule.print', $poster['week'])" target="_blank">طباعة</flux:button>
                @endif
            </div>

            @if (! $poster)
                <div class="rounded-2xl border border-zinc-100 bg-white p-10 text-center dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="font-bold text-zinc-700 dark:text-zinc-200">لم يُنشر جدول هذا الأسبوع بعد.</p>
                </div>
            @else
                @if ($layout !== 'cards')
                    <div @class(['overflow-x-auto pb-3', 'max-md:hidden' => $layout === ''])>
                        <x-schedule.poster :data="$poster" />
                    </div>
                @endif
                @if ($layout !== 'poster')
                <div @class(['grid gap-4 sm:grid-cols-2 xl:grid-cols-3', 'md:hidden' => $layout === ''])>
                    @foreach ($poster['grid'] as $weekday => $row)
                        @php
                            $cardDate = $poster['dates'][$weekday];
                            $memo = $poster['week']->memoFor($weekday);
                        @endphp
                        <article wire:key="card-{{ $weekday }}" class="overflow-hidden rounded-2xl border bg-white dark:bg-zinc-900 {{ $cardDate->isToday() ? 'border-sky-300 ring-2 ring-sky-100 dark:border-sky-700 dark:ring-sky-900/40' : 'border-zinc-100 dark:border-zinc-800' }}">
                            <header class="flex items-baseline justify-between gap-2 border-b border-zinc-100 bg-sky-50/60 px-4 py-3 dark:border-zinc-800 dark:bg-sky-500/5">
                                <b class="text-lg font-extrabold text-sky-800 dark:text-sky-300">{{ $weekdayNames[$weekday] }}</b>
                                <span class="text-xs text-zinc-500">{{ $poster['date_labels'][$weekday] }}</span>
                            </header>
                            <ul class="divide-y divide-zinc-100 px-3 dark:divide-zinc-800">
                                @foreach (collect($row)->pluck('cell')->filter() as $cell)
                                    <li wire:key="card-{{ $weekday }}-{{ $cell->id }}" class="flex items-center gap-3 py-2.5">
                                        <span class="grid size-10 shrink-0 place-items-center rounded-xl" style="background: {{ \App\Models\ScheduleActivity::hexFor($cell->activity?->color) }}1c">
                                            <x-schedule.icon :name="$cell->activity?->icon" :color="$cell->activity?->color" class="size-6" />
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm font-bold text-zinc-900 dark:text-white">{{ $cell->displayName() }}</span>
                                            @if ($cell->detail)
                                                <span class="block text-xs text-rose-600 dark:text-rose-400">{{ $cell->detail }}</span>
                                            @endif
                                        </span>
                                        @if ($cell->displayTime())
                                            <span class="whitespace-nowrap text-sm font-extrabold text-cyan-700 dark:text-cyan-400">{{ ArabicDigits::toEastern($cell->displayTime()) }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            @if ($memo['line'])
                                <p class="mx-3 mb-3 rounded-xl border border-orange-100 bg-orange-50 px-3 py-2 text-xs font-bold text-zinc-700 dark:border-orange-500/20 dark:bg-orange-500/10 dark:text-zinc-200">
                                    محفوظ اليوم: {{ $memo['line'] }}
                                    @if ($memo['reps']) · {{ $memo['reps'] }} @endif
                                </p>
                            @endif
                        </article>
                    @endforeach
                </div>
                @endif
            @endif
        @endif

        {{-- ================= Month ================= --}}
        @if ($view === 'month')
            @php
                $monthData = $this->monthData;
                $first = $monthData['first'];
                $last = $first->endOfMonth();
                $hijriFrom = \App\Support\HijriDate::monthYear($first);
                $hijriTo = \App\Support\HijriDate::monthYear($last);
                $trailing = (7 - ($monthData['lead'] + count($monthData['days'])) % 7) % 7;
            @endphp
            <div class="flex flex-wrap items-center gap-3">
                <flux:button icon="chevron-right" variant="ghost" square wire:click="stepMonth(-1)" aria-label="الشهر السابق" />
                <div class="min-w-0">
                    <p class="text-lg font-extrabold text-zinc-900 dark:text-white">{{ ArabicDigits::toEastern($first->locale('ar')->translatedFormat('F Y')) }}</p>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $hijriFrom === $hijriTo ? $hijriFrom : $hijriFrom.' – '.$hijriTo }} هـ · {{ $this->track->name }}</p>
                </div>
                <flux:button icon="chevron-left" variant="ghost" square wire:click="stepMonth(1)" aria-label="الشهر التالي" />
                @unless ($first->isSameMonth(now()))
                    <flux:button size="sm" wire:click="backToToday">الشهر الحالي</flux:button>
                @endunless
            </div>

            <div class="overflow-hidden rounded-2xl border border-zinc-100 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="grid grid-cols-7 border-b border-zinc-100 bg-zinc-50 text-center text-xs font-bold text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/50">
                    @foreach ($weekdayNames as $index => $name)
                        <div wire:key="head-{{ $index }}" class="px-1 py-2 {{ in_array($index, $schedule->weekdays(), true) ? '' : 'text-zinc-300 dark:text-zinc-600' }}">
                            <span class="max-sm:hidden">{{ $name }}</span><span class="sm:hidden">{{ \App\Services\ProgramScheduleService::WEEKDAY_SHORT_NAMES[$index] }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="grid grid-cols-7">
                    @for ($i = 0; $i < $monthData['lead']; $i++)
                        <div wire:key="lead-{{ $i }}" class="min-h-18 border-b border-s border-zinc-100 bg-zinc-50/50 sm:min-h-28 dark:border-zinc-800 dark:bg-zinc-900/40"></div>
                    @endfor
                    @foreach ($monthData['days'] as $cellDay)
                        @php $cellDate = $cellDay['date']; @endphp
                        @if ($cellDay['is_program_day'])
                            <button type="button" wire:key="m-{{ $cellDate->toDateString() }}" wire:click="openDay('{{ $cellDate->toDateString() }}')"
                                    aria-label="{{ $weekdayNames[$cellDate->dayOfWeek] }} {{ $schedule->gregorianLabel($cellDate) }}"
                                    class="flex min-h-18 min-w-0 flex-col items-stretch gap-1 border-b border-s border-zinc-100 p-1.5 text-start transition hover:bg-sky-50/60 sm:min-h-28 dark:border-zinc-800 dark:hover:bg-sky-500/5">
                        @else
                            <div wire:key="m-{{ $cellDate->toDateString() }}" class="flex min-h-18 min-w-0 flex-col gap-1 border-b border-s border-zinc-100 bg-zinc-50/60 p-1.5 sm:min-h-28 dark:border-zinc-800 dark:bg-zinc-900/40">
                        @endif
                                <span class="flex items-center justify-between gap-1 max-sm:justify-center">
                                    <span @class([
                                        'grid size-7 place-items-center rounded-full text-sm font-extrabold',
                                        'bg-sky-600 text-white' => $cellDate->isToday(),
                                        'text-zinc-800 dark:text-zinc-100' => ! $cellDate->isToday() && $cellDay['has_week'],
                                        'text-zinc-400' => ! $cellDate->isToday() && ! $cellDay['has_week'],
                                    ])>{{ ArabicDigits::toEastern($cellDate->day) }}</span>
                                    <span class="truncate text-[10px] text-zinc-400 max-sm:hidden">{{ \App\Support\HijriDate::format($cellDate, 'd') }}</span>
                                </span>
                                @if ($cellDay['week_label'])
                                    <span class="truncate self-start rounded-md bg-teal-50 px-1.5 text-[10px] font-bold text-teal-700 max-sm:hidden dark:bg-teal-500/10 dark:text-teal-300">{{ $cellDay['week_label'] }}</span>
                                @endif
                                <span class="flex min-w-0 flex-col gap-0.5 max-sm:flex-row max-sm:flex-wrap max-sm:justify-center">
                                    @foreach ($cellDay['chips']->take(3) as $chip)
                                        <span wire:key="chip-{{ $chip->id }}" class="flex min-w-0 items-center gap-1 rounded-md px-1 py-0.5 text-[11px] text-zinc-700 dark:text-zinc-200"
                                              style="background: {{ \App\Models\ScheduleActivity::hexFor($chip->activity?->color) }}1c" title="{{ $chip->displayName() }}{{ $chip->detail ? ' · '.$chip->detail : '' }}">
                                            <x-schedule.icon :name="$chip->activity?->icon" :color="$chip->activity?->color" class="size-3.5" />
                                            <span class="truncate max-sm:hidden">{{ $chip->detail ?: $chip->displayName() }}</span>
                                        </span>
                                    @endforeach
                                </span>
                        @if ($cellDay['is_program_day'])
                            </button>
                        @else
                            </div>
                        @endif
                    @endforeach
                    @for ($i = 0; $i < $trailing; $i++)
                        <div wire:key="tail-{{ $i }}" class="min-h-18 border-b border-s border-zinc-100 bg-zinc-50/50 sm:min-h-28 dark:border-zinc-800 dark:bg-zinc-900/40"></div>
                    @endfor
                </div>
            </div>
            <p class="text-xs text-zinc-500">اضغط على أي يوم لعرض برنامجه كاملاً. تظهر هنا الأنشطة المتغيّرة، أما الحلقة والصلوات فثابتة كل يوم.</p>
        @endif
    @endif
</div>
