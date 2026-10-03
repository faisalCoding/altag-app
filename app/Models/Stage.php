<?php

namespace App\Models;

use Database\Factories\StageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'level', 'position', 'require_edit_reason', 'whatsapp_group_url'])]
class Stage extends Model
{
    /** @use HasFactory<StageFactory> */
    use HasFactory;

    protected $casts = [
        'require_edit_reason' => 'boolean',
        'position' => 'integer',
    ];

    /**
     * Stages come back in the order the academy arranged them, wherever they
     * are asked for — the reports, every stage picker, the supervisors' scopes.
     *
     * A global scope rather than an orderBy at each of the twenty-odd call
     * sites, because "everywhere" is the requirement and a call site added
     * tomorrow would not know to ask. It is applied after any order the caller
     * set, so a query that means something else by its ordering — the promotion
     * ladder, which sorts by rung — still wins, and position only breaks ties.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('ordered', function (Builder $query) {
            $query->orderBy('stages.position')->orderBy('stages.name');
        });

        // A stage's id also lives inside JSON columns that no foreign key
        // guards — the stages an attendance period applies to, most of all.
        // Left behind, a dead id makes the period unsaveable over a stage the
        // form cannot show.
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
