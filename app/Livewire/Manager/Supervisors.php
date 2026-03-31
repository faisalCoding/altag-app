<?php

namespace App\Livewire\Manager;

use App\Models\Stage;
use App\Models\Supervisor;
use Flux\Flux;
use Livewire\Component;

class Supervisors extends Component
{
    public $supervisors;

    public $stages;

    public string $name = '';

    public string $email = '';

    public array $selectedStages = [];

    public $editingSupervisorId = null;

    public string $search = '';

    public string $statusFilter = 'all';

    public string $stageFilter = 'all';

    public function mount()
    {
        $this->stages = Stage::all();
        $this->loadData();
    }

    public function loadData()
    {
        $query = Supervisor::with('stages');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('email', 'like', '%'.$this->search.'%');
            });
        }

        if ($this->statusFilter === 'pending') {
            $query->where('is_approved', false);
        } elseif ($this->statusFilter === 'approved') {
            $query->where('is_approved', true);
        }

        if ($this->stageFilter !== 'all') {
            $query->whereHas('stages', function ($q) {
                $q->where('stages.id', $this->stageFilter);
            });
        }

        $this->supervisors = $query->latest()->get();
    }

    public function updatedSearch()
    {
        $this->loadData();
    }

    public function updatedStatusFilter()
    {
        $this->loadData();
    }

    public function updatedStageFilter()
    {
        $this->loadData();
    }

    public function approve($id)
    {
        $supervisor = Supervisor::find($id);

        if (! $supervisor) {
            Flux::toast(__('المشرف غير موجود'), variant: 'danger');

            return;
        }

        $supervisor->update([
            'is_approved' => true,
            'approved_by' => auth()->id(),
        ]);
        $this->loadData();
        Flux::toast(__('تمت الموافقة على المشرف بنجاح'), variant: 'success');
    }

    public function edit($id)
    {
        $supervisor = Supervisor::find($id);

        if (! $supervisor) {
            Flux::toast(__('المشرف غير موجود'), variant: 'danger');

            return;
        }

        $this->editingSupervisorId = $supervisor->id;
        $this->name = $supervisor->name;
        $this->email = $supervisor->email;
        $this->selectedStages = $supervisor->stages->pluck('id')->toArray();
        Flux::modal('supervisor-modal')->show();
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:supervisors,email,'.$this->editingSupervisorId,
        ]);

        $supervisor = Supervisor::find($this->editingSupervisorId);
        $supervisor->update([
            'name' => $this->name,
            'email' => $this->email,
        ]);

        $supervisor->stages()->sync($this->selectedStages);

        Flux::toast(__('تم تحديث بيانات المشرف بنجاح'), variant: 'success');
        $this->reset(['name', 'email', 'selectedStages', 'editingSupervisorId']);
        $this->loadData();
        Flux::modal('supervisor-modal')->close();
    }

    public function delete($id)
    {
        $supervisor = Supervisor::find($id);

        if ($supervisor) {
            $supervisor->delete();
        }

        $this->loadData();
        Flux::toast(__('تم حذف المشرف بنجاح'), variant: 'success');
    }

    public function cancel()
    {
        $this->reset(['name', 'email', 'selectedStages', 'editingSupervisorId']);
    }

    public function render()
    {
        return view('livewire.manager.supervisors');
    }
}
