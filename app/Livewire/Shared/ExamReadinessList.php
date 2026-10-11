<?php

namespace App\Livewire\Shared;

use App\Models\ExamBatch;
use App\Models\ExamLevel;
use App\Models\Stage;
use App\Models\StudentExam;
use App\Services\ExamReadiness;
use App\Services\ExamSnapshot;
use App\Services\TeacherSyncSnapshot;
use App\Support\HijriDate;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The students put forward for the association's exam: those their teachers
 * have ready, by stage and circle, and those only expected to be.
 *
 * The manager ticks students — one by one, a circle, a stage or every one
 * ready — and seats them in a dated batch, a new one or one already set. A
 * student only expected may be ticked too; when their expected day falls
 * after the batch's, the manager is warned before seating them. A supervisor
 * reads the same lists for the stages they oversee, and seats no one.
 */
class ExamReadinessList extends Component
{
    #[Locked]
    public string $role = 'manager';

    public ?int $levelId = null;

    public ?int $stageId = null;

    /** @var array<int, int|string> the exams ticked for a batch, ready or expected */
    public array $selected = [];

    public ?string $batchDate = null;

    public string $batchTime = '16:00';

    public string $batchLocation = '';

    public string $batchNotes = '';

    public ?int $targetBatchId = null;

    public function mount(string $role = 'manager'): void
    {
        abort_unless(in_array($role, ['manager', 'supervisor'], true) && auth($role)->check(), 403);

        $this->role = $role;
    }

    public function updatedLevelId(): void
    {
        $this->selected = [];
    }

    public function updatedStageId(): void
    {
        $this->selected = [];
    }

    /**
     * Tick every one of these students, or, when all are ticked already,
     * none of them: a circle, a stage, or everyone ready.
     *
     * @param  array<int, int|string>  $examIds
     */
    public function toggleMany(array $examIds): void
    {
        if (! $this->mayBatch()) {
            return;
        }

        $ids = array_map('intval', $examIds);
        $ticked = array_map('intval', $this->selected);

        $this->selected = array_values(array_diff($ids, $ticked) === []
            ? array_diff($ticked, $ids)
            : array_unique([...$ticked, ...$ids]));
    }

    public function openNewBatch(): void
    {
        if (! $this->mayBatch() || $this->selected === []) {
            return;
        }

        $this->resetValidation();
        $this->batchDate = null;
        $this->batchTime = '16:00';
        $this->batchLocation = '';
        $this->batchNotes = '';
        Flux::modal('new-exam-batch')->show();
    }

    public function createBatch(): void
    {
        if (! $this->mayBatch()) {
            return;
        }

        $this->validate([
            'batchDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.TeacherSyncSnapshot::today()],
            'batchTime' => ['required', 'date_format:H:i'],
            'batchLocation' => ['nullable', 'string', 'max:255'],
            'batchNotes' => ['nullable', 'string', 'max:2000'],
            'selected' => ['required', 'array', 'min:1'],
        ], [
            'batchDate.required' => 'اختر يوم الاختبار.',
            'batchDate.after_or_equal' => 'مضى هذا اليوم، فاختر يوماً قادماً.',
            'batchTime.required' => 'اختر ساعة الاختبار.',
            'selected.required' => 'حدّد الطلاب أولاً.',
        ]);

        $batch = ExamReadiness::createBatch(
            "{$this->batchDate} {$this->batchTime}",
            $this->batchLocation,
            $this->batchNotes,
            $this->selectedIds(),
            auth('manager')->user(),
        );

        Flux::modal('new-exam-batch')->close();
        $this->seated($batch, $batch->exams()->count());
    }

    public function addToBatch(): void
    {
        if (! $this->mayBatch()) {
            return;
        }

        $batch = $this->upcomingBatches->firstWhere('id', $this->targetBatchId);

        if (! $batch || $this->selected === []) {
            Flux::toast('اختر الدفعة وحدّد الطلاب أولاً.', variant: 'warning');

            return;
        }

        $this->seated($batch, ExamReadiness::seat($batch, $this->selectedIds()));
    }

    /** @return Collection<int, ExamLevel> */
    #[Computed]
    public function levels(): Collection
    {
        return ExamSnapshot::levels();
    }

    /** @return Collection<int, Stage> */
    #[Computed]
    public function stages(): Collection
    {
        return Stage::whereIn('id', $this->stageIds() ?? Stage::pluck('id'))->orderBy('position')->orderBy('id')->get(['id', 'name']);
    }

    /** @return Collection<int, StudentExam> */
    #[Computed]
    public function ready(): Collection
    {
        return $this->nominations()->whereNotNull('ready_at')->orderBy('ready_at')->get();
    }

    /**
     * The ready, by stage — in the stages' own order — then by circle.
     *
     * @return Collection<int, array{name: string, ids: array<int, int>, circles: Collection<int, array{name: string, ids: array<int, int>, exams: Collection<int, StudentExam>}>}>
     */
    #[Computed]
    public function readyGroups(): Collection
    {
        return $this->ready
            ->groupBy(fn (StudentExam $exam) => $exam->student?->circle?->stage_id ?? 0)
            ->sortBy(fn (Collection $exams) => ExamBatch::stageOrder($exams->first()))
            ->map(fn (Collection $exams) => [
                'name' => $exams->first()->student?->circle?->stage?->name ?? 'بلا مرحلة',
                'ids' => $exams->modelKeys(),
                'circles' => $exams
                    ->groupBy(fn (StudentExam $exam) => $exam->student?->circle_id ?? 0)
                    ->sortBy(fn (Collection $circle) => $circle->first()->student?->circle?->name ?? "\u{10FFFF}")
                    ->map(fn (Collection $circle) => [
                        'name' => $circle->first()->student?->circle?->name ?? 'بلا حلقة',
                        'ids' => $circle->modelKeys(),
                        'exams' => $circle->sortBy(fn (StudentExam $exam) => $exam->student?->name)->values(),
                    ])
                    ->values(),
            ])
            ->values();
    }

    /** @return Collection<int, StudentExam> */
    #[Computed]
    public function expected(): Collection
    {
        return $this->nominations()->whereNull('ready_at')->orderBy('expected_ready_on')->get();
    }

    /**
     * The ticked students expected ready only after the day given — the new
     * batch's, or the one they would be added to.
     *
     * @return Collection<int, StudentExam>
     */
    public function lateFor(?string $day): Collection
    {
        if (! filled($day)) {
            return collect();
        }

        $ticked = array_map('intval', $this->selected);

        return $this->expected
            ->filter(fn (StudentExam $exam) => in_array($exam->id, $ticked, true)
                && $exam->expected_ready_on !== null
                && $exam->expected_ready_on->toDateString() > $day)
            ->values();
    }

    /** @return Collection<int, StudentExam> */
    #[Computed]
    public function lateForNewBatch(): Collection
    {
        return $this->lateFor($this->batchDate);
    }

    /** @return Collection<int, StudentExam> */
    #[Computed]
    public function lateForTarget(): Collection
    {
        return $this->lateFor($this->upcomingBatches->firstWhere('id', $this->targetBatchId)?->date_time?->toDateString());
    }

    /** @return Collection<int, ExamBatch> */
    #[Computed]
    public function upcomingBatches(): Collection
    {
        return ExamBatch::whereDate('date_time', '>=', TeacherSyncSnapshot::today())->withCount('exams')->orderBy('date_time')->get();
    }

    public function mayBatch(): bool
    {
        return $this->role === 'manager';
    }

    public function render()
    {
        return view('livewire.shared.exam-readiness-list', ['today' => TeacherSyncSnapshot::today()]);
    }

    /** @return Builder<StudentExam> */
    private function nominations(): Builder
    {
        $stageIds = $this->stageId !== null ? [$this->stageId] : $this->stageIds();

        return StudentExam::nominated()
            ->when($this->levelId, fn ($q) => $q->where('exam_level_id', $this->levelId))
            ->when($stageIds !== null, fn ($q) => $q->whereHas('student.circle', fn ($c) => $c->whereIn('stage_id', $stageIds)))
            ->with('student:id,name,circle_id', 'student.circle:id,name,stage_id', 'student.circle.stage:id,name,position', 'examLevel:id,name', 'nominator:id,name');
    }

    /**
     * The stages whose students may be read: a supervisor's own, or every
     * stage (null) for the manager.
     *
     * @return array<int, int>|null
     */
    private function stageIds(): ?array
    {
        return $this->role === 'supervisor'
            ? auth('supervisor')->user()->stages()->pluck('stages.id')->all()
            : null;
    }

    /** @return array<int, int> the ticked exams still listed, ready or expected */
    private function selectedIds(): array
    {
        return array_values(array_intersect(
            [...$this->ready->modelKeys(), ...$this->expected->modelKeys()],
            array_map('intval', $this->selected),
        ));
    }

    private function seated(ExamBatch $batch, int $count): void
    {
        $this->selected = [];
        unset($this->ready, $this->readyGroups, $this->expected, $this->upcomingBatches);

        Flux::toast('جُدول '.HijriDate::arabicDigits($count).' في دفعة '.ExamReadiness::when($batch).'، وأُبلغ الطلاب وأولياء أمورهم.', variant: 'success');
        $this->dispatch('exam-batch-saved');
    }
}
