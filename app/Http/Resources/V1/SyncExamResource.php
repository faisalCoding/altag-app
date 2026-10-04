<?php

namespace App\Http\Resources\V1;

use App\Models\StudentExam;
use App\Support\HijriDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An exam a student is waiting to sit. The date column holds the academy's
 * wall-clock time, so its date part is the day as the teacher set it.
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
            'date_hijri' => HijriDate::full($date),
            'location' => $this->location,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
