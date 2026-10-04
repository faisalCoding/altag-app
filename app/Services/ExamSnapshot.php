<?php

namespace App\Services;

use App\Http\Resources\V1\SyncExamLevelResource;
use App\Http\Resources\V1\SyncExamResource;
use App\Models\ExamLevel;
use App\Models\StudentExam;
use Illuminate\Support\Collection;

/**
 * What the teacher app needs to show each student's next exam and to schedule
 * one offline: the exam levels with their juz counts, every exam still pending
 * for the teacher's students, and the level each student would sit next.
 *
 * The next exam is the earliest pending one whatever its date: an exam that
 * slipped past its day without a result is still the one the student awaits,
 * and showing nothing for it would invite a duplicate.
 */
class ExamSnapshot
{
    /**
     * @param  array<int, int>  $studentIds
     * @return array{exam_levels: array<int, SyncExamLevelResource>, exams: array<int, SyncExamResource>, exam_suggestions: array<int, array{student_id: int, level_id: int}>}
     */
    public static function for(array $studentIds): array
    {
        $levels = self::levels();

        $exams = StudentExam::whereIn('student_id', $studentIds)
            ->pending()
            ->orderBy('date_time')
            ->orderBy('id')
            ->get();

        return [
            'exam_levels' => $levels->values()
                ->map(fn (ExamLevel $level, int $position) => new SyncExamLevelResource($level, $position))
                ->all(),
            'exams' => SyncExamResource::collection($exams)->all(),
            'exam_suggestions' => self::suggestions($studentIds, $levels),
        ];
    }

    /**
     * The nothing an app sees while the page is switched off for teachers.
     *
     * @return array{exam_levels: array{}, exams: array{}, exam_suggestions: array{}}
     */
    public static function empty(): array
    {
        return ['exam_levels' => [], 'exams' => [], 'exam_suggestions' => []];
    }

    /**
     * The levels in the order teachers climb them: by juz count, the ones
     * without an end last.
     *
     * @return Collection<int, ExamLevel>
     */
    private static function levels(): Collection
    {
        return ExamLevel::with('endAyah:id,juz_number')
            ->orderBy('id')
            ->get()
            ->sortBy([
                fn (ExamLevel $a, ExamLevel $b) => ($a->juzCount() ?? PHP_INT_MAX) <=> ($b->juzCount() ?? PHP_INT_MAX),
                fn (ExamLevel $a, ExamLevel $b) => $a->id <=> $b->id,
            ])
            ->values();
    }

    /**
     * For each student, the smallest level beyond the largest they have
     * passed. Juz counts decide rather than the levels' previous-level chain,
     * which branches.
     *
     * @param  array<int, int>  $studentIds
     * @param  Collection<int, ExamLevel>  $levels
     * @return array<int, array{student_id: int, level_id: int}>
     */
    private static function suggestions(array $studentIds, Collection $levels): array
    {
        $juzCounts = $levels->mapWithKeys(fn (ExamLevel $level) => [$level->id => $level->juzCount()]);
        $climbable = $levels->filter(fn (ExamLevel $level) => $level->juzCount() !== null)->values();

        $passed = StudentExam::whereIn('student_id', $studentIds)
            ->where('status', 'passed')
            ->get(['student_id', 'exam_level_id'])
            ->groupBy('student_id')
            ->map(fn (Collection $exams) => (int) $exams->map(fn (StudentExam $exam) => $juzCounts[$exam->exam_level_id] ?? 0)->max());

        $suggestions = [];

        foreach (collect($studentIds)->sort()->values() as $studentId) {
            $reached = $passed[$studentId] ?? 0;
            $next = $climbable->first(fn (ExamLevel $level) => $level->juzCount() > $reached);

            if ($next !== null) {
                $suggestions[] = ['student_id' => $studentId, 'level_id' => $next->id];
            }
        }

        return $suggestions;
    }
}
