<?php

namespace App\Http\Resources\V1;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One attendance cell as the teacher app stores it: keyed by student and day,
 * since a student holds at most one record per day whichever circle took it.
 *
 * @mixin Attendance
 */
class SyncAttendanceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'student_id' => $this->student_id,
            'circle_id' => $this->circle_id,
            'date' => $this->date->toDateString(),
            'status' => $this->status,
            'updated_at' => $this->updated_at?->toISOString(),
            // Who last wrote the cell, so a conflict can say whose value it is.
            'updated_by' => $this->teacher?->name,
        ];
    }
}
