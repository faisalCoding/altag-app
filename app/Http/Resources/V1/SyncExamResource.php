<?php

namespace App\Http\Resources\V1;

use App\Models\StudentExam;
use App\Support\HijriDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A student's open exam: put forward for the association's exam, or seated
 * in a batch and waiting to be sat. The date column holds the academy's
 * wall-clock time, so its date part is the day as it was set; a nomination
 * has none, only whether the teacher has the student ready or the day they
 * expect them to be.
 *
 * @mixin StudentExam
 */
class SyncExamResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $date = $this->date_time?->toDateString();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'student_id' => $this->student_id,
            'level_id' => $this->exam_level_id,
            'status' => $this->status,
            'date' => $date,
            'date_hijri' => $date === null ? null : HijriDate::full($date),
            'ready' => $this->status !== StudentExam::NOMINATED || $this->ready_at !== null,
            'expected_on' => $this->status === StudentExam::NOMINATED && $this->ready_at === null ? $this->expected_ready_on?->toDateString() : null,
            'location' => $this->location,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
