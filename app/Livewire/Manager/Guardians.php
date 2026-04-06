<?php

namespace App\Livewire\Manager;

use App\Models\Guardian;
use App\Models\Student;
use Flux\Flux;
use Livewire\Component;

class Guardians extends Component
{
    public $guardians;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public $editingGuardianId = null;

    public string $search = '';

    public string $statusFilter = 'all';

    public array $selectedStudents = [];

    public $studentsList = [];

    public function mount()
    {
        $this->studentsList = Student::all();
        $this->loadData();
    }

    public function loadData()
    {
        $query = Guardian::with('students');

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

        $this->guardians = $query->latest()->get();
    }

    public function updatedSearch()
    {
        $this->loadData();
    }

    public function updatedStatusFilter()
    {
        $this->loadData();
    }

    public function approve($id)
    {
        $guardian = Guardian::find($id);

        if (! $guardian) {
            Flux::toast(__('ولي الأمر غير موجود'), variant: 'danger');

            return;
        }

        $guardian->update([
            'is_approved' => true,
            'approved_by' => auth()->id(),
        ]);
        $this->loadData();
        Flux::toast(__('تمت الموافقة على ولي الأمر بنجاح'), variant: 'success');
    }

    public function edit($id)
    {
        $guardian = Guardian::find($id);

        if (! $guardian) {
            Flux::toast(__('ولي الأمر غير موجود'), variant: 'danger');

            return;
        }

        $this->editingGuardianId = $guardian->id;
        $this->name = $guardian->name;
        $this->email = $guardian->email;
        $this->phone = $guardian->phone ?? '';
        $this->selectedStudents = $guardian->students->pluck('id')->toArray();
        Flux::modal('guardian-modal')->show();
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:guardians,email,'.$this->editingGuardianId,
            'phone' => 'nullable|string|max:20',
        ]);

        $guardian = Guardian::find($this->editingGuardianId);
        $guardian->update([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
        ]);

        // Remove guardian_id from students that are no longer assigned to this guardian
        Student::where('guardian_id', $guardian->id)
            ->whereNotIn('id', $this->selectedStudents)
            ->update(['guardian_id' => null]);

        // Assign this guardian to the currently selected students
        if (! empty($this->selectedStudents)) {
            Student::whereIn('id', $this->selectedStudents)
                ->update(['guardian_id' => $guardian->id]);
        }

        Flux::toast(__('تم تحديث بيانات ولي الأمر بنجاح'), variant: 'success');
        $this->reset(['name', 'email', 'phone', 'selectedStudents', 'editingGuardianId']);
        $this->loadData();
        Flux::modal('guardian-modal')->close();
    }

    public function delete($id)
    {
        $guardian = Guardian::find($id);

        if ($guardian) {
            $guardian->delete();
        }

        $this->loadData();
        Flux::toast(__('تم حذف ولي الأمر بنجاح'), variant: 'success');
    }

    public function cancel()
    {
        $this->reset(['name', 'email', 'phone', 'selectedStudents', 'editingGuardianId']);
    }

    public function render()
    {
        return view('livewire.manager.guardians');
    }
}
