<?php

namespace App\Http\Resources\V1;

use App\Models\StudentPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An active Quran plan of one of the teacher's students. A student may follow
 * several at once; position orders them as the web card lists them, newest
 * first.
 *
 * @mixin StudentPlan
 */
class SyncTasmeehPlanResource extends JsonResource
{
    public function __construct(StudentPlan $plan, private readonly int $position)
    {
        parent::__construct($plan);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'type' => $this->plan_type,
            'position' => $this->position,
        ];
    }
}
