<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to a teacher's day — the teachers' counterpart of
 * AttendanceRevision. Rows are written, never updated.
 */
#[Fillable([
    'teacher_attendance_id',
    'teacher_id',
    'stage_id',
    'date',
    'old_status',
    'new_status',
    'old_notes',
    'new_notes',
    'old_arrived_at',
    'new_arrived_at',
    'old_substitute_id',
    'new_substitute_id',
    'edited_by_id',
    'edited_by_role',
])]
class TeacherAttendanceRevision extends Model
{
    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /** @return BelongsTo<Teacher, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    /** @return BelongsTo<Teacher, $this> */
    public function newSubstitute(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'new_substitute_id');
    }

    /** @return BelongsTo<User, $this> */
    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_id');
    }

    /**
     * How the status moved, as the history panel reads it: "غائب ← حاضر".
     * Null when only the reason or the arrival time changed.
     */
    public function summary(): ?string
    {
        if ($this->old_status === $this->new_status) {
            return null;
        }

        $labels = AttendanceRevision::statusLabels();
        $from = $this->old_status ? ($labels[$this->old_status] ?? $this->old_status) : 'بدون تسجيل';
        $to = $this->new_status ? ($labels[$this->new_status] ?? $this->new_status) : 'حُذف التسجيل';

        return "{$from} ← {$to}";
    }

    public function notesChanged(): bool
    {
        return $this->new_status !== null && $this->old_notes !== $this->new_notes;
    }

    public function arrivalChanged(): bool
    {
        return $this->new_status !== null && $this->old_arrived_at !== $this->new_arrived_at;
    }

    public function substituteChanged(): bool
    {
        return $this->new_status !== null && $this->old_substitute_id !== $this->new_substitute_id;
    }

    /** Who made the change, and in which capacity. */
    public function editorLabel(): string
    {
        $role = match ($this->edited_by_role) {
            'manager' => 'المدير',
            'supervisor' => 'المشرف',
            default => null,
        };

        return collect([$role, $this->editedBy?->name])->filter()->implode(' ') ?: 'غير معروف';
    }
}
