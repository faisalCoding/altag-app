<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Circle;
use App\Models\Student;
use App\Models\StudentHadithAchievement;
use App\Models\StudentOdeAchievement;
use App\Models\StudentPlanDay;
use App\Services\GamificationService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public $studentId;

    public $studentName;

    /** Key of the entry currently being edited, e.g. "quran:123:hifz". */
    public ?string $editKey = null;

    public ?string $editDate = null;

    /** @var string all|hifz|review */
    public string $partFilter = 'all';

    /** @var string all|quran|ode|hadith */
    public string $typeFilter = 'all';

    /** @var ?string one of "3"|"2"|"1"|"0", or null for no quality filter */
    public ?string $qualityFilter = null;

    public function mount($studentId)
    {
        $this->studentId = $studentId;
        $student = Student::findOrFail($studentId);
        $this->studentName = $student->name;
    }

    public function setPart(string $part): void
    {
        $this->partFilter = in_array($part, ['all', 'hifz', 'review'], true) ? $part : 'all';
    }

    public function setType(string $type): void
    {
        $this->typeFilter = in_array($type, ['all', 'quran', 'ode', 'hadith'], true) ? $type : 'all';
    }

    public function toggleQuality(int $quality): void
    {
        $this->qualityFilter = $this->qualityFilter === (string) $quality ? null : (string) $quality;
    }

    public function clearFilters(): void
    {
        $this->partFilter = 'all';
        $this->typeFilter = 'all';
        $this->qualityFilter = null;
    }

    public function startEdit(string $key, ?string $currentDate): void
    {
        $this->editKey = $key;
        $this->editDate = $currentDate ?: now('Asia/Riyadh')->format('Y-m-d');
    }

    public function cancelEdit(): void
    {
        $this->reset('editKey', 'editDate');
    }

    /**
     * Render a log day the way the academy reads dates: the Arabic weekday and
     * the Umm al-Qura date, with the Gregorian one kept alongside for anyone
     * cross-referencing another system.
     *
     * Returns null for the undated group, whose key is a label rather than a date.
     *
     * @return array{weekday: string, hijri: string, gregorian: string}|null
     */
    public function formatLogDate(string|CarbonInterface|null $date): ?array
    {
        if ($date === null) {
            return null;
        }

        try {
            $carbon = $date instanceof CarbonInterface ? $date : Carbon::parse($date);
        } catch (\Throwable) {
            return null;
        }

        return [
            'weekday' => \App\Support\HijriDate::weekday($carbon),
            'hijri' => \App\Support\HijriDate::full($carbon),
            'gregorian' => $carbon->format('Y-m-d'),
        ];
    }

    /**
     * Move a single grading (hifz/review of a quran/ode/hadith record) to a new
     * date, keeping its original time of day, then re-sync the student's points,
     * competitions and streak — which are all credited by the grading date.
     */
    public function saveGradingDate(): void
    {
        $this->validate(
            ['editDate' => ['required', 'date']],
            ['editDate.required' => __('يرجى اختيار تاريخ.'), 'editDate.date' => __('تاريخ غير صالح.')],
        );

        $this->moveGrading((string) $this->editKey, $this->editDate);

        $this->cancelEdit();
    }

    /**
     * Put a grading back on the day its plan scheduled it for — the common
     * correction when a teacher records a session a day or two late.
     */
    public function matchPlanDate(string $key): void
    {
        $record = $this->resolveRecord($key);
        $planDate = $this->planDateOf($record);

        if (! $planDate) {
            Flux::toast(__('لا يوجد يوم خطة لهذا التقييم.'), variant: 'warning');

            return;
        }

        $this->moveGrading($key, $planDate->format('Y-m-d'));

        // The edit form may be open on this very entry; its date is now stale.
        if ($this->editKey === $key) {
            $this->cancelEdit();
        }
    }

    /**
     * Apply a new grading date, keeping the original time of day, then re-sync
     * the student's points, competitions and streak — which are all credited by
     * the grading date rather than the scheduled one.
     */
    protected function moveGrading(string $key, ?string $date): void
    {
        [$kind, , $part] = array_pad(explode(':', $key), 3, null);
        $field = $part === 'review' ? 'review_graded_at' : 'hifz_graded_at';

        $record = $this->resolveRecord($key);

        if (! $record || $record->{$field} === null || blank($date)) {
            return;
        }

        $student = $record->plan?->student;

        if (! $student || ! $this->teacherOwns($student)) {
            abort(403);
        }

        $original = $record->{$field};
        $record->{$field} = Carbon::parse($date)->setTimeFromTimeString($original->format('H:i:s'));
        $record->save();

        match ($kind) {
            'quran' => GamificationService::syncStudentPlanDayXP($record->fresh('plan.student')),
            'ode' => GamificationService::syncStudentOdeAchievementXP($record->fresh(['plan.student', 'pathDay'])),
            'hadith' => GamificationService::syncStudentHadithAchievementXP($record->fresh(['plan.student', 'pathDay'])),
            default => null,
        };

        Flux::toast(__('تم تحديث تاريخ التقييم وإعادة احتساب النقاط والاستريك.'), variant: 'success');
    }

    /**
     * Resolve an entry key such as "quran:123:hifz" to its underlying record.
     */
    protected function resolveRecord(string $key): mixed
    {
        [$kind, $id] = array_pad(explode(':', $key), 3, null);

        return match ($kind) {
            'quran' => StudentPlanDay::with('plan.student')->find($id),
            'ode' => StudentOdeAchievement::with('plan.student', 'pathDay')->find($id),
            'hadith' => StudentHadithAchievement::with('plan.student', 'pathDay')->find($id),
            default => null,
        };
    }

    /**
     * The day the plan scheduled this record for: a Quran plan day carries its
     * own date, while odes and mutun take theirs from the shared path day.
     */
    protected function planDateOf(mixed $record): ?CarbonInterface
    {
        if (! $record) {
            return null;
        }

        return $record instanceof StudentPlanDay
            ? $record->date
            : $record->pathDay?->date;
    }

    protected function teacherOwns(Student $student): bool
    {
        $teacher = Auth::guard('teacher')->user();

        return $teacher
            && $student->circle_id
            && Circle::where('id', $student->circle_id)
                ->whereHas('teachers', fn ($q) => $q->whereKey($teacher->id))
                ->exists();
    }

    /**
     * @return array{key: string, kind: string, type_label: string, type_color: string, part: string, part_label: string, achievement: int, graded_at: ?CarbonInterface, range: ?string, scheduled: ?CarbonInterface}
     */
    protected function pushParts($entries, string $kind, string $typeLabel, string $typeColor, $record, ?CarbonInterface $scheduled, callable $formatRange): void
    {
        $partLabels = ['hifz' => __('الحفظ'), 'review' => __('المراجعة')];

        foreach (['hifz', 'review'] as $part) {
            if ($record->{$part.'_achievement'} === null) {
                continue;
            }

            $entries->push([
                'key' => "{$kind}:{$record->id}:{$part}",
                'kind' => $kind,
                'type_label' => $typeLabel,
                'type_color' => $typeColor,
                'part' => $part,
                'part_label' => $partLabels[$part],
                'achievement' => (int) $record->{$part.'_achievement'},
                'graded_at' => $record->{$part.'_graded_at'},
                'range' => $formatRange($record, $part),
                'scheduled' => $scheduled,
            ]);
        }
    }

    /** Whether an entry survives the active part/type/quality filters. */
    protected function passesFilters(array $entry): bool
    {
        return ($this->partFilter === 'all' || $entry['part'] === $this->partFilter)
            && ($this->typeFilter === 'all' || $entry['kind'] === $this->typeFilter)
            && ($this->qualityFilter === null || $entry['achievement'] === (int) $this->qualityFilter);
    }

    /**
     * The student's status on a date, resolved from a pre-loaded history so no
     * query runs per day. Mirrors StudentStatusService::statusOn — the latest
     * row starting on or before the date wins; with none, the student is active.
     */
    protected function statusOnDate(string $date, $histories, string $fallback): string
    {
        $row = $histories
            ->filter(fn ($h) => $h->start_date->format('Y-m-d') <= $date)
            ->last();

        return $row ? $row->status : $fallback;
    }

    /**
     * The earliest day the student has a footprint — a plan start, an attendance
     * record or a grading — which bounds how far back the working-day roll goes.
     */
    protected function earliestFootprint(Student $student, $entries): ?string
    {
        $candidates = collect();

        if ($planStart = $student->plans()->min('start_date')) {
            $candidates->push(Carbon::parse($planStart)->format('Y-m-d'));
        }

        if ($firstAttendance = $student->attendances->min('date')) {
            $candidates->push($firstAttendance->format('Y-m-d'));
        }

        $firstGrading = $entries
            ->map(fn ($e) => optional($e['graded_at'] ?? $e['scheduled'])->format('Y-m-d'))
            ->filter()
            ->min();

        if ($firstGrading) {
            $candidates->push($firstGrading);
        }

        return $candidates->min();
    }

    /**
     * Totals, quality distribution and date span for a set of entries. Computed
     * in memory over the already-loaded collection — no extra queries.
     *
     * @return array{total:int, hifz:int, review:int, distribution:array<int,int>, earliest:?CarbonInterface, latest:?CarbonInterface}
     */
    protected function buildStats($scoped): array
    {
        $distribution = [3 => 0, 2 => 0, 1 => 0, 0 => 0];

        foreach ($scoped as $entry) {
            $distribution[$entry['achievement']] = ($distribution[$entry['achievement']] ?? 0) + 1;
        }

        $timestamps = $scoped
            ->map(fn ($entry) => optional($entry['graded_at'] ?? $entry['scheduled'])->getTimestamp())
            ->filter()
            ->values();

        return [
            'total' => $scoped->count(),
            'hifz' => $scoped->where('part', 'hifz')->count(),
            'review' => $scoped->where('part', 'review')->count(),
            'distribution' => $distribution,
            'earliest' => $timestamps->isNotEmpty() ? Carbon::createFromTimestamp($timestamps->min()) : null,
            'latest' => $timestamps->isNotEmpty() ? Carbon::createFromTimestamp($timestamps->max()) : null,
        ];
    }

    public function with()
    {
        $student = Student::with('circle')->findOrFail($this->studentId);

        $entries = collect();

        StudentPlanDay::with('plan', 'fromAyah.surah', 'toAyah.surah', 'reviewFromAyah.surah', 'reviewToAyah.surah')
            ->whereHas('plan', fn ($q) => $q->where('student_id', $this->studentId))
            ->where(fn ($q) => $q->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement'))
            ->get()
            ->each(fn ($day) => $this->pushParts($entries, 'quran', __('قرآن'), 'indigo', $day, $day->date, fn ($r, $p) => $r->formatRange($p, false)));

        StudentOdeAchievement::with('plan', 'pathDay')
            ->whereHas('plan', fn ($q) => $q->where('student_id', $this->studentId))
            ->where(fn ($q) => $q->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement'))
            ->get()
            ->each(fn ($ach) => $this->pushParts($entries, 'ode', __('منظومة'), 'purple', $ach, $ach->pathDay?->date, fn ($r, $p) => $r->formatOdeRange($p)));

        StudentHadithAchievement::with('plan', 'pathDay')
            ->whereHas('plan', fn ($q) => $q->where('student_id', $this->studentId))
            ->where(fn ($q) => $q->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement'))
            ->get()
            ->each(fn ($ach) => $this->pushParts($entries, 'hadith', __('متن'), 'teal', $ach, $ach->pathDay?->date, fn ($r, $p) => $r->formatHadithRange($p)));

        // Graded entries keyed by the day they are credited to (grading date).
        $entriesByDate = $entries->groupBy(fn ($e) => optional($e['graded_at'] ?? $e['scheduled'])->format('Y-m-d') ?? '__undated__');

        // Attendance status per day.
        $attendanceByDate = $student->attendances
            ->keyBy(fn ($a) => $a->date->format('Y-m-d'))
            ->map(fn ($a) => $a->status);

        // Every working day the student was active, from their first footprint to
        // today — so days attended without reciting, or missed entirely, still show.
        $histories = $student->statusHistories()->orderBy('start_date')->orderBy('id')->get();
        // Days before any recorded status change count as active — the same rule
        // Attendance::activeStatusOnDateSql applies, so a now-"left" student's
        // earlier active days are still enumerated.
        $fallbackStatus = 'active';
        $from = $this->earliestFootprint($student, $entries);
        $today = now('Asia/Riyadh')->format('Y-m-d');

        $workingActiveDays = collect();
        if ($from) {
            foreach (AcademicCalendarEvent::workingDaysBetween($from, $today, $student->effective_stage_id) as $day) {
                if ($this->statusOnDate($day, $histories, $fallbackStatus) === 'active') {
                    $workingActiveDays->push($day);
                }
            }
        }

        // The full set of dated days, newest first: active working days unioned
        // with any day that actually carries a grading or an attendance record.
        $datedKeys = $entriesByDate->keys()
            ->reject(fn ($k) => $k === '__undated__')
            ->merge($attendanceByDate->keys())
            ->merge($workingActiveDays)
            ->unique()
            ->sortDesc()
            ->values();

        // Every day stays visible; a filter only narrows which gradings show
        // inside it, so a day whose gradings are all filtered out reads as empty.
        $days = $datedKeys->map(function ($date) use ($entriesByDate, $attendanceByDate) {
            $all = $entriesByDate->get($date) ?? collect();

            return [
                'date' => $date,
                'attendance' => $attendanceByDate->get($date),
                'entries' => $all->filter(fn ($e) => $this->passesFilters($e))->values(),
                'hadEntries' => $all->isNotEmpty(),
            ];
        });

        // Rare bucket: gradings with no date at all.
        $undated = ($entriesByDate->get('__undated__') ?? collect())
            ->filter(fn ($e) => $this->passesFilters($e))
            ->values();

        // Stats: gradings scoped by part+type, attendance across the record.
        $scoped = $entries->filter(fn ($e) => ($this->partFilter === 'all' || $e['part'] === $this->partFilter)
            && ($this->typeFilter === 'all' || $e['kind'] === $this->typeFilter));

        $stats = $this->buildStats($scoped);
        $stats['attendance'] = [
            'present' => $student->attendances->where('status', 'present')->count(),
            'late' => $student->attendances->where('status', 'late')->count(),
            'absent' => $student->attendances->where('status', 'absent')->count(),
            'excused' => $student->attendances->where('status', 'excused')->count(),
        ];

        return [
            'days' => $days,
            'undated' => $undated,
            'stats' => $stats,
            'kindsPresent' => $entries->pluck('kind')->unique()->values(),
            'hasEntries' => $entries->isNotEmpty(),
            'hasRows' => $days->isNotEmpty() || $undated->isNotEmpty(),
        ];
    }
};
?>

@php
    $gradeLabels = [
        3 => ['label' => __('ممتاز'), 'color' => 'green'],
        2 => ['label' => __('جيد'), 'color' => 'blue'],
        1 => ['label' => __('مقبول'), 'color' => 'amber'],
        0 => ['label' => __('لم يُسمع'), 'color' => 'red'],
    ];
    $attendanceMeta = [
        'present' => ['label' => __('حاضر'), 'color' => 'green'],
        'late' => ['label' => __('متأخر'), 'color' => 'amber'],
        'absent' => ['label' => __('غائب'), 'color' => 'red'],
        'excused' => ['label' => __('غياب بعذر'), 'color' => 'blue'],
    ];
    // Full class strings — Tailwind can't detect names built at runtime.
    $qualityPill = [
        'green' => 'border-green-200 text-green-700 dark:border-green-800/70 dark:text-green-400',
        'blue' => 'border-blue-200 text-blue-700 dark:border-blue-800/70 dark:text-blue-400',
        'amber' => 'border-amber-200 text-amber-700 dark:border-amber-800/70 dark:text-amber-400',
        'red' => 'border-red-200 text-red-700 dark:border-red-800/70 dark:text-red-400',
    ];
    $qualityActive = [
        'green' => 'bg-green-50 ring-2 ring-green-400/60 dark:bg-green-500/10',
        'blue' => 'bg-blue-50 ring-2 ring-blue-400/60 dark:bg-blue-500/10',
        'amber' => 'bg-amber-50 ring-2 ring-amber-400/60 dark:bg-amber-500/10',
        'red' => 'bg-red-50 ring-2 ring-red-400/60 dark:bg-red-500/10',
    ];
    $accentBar = [
        'green' => 'bg-green-400 dark:bg-green-500',
        'blue' => 'bg-blue-400 dark:bg-blue-500',
        'amber' => 'bg-amber-400 dark:bg-amber-500',
        'red' => 'bg-red-400 dark:bg-red-500',
        'zinc' => 'bg-zinc-300 dark:bg-zinc-600',
    ];
    $typeOptions = ['all' => __('الكل'), 'quran' => __('قرآن'), 'ode' => __('منظومة'), 'hadith' => __('متن')];
    $filtersActive = $partFilter !== 'all' || $typeFilter !== 'all' || $qualityFilter !== null;
@endphp

<div>
    {{-- Header --}}
    <div class="flex items-start justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl" level="1">{{ __('سجل التسميع الفعلي') }}</flux:heading>
            <flux:subheading>{{ __('كل ما تم تسميعه للطالب') }} <span class="font-bold">{{ $studentName }}</span> {{ __('مع الحضور وتاريخ التقييم — يمكنك تعديل التاريخ ليُحتسب في اليوم الصحيح.') }}</flux:subheading>
        </div>
        <flux:button href="{{ route('teacher.students') }}" icon="arrow-right" variant="ghost" class="shrink-0">{{ __('العودة للطلاب') }}</flux:button>
    </div>

    @if ($hasRows || $hasEntries)
        {{-- Summary tiles --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
            <div class="rounded-2xl border border-indigo-100 dark:border-indigo-500/20 bg-indigo-50/60 dark:bg-indigo-500/10 p-4 flex items-center gap-3">
                <div class="p-2.5 rounded-xl bg-indigo-600/10 text-indigo-600 dark:text-indigo-400 shrink-0"><flux:icon.chart-bar class="size-5" /></div>
                <div class="min-w-0">
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white leading-none">{{ $stats['total'] }}</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-1 truncate">{{ __('إجمالي التقييمات') }}</div>
                </div>
            </div>
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 flex items-center gap-3 shadow-xs">
                <div class="p-2.5 rounded-xl bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 shrink-0"><flux:icon.book-open class="size-5" /></div>
                <div class="min-w-0">
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white leading-none">{{ $stats['hifz'] }}</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-1 truncate">{{ __('الحفظ') }}</div>
                </div>
            </div>
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 flex items-center gap-3 shadow-xs">
                <div class="p-2.5 rounded-xl bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 shrink-0"><flux:icon.arrow-path class="size-5" /></div>
                <div class="min-w-0">
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white leading-none">{{ $stats['review'] }}</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-1 truncate">{{ __('المراجعة') }}</div>
                </div>
            </div>
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 flex items-center gap-3 shadow-xs">
                <div class="p-2.5 rounded-xl bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 shrink-0"><flux:icon.calendar-days class="size-5" /></div>
                <div class="min-w-0">
                    @if($stats['earliest'])
                        <div class="text-sm font-bold text-zinc-900 dark:text-white leading-tight" dir="ltr">{{ $stats['earliest']->format('Y-m-d') }}</div>
                        @if($stats['latest'] && $stats['latest']->format('Y-m-d') !== $stats['earliest']->format('Y-m-d'))
                            <div class="text-[11px] text-zinc-400 dark:text-zinc-500" dir="ltr">← {{ $stats['latest']->format('Y-m-d') }}</div>
                        @endif
                    @else
                        <div class="text-sm font-bold text-zinc-300 dark:text-zinc-600">—</div>
                    @endif
                    <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5 truncate">{{ __('الفترة') }}</div>
                </div>
            </div>
        </div>

        {{-- Attendance summary --}}
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <span class="text-xs font-medium text-zinc-400 dark:text-zinc-500">{{ __('الحضور:') }}</span>
            @foreach(['present', 'late', 'absent', 'excused'] as $st)
                @php $m = $attendanceMeta[$st]; @endphp
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-xs font-semibold {{ $qualityPill[$m['color']] }} bg-white dark:bg-zinc-900">
                    <span class="size-2 rounded-full {{ $accentBar[$m['color']] }}"></span>
                    {{ $m['label'] }}
                    <span class="text-zinc-400 dark:text-zinc-500">{{ $stats['attendance'][$st] ?? 0 }}</span>
                </span>
            @endforeach
        </div>

        {{-- Filters --}}
        <div class="flex flex-wrap items-center gap-3 mb-6">
            <div class="inline-flex rounded-xl bg-zinc-100 dark:bg-zinc-800 p-1">
                @foreach(['all' => __('الكل'), 'hifz' => __('حفظ'), 'review' => __('مراجعة')] as $value => $label)
                    <button type="button" wire:click="setPart('{{ $value }}')"
                        class="px-4 py-1.5 rounded-lg text-sm font-semibold transition-colors {{ $partFilter === $value ? 'bg-white dark:bg-zinc-950 shadow-sm text-indigo-600 dark:text-indigo-400' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if($kindsPresent->count() > 1)
                <div class="inline-flex rounded-xl bg-zinc-100 dark:bg-zinc-800 p-1">
                    @foreach(['all', 'quran', 'ode', 'hadith'] as $value)
                        @if($value === 'all' || $kindsPresent->contains($value))
                            <button type="button" wire:click="setType('{{ $value }}')"
                                class="px-3.5 py-1.5 rounded-lg text-sm font-semibold transition-colors {{ $typeFilter === $value ? 'bg-white dark:bg-zinc-950 shadow-sm text-indigo-600 dark:text-indigo-400' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
                                {{ $typeOptions[$value] }}
                            </button>
                        @endif
                    @endforeach
                </div>
            @endif

            <div class="h-6 w-px bg-zinc-200 dark:bg-zinc-700 hidden sm:block"></div>

            <div class="flex flex-wrap items-center gap-2">
                @foreach([3, 2, 1, 0] as $q)
                    @php
                        $meta = $gradeLabels[$q];
                        $count = $stats['distribution'][$q] ?? 0;
                        $active = $qualityFilter === (string) $q;
                    @endphp
                    <button type="button" wire:click="toggleQuality({{ $q }})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border text-xs font-semibold transition {{ $qualityPill[$meta['color']] }} {{ $active ? $qualityActive[$meta['color']] : 'bg-white dark:bg-zinc-900 hover:bg-zinc-50 dark:hover:bg-zinc-800' }}">
                        <span class="size-2 rounded-full {{ $accentBar[$meta['color']] }}"></span>
                        {{ $meta['label'] }}
                        <span class="text-zinc-400 dark:text-zinc-500">{{ $count }}</span>
                    </button>
                @endforeach
            </div>

            @if($filtersActive)
                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('مسح الفلاتر') }}</flux:button>
            @endif
        </div>
    @endif

    <div class="space-y-3">
        @forelse ($days as $day)
            @php
                $logDate = $this->formatLogDate($day['date']);
                $att = $day['attendance'] ? ($attendanceMeta[$day['attendance']] ?? null) : null;
                $hasTasmee = $day['entries']->isNotEmpty();
            @endphp

            @if ($hasTasmee)
                {{-- A day with recitations: full card --}}
                <flux:card class="p-0 overflow-hidden shadow-sm">
                    <div class="bg-zinc-50/80 dark:bg-zinc-900/50 px-5 py-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 rounded-xl">
                                <flux:icon.calendar size="sm" />
                            </div>
                            <div class="flex flex-col">
                                @if ($logDate)
                                    <span class="font-bold text-zinc-800 dark:text-zinc-100">
                                        {{ $logDate['weekday'] }}، {{ $logDate['hijri'] }} هـ
                                    </span>
                                    <span class="text-xs text-zinc-400 dark:text-zinc-500" dir="ltr">{{ $logDate['gregorian'] }}</span>
                                @else
                                    <span class="font-bold text-zinc-800 dark:text-zinc-100">{{ $day['date'] }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            @if ($att)
                                <flux:badge size="sm" variant="subtle" color="{{ $att['color'] }}">{{ $att['label'] }}</flux:badge>
                            @endif
                            <flux:badge size="sm" variant="subtle" color="indigo">{{ $day['entries']->count() }} {{ __('تقييمات') }}</flux:badge>
                        </div>
                    </div>

                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($day['entries'] as $entry)
                            @php
                                $g = $gradeLabels[$entry['achievement']] ?? ['label' => __('غير معروف'), 'color' => 'zinc'];
                            @endphp
                            <div class="flex items-stretch gap-3 p-5" wire:key="entry-{{ $entry['key'] }}">
                                <div class="w-1 rounded-full shrink-0 {{ $accentBar[$g['color']] ?? $accentBar['zinc'] }}"></div>
                                <div class="flex-1 flex flex-col gap-3 min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:badge color="{{ $entry['type_color'] }}" size="sm">{{ $entry['type_label'] }}</flux:badge>
                                        <span class="text-xs font-medium text-zinc-400 dark:text-zinc-500">{{ $entry['part_label'] }}</span>
                                        <flux:badge color="{{ $g['color'] }}" size="sm" variant="subtle">{{ $g['label'] }}</flux:badge>
                                        @if($entry['graded_at'])
                                            <span class="text-[10px] text-zinc-400" dir="ltr">{{ $entry['graded_at']->format('h:i A') }}</span>
                                        @endif
                                    </div>

                                    <span class="font-semibold text-sm text-zinc-800 dark:text-zinc-200">
                                        {{ $entry['range'] ?? __('لا يوجد مقرر') }}
                                    </span>

                                    @php
                                        $planDate = $this->formatLogDate($entry['scheduled'] ?? null);
                                    @endphp
                                    @if ($planDate)
                                        <div class="flex flex-wrap items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                            <flux:icon.calendar-days class="size-3.5 shrink-0" />
                                            <span>{{ __('يوم الخطة:') }}</span>
                                            <span class="font-medium text-zinc-600 dark:text-zinc-300">
                                                {{ $planDate['weekday'] }}، {{ $planDate['hijri'] }} هـ
                                            </span>
                                            <span class="text-zinc-400 dark:text-zinc-500" dir="ltr">({{ $planDate['gregorian'] }})</span>
                                            {{-- The grading may have been credited to a different day than the plan scheduled. --}}
                                            @if ($entry['graded_at'] && $planDate['gregorian'] !== $entry['graded_at']->format('Y-m-d'))
                                                <flux:badge size="sm" variant="subtle" color="amber">{{ __('قُيّم في يوم آخر') }}</flux:badge>
                                                <flux:button
                                                    wire:click="matchPlanDate('{{ $entry['key'] }}')"
                                                    wire:confirm="{{ __('نقل هذا التقييم إلى يوم الخطة؟ ستُعاد احتساب النقاط والاستريك.') }}"
                                                    size="xs" variant="ghost" icon="arrow-uturn-right">
                                                    {{ __('إرجاعه ليوم الخطة') }}
                                                </flux:button>
                                            @endif
                                        </div>
                                    @endif

                                    @if($editKey === $entry['key'])
                                        <div class="flex flex-col gap-2 pt-1">
                                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('اختر تاريخ التسميع (هجري) — أيام الدوام مميّزة') }}</span>
                                            <div class="max-w-xs">
                                                <livewire:shared.hijri-datepicker wire:model.live="editDate" :show-attendance-days="true" label="" :key="'editdate-'.$entry['key']" />
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <flux:button wire:click="saveGradingDate" size="sm" variant="primary" icon="check">{{ __('حفظ') }}</flux:button>
                                                <flux:button wire:click="cancelEdit" size="sm" variant="ghost">{{ __('إلغاء') }}</flux:button>
                                            </div>
                                            <flux:error name="editDate" />
                                        </div>
                                    @else
                                        <flux:button
                                            wire:click="startEdit('{{ $entry['key'] }}', '{{ optional($entry['graded_at'])->format('Y-m-d') }}')"
                                            size="xs" variant="ghost" icon="pencil-square" class="self-start">
                                            {{ __('تعديل تاريخ التقييم') }}
                                        </flux:button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </flux:card>
            @else
                {{-- A working day with no recitation: compact row showing attendance --}}
                <div class="flex items-center justify-between gap-3 rounded-xl border border-zinc-100 dark:border-zinc-800/70 bg-zinc-50/50 dark:bg-zinc-900/30 px-4 py-2.5" wire:key="empty-{{ $day['date'] }}">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <flux:icon.minus-circle class="size-4 text-zinc-300 dark:text-zinc-600 shrink-0" />
                        <div class="flex flex-wrap items-baseline gap-x-2 min-w-0">
                            @if ($logDate)
                                <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ $logDate['weekday'] }}، {{ $logDate['hijri'] }} هـ</span>
                                <span class="text-[11px] text-zinc-400" dir="ltr">{{ $logDate['gregorian'] }}</span>
                            @else
                                <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ $day['date'] }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        @if ($att)
                            <flux:badge size="sm" variant="subtle" color="{{ $att['color'] }}">{{ $att['label'] }}</flux:badge>
                        @else
                            <span class="text-[11px] text-zinc-400 dark:text-zinc-500">{{ __('لم يُسجّل حضور') }}</span>
                        @endif
                        <span class="text-[11px] text-zinc-400 dark:text-zinc-500">{{ ($filtersActive && $day['hadEntries']) ? __('لا يوجد ضمن الفلتر') : __('لا تسميع') }}</span>
                    </div>
                </div>
            @endif
        @empty
            <flux:card class="py-12 text-center text-zinc-500">
                <div class="flex flex-col items-center justify-center gap-3">
                    <div class="p-3 bg-zinc-50 dark:bg-zinc-950 text-zinc-400 rounded-full">
                        <flux:icon.calendar size="lg" />
                    </div>
                    <span>{{ __('لم يقم الطالب بأي تسميع حتى الآن.') }}</span>
                </div>
            </flux:card>
        @endforelse

        {{-- Undated gradings, if any --}}
        @if ($undated->isNotEmpty())
            <flux:card class="p-0 overflow-hidden shadow-sm">
                <div class="bg-zinc-50/80 dark:bg-zinc-900/50 px-5 py-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center justify-between">
                    <span class="font-bold text-zinc-800 dark:text-zinc-100">{{ __('غير مؤرّخ') }}</span>
                    <flux:badge size="sm" variant="subtle" color="indigo">{{ $undated->count() }} {{ __('تقييمات') }}</flux:badge>
                </div>
                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($undated as $entry)
                        @php
                            $g = $gradeLabels[$entry['achievement']] ?? ['label' => __('غير معروف'), 'color' => 'zinc'];
                        @endphp
                        <div class="flex items-stretch gap-3 p-5" wire:key="entry-{{ $entry['key'] }}">
                            <div class="w-1 rounded-full shrink-0 {{ $accentBar[$g['color']] ?? $accentBar['zinc'] }}"></div>
                            <div class="flex-1 flex flex-col gap-3 min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:badge color="{{ $entry['type_color'] }}" size="sm">{{ $entry['type_label'] }}</flux:badge>
                                    <span class="text-xs font-medium text-zinc-400 dark:text-zinc-500">{{ $entry['part_label'] }}</span>
                                    <flux:badge color="{{ $g['color'] }}" size="sm" variant="subtle">{{ $g['label'] }}</flux:badge>
                                </div>
                                <span class="font-semibold text-sm text-zinc-800 dark:text-zinc-200">
                                    {{ $entry['range'] ?? __('لا يوجد مقرر') }}
                                </span>
                                <flux:button
                                    wire:click="startEdit('{{ $entry['key'] }}', '')"
                                    size="xs" variant="ghost" icon="pencil-square" class="self-start">
                                    {{ __('تعديل تاريخ التقييم') }}
                                </flux:button>
                                @if($editKey === $entry['key'])
                                    <div class="flex flex-col gap-2 pt-1">
                                        <div class="max-w-xs">
                                            <livewire:shared.hijri-datepicker wire:model.live="editDate" :show-attendance-days="true" label="" :key="'editdate-'.$entry['key']" />
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <flux:button wire:click="saveGradingDate" size="sm" variant="primary" icon="check">{{ __('حفظ') }}</flux:button>
                                            <flux:button wire:click="cancelEdit" size="sm" variant="ghost">{{ __('إلغاء') }}</flux:button>
                                        </div>
                                        <flux:error name="editDate" />
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endif
    </div>
</div>
