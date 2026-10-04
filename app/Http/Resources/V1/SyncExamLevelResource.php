<?php

namespace App\Http\Resources\V1;

use App\Models\ExamLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An exam level, with the number of juz teachers call it by.
 *
 * @mixin ExamLevel
 */
class SyncExamLevelResource extends JsonResource
{
    public function __construct(ExamLevel $level, private readonly int $position)
    {
        parent::__construct($level);
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
            'juz_count' => $this->juzCount(),
            'position' => $this->position,
        ];
    }
}
