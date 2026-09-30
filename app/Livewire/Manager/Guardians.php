<?php

namespace App\Livewire\Manager;

use App\Concerns\CopiesMagicLinks;
use App\Models\Guardian;
use App\Models\Student;
use App\Support\HijriDate;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Guardians extends Component
{
    use CopiesMagicLinks;

    public $guardians;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public $editingGuardianId = null;

    public string $search = '';

    public string $statusFilter = 'all';

    public array $selectedStudents = [];

    public string $studentSearch = '';

    public function mount()
    {
        $this->loadData();
    }

    #[Computed]
    public function groupedStudents()
    {
        $query = Student::with('circle');

        if ($this->studentSearch) {
            $query->where('name', 'like', '%'.$this->studentSearch.'%');
        }

        $students = $query->get();

        return $students->groupBy(function ($s) {
            return $s->circle ? $s->circle->name : 'بدون حلقة';
        });
    }

    /**
     * The list as the filters on screen describe it. Shared with the selection,
     * so ticking the header checkbox picks exactly the rows a manager can see.
     */
    protected function listQuery()
    {
        $query = Guardian::with('students');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('email', 'like', '%'.$this->search.'%');
            });
        }

        if ($this->statusFilter === 'pending') {
            $query->whereRoleState(fn ($q) => $q->where('is_approved', false));
        } elseif ($this->statusFilter === 'approved') {
            $query->whereRoleState(fn ($q) => $q->where('is_approved', true));
        }

        return $query->latest();
    }

    public function loadData()
    {
        $this->guardians = $this->listQuery()->get();
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

    public $viewingGuardian = null;

    public function edit($id)
    {
        $this->viewingGuardian = Guardian::with('students')->find($id);

        if (! $this->viewingGuardian) {
            Flux::toast(__('ولي الأمر غير موجود'), variant: 'danger');

            return;
        }

        $this->editingGuardianId = $this->viewingGuardian->id;
        $this->name = $this->viewingGuardian->name;
        $this->email = $this->viewingGuardian->email;
        $this->phone = $this->viewingGuardian->phone ?? '';
        $this->selectedStudents = $this->viewingGuardian->students->pluck('id')->toArray();
        Flux::modal('guardian-modal')->show();
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$this->editingGuardianId,
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

    public function resetToken($id)
    {
        $guardian = Guardian::find($id);
        if ($guardian) {
            $guardian->update([
                'access_token' => Str::random(32),
            ]);
            $this->loadData();
            // Update viewingGuardian so the modal shows the new token immediately
            if ($this->viewingGuardian && $this->viewingGuardian->id === $guardian->id) {
                $this->viewingGuardian->access_token = $guardian->access_token;
            }
            Flux::toast(__('تم إنشاء رابط الدخول بنجاح'), variant: 'success');
        }
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

    /**
     * Everyone this directory may act on. A manager sees the whole academy, so
     * nothing is excluded here — the narrowing happens in filteredQuery().
     */
    protected function selectableQuery()
    {
        return Guardian::query();
    }

    /**
     * The same set as the list on screen, so ticking the header checkbox picks
     * exactly the rows the manager can see.
     */
    protected function filteredQuery()
    {
        return $this->listQuery();
    }

    protected function magicLinkRoute(): string
    {
        return 'guardian.magic-link';
    }

    protected function magicLinkAudience(): string
    {
        return 'الأوصياء';
    }

    public function copySelectedMagicLinks(): void
    {
        // Nothing to do here but let the render pass rebuild the text; the
        // button reads it out of the view and hands it to the clipboard.
    }

    public function regenerateSelected(): void
    {
        $count = $this->regenerateSelectedMagicLinks();

        if ($count === 0) {
            Flux::toast(__('لم يُحدَّد أحد.'), variant: 'danger');

            return;
        }

        $this->resetSelection();
        $this->loadData();

        Flux::toast(
            __('أُبطلت الروابط القديمة وأُنشئت :count روابط جديدة.', ['count' => HijriDate::arabicDigits($count)]),
            variant: 'success',
        );
    }

    public function render()
    {
        return view('livewire.manager.guardians', [
            'selectedMagicLinksText' => $this->buildSelectedMagicLinksText(),
            'selectedOutsideFilters' => $this->selectedOutsideFiltersCount(),
        ]);
    }
}
