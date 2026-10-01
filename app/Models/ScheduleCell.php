<?php

namespace App\Models;

use Database\Factories\ScheduleCellFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One box of a week's grid. `weekday` follows Carbon's dayOfWeek (0 = Sunday
 * … 6 = Saturday) and `position` counts the boxes of that day from the right.
 * A box may `span` several columns, as the Thursday recreation banner does. A
 * box with no activity is an empty slot kept so the columns stay aligned.
 */
#[Fillable(['schedule_week_id', 'weekday', 'position', 'span', 'schedule_activity_id', 'title', 'detail', 'time'])]
class ScheduleCell extends Model
{
    /** @use HasFactory<ScheduleCellFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'position' => 'integer',
            'span' => 'integer',
        ];
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(ScheduleWeek::class, 'schedule_week_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ScheduleActivity::class, 'schedule_activity_id');
    }

    public function isEmpty(): bool
    {
        return $this->schedule_activity_id === null;
    }

    public function displayName(): string
    {
        return $this->title ?: ($this->activity?->name ?? '');
    }

    public function displayTime(): ?string
    {
        return $this->time ?: $this->activity?->default_time;
    }
}
