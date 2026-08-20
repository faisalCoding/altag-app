<?php

namespace App\Models;

use App\Support\HijriDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One recorded change to an attendance cell.
 *
 * The attendance table itself only ever holds the current status, so the trail
 * of who set it — and why, when the day was marked outside its own date — lives
 * here. Rows are written, never updated.
 */
class AttendanceRevision extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'attendance_id',
        'student_id',
        'circle_id',
        'date',
        'old_status',
        'new_status',
        'reason',
        'is_off_day_edit',
        'edited_on',
        'edited_by_id',
        'edited_by_type',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'edited_on' => 'date',
            'is_off_day_edit' => 'boolean',
        ];
    }

    /** @return BelongsTo<Attendance, $this> */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<Circle, $this> */
    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    /** @return MorphTo<Model, $this> */
    public function editedBy(): MorphTo
    {
        return $this->morphTo('edited_by');
    }

    /**
     * The Arabic labels the sheet and the history panel both read statuses by.
     * A null status is not a state of its own — it is the absence of a record.
     *
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            'present' => 'حاضر',
            'absent' => 'غائب',
            'late' => 'متأخر',
            'excused' => 'مستأذن',
        ];
    }

    /**
     * How this change reads in the history panel: "غائب ← حاضر".
     */
    public function summary(): string
    {
        $labels = self::statusLabels();
        $from = $this->old_status ? ($labels[$this->old_status] ?? $this->old_status) : 'بدون تسجيل';
        $to = $this->new_status ? ($labels[$this->new_status] ?? $this->new_status) : 'حُذف التسجيل';

        return "{$from} ← {$to}";
    }

    /**
     * The day this revision marked, as the academy reads dates.
     */
    public function hijriDate(): string
    {
        return HijriDate::full($this->date);
    }
}
