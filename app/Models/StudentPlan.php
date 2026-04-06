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
}
