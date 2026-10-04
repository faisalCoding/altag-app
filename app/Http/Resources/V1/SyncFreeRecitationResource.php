<?php

namespace App\Http\Resources\V1;

use App\Models\FreeRecitation;
use App\Support\AyahIndex;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A recitation by a student outside any plan, as the teacher app stores it:
 * one per student, part and day.
 *
 * @mixin FreeRecitation
 */
class SyncFreeRecitationResource extends JsonResource
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
            'uuid' => $this->uuid,
            'student_id' => $this->student_id,
            'part' => $this->type,
            'date' => $this->recited_on->toDateString(),
            'grade' => $this->achievement,
            'recited' => AyahIndex::presentRange($this->from_ayah_id, $this->to_ayah_id),
            'graded_at' => $this->graded_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'updated_by' => $this->recorder?->name,
        ];
    }
}
