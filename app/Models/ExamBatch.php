<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sitting of the association's exam: the day, hour and place the manager
 * set for a group of ready students.
 */
class ExamBatch extends Model
{
    /** @var list<string> */
    protected $fillable = ['date_time', 'location', 'notes', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date_time' => 'datetime'];
    }

    /** @return HasMany<StudentExam, $this> */
    public function exams(): HasMany
    {
        return $this->hasMany(StudentExam::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
