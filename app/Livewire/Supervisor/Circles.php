<?php

namespace App\Livewire\Supervisor;

use App\Livewire\Concerns\MergesCircles;
use App\Models\Circle;
use App\Models\Teacher;
use App\Services\CircleMergeService;
use Flux\Flux;
use Livewire\Component;

class Circles extends Component
{
    use MergesCircles;

    /**
     * A supervisor merges only within the stages they hold.
     */
    protected function mergeableCircleIds(): ?array
    {
        return $this->getSupervisorCircleIds();
    }

    public $circles;

    public string $name = '';

    public string $description = '';

    public $editingCircleId = null;

    public $stage_id = null;

    public string $search = '';

    public string $teacherFilter = 'all';

    public array $selectedTeachers = [];

    public $teachersList = [];

    public $viewingCircleStudents = null;

    public $viewingCircleName = '';

    public function mount(): void
    {
        $this->loadData();
    }

    private function getSupervisorCircleIds(): array
    {
        $supervisor = auth()->guard('supervisor')->user();

        return Circle::whereIn('stage_id', $supervisor->stages()->pluck('stages.id'))->pluck('id')->toArray();
    }

    private function getSupervisorStages()
    {
        return auth()->guard('supervisor')->user()->stages()->get();
    }

    public function loadData(): void
    {
        $circleIds = $this->getSupervisorCircleIds();

        $query = Circle::with(['stage', 'teachers'])
            ->withCount('students')
            ->whereIn('id', $circleIds);

        if ($this->search) {
            $query->where('name', 'like', '%'.$this->search.'%');
        }

        if ($this->teacherFilter !== 'all') {
            $query->whereHas('teachers', function ($q) {
                $q->where('users.id', $this->teacherFilter);
            });
        }

        // Sorted in-memory (not via a SQL join) so the already-eager-loaded
        // "stage" relation can be reused without a stages/circles column-name
        // collision under a plain `select *`.
        $this->circles = $query->get()->sortBy(['stage.name', 'name'])->values();
        $this->teachersList = Teacher::whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->whereHas('circles', function ($q) use ($circleIds) {
                $q->whereIn('circles.id', $circleIds);
            })
            ->get();
    }

    public function updatedSearch(): void
    {
        $this->loadData();
    }

    public function updatedTeacherFilter(): void
    {
        $this->loadData();
    }

    public function create(): void
    {
        $stages = $this->getSupervisorStages();
        if ($stages->isEmpty()) {
            Flux::toast(__('عذراً، لا توجد مراحل تعليمية مخصصة لك لإضافة حلقة.'), variant: 'danger');

            return;
        }

        $this->reset(['name', 'description', 'editingCircleId', 'selectedTeachers', 'stage_id']);

        // Auto-select if there's only one stage
        if ($stages->count() === 1) {
            $this->stage_id = $stages->first()->id;
        }

        Flux::modal('circle-modal')->show();
    }

    public function edit($id): void
    {
        $circleIds = $this->getSupervisorCircleIds();
        $circle = Circle::whereIn('id', $circleIds)->findOrFail($id);

        $this->editingCircleId = $circle->id;
        $this->name = $circle->name;
        $this->description = $circle->description ?? '';
        $this->stage_id = $circle->stage_id;
        $this->selectedTeachers = $circle->teachers->pluck('id')->toArray();

        Flux::modal('circle-modal')->show();
    }

    public function viewStudents($id): void
    {
        $circleIds = $this->getSupervisorCircleIds();
        $circle = Circle::whereIn('id', $circleIds)->with('students')->findOrFail($id);

        $this->viewingCircleName = $circle->name;
        $this->viewingCircleStudents = $circle->students->map(fn ($student) => [
            'id' => $student->id,
            'name' => $student->name,
            'status' => $student->status,
            'memorization_percentage' => $student->memorizationPercentage(),
            'absences' => $student->getAbsencesInPeriodCount(),
            'lateness' => $student->getLatenessInPeriodCount(),
        ])->sortBy('name')->values();

        Flux::modal('circle-students-modal')->show();
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'stage_id' => 'required|exists:stages,id',
        ]);

        // Verify supervisor has access to the chosen stage, whether creating
        // or moving an existing circle to it.
        $validStages = $this->getSupervisorStages()->pluck('id')->toArray();
        if (! in_array((int) $this->stage_id, $validStages, true)) {
            abort(403, 'Unauthorized action.');
        }

        $circleIds = $this->getSupervisorCircleIds();

        if ($this->editingCircleId) {
            $circle = Circle::whereIn('id', $circleIds)->findOrFail($this->editingCircleId);
            $circle->update([
                'name' => $this->name,
                'description' => $this->description,
                'stage_id' => $this->stage_id,
            ]);
            $message = __('تم تحديث الحلقة بنجاح');
        } else {
            $circle = Circle::create([
                'name' => $this->name,
                'description' => $this->description,
                'stage_id' => $this->stage_id,
            ]);
            $message = __('تم إضافة الحلقة بنجاح');

            // Re-fetch circleIds after creating the new circle so it can assign teachers
            $circleIds = $this->getSupervisorCircleIds();
        }

        // Only assign teachers from this supervisor's scope
        $validTeachers = Teacher::whereHas('circles', function ($q) use ($circleIds) {
            $q->whereIn('circles.id', $circleIds);
        })->whereIn('id', $this->selectedTeachers)->pluck('id')->toArray();

        $circle->teachers()->sync($validTeachers);

        Flux::toast($message, variant: 'success');
        $this->reset(['name', 'description', 'editingCircleId', 'selectedTeachers', 'stage_id']);
        $this->loadData();
        Flux::modal('circle-modal')->close();
    }

    public function cancel(): void
    {
        $this->reset(['name', 'description', 'editingCircleId', 'selectedTeachers', 'stage_id']);
    }

    public function render(CircleMergeService $merges)
    {
        $mergeSource = $this->mergeSourceId ? Circle::find($this->mergeSourceId) : null;

        return view('livewire.supervisor.circles', [
            'stages' => $this->getSupervisorStages(),
            'mergeSource' => $mergeSource,
            'mergePreview' => $mergeSource ? $merges->preview($mergeSource) : null,
            'mergeableCircles' => Circle::whereIn('id', $this->getSupervisorCircleIds())->orderBy('name')->get(['id', 'name']),
            'recentMerges' => $this->recentMerges(),
        ])->layout('layouts.role-shell');
    }
}
