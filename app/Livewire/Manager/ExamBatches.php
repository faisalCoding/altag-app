<?php

namespace App\Livewire\Manager;

use App\Models\ExamBatch;
use App\Models\StudentExam;
use App\Services\ExamReadiness;
use App\Services\TeacherSyncSnapshot;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The manager's exam batches: each sitting with its day, place and students,
 * where the results are recorded together — a student marked absent is put
 * forward again, ready, for the same level. A student can be taken out of a
 * batch while it waits, back among the ready, and a batch can be moved.
 */
class ExamBatches extends Component
{
    public ?int $batchId = null;

    /** @var array<int|string, array{status: string, score: string|null}> the results being written, by exam */
    public array $results = [];

    public ?string $editDate = null;

    public string $editTime = '';

    public string $editLocation = '';

    public string $editNotes = '';

    #[On('exam-batch-saved')]
    public function refresh(): void
    {
        unset($this->batches);

        if ($this->batchId !== null) {
            $this->fillResults();
        }
    }

    public function openBatch(int $batchId): void
    {
        $this->batchId = ExamBatch::whereKey($batchId)->exists() ? $batchId : null;
        $this->fillResults();
    }

    public function back(): void
    {
        $this->batchId = null;
        $this->results = [];
        unset($this->batches);
    }

    public function saveResults(): void
    {
        $this->validate([
            'results.*.status' => ['nullable', 'in:'.implode(',', StudentExam::RESULTS)],
            'results.*.score' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            'results.*.score.numeric' => 'الدرجة رقم من ٠ إلى ١٠٠.',
            'results.*.score.max' => 'الدرجة رقم من ٠ إلى ١٠٠.',
        ]);

        $written = 0;

        foreach ($this->batch?->exams ?? [] as $exam) {
            $result = $this->results[$exam->id] ?? null;
            $status = $result['status'] ?? '';
            $score = ($result['score'] ?? '') === '' ? null : (string) $result['score'];

            $sameScore = $exam->score_percentage === null
                ? $score === null
                : $score !== null && (float) $exam->score_percentage === (float) $score;

            // Nothing chosen, or nothing changed: left as it is.
            if ($status === '' || ($status === $exam->status && $sameScore)) {
                continue;
            }

            ExamReadiness::recordResult($exam, $status, $score);
            $written++;
        }

        unset($this->batch, $this->batches);
        $this->fillResults();
        Flux::toast($written > 0 ? 'رُصدت النتائج' : 'لا جديد يُرصد', variant: $written > 0 ? 'success' : 'warning');
    }

    public function unseat(int $examId): void
    {
        $exam = $this->batch?->exams->firstWhere('id', $examId);

        if ($exam && $exam->status === StudentExam::SCHEDULED) {
            ExamReadiness::unseat($exam);
            Flux::toast('أُخرج '.$exam->student?->name.' من الدفعة، وعاد إلى الجاهزين.', variant: 'success');
        }

        unset($this->batch, $this->batches);
        $this->fillResults();
        $this->dispatch('exam-batch-saved');
    }

    public function openEdit(): void
    {
        $batch = $this->batch;

        if (! $batch) {
            return;
        }

        $this->resetValidation();
        $this->editDate = $batch->date_time->toDateString();
        $this->editTime = $batch->date_time->format('H:i');
        $this->editLocation = (string) $batch->location;
        $this->editNotes = (string) $batch->notes;
        Flux::modal('edit-exam-batch')->show();
    }

    public function saveEdit(): void
    {
        $batch = $this->batch;

        if (! $batch) {
            return;
        }

        $this->validate([
            'editDate' => ['required', 'date_format:Y-m-d'],
            'editTime' => ['required', 'date_format:H:i'],
            'editLocation' => ['nullable', 'string', 'max:255'],
            'editNotes' => ['nullable', 'string', 'max:2000'],
        ], [
            'editDate.required' => 'اختر يوم الاختبار.',
            'editTime.required' => 'اختر ساعة الاختبار.',
        ]);

        ExamReadiness::updateBatch($batch, "{$this->editDate} {$this->editTime}", $this->editLocation, $this->editNotes);

        Flux::modal('edit-exam-batch')->close();
        unset($this->batch, $this->batches);
        Flux::toast('حُفظت الدفعة', variant: 'success');
    }

    /** A batch left without students can go. */
    public function deleteBatch(): void
    {
        $batch = $this->batch;

        if ($batch && $batch->exams->isEmpty()) {
            $batch->delete();
            $this->back();
            Flux::toast('حُذفت الدفعة', variant: 'success');
        }
    }

    /** @return Collection<int, ExamBatch> */
    #[Computed]
    public function batches(): Collection
    {
        return ExamBatch::withCount([
            'exams',
            'exams as recorded_count' => fn ($q) => $q->whereIn('status', StudentExam::RESULTS),
        ])->orderByDesc('date_time')->get();
    }

    #[Computed]
    public function batch(): ?ExamBatch
    {
        return $this->batchId === null ? null : ExamBatch::with([
            'exams' => fn ($q) => $q->orderBy('id'),
            'exams.student:id,name,circle_id',
            'exams.student.circle:id,name',
            'exams.examLevel:id,name',
        ])->find($this->batchId);
    }

    public function render()
    {
        return view('livewire.manager.exam-batches', ['today' => TeacherSyncSnapshot::today()]);
    }

    private function fillResults(): void
    {
        unset($this->batch);

        $this->results = ($this->batch?->exams ?? collect())->mapWithKeys(fn (StudentExam $exam) => [
            $exam->id => [
                'status' => in_array($exam->status, StudentExam::RESULTS, true) ? $exam->status : '',
                'score' => $exam->score_percentage === null ? null : rtrim(rtrim((string) $exam->score_percentage, '0'), '.'),
            ],
        ])->all();
    }
}
