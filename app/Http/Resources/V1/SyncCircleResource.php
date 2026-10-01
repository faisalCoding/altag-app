<?php

namespace App\Http\Resources\V1;

use App\Models\Circle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A circle the teacher takes attendance for, with the days its stage meets
 * inside the sync window and whether an off-day edit must give a reason.
 *
 * @mixin Circle
 */
class SyncCircleResource extends JsonResource
{
    /**
     * @param  array<int, string>  $workingDays  Y-m-d strings, in order.
     */
    public function __construct(Circle $circle, private readonly array $workingDays)
    {
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
            'working_days' => $this->workingDays,
        ];
    }
}
