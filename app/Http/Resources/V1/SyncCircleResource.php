<?php

namespace App\Http\Resources\V1;

use App\Models\Circle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A circle the teacher takes attendance for, with the days its stage meets
 * inside the sync window and whether an off-day edit must give a reason.
 *
 * Beside the window it carries the attendance period its stage is in, so the
 * student card can count a term's attendance against the days that met.
 *
 * @mixin Circle
 */
class SyncCircleResource extends JsonResource
{
    /**
     * @param  array<int, string>  $workingDays  Y-m-d strings, in order.
     * @param  array{start: string, end: string|null, working_days: array<int, string>}|null  $period
     */
    public function __construct(
        Circle $circle,
        private readonly array $workingDays,
        private readonly ?array $period,
        private readonly bool $standingIn = false,
    ) {
        parent::__construct($circle);
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
            'name' => $this->name,
            'description' => $this->description,
            'stage' => $this->stage ? [
                'id' => $this->stage->id,
                'name' => $this->stage->name,
            ] : null,
            // Unanswered keeps the requirement, as the sheet reads it.
            'require_edit_reason' => $this->stage?->require_edit_reason ?? true,
            // Opened once the absence message is copied: the circle's own
            // group, else its stage's; null copies only.
            'whatsapp_group_url' => $this->effective_whatsapp_group_url,
            'working_days' => $this->workingDays,
            // The term covering today, else the last one before it; its
            // working days run from its start to today at the latest.
            'period' => $this->period,
            // A circle the teacher stands in for today, not one of their own:
            // its working days are today alone.
            'standing_in' => $this->standingIn,
        ];
    }
}
