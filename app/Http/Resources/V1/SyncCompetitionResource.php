<?php

namespace App\Http\Resources\V1;

use App\Models\Leaderboard;
use App\Models\LeaderboardCriterion;
use App\Support\HijriDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A competition the teacher records criteria scores in, with its criteria and
 * the teacher's circles that grade in it.
 *
 * @mixin Leaderboard
 */
class SyncCompetitionResource extends JsonResource
{
    /**
     * @param  array<int, int>  $circleIds  The teacher's circles that grade in this competition.
     */
    public function __construct(Leaderboard $competition, private readonly array $circleIds)
    {
        parent::__construct($competition);
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
            'title' => $this->title,
            'type' => $this->competition_type,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'start_date_hijri' => $this->start_date ? HijriDate::full($this->start_date) : null,
            'end_date_hijri' => $this->end_date ? HijriDate::full($this->end_date) : null,
            'extra_points_enabled' => (bool) ($this->settings['extra_points_enabled'] ?? false),
            'circle_ids' => $this->circleIds,
            'criteria' => $this->criteria
                ->map(fn (LeaderboardCriterion $criterion) => [
                    'id' => $criterion->id,
                    'name' => $criterion->name,
                    'points' => (int) $criterion->points,
                    'coins' => (int) $criterion->coins,
                    'is_enthusiasm_trigger' => (bool) $criterion->is_enthusiasm_trigger,
                ])
                ->values()
                ->all(),
        ];
    }
}
