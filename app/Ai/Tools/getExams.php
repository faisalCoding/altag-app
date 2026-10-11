<?php

namespace App\Ai\Tools;

use App\Models\ExamBatch;
use App\Models\StudentExam;
use App\Services\TeacherSyncSnapshot;
use App\Support\ArabicSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The association's exams across the academy: who is put forward — ready,
 * or expected ready by a day — who is seated in a dated batch, the batches
 * themselves, and how the finished exams went by level and by circle.
 */
class getExams implements Tool
{
    private const MAX_STUDENTS = 200;

    public function description(): Stringable|string
    {
        return 'Get the association\'s exams (اختبارات الجمعية): students put forward by their teachers (ready now, or expected ready by a '
            .'day — "due" once that day has come without the teacher confirming), students seated in a dated exam batch (دفعة), '
            .'the batches with their dates and how many results are recorded, and the results by level and by circle with pass rates. '
            .'Filter with "level", "circle" or "stage"; bound the batches and results with "from" and "to" (Y-m-d).';
    }

    public function handle(Request $request): Stringable|string
    {
        $today = TeacherSyncSnapshot::today();
        $exams = fn () => $this->filtered($request);

        $nominated = $exams()->nominated()->with($this->relations())->get();
        $seated = $exams()->pending()->with([...$this->relations(), 'batch'])->get();

        $finished = $exams()->whereIn('status', StudentExam::RESULTS)
            ->when($request['from'] ?? null, fn ($q, $from) => $q->whereDate('date_time', '>=', $from))
            ->when($request['to'] ?? null, fn ($q, $to) => $q->whereDate('date_time', '<=', $to))
            ->with($this->relations())
            ->get();

        $ready = $nominated->filter(fn (StudentExam $exam) => $exam->isReady());
        $expected = $nominated->reject(fn (StudentExam $exam) => $exam->isReady());

        $batches = ExamBatch::query()
            ->when($request['from'] ?? null, fn ($q, $from) => $q->whereDate('date_time', '>=', $from))
            ->when($request['to'] ?? null, fn ($q, $to) => $q->whereDate('date_time', '<=', $to))
            ->withCount(['exams', 'exams as recorded_count' => fn ($q) => $q->whereIn('status', StudentExam::RESULTS)])
            ->orderBy('date_time')
            ->get();

        return json_encode([
            'today' => $today,
            'ready' => [
                'count' => $ready->count(),
                'students' => $ready->sortBy('ready_at')->take(self::MAX_STUDENTS)->map(fn ($exam) => $this->row($exam) + [
                    'ready_since' => $exam->ready_at?->format('Y-m-d'),
                ])->values()->all(),
            ],
            'expected' => [
                'count' => $expected->count(),
                'students' => $expected->sortBy('expected_ready_on')->take(self::MAX_STUDENTS)->map(fn ($exam) => $this->row($exam) + [
                    'expected_on' => $exam->expected_ready_on?->format('Y-m-d'),
                    'due' => $exam->expected_ready_on !== null && $exam->expected_ready_on->toDateString() <= $today,
                ])->values()->all(),
            ],
            'seated' => [
                'count' => $seated->count(),
                'students' => $seated->sortBy('date_time')->take(self::MAX_STUDENTS)->map(fn ($exam) => $this->row($exam) + [
                    'exam_at' => $exam->date_time?->format('Y-m-d H:i'),
                    'location' => $exam->location,
                    'in_batch' => $exam->exam_batch_id !== null,
                ])->values()->all(),
            ],
            'batches' => $batches->map(fn (ExamBatch $batch) => [
                'date' => $batch->date_time?->format('Y-m-d H:i'),
                'location' => $batch->location,
                'students' => $batch->exams_count,
                'results_recorded' => $batch->recorded_count,
                'upcoming' => $batch->date_time?->toDateString() >= $today,
            ])->all(),
            'results' => [
                'total' => $finished->count(),
                'by_level' => $this->results($finished, fn ($exam) => $exam->examLevel?->name ?? 'مستوى محذوف'),
                'by_circle' => $this->results($finished, fn ($exam) => $exam->student?->circle?->name ?? 'بلا حلقة'),
            ],
            'note' => 'Pass rate = passed ÷ (passed + failed); absent and cancelled exams are counted apart.',
        ], JSON_UNESCAPED_UNICODE);
    }

    /** @return Builder<StudentExam> */
    private function filtered(Request $request): Builder
    {
        return StudentExam::query()
            ->when($request['level'] ?? null, fn ($q, $level) => $q->whereHas('examLevel', fn ($l) => ArabicSearch::where($l, 'name', $level)))
            ->when($request['circle'] ?? null, fn ($q, $circle) => $q->whereHas('student.circle', fn ($c) => ArabicSearch::where($c, 'name', $circle)))
            ->when($request['stage'] ?? null, fn ($q, $stage) => $q->whereHas('student.circle.stage', fn ($s) => ArabicSearch::where($s, 'name', $stage)));
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return ['student:id,name,circle_id', 'student.circle:id,name,stage_id', 'student.circle.stage:id,name', 'examLevel:id,name', 'nominator:id,name'];
    }

    /** @return array<string, mixed> */
    private function row(StudentExam $exam): array
    {
        return [
            'student' => trim((string) $exam->student?->name),
            'circle' => $exam->student?->circle?->name,
            'stage' => $exam->student?->circle?->stage?->name,
            'level' => $exam->examLevel?->name,
            'nominated_by' => $exam->nominator?->name,
        ];
    }

    /**
     * @param  Collection<int, StudentExam>  $exams
     * @return array<string, array<string, int|float|null>>
     */
    private function results(Collection $exams, callable $key): array
    {
        return $exams->groupBy($key)->map(function (Collection $group) {
            $counts = [];

            foreach (StudentExam::RESULTS as $status) {
                $counts[StudentExam::STATUS_LABELS[$status]] = $group->where('status', $status)->count();
            }

            $passed = $group->where('status', 'passed')->count();
            $sat = $passed + $group->where('status', 'failed')->count();
            $scores = $group->whereNotNull('score_percentage')->pluck('score_percentage')->map(fn ($score) => (float) $score);

            return $counts + [
                'نسبة النجاح' => $sat > 0 ? (int) round($passed / $sat * 100) : null,
                'متوسط الدرجة' => $scores->isEmpty() ? null : round($scores->avg(), 1),
            ];
        })->all();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'level' => $schema->string()->description('Exam level name (مستوى الاختبار), or part of it.'),
            'circle' => $schema->string()->description('Circle name (حلقة), or part of it.'),
            'stage' => $schema->string()->description('Stage name (مرحلة), or part of it.'),
            'from' => $schema->string()->description('Only batches and results on or after this date (Y-m-d).'),
            'to' => $schema->string()->description('Only batches and results on or before this date (Y-m-d).'),
        ];
    }
}
