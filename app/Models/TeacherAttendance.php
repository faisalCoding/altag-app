<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['teacher_id', 'stage_id', 'date', 'status', 'arrived_at', 'notes', 'substitute_teacher_id', 'recorded_by_id'])]
class TeacherAttendance extends Model
{
    use HasFactory;

    /** The same vocabulary the students' register uses, so reports read alike. */
    public const STATUSES = ['present', 'absent', 'late', 'excused'];

    /** The days a teacher was away, and someone may have covered for them. */
    public const AWAY = ['absent', 'excused'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /** @return BelongsTo<Teacher, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    /**
     * The stage the day was taken under — kept on the day, so a teacher who
     * moves on does not take their past with them.
     *
     * @return BelongsTo<Stage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    /**
     * Who covered for the teacher, when they were away.
     *
     * @return BelongsTo<Teacher, $this>
     */
    public function substitute(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'substitute_teacher_id');
    }

    /** @return HasMany<TeacherAttendanceRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(TeacherAttendanceRevision::class);
    }

    /** The arrival time as the page shows it: "16:20". */
    public function arrivalLabel(): ?string
    {
        return $this->arrived_at ? substr($this->arrived_at, 0, 5) : null;
    }

    /**
     * How late a late teacher was, against the first session the calendar
     * gives their stage that day. Null when either side is unknown.
     */
    public function minutesLate(): ?int
    {
        if ($this->status !== 'late' || ! $this->arrived_at) {
            return null;
        }

        $start = collect(AcademicCalendarEvent::sessionsOn($this->date, $this->stage_id))->pluck('from')->filter()->sort()->first();

        if (! $start) {
            return null;
        }

        $minutes = intdiv(strtotime($this->arrived_at) - strtotime($start), 60);

        return $minutes > 0 ? $minutes : null;
    }
}
