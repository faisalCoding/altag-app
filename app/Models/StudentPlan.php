<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentPlan extends Model
{
    protected $fillable = [
        'student_id',
        'teacher_id',
        'start_date',
        'days_count',
        'active_days',
        'description',
        'status',
        'plan_type',
        'direction',
        'review_direction',
        'is_approved',
        'created_by_role',
    ];

    protected $casts = [
        'start_date' => 'date',
        'active_days' => 'array',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function days()
    {
        return $this->hasMany(StudentPlanDay::class);
    }

    /**
     * Percentage of this plan's scheduled days where at least one part (hifz
     * and/or review) was recited, out of days_count. A «لم يسمع» is not one.
     */
    public function completionPercentage(): float
    {
        if ($this->days_count <= 0) {
            return 0.0;
        }

        $recited = $this->days()
            ->where(function ($q) {
                $q->where('hifz_achievement', '>=', 1)->orWhere('review_achievement', '>=', 1);
            })
            ->count();

        return round(min($recited, $this->days_count) / $this->days_count * 100, 1);
    }

    /**
     * Counts of every recorded hifz/review rating for this plan, bucketed by
     * tier (a day with both a hifz and a review rating contributes to both).
     * «لم يسمع» gets a bucket of its own.
     *
     * @return array{excellent: int, good: int, weak: int, not_heard: int}
     */
    public function achievementDistribution(): array
    {
        $counts = ['excellent' => 0, 'good' => 0, 'weak' => 0, 'not_heard' => 0];

        $rows = $this->days()->get(['hifz_achievement', 'review_achievement']);

        foreach ($rows as $row) {
            foreach ([$row->hifz_achievement, $row->review_achievement] as $value) {
                match ($value) {
                    3 => $counts['excellent']++,
                    2 => $counts['good']++,
                    1 => $counts['weak']++,
                    0 => $counts['not_heard']++,
                    default => null,
                };
            }
        }

        return $counts;
    }
}
