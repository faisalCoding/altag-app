<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AcademicCalendarEvent extends Model
{
    /**
     * The ordinary week when the calendar holds no period for a stage at all.
     */
    private const DEFAULT_WEEKDAYS = [1, 2, 3, 4, 5]; // Sunday to Thursday.

    /**
     * Where the loaded periods are kept for the rest of the request. The
     * container rather than a static, so a fresh request — and a fresh test —
     * starts with nothing remembered.
     */
    private const CACHE_KEY = 'academic_attendance_periods';

    /**
     * Whether the circles of a stage meet on a date.
     *
     * The period's weekdays say which days of the week the circles meet; a
     * period with none set covers every day it spans. On top of that the
     * calendar may name single dates: an extra day the circles meet outside
     * their usual week, and an excluded day they do not meet despite it. An
     * exclusion always wins — it is the manager naming that exact date.
     *
     * A period bound to stages speaks only for them, so a stage with no period
     * of its own falls back to the academy's ordinary Sunday-to-Thursday week
     * rather than losing its calendar entirely.
     *
     * This is the one definition of a working day. Plans are laid out on them,
     * streak badges count runs of them, and the circle report measures
     * attendance against them.
     */
    public static function isWorkingDay(Carbon|string $date, ?int $stageId = null): bool
    {
        $day = Carbon::parse($date)->format('Y-m-d');
        $weekday = Carbon::parse($date)->dayOfWeek + 1; // 1=Sunday … 7=Saturday
        $periods = self::periodsForStage($stageId);

        if ($periods->contains(fn (array $period) => in_array($day, $period['excluded_dates'], true))) {
            return false;
        }

        if ($periods->contains(fn (array $period) => in_array($day, $period['extra_dates'], true))) {
            return true;
        }

        if ($periods->isEmpty()) {
            return in_array($weekday, self::DEFAULT_WEEKDAYS, true);
        }

        return $periods->contains(fn (array $period) => $day >= $period['start']
            && (! $period['end'] || $day <= $period['end'])
            && ($period['weekdays'] === [] || in_array($weekday, $period['weekdays'], true)));
    }

    /**
     * The working days of a stage between two dates, as Y-m-d strings in order.
     *
     * @return array<int, string>
     */
    public static function workingDaysBetween(Carbon|string $from, Carbon|string $to, ?int $stageId = null): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        if ($start->gt($end)) {
            return [];
        }

        $days = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            if (self::isWorkingDay($day, $stageId)) {
                $days[] = $day->format('Y-m-d');
            }
        }

        return $days;
    }

    /**
     * Whether a plan may put one of its days on this date.
     *
     * Between terms the calendar holds no period, and a plan laid out across
     * the gap has always been allowed to run straight through it — that stays.
     * What does not is a date the manager named as excluded: a closure refuses
     * the day wherever it falls, inside a period or between two.
     */
    public static function isSchedulable(Carbon|string $date, ?int $stageId = null): bool
    {
        $day = Carbon::parse($date)->format('Y-m-d');
        $periods = self::periodsForStage($stageId);

        if ($periods->contains(fn (array $period) => in_array($day, $period['excluded_dates'], true))) {
            return false;
        }

        $covered = $periods->contains(fn (array $period) => ($day >= $period['start'] && (! $period['end'] || $day <= $period['end']))
            || in_array($day, $period['extra_dates'], true));

        return $covered ? self::isWorkingDay($day, $stageId) : true;
    }

    /**
     * The working times of the stage on a date, as stored on its period.
     *
     * @return array<int, array{from: string, to: string, label: string|null}>
     */
    public static function sessionsOn(Carbon|string $date, ?int $stageId = null): array
    {
        if (! self::isWorkingDay($date, $stageId)) {
            return [];
        }

        $day = Carbon::parse($date)->format('Y-m-d');

        return self::periodsForStage($stageId)
            ->filter(fn (array $period) => $period['sessions'] !== []
                && ($day >= $period['start'] || in_array($day, $period['extra_dates'], true))
                && (! $period['end'] || $day <= $period['end'] || in_array($day, $period['extra_dates'], true)))
            ->flatMap(fn (array $period) => $period['sessions'])
            ->values()
            ->all();
    }

    /**
     * The attendance period a stage is in on a date: the one covering it, else
     * the latest that started before it — the term just ended, while the
     * calendar has nothing newer. Null when no period speaks for the stage.
     *
     * Where periods overlap on the date, the stage's own wins over an
     * academy-wide one, and among those the one that started last.
     *
     * @return array{start: string, end: string|null}|null
     */
    public static function attendancePeriodOn(Carbon|string $date, ?int $stageId = null): ?array
    {
        $day = Carbon::parse($date)->format('Y-m-d');

        $started = self::periodsForStage($stageId)->filter(fn (array $period) => $period['start'] <= $day);

        $period = $started
            ->filter(fn (array $period) => ! $period['end'] || $day <= $period['end'])
            ->sortBy([
                fn (array $a, array $b) => ($b['stage_ids'] !== []) <=> ($a['stage_ids'] !== []),
                fn (array $a, array $b) => $b['start'] <=> $a['start'],
            ])
            ->first() ?? $started->sortByDesc('start')->first();

        return $period === null ? null : ['start' => $period['start'], 'end' => $period['end']];
    }

    /**
     * Forget the loaded periods, so the next question reads them again. Call
     * after saving or deleting a period within the same request.
     */
    public static function forgetPeriodCache(): void
    {
        app()->forgetInstance(self::CACHE_KEY);
    }

    /**
     * The attendance periods that speak for any of the stages, as the working
     * days are read from them: for the teacher app, which lays plans out on
     * days to come with no connection.
     *
     * @param  array<int, int>  $stageIds
     * @return list<array{name: string, start: string, end: string|null, weekdays: list<int>, stage_ids: list<int>, extra_dates: list<string>, excluded_dates: list<string>}>
     */
    public static function periodsFor(array $stageIds): array
    {
        return self::allPeriods()
            ->filter(fn (array $period) => $period['stage_ids'] === [] || array_intersect($stageIds, $period['stage_ids']) !== [])
            ->map(fn (array $period) => collect($period)->except('sessions')->all())
            ->values()
            ->all();
    }

    /**
     * The attendance periods that speak for a stage: those bound to it, plus
     * the academy-wide ones that name no stage at all.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private static function periodsForStage(?int $stageId)
    {
        return self::allPeriods()->filter(fn (array $period) => $period['stage_ids'] === []
            || ($stageId !== null && in_array($stageId, $period['stage_ids'], true)));
    }

    /**
     * Every attendance period, read once a request.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private static function allPeriods(): Collection
    {
        if (! app()->bound(self::CACHE_KEY)) {
            app()->instance(self::CACHE_KEY, static::where('is_attendance_period', true)
                ->get()
                ->map(fn (self $event) => [
                    'name' => $event->event_name,
                    'start' => Carbon::parse($event->start_date)->format('Y-m-d'),
                    'end' => $event->end_date ? Carbon::parse($event->end_date)->format('Y-m-d') : null,
                    // Stored weekdays mix integers and strings, so compare as integers.
                    'weekdays' => array_map('intval', $event->weekdays ?? []),
                    'stage_ids' => array_map('intval', $event->stage_ids ?? []),
                    'extra_dates' => $event->extra_dates ?? [],
                    'excluded_dates' => $event->excluded_dates ?? [],
                    'sessions' => $event->sessions ?? [],
                ])
                ->values());
        }

        return app(self::CACHE_KEY);
    }

    protected $fillable = [
        'event_name',
        'start_date',
        'end_date',
        'color',
        'is_attendance_period',
        'weekdays',
        'stage_ids',
        'extra_dates',
        'excluded_dates',
        'sessions',
        'description',
        'day_count',
        'created_by_id',
        'created_by_type',
        'shared_with',
        'is_visible',
        'has_tasks',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_attendance_period' => 'boolean',
        'weekdays' => 'array',
        'stage_ids' => 'array',
        'extra_dates' => 'array',
        'excluded_dates' => 'array',
        'sessions' => 'array',
        'day_count' => 'integer',
        'shared_with' => 'array',
        'is_visible' => 'boolean',
        'has_tasks' => 'boolean',
    ];

    /**
     * Narrow to the periods that speak for a stage: those naming it, and the
     * academy-wide ones that name no stage at all.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForStage($query, ?int $stageId)
    {
        return $query->where(function ($q) use ($stageId) {
            $q->whereNull('stage_ids')->orWhereJsonLength('stage_ids', 0);

            if ($stageId !== null) {
                $q->orWhereJsonContains('stage_ids', $stageId);
            }
        });
    }

    /**
     * The names of the stages this period speaks for, for display. An empty
     * stage list means the whole academy.
     *
     * @return Collection<int, string>
     */
    public function stageNames()
    {
        return Stage::whereIn('id', $this->stage_ids ?? [])->orderBy('name')->pluck('name');
    }

    public function createdBy()
    {
        return $this->morphTo();
    }

    public function tasks()
    {
        return $this->belongsToMany(Task::class, 'academic_calendar_event_task');
    }

    public function taskCategories()
    {
        return $this->hasMany(TaskCategory::class, 'event_id');
    }

    public function scopeVisibleTo($query, $user)
    {
        $userType = get_class($user);

        return $query->where(function ($q) use ($user, $userType) {
            // 1. Is Creator
            $q->where(function ($sq) use ($user, $userType) {
                $sq->where('created_by_id', $user->id)
                    ->where('created_by_type', $userType);
            })
            // 2. Or is explicitly shared with them
                ->orWhere(function ($sq) use ($user, $userType) {
                    $sq->whereNotNull('shared_with')
                        ->where(function ($jsonQuery) use ($user, $userType) {
                            if ($userType === Teacher::class) {
                                $jsonQuery->whereJsonContains('shared_with->all_teachers', true)
                                    ->orWhereJsonContains('shared_with->teacher_ids', $user->id);

                                if ($user->relationLoaded('circles') || $user->circles()->exists()) {
                                    foreach ($user->circles->pluck('id') as $cId) {
                                        $jsonQuery->orWhereJsonContains('shared_with->circle_ids', $cId);
                                    }
                                    foreach ($user->circles->pluck('stage_id')->unique() as $sId) {
                                        $jsonQuery->orWhereJsonContains('shared_with->stage_ids_for_teachers', $sId);
                                    }
                                }
                            } elseif ($userType === Supervisor::class) {
                                $jsonQuery->whereJsonContains('shared_with->all_supervisors', true)
                                    ->orWhereJsonContains('shared_with->supervisor_ids', $user->id);

                                if ($user->relationLoaded('stages') || $user->stages()->exists()) {
                                    foreach ($user->stages->pluck('id') as $sId) {
                                        $jsonQuery->orWhereJsonContains('shared_with->stage_ids_for_supervisors', $sId);
                                    }
                                }
                            } elseif ($userType === Student::class) {
                                $jsonQuery->whereJsonContains('shared_with->all_students', true)
                                    ->orWhereJsonContains('shared_with->student_ids', $user->id);
                                if ($user->circle_id) {
                                    $jsonQuery->orWhereJsonContains('shared_with->circle_ids', $user->circle_id);
                                }
                                $effectiveStageId = $user->circle?->stage_id ?? $user->stage_id;
                                if ($effectiveStageId) {
                                    $jsonQuery->orWhereJsonContains('shared_with->stage_ids_for_students', $effectiveStageId);
                                }
                            } elseif ($userType === Manager::class) {
                                $jsonQuery->whereJsonContains('shared_with->all_managers', true)
                                    ->orWhereJsonContains('shared_with->manager_ids', $user->id);
                            }
                        });
                });
        })->where('is_visible', true);
    }
}
