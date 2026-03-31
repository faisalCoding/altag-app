<?php

namespace App\Livewire\Manager;

use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('الموافقة على المستخدمين')]
#[Layout('layouts.app')]
class PendingApprovals extends Component
{
    use WithPagination;

    public string $activeTab = 'supervisor';

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    protected function getModelClass()
    {
        return match ($this->activeTab) {
            'supervisor' => Supervisor::class,
            'teacher' => Teacher::class,
            'student' => Student::class,
            default => Supervisor::class,
        };
    }

    public function approve(int $userId): void
    {
        $model = $this->getModelClass();
        $user = $model::findOrFail($userId);
        $user->update([
            'is_approved' => true,
            'approved_by' => Auth::id(),
        ]);
    }

    public function reject(int $userId): void
    {
        $model = $this->getModelClass();
        $model::findOrFail($userId)->delete();
    }

    #[Computed]
    public function pendingUsers()
    {
        $model = $this->getModelClass();
        return $model::query()
            ->where('is_approved', false)
            ->latest()
            ->paginate(10);
    }

    #[Computed]
    public function counts(): array
    {
        return [
            'supervisor' => Supervisor::where('is_approved', false)->count(),
            'teacher' => Teacher::where('is_approved', false)->count(),
            'student' => Student::where('is_approved', false)->count(),
        ];
    }

    public function render(): View
    {
        return view('livewire.manager.pending-approvals');
    }
}
