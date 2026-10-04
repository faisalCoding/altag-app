<?php

namespace App\Http\Resources\V1;

use App\Models\StudentPlanDay;
use App\Services\TasmeehSnapshot;
use App\Support\AyahIndex;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One day of a plan with the portion it sets for each part, written as surah
 * and verse numbers. A part the plan does not have, or whose portion is
 * missing an end, is null.
 *
 * @mixin StudentPlanDay
 */
class SyncTasmeehDayResource extends JsonResource
{
    /**
     * @param  array<int, string>  $parts  The parts of the day's plan.
     */
    public function __construct(StudentPlanDay $day, private readonly int $position, private readonly array $parts)
    {
        parent::__construct($day);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $range = fn (string $part) => in_array($part, $this->parts, true)
            ? AyahIndex::presentRange(...TasmeehSnapshot::scheduledAyahIds($this->resource, $part))
            : null;

        return [
            'id' => $this->id,
            'plan_id' => $this->student_plan_id,
            'position' => $this->position,
            'date' => $this->date->toDateString(),
            'hifz' => $range('hifz'),
            'review' => $range('review'),
        ];
    }
}
