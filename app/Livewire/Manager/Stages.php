<?php

namespace App\Livewire\Manager;

use App\Models\Stage;
use App\Models\Supervisor;
use Flux\Flux;
use Livewire\Component;

class Stages extends Component
{
    public $stages;

    public $supervisorsList = [];

    public string $name = '';

    public string $description = '';

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
                $q->where('supervisors.id', $this->supervisorFilter);
            });
        }

        $this->stages = $query->latest()->get();
        $this->supervisorsList = Supervisor::where('is_approved', true)->get();
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
        $this->description = $stage->description;
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
