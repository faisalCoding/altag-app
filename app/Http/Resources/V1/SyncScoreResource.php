<?php

namespace App\Http\Resources\V1;

use App\Models\LeaderboardScore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A criterion granted to a student on a day. Its presence is the whole fact:
 * an ungranted criterion has no row.
 *
 * @mixin LeaderboardScore
 */
class SyncScoreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'competition_id' => $this->leaderboard_id,
            'criterion_id' => $this->leaderboard_criterion_id,
            'student_id' => $this->student_id,
            'date' => $this->date->toDateString(),
        ];
    }
}
