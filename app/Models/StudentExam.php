<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's exam of the association, from the teacher marking them ready to
 * the result.
 *
 * 'nominated': the teacher put the student forward, ready now or by a day
 * they expect; it has no day of its own and the student does not see it.
 * 'pending': the manager seated the student in a dated batch. Then a result:
 * 'passed', 'failed', 'absent' or 'cancelled'. A student has at most one
 * open exam, nominated or pending.
 */
class StudentExam extends Model
{
    use HasFactory;

    public const NOMINATED = 'nominated';

    public const SCHEDULED = 'pending';

    /** The results an exam can end in. */
    public const RESULTS = ['passed', 'failed', 'absent', 'cancelled'];

    public const STATUS_LABELS = [
        'nominated' => 'مرشح',
        'pending' => 'مجدول',
        'passed' => 'تم التجاوز',
        'failed' => 'لم يتجاوز',
        'absent' => 'تغيب',
        'cancelled' => 'ملغى',
    ];

    protected $fillable = [
        'uuid',
        'student_id',
        'exam_level_id',
        'exam_batch_id',
        'status',
        'expected_ready_on',
        'ready_at',
        'nominated_by',
        'ready_reminded_at',
        'date_time',
        'location',
        'notes',
        'score_percentage',
    ];

    protected $casts = [
        'date_time' => 'datetime',
        'expected_ready_on' => 'date',
        'ready_at' => 'datetime',
        'ready_reminded_at' => 'datetime',
        'score_percentage' => 'decimal:2',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function examLevel(): BelongsTo
    {
        return $this->belongsTo(ExamLevel::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ExamBatch::class, 'exam_batch_id');
    }

    public function nominator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nominated_by');
    }

    /** Whether the teacher has confirmed the student ready, now or after a day they expected. */
    public function isReady(): bool
    {
        return $this->status === self::NOMINATED && $this->ready_at !== null;
    }

    /**
     * Exams seated in a batch and still waiting to be sat, whatever their
     * date: one that slipped past its day without a result is still the
     * student's next exam.
     *
     * @param  Builder<StudentExam>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::SCHEDULED);
    }

    /**
     * Students put forward and not yet seated.
     *
     * @param  Builder<StudentExam>  $query
     */
    public function scopeNominated(Builder $query): void
    {
        $query->where('status', self::NOMINATED);
    }

    /**
     * The exam a student is on its way to: put forward or seated.
     *
     * @param  Builder<StudentExam>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [self::NOMINATED, self::SCHEDULED]);
    }

    /**
     * Everything a student or a guardian is shown: nominations stay between
     * the teacher and the manager until a batch gives them a day.
     *
     * @param  Builder<StudentExam>  $query
     */
    public function scopeVisibleToStudent(Builder $query): void
    {
        $query->where('status', '!=', self::NOMINATED);
    }
}
