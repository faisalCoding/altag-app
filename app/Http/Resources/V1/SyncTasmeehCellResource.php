<?php

namespace App\Http\Resources\V1;

use App\Models\StudentPlanDay;
use App\Services\TasmeehSnapshot;
use App\Services\TeacherSyncSnapshot;
use App\Support\AyahIndex;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a teacher recorded for one part of a plan day: the grade (0 for «لم
 * يسمع», null when not graded) and the range actually recited when it was not
 * the portion the day set.
 *
 * @mixin StudentPlanDay
 */
class SyncTasmeehCellResource extends JsonResource
{
    public function __construct(StudentPlanDay $day, private readonly string $part)
    {
        parent::__construct($day);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $gradedAt = $this->{"{$this->part}_graded_at"};

        return [
            'day_id' => $this->id,
            'part' => $this->part,
            'grade' => $this->{"{$this->part}_achievement"},
            'recited' => AyahIndex::presentRange(...(TasmeehSnapshot::recitedAyahIds($this->resource, $this->part) ?? [null, null])),
            'graded_at' => $gradedAt?->toISOString(),
            // The academy's day the grade was given on, which pins the wird
            // the phone shows to the day it was marked.
            'graded_on' => $gradedAt?->copy()->setTimezone(TeacherSyncSnapshot::TIMEZONE)->toDateString(),
            'updated_at' => ($gradedAt ?? $this->updated_at)?->toISOString(),
            // Who last wrote the part, so a conflict can say whose value it is.
            'updated_by' => $this->{"{$this->part}Recorder"}?->name,
        ];
    }
}
