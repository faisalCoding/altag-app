<?php

namespace App\Livewire\Manager;

use App\Models\Circle;
use App\Models\Teacher;
use Flux\Flux;
use Livewire\Component;

class Teachers extends Component
{
    public $teachers;

    public $circles;

    public string $name = '';

    public string $email = '';

    public array $selectedCircles = [];

    public $editingTeacherId = null;

    public string $search = '';

    public string $statusFilter = 'all';

    public string $circleFilter = 'all';

    public function mount()
    {
        $this->loadData();
    }

    public function loadData()
    {
        $this->circles = Circle::with('stage')->get();
        $query = Teacher::with('circles');

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

        if ($this->circleFilter !== 'all') {
            $query->whereHas('circles', function ($q) {
                $q->where('circles.id', $this->circleFilter);
            });
        }

        $this->teachers = $query->latest()->get();
    }

    public function updatedSearch()
    {
        $this->loadData();
    }

    public function updatedStatusFilter()
    {
        $this->loadData();
    }

    public function updatedCircleFilter()
    {
        $this->loadData();
    }

    public function approve($id)
    {
        $teacher = Teacher::find($id);

        if (! $teacher) {
            Flux::toast(__('المعلم غير موجود'), variant: 'danger');

            return;
        }

        $teacher->update([
            'is_approved' => true,
            'approved_by' => auth()->id(),
        ]);
        $this->loadData();
        Flux::toast(__('تمت الموافقة على المعلم بنجاح'), variant: 'success');
    }

    public function edit($id)
    {
        $teacher = Teacher::find($id);

        if (! $teacher) {
            Flux::toast(__('المعلم غير موجود'), variant: 'danger');

            return;
        }

        $this->editingTeacherId = $teacher->id;
        $this->name = $teacher->name;
        $this->email = $teacher->email;
        $this->selectedCircles = $teacher->circles->pluck('id')->toArray();
        Flux::modal('teacher-modal')->show();
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:teachers,email,'.$this->editingTeacherId,
        ]);

        $teacher = Teacher::find($this->editingTeacherId);
        $teacher->update([
            'name' => $this->name,
            'email' => $this->email,
        ]);

        $teacher->circles()->sync($this->selectedCircles);

        Flux::toast(__('تم تحديث بيانات المعلم بنجاح'), variant: 'success');
        $this->reset(['name', 'email', 'selectedCircles', 'editingTeacherId']);
        $this->loadData();
        Flux::modal('teacher-modal')->close();
    }

    public function delete($id)
    {
        $teacher = Teacher::find($id);

        if ($teacher) {
            $teacher->delete();
        }

        $this->loadData();
        Flux::toast(__('تم حذف المعلم بنجاح'), variant: 'success');
    }

    public function cancel()
    {
        $this->reset(['name', 'email', 'selectedCircles', 'editingTeacherId']);
    }

    public function render()
    {
        return view('livewire.manager.teachers');
    }
}
