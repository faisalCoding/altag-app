<?php

namespace App\Models;

use Database\Factories\StageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description'])]
class Stage extends Model
{
    /** @use HasFactory<StageFactory> */
    use HasFactory;

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
