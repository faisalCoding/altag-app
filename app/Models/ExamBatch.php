<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
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

    /**
     * The batch's students as they are read out on the day: by stage, in the
     * stages' own order, then by circle and name.
     *
     * @return Collection<int, StudentExam>
     */
    public function roster(): Collection
    {
        return $this->exams()
            ->with('student:id,name,circle_id', 'student.circle:id,name,stage_id', 'student.circle.stage:id,name,position', 'examLevel:id,name', 'nominator:id,name')
            ->get()
            ->sortBy([
                fn (StudentExam $a, StudentExam $b) => self::stageOrder($a) <=> self::stageOrder($b),
                fn (StudentExam $a, StudentExam $b) => strcmp((string) $a->student?->circle?->name, (string) $b->student?->circle?->name),
                fn (StudentExam $a, StudentExam $b) => strcmp((string) $a->student?->name, (string) $b->student?->name),
            ])
            ->values();
    }

    /**
     * Where a student's stage falls: in the stages' own order, a stage kept
     * whole when two share a place, and students of no stage last.
     *
     * @return array{0: bool, 1: int, 2: int}
     */
    public static function stageOrder(StudentExam $exam): array
    {
        $stage = $exam->student?->circle?->stage;

        return [$stage === null, (int) ($stage?->position ?? 0), (int) ($stage?->id ?? 0)];
    }
}
