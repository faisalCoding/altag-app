<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'challenge_id',
        'type',
        'current_progress',
        'target_value',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'target_value' => 'integer',
    ];

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    public function calculateProgress(): int
    {
        $studentId = $this->challenge->student_id;
        $startDate = $this->challenge->start_date;

        if ($this->type === 'attendance') {
            $attendances = Attendance::where('student_id', $studentId)
                ->where('date', '>=', $startDate)
                ->orderBy('date', 'asc')
                ->get();

            $maxConsecutive = 0;
            $currentConsecutive = 0;
            $ignoredAbsences = $this->metadata['ignored_absences'] ?? [];

            foreach ($attendances as $record) {
                $isLate = $record->status === 'late';
                $isAbsent = $record->status === 'absent';
                $latenessNotAllowed = ($this->metadata['no_lateness'] ?? false);

                $isFailureRecord = $isAbsent || ($isLate && $latenessNotAllowed);

                if ($isFailureRecord && ! in_array($record->id, $ignoredAbsences)) {
                    // Unignored failure breaks the streak permanently unless forgiven
                    $currentConsecutive = 0;
                } elseif ($record->status === 'present' || ($isLate && ! $latenessNotAllowed)) {
                    $currentConsecutive++;
                    if ($currentConsecutive > $maxConsecutive) {
                        $maxConsecutive = $currentConsecutive;
                    }
                }
            }

            return min($maxConsecutive, $this->target_value);
        }

        if ($this->type === 'recitation_days') {
            $dayIds = $this->metadata['day_ids'] ?? [];
            if (empty($dayIds)) {
                return 0;
            }

            // Only recited days count: a «لم يسمع» (0) is graded but not recited.
            $query = StudentPlanDay::whereIn('id', $dayIds)->recited('hifz');

            if ($this->metadata['quality_required'] ?? false) {
                $req = $this->metadata['quality_req'] ?? 'excellent';
                if ($req === 'excellent') {
                    $query->where('hifz_achievement', 3);
                } else {
                    $query->whereIn('hifz_achievement', [2, 3]);
                }
            }

            return min($query->count(), $this->target_value);
        }

        if ($this->type === 'exam_passed') {
            $query = StudentExam::where('student_id', $studentId)
                ->where('date_time', '>=', $startDate)
                ->where('status', 'passed')
                ->where('score_percentage', '>=', $this->target_value);

            if (isset($this->metadata['exam_level_id']) && $this->metadata['exam_level_id']) {
                $query->where('exam_level_id', $this->metadata['exam_level_id']);
            }

            $passed = $query->exists();

            return $passed ? $this->target_value : 0;
        }

        return $this->current_progress;
    }

    public function getUnignoredAbsences()
    {
        if ($this->type !== 'attendance') {
            return collect();
        }

        $studentId = $this->challenge->student_id;
        $startDate = $this->challenge->start_date;
        $ignored = $this->metadata['ignored_absences'] ?? [];
        $noLateness = $this->metadata['no_lateness'] ?? false;

        return Attendance::where('student_id', $studentId)
            ->where('date', '>=', $startDate)
            ->where(function ($query) use ($noLateness) {
                $query->where('status', 'absent');
                if ($noLateness) {
                    $query->orWhere('status', 'late');
                }
            })
            ->whereNotIn('id', $ignored)
            ->get();
    }
}
