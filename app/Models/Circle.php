<?php

namespace App\Models;

use Database\Factories\CircleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'stage_id', 'level', 'whatsapp_group_url'])]
class Circle extends Model
{
    /** @use HasFactory<CircleFactory> */
    use HasFactory;

    /** @return BelongsTo<Stage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /**
     * The WhatsApp group the teacher's absence message goes to: the circle's
     * own when the supervisor gave it one, otherwise its stage's.
     */
    public function getEffectiveWhatsappGroupUrlAttribute(): ?string
    {
        return $this->whatsapp_group_url ?: $this->stage?->whatsapp_group_url;
    }

    /** @return BelongsToMany<Teacher, $this> */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(Teacher::class, 'circle_teacher', 'circle_id', 'teacher_id');
    }

    /** @return HasMany<Attendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /** @return HasMany<Student, $this> */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /** @return HasMany<Leaderboard, $this> */
    public function leaderboards(): HasMany
    {
        return $this->hasMany(Leaderboard::class);
    }
}
