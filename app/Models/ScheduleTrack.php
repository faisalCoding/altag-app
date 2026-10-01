<?php

namespace App\Models;

use Database\Factories\ScheduleTrackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the programme's own stages (الأولية، العليا، المتوسطة). These are not
 * the school's stages: each track is linked to whichever school stages attend
 * it, and a guardian sees the tracks linked to their children's stages.
 */
#[Fillable(['name', 'columns', 'column_heads', 'sort_order'])]
class ScheduleTrack extends Model
{
    /** @use HasFactory<ScheduleTrackFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'columns' => 'integer',
            'column_heads' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function stages(): BelongsToMany
    {
        return $this->belongsToMany(Stage::class, 'schedule_track_stage');
    }

    public function weeks(): HasMany
    {
        return $this->hasMany(ScheduleWeek::class)->orderBy('week_number');
    }

    /**
     * The track's name without the leading «المرحلة», for tabs and chips.
     */
    public function shortName(): string
    {
        return trim(preg_replace('/^المرحلة\s+/u', '', $this->name));
    }
}
