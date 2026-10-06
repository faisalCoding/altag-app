<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One session a part of a plan day was recited in: the grade it got that day
 * (0 for «لم يسمع») and the range recited when it was not the day's portion.
 *
 * `recited_on` is left uncast and always written as Y-m-d: a date cast would
 * store a time beside it, and the unique key on the session would no longer
 * tell two writes of the same day apart.
 */
class PlanDayAttempt extends Model
{
    protected $fillable = [
        'student_plan_day_id',
        'part',
        'recited_on',
        'grade',
        'recited_from_ayah_id',
        'recited_to_ayah_id',
        'graded_at',
        'recorded_by',
    ];

    protected $casts = [
        'grade' => 'integer',
        'graded_at' => 'datetime',
    ];

    /** @return BelongsTo<StudentPlanDay, $this> */
    public function day(): BelongsTo
    {
        return $this->belongsTo(StudentPlanDay::class, 'student_plan_day_id');
    }

    /** @return BelongsTo<Ayah, $this> */
    public function recitedFromAyah(): BelongsTo
    {
        return $this->belongsTo(Ayah::class, 'recited_from_ayah_id');
    }

    /** @return BelongsTo<Ayah, $this> */
    public function recitedToAyah(): BelongsTo
    {
        return $this->belongsTo(Ayah::class, 'recited_to_ayah_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * The range recited, as ayah ids; null when none was recorded.
     *
     * @return array{0: int, 1: int}|null
     */
    public function recitedAyahIds(): ?array
    {
        return $this->recited_from_ayah_id === null || $this->recited_to_ayah_id === null
            ? null
            : [(int) $this->recited_from_ayah_id, (int) $this->recited_to_ayah_id];
    }
}
