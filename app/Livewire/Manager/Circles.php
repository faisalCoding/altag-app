<?php

namespace App\Livewire\Manager;

use App\Livewire\Concerns\MergesCircles;
use App\Models\Circle;
use App\Models\Stage;
use App\Models\Teacher;
use App\Services\CircleMergeService;
use Flux\Flux;
use Livewire\Component;

class Circles extends Component
{
    use MergesCircles;

    /**
     * A manager may merge any circle into any other.
     */
    protected function mergeableCircleIds(): ?array
    {
        return null;
    }

    public $circles;

    public $stages;

    public string $name = '';

    public string $description = '';

    public $stage_id = null;

    public $editingCircleId = null;

    public string $search = '';

    public $stageFilter = null;

    public string $teacherFilter = 'all';

    public array $selectedTeachers = [];

    public $teachersList = [];

    public function mount()
    {
        $this->loadData();
    }

    public function loadData()
    {
        $this->stages = Stage::all();
        $query = Circle::with(['stage', 'teachers'])->withCount('students');

        if ($this->search) {
            $query->where('name', 'like', '%'.$this->search.'%');
        }

        if ($this->stageFilter) {
            $query->where('stage_id', $this->stageFilter);
        }

        if ($this->teacherFilter !== 'all') {
            $query->whereHas('teachers', function ($q) {
                $q->where('users.id', $this->teacherFilter);
            });
        }

        $this->circles = $query->latest()->get();
        $this->teachersList = Teacher::whereRoleState(fn ($q) => $q->where('is_approved', true))->get();
    }

    public function updatedSearch()
    {
        $this->loadData();
    }

    public function updatedStageFilter()
    {
        $this->loadData();
    }

    public function updatedTeacherFilter()
    {
        $this->loadData();
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'stage_id' => 'required|exists:stages,id',
        ]);

        if ($this->editingCircleId) {
            $circle = Circle::find($this->editingCircleId);
            $circle->update([
                'name' => $this->name,
                'description' => $this->description,
                'stage_id' => $this->stage_id,
            ]);
            $circle->teachers()->sync($this->selectedTeachers);
            Flux::toast(__('تم تحديث الحلقة بنجاح'), variant: 'success');
        } else {
            $circle = Circle::create([
                'name' => $this->name,
                'description' => $this->description,
                'stage_id' => $this->stage_id,
            ]);
            $circle->teachers()->attach($this->selectedTeachers);
            Flux::toast(__('تم إضافة الحلقة بنجاح'), variant: 'success');
        }

        $this->reset(['name', 'description', 'stage_id', 'editingCircleId', 'selectedTeachers']);
        $this->loadData();
        Flux::modal('circle-modal')->close();
    }

    public function edit($id)
    {
        $circle = Circle::findOrFail($id);
        $this->editingCircleId = $circle->id;
        $this->name = $circle->name;
        $this->description = $circle->description ?? '';
        $this->stage_id = $circle->stage_id;
        $this->selectedTeachers = $circle->teachers->pluck('id')->toArray();
        Flux::modal('circle-modal')->show();
    }

    public function create()
    {
        $this->cancel();
        Flux::modal('circle-modal')->show();
    }

    public function delete($id)
    {
        $circle = Circle::findOrFail($id);
        if ($circle->teachers()->count() > 0 || $circle->students()->count() > 0) {
            Flux::toast(__('لا يمكن حذف الحلقة لاحتوائها على معلمين أو طلاب'), variant: 'danger');

            return;
        }

        // Attendance, its revisions and the circle's competitions are all wired to
        // cascade, so deleting an emptied circle would take its register with it —
        // which is precisely the state a merge leaves a circle in.
        if ($circle->attendances()->exists() || $circle->leaderboards()->exists()) {
            Flux::toast(__('لا يمكن حذف الحلقة لأن سجل حضورها ومسابقاتها محفوظة فيها. أفرغها بالدمج واتركها للأرشيف.'), variant: 'danger');

            return;
        }

        $circle->delete();
        $this->loadData();
        Flux::toast(__('تم حذف الحلقة بنجاح'), variant: 'success');
    }

    public function cancel()
    {
        $this->reset(['name', 'description', 'stage_id', 'editingCircleId', 'selectedTeachers']);
    }

    public function render(CircleMergeService $merges)
    {
        $mergeSource = $this->mergeSourceId ? Circle::find($this->mergeSourceId) : null;

        return view('livewire.manager.circles', [
            'mergeSource' => $mergeSource,
            'mergePreview' => $mergeSource ? $merges->preview($mergeSource) : null,
            'mergeableCircles' => Circle::orderBy('name')->get(['id', 'name']),
            'recentMerges' => $this->recentMerges(),
        ]);
    }
}
