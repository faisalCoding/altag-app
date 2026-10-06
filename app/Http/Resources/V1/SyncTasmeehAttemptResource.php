<?php

namespace App\Http\Resources\V1;

use App\Models\PlanDayAttempt;
use App\Services\TasmeehSnapshot;
use App\Support\AyahIndex;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One session a part of a plan day was recited in: the academy's day it was
 * on, the grade it got (0 for «لم يسمع», null when only a range was recorded)
 * and the range recited when it was not the day's portion.
 *
 * Expects the attempt's day loaded, to tell the day's own portion apart.
 *
 * @mixin PlanDayAttempt
 */
class SyncTasmeehAttemptResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $recited = TasmeehSnapshot::withoutScheduled($this->day, $this->part, $this->recitedAyahIds());

        return [
            'day_id' => $this->student_plan_day_id,
            'part' => $this->part,
            'date' => substr((string) $this->recited_on, 0, 10),
            'grade' => $this->grade,
            'recited' => AyahIndex::presentRange(...($recited ?? [null, null])),
            'graded_at' => $this->graded_at?->toISOString(),
            'updated_at' => ($this->graded_at ?? $this->updated_at)?->toISOString(),
            // Who last wrote the session, so a conflict can say whose value it is.
            'updated_by' => $this->recorder?->name,
        ];
    }
}
