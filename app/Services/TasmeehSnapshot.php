<?php

namespace App\Services;

use App\Http\Resources\V1\SyncFreeRecitationResource;
use App\Http\Resources\V1\SyncTasmeehCellResource;
use App\Http\Resources\V1\SyncTasmeehDayResource;
use App\Http\Resources\V1\SyncTasmeehPlanResource;
use App\Models\FreeRecitation;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use Illuminate\Support\Collection;

/**
 * The Quran plans the teacher app grades offline: every active, approved hifz
 * and review plan of the teacher's students with all of its days, what has
 * been recorded on them, and the free recitations of the sync window.
 *
 * All days travel rather than a slice around today: which day the phone shows
 * follows the last ayah recited, which may sit anywhere in the plan.
 */
class TasmeehSnapshot
{
    /**
     * The parts each Quran plan type grades.
     *
     * @var array<string, array<int, string>>
     */
    public const PARTS = [
        'hifz' => ['hifz'],
        'review' => ['review'],
        'hifz_review' => ['hifz', 'review'],
    ];

    /**
     * @param  array<int, int>  $studentIds
     * @return array{tasmeeh_plans: array<int, SyncTasmeehPlanResource>, tasmeeh_days: array<int, SyncTasmeehDayResource>, tasmeeh_cells: array<int, SyncTasmeehCellResource>, free_recitations: array<int, SyncFreeRecitationResource>}
     */
    public static function for(array $studentIds, string $from, string $today): array
    {
        $plans = StudentPlan::whereIn('student_id', $studentIds)
            ->where('status', 'active')
            // A plan a student drew up waits for a teacher's approval before
            // the app grades it.
            ->where('is_approved', true)
            ->whereIn('plan_type', array_keys(self::PARTS))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'student_id', 'plan_type', 'created_at']);

        $days = StudentPlanDay::whereIn('student_plan_id', $plans->modelKeys())
            ->with(['hifzRecorder:id,name', 'reviewRecorder:id,name'])
            ->orderBy('student_plan_id')
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->groupBy('student_plan_id');

        // A plan with no days has nothing to grade.
        $plans = $plans->filter(fn (StudentPlan $plan) => $days->has($plan->id));

        $freeRecitations = FreeRecitation::whereIn('student_id', $studentIds)
            ->whereDate('recited_on', '>=', $from)
            ->whereDate('recited_on', '<=', $today)
            ->with('recorder:id,name')
            ->orderBy('id')
            ->get();

        return [
            'tasmeeh_plans' => self::plans($plans),
            'tasmeeh_days' => self::days($plans, $days),
            'tasmeeh_cells' => self::cells($plans, $days),
            'free_recitations' => SyncFreeRecitationResource::collection($freeRecitations)->all(),
        ];
    }

    /**
     * The nothing an app sees while the page is switched off for teachers.
     *
     * @return array{tasmeeh_plans: array{}, tasmeeh_days: array{}, tasmeeh_cells: array{}, free_recitations: array{}}
     */
    public static function empty(): array
    {
        return ['tasmeeh_plans' => [], 'tasmeeh_days' => [], 'tasmeeh_cells' => [], 'free_recitations' => []];
    }

    /**
     * The first and last dates among the plan days a snapshot carries, or null
     * when it carries none. The app shows a whole plan as a table, so the Hijri
     * months sent beside the plans must reach both ends of it; read from the
     * days already loaded, it costs no query of its own.
     *
     * @param  array{tasmeeh_days: array<int, SyncTasmeehDayResource>}  $snapshot
     * @return array{0: string, 1: string}|null
     */
    public static function daySpan(array $snapshot): ?array
    {
        $dates = collect($snapshot['tasmeeh_days'])
            ->map(fn (SyncTasmeehDayResource $day) => $day->resource->date?->toDateString())
            ->filter();

        return $dates->isEmpty() ? null : [$dates->min(), $dates->max()];
    }

    /**
     * The portion a day sets for a part, as ayah ids. Hifz keeps the columns
     * the table started with; review was added beside them.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public static function scheduledAyahIds(StudentPlanDay $day, string $part): array
    {
        $ids = $part === 'hifz'
            ? [$day->from_ayah_id, $day->to_ayah_id]
            : [$day->review_from_ayah_id, $day->review_to_ayah_id];

        return array_map(fn ($id) => $id === null ? null : (int) $id, $ids);
    }

    /**
     * The range actually recited for a part, as ayah ids; null when none was
     * recorded or when it is the day's own portion (a plan edited after the
     * grade can make the two equal).
     *
     * @return array{0: int, 1: int}|null
     */
    public static function recitedAyahIds(StudentPlanDay $day, string $part): ?array
    {
        $from = $day->{"{$part}_recited_from_ayah_id"};
        $to = $day->{"{$part}_recited_to_ayah_id"};

        return self::withoutScheduled($day, $part, $from === null || $to === null ? null : [(int) $from, (int) $to]);
    }

    /**
     * A recited range, or null when it is exactly the day's portion.
     *
     * @param  array{0: int, 1: int}|null  $range
     * @return array{0: int, 1: int}|null
     */
    public static function withoutScheduled(StudentPlanDay $day, string $part, ?array $range): ?array
    {
        return $range === self::scheduledAyahIds($day, $part) ? null : $range;
    }

    /**
     * @param  Collection<int, StudentPlan>  $plans
     * @return array<int, SyncTasmeehPlanResource>
     */
    private static function plans(Collection $plans): array
    {
        return $plans->groupBy('student_id')
            ->flatMap(fn (Collection $own) => $own->values()->map(
                fn (StudentPlan $plan, int $position) => new SyncTasmeehPlanResource($plan, $position),
            ))
            ->sortBy(fn (SyncTasmeehPlanResource $resource) => $resource->resource->id)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, StudentPlan>  $plans
     * @param  Collection<int, Collection<int, StudentPlanDay>>  $days
     * @return array<int, SyncTasmeehDayResource>
     */
    private static function days(Collection $plans, Collection $days): array
    {
        return $plans->sortBy('id')
            ->flatMap(fn (StudentPlan $plan) => $days[$plan->id]->values()->map(
                fn (StudentPlanDay $day, int $position) => new SyncTasmeehDayResource($day, $position, self::PARTS[$plan->plan_type]),
            ))
            ->values()
            ->all();
    }

    /**
     * One cell per part that holds a grade or a recited range.
     *
     * @param  Collection<int, StudentPlan>  $plans
     * @param  Collection<int, Collection<int, StudentPlanDay>>  $days
     * @return array<int, SyncTasmeehCellResource>
     */
    private static function cells(Collection $plans, Collection $days): array
    {
        $cells = [];

        foreach ($plans->sortBy('id') as $plan) {
            foreach ($days[$plan->id] as $day) {
                foreach (self::PARTS[$plan->plan_type] as $part) {
                    if (self::holdsRecord($day, $part)) {
                        $cells[] = new SyncTasmeehCellResource($day, $part);
                    }
                }
            }
        }

        return $cells;
    }

    /**
     * Whether a part of a day carries anything a teacher recorded.
     */
    public static function holdsRecord(StudentPlanDay $day, string $part): bool
    {
        return $day->{"{$part}_achievement"} !== null || self::recitedAyahIds($day, $part) !== null;
    }
}
