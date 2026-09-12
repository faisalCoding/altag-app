<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['promotion_run_id', 'student_id', 'from_circle_id', 'to_circle_id', 'from_status', 'action'])]
class PromotionRunItem extends Model
{
    use HasFactory;

    /** Move the student into `to_circle_id`. */
    public const PROMOTE = 'promote';

    /** The student has finished: mark them as having left. */
    public const GRADUATE = 'graduate';

    /** Leave the student exactly where they are — repeating, or off the ladder. */
    public const HOLD = 'hold';

    /** @return BelongsTo<PromotionRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PromotionRun::class, 'promotion_run_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    /** @return BelongsTo<Circle, $this> */
    public function fromCircle(): BelongsTo
    {
        return $this->belongsTo(Circle::class, 'from_circle_id');
    }

    /** @return BelongsTo<Circle, $this> */
    public function toCircle(): BelongsTo
    {
        return $this->belongsTo(Circle::class, 'to_circle_id');
    }
}
