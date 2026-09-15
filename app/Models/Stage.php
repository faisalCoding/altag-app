<?php

namespace App\Models;

use Database\Factories\StageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'level', 'require_edit_reason'])]
class Stage extends Model
{
    /** @use HasFactory<StageFactory> */
    use HasFactory;

    protected $casts = [
        'require_edit_reason' => 'boolean',
    ];

    /**
     * A stage's id also lives inside JSON columns that no foreign key guards —
     * the stages an attendance period applies to, most of all. Left behind, a
     * dead id makes the period unsaveable over a stage the form cannot show.
     */
    protected static function booted(): void
    {
        static::deleted(function (Stage $stage) {
            foreach (AcademicCalendarEvent::whereJsonContains('stage_ids', $stage->id)->get() as $event) {
                $event->update([
                    'stage_ids' => array_values(array_diff($event->stage_ids ?? [], [$stage->id])),
                ]);
            }
        });
    }

    /** @return HasMany<Circle, $this> */
    public function circles(): HasMany
    {
        return $this->hasMany(Circle::class);
    }

    /** @return BelongsToMany<Supervisor, $this> */
    public function supervisors(): BelongsToMany
    {
        return $this->belongsToMany(Supervisor::class, 'stage_supervisor', 'stage_id', 'supervisor_id');
    }
}
