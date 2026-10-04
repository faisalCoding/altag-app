<?php

namespace App\Http\Resources\V1;

use App\Models\Student;
use App\Models\StudentStatusHistory;
use App\Support\HijriDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A student as the teacher app needs them to decide, offline, which days are
 * theirs to mark: when they joined and every status they have held since —
 * and the numbers the student card dials: the student's own and their
 * guardian's.
 *
 * The Hijri readings travel with the dates because the phone has no reliable
 * Umm al-Qura calendar of its own, and the reasons it shows for a closed day
 * name those dates the way the sheet does.
 *
 * @mixin Student
 */
class SyncStudentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'circle_id' => $this->circle_id,
            'status' => $this->status,
            'phone' => $this->phone,
            'guardian_name' => $this->guardian?->name,
            'guardian_phone' => $this->guardian?->phone,
            'joined_at' => $this->joined_at?->toDateString(),
            'joined_at_hijri' => $this->joined_at ? HijriDate::full($this->joined_at) : null,
            'status_histories' => $this->statusHistories
                ->map(fn (StudentStatusHistory $history) => [
                    'id' => $history->id,
                    'status' => $history->status,
                    'start_date' => $history->start_date->toDateString(),
                    'start_date_hijri' => HijriDate::full($history->start_date),
                ])
                ->values()
                ->all(),
        ];
    }
}
