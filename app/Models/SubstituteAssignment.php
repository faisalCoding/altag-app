<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teacher standing in for a circle on one day. See the migration for what
 * it lets them do, and Teacher::workingCircleIds() for where it is read.
 */
#[Fillable([
    'circle_id',
    'teacher_id',
    'date',
    'absent_teacher_id',
    'teacher_attendance_id',
    'granted_by_id',
    'granted_by_role',
])]
class SubstituteAssignment extends Model
{
    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /** @return BelongsTo<Circle, $this> */
    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    /** @return BelongsTo<Teacher, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    /** @return BelongsTo<Teacher, $this> */
    public function absentTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'absent_teacher_id');
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_id');
    }
}
