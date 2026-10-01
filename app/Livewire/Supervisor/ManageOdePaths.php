<?php

namespace App\Livewire\Supervisor;

use App\Models\Circle;
use App\Models\Ode;
use App\Models\OdePath;
use App\Models\StudentOdePlan;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ManageOdePaths extends Component
{
    public string $search = '';

    // Path form fields
    #[Locked]
    public ?int $editingPathId = null;

    public ?int $odeId = null;

    public string $name = '';

    public string $startDate = '';

    public string $endDate = '';

    public function mount(): void
    {
        $this->startDate = now()->format('Y-m-d');
    }

    public function createPath(): void
    {
        $this->reset(['editingPathId', 'odeId', 'name', 'endDate']);
        $this->startDate = now()->format('Y-m-d');
        Flux::modal('path-modal')->show();
    }

    public function editPath(int $id): void
    {
        $path = OdePath::findOrFail($id);
        $this->editingPathId = $path->id;
        $this->odeId = $path->ode_id;
        $this->name = $path->name;
        $this->startDate = $path->start_date->format('Y-m-d');
        $this->endDate = $path->end_date?->format('Y-m-d') ?? '';
        Flux::modal('path-modal')->show();
    }

    public function savePath(): void
    {
        $this->validate([
            'odeId' => 'required|exists:odes,id',
            'name' => 'required|string|max:255',
            'startDate' => 'required|date',
            'endDate' => 'nullable|date|after_or_equal:startDate',
        ]);

        if ($this->editingPathId) {
            $path = OdePath::findOrFail($this->editingPathId);
            $path->update([
                'ode_id' => $this->odeId,
                'name' => $this->name,
                'start_date' => $this->startDate,
                'end_date' => $this->endDate ?: null,
            ]);
            Flux::toast('تم تعديل مسار المنظومة بنجاح', variant: 'success');
        } else {
            OdePath::create([
                'ode_id' => $this->odeId,
                'name' => $this->name,
                'start_date' => $this->startDate,
                'end_date' => $this->endDate ?: null,
            ]);
            Flux::toast('تم إنشاء مسار المنظومة بنجاح', variant: 'success');
        }

        Flux::modal('path-modal')->close();
        $this->reset(['editingPathId', 'odeId', 'name', 'endDate']);
    }

    public function deletePath(int $id): void
    {
        $path = OdePath::findOrFail($id);
        $path->delete();
        Flux::toast('تم حذف مسار المنظومة بنجاح', variant: 'success');
    }

    // Enrollment fields
    #[Locked]
    public ?int $enrollingPathId = null;

    public array $selectedStudentIds = [];

    public string $studentSearch = '';

    public function showEnrollModal(int $pathId): void
    {
        $this->enrollingPathId = $pathId;
        $this->selectedStudentIds = StudentOdePlan::where('ode_path_id', $pathId)
            ->where('status', 'active')
            ->pluck('student_id')
            ->map(fn ($id) => (string) $id)
            ->toArray();
        $this->studentSearch = '';
        Flux::modal('enroll-modal')->show();
    }

    public function toggleSelectAll(array $allIds): void
    {
        $allIds = array_map('intval', $allIds);
        $selectedIds = array_map('intval', $this->selectedStudentIds);
        $alreadySelectedCount = count(array_intersect($selectedIds, $allIds));

        if ($alreadySelectedCount === count($allIds)) {
            // Deselect all
            $newSelected = array_diff($selectedIds, $allIds);
        } else {
            // Select all
            $newSelected = array_unique(array_merge($selectedIds, $allIds));
        }
        $this->selectedStudentIds = array_values(array_map('strval', $newSelected));
    }

    public function toggleSelectCircle(array $studentIds): void
    {
        $studentIds = array_map('intval', $studentIds);
        $selectedIds = array_map('intval', $this->selectedStudentIds);
        $alreadySelectedCount = count(array_intersect($selectedIds, $studentIds));

        if ($alreadySelectedCount === count($studentIds)) {
            // Deselect these students
            $newSelected = array_diff($selectedIds, $studentIds);
        } else {
            // Select these students
            $newSelected = array_unique(array_merge($selectedIds, $studentIds));
        }
        $this->selectedStudentIds = array_values(array_map('strval', $newSelected));
    }

    public function enrollStudents(): void
    {
        if (! $this->enrollingPathId) {
            return;
        }

        $path = OdePath::findOrFail($this->enrollingPathId);

        $selectedIds = array_map('intval', $this->selectedStudentIds);

        // Get currently active student IDs for this path
        $currentlyEnrolledStudentIds = StudentOdePlan::where('ode_path_id', $path->id)
            ->where('status', 'active')
            ->pluck('student_id')
            ->toArray();

        // 1. De-enroll students who were unchecked
        $toDeEnroll = array_diff($currentlyEnrolledStudentIds, $selectedIds);
        if (! empty($toDeEnroll)) {
            StudentOdePlan::where('ode_path_id', $path->id)
                ->whereIn('student_id', $toDeEnroll)
                ->where('status', 'active')
                ->update(['status' => 'suspended']);
        }

        // If no students are checked at all
        if (empty($selectedIds)) {
            Flux::toast('تم إلغاء تسكين جميع الطلاب من المسار', variant: 'success');
            Flux::modal('enroll-modal')->close();
            $this->reset(['enrollingPathId', 'selectedStudentIds', 'studentSearch']);

            return;
        }

        // 2. Enroll new students who were checked (no day copying — path days are shared)
        $toEnroll = array_diff($selectedIds, $currentlyEnrolledStudentIds);
        foreach ($toEnroll as $studentId) {
            // Deactivate any existing active ode plan for the student (on other paths)
            StudentOdePlan::where('student_id', $studentId)
                ->where('status', 'active')
                ->update(['status' => 'suspended']);

            // Create new plan — references the path directly, no day duplication
            StudentOdePlan::create([
                'student_id' => $studentId,
                'ode_path_id' => $path->id,
                'start_date' => $path->start_date,
                'status' => 'active',
                'created_by_role' => 'supervisor',
            ]);
        }

        Flux::toast('تم تسكين الطلاب بنجاح في المسار', variant: 'success');
        Flux::modal('enroll-modal')->close();
        $this->reset(['enrollingPathId', 'selectedStudentIds', 'studentSearch']);
    }

    private function getSupervisorCircleIds(): array
    {
        $supervisor = auth()->guard('supervisor')->user();
        if (! $supervisor) {
            return [];
        }

        return Circle::whereIn('stage_id', $supervisor->stages()->pluck('stages.id'))->pluck('id')->toArray();
    }

    public function render()
    {
        $query = OdePath::query()->with('ode');
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhereHas('ode', function ($sq) {
                        $sq->where('name', 'like', '%'.$this->search.'%');
                    });
            });
        }

        $paths = $query->latest()->get();
        $odes = Ode::orderBy('name')->get();

        return view('livewire.supervisor.manage-ode-paths', [
            'paths' => $paths,
            'odes' => $odes,
        ])->layout('layouts.role-shell');
    }
}
