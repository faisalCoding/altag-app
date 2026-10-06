<?php

namespace App\Http\Resources\V1;

use App\Models\CircleTurn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A student's turn in their circle's tasmeeh queue on a day, which the app
 * shows beside the student's name and orders the day's list by.
 *
 * @mixin CircleTurn
 */
class SyncTurnResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'circle_id' => $this->circle_id,
            'student_id' => $this->student_id,
            'date' => substr((string) $this->date, 0, 10),
            'number' => $this->turn_number,
        ];
    }
}
