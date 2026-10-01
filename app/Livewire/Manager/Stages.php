<?php

namespace App\Livewire\Manager;

use App\Models\Stage;
use App\Models\Supervisor;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Stages extends Component
{
    public $stages;

    public $supervisorsList = [];

    public string $name = '';

    public string $description = '';

    #[Locked]
    public $editingStageId = null;

    public array $selectedSupervisors = [];

    public string $search = '';

    public string $supervisorFilter = 'all';

    public function mount()
    {
        $this->loadStages();
    }

    public function updatedSearch()
    {
        $this->loadStages();
    }

    public function updatedSupervisorFilter()
    {
        $this->loadStages();
    }

    public function loadStages()
    {
        $query = Stage::withCount('circles')->with('supervisors');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('description', 'like', '%'.$this->search.'%');
            });
        }

        if ($this->supervisorFilter !== 'all') {
            $query->whereHas('supervisors', function ($q) {
                $q->where('users.id', $this->supervisorFilter);
            });
        }

        // Newest-first would hide the very order this page exists to arrange.
        $this->stages = $query->get();
        $this->supervisorsList = Supervisor::whereRoleState(fn ($q) => $q->where('is_approved', true))->get();
    }

    /**
     * Move a stage one place up or down.
     *
     * The order is read wherever stages are listed — the reports, every stage
     * picker, the supervisors' own scopes — so this is the one screen that sets
     * it. Reordering while a search is active still moves the stage within the
     * whole list, not within the filtered view.
     */
    public function moveStage(int $id, int $direction): void
    {
        $stages = Stage::get()->values();

        // Normalised first: positions may have collided or never been set, and a
        // swap only means anything once each stage owns a distinct number.
        foreach ($stages as $index => $stage) {
            if ($stage->position !== $index + 1) {
                $stage->update(['position' => $index + 1]);
            }
        }

        $index = $stages->search(fn (Stage $stage) => $stage->id === $id);
        $target = $index === false ? null : ($stages[$index + $direction] ?? null);

        if ($target === null) {
            return;
        }

        $stages[$index]->update(['position' => $index + 1 + $direction]);
        $target->update(['position' => $index + 1]);

        $this->loadStages();
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($this->editingStageId) {
            $stage = Stage::find($this->editingStageId);
            $stage->update([
                'name' => $this->name,
                'description' => $this->description,
            ]);
            $stage->supervisors()->sync($this->selectedSupervisors);
            Flux::toast(__('تم تحديث المرحلة بنجاح'), variant: 'success');
        } else {
            $stage = Stage::create([
                'name' => $this->name,
                'description' => $this->description,
            ]);
            $stage->supervisors()->attach($this->selectedSupervisors);
            Flux::toast(__('تم إضافة المرحلة بنجاح'), variant: 'success');
        }

        $this->reset(['name', 'description', 'editingStageId', 'selectedSupervisors']);
        $this->loadStages();
        Flux::modal('stage-modal')->close();
    }

    public function edit($id)
    {
        $stage = Stage::findOrFail($id);
        $this->editingStageId = $stage->id;
        $this->name = $stage->name;
        $this->description = $stage->description ?? '';
        $this->selectedSupervisors = $stage->supervisors->pluck('id')->toArray();
        Flux::modal('stage-modal')->show();
    }

    public function create()
    {
        $this->cancel();
        Flux::modal('stage-modal')->show();
    }

    public function delete($id)
    {
        $stage = Stage::findOrFail($id);
        if ($stage->circles()->count() > 0) {
            Flux::toast(__('لا يمكن حذف المرحلة لاحتوائها على حلقات'), variant: 'danger');

            return;
        }

        $stage->delete();
        $this->loadStages();
        Flux::toast(__('تم حذف المرحلة بنجاح'), variant: 'success');
    }

    public function cancel()
    {
        $this->reset(['name', 'description', 'editingStageId', 'selectedSupervisors']);
    }

    public function render()
    {
        return view('livewire.manager.stages');
    }
}
