<?php

namespace App\Livewire\Manager;

use App\Concerns\CopiesMagicLinks;
use App\Models\Circle;
use App\Models\Teacher;
use App\Support\HijriDate;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Teachers extends Component
{
    use CopiesMagicLinks;

    public $teachers;

    public $circles;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public array $selectedCircles = [];

    #[Locked]
    public $editingTeacherId = null;

    public string $quickName = '';

    public string $quickPhone = '';

    public string $search = '';

    public string $statusFilter = 'all';

    public string $circleFilter = 'all';

    public function mount()
    {
        $this->loadData();
    }

    /**
     * The list as the filters on screen describe it. Shared with the selection,
     * so ticking the header checkbox picks exactly the rows a manager can see.
     */
    protected function listQuery()
    {
        $query = Teacher::with('circles');

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

        if ($this->circleFilter !== 'all') {
            $query->whereHas('circles', function ($q) {
                $q->where('circles.id', $this->circleFilter);
            });
        }

        return $query->latest();
    }

    public function loadData()
    {
        $this->circles = Circle::with('stage')->get();
        $this->teachers = $this->listQuery()->get();
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

    public function createQuickTeacher()
    {
        $this->validate([
            'quickName' => 'required|string|min:2|max:255',
            'quickPhone' => 'nullable|string|max:20',
        ]);

        Teacher::create([
            'name' => $this->quickName,
            'phone' => $this->quickPhone,
            'email' => 'teacher_'.Str::random(10).'@uncompleted.altag.app',
            'password' => Hash::make(Str::random(10)),
            'is_approved' => true,
            'approved_by' => auth()->id(),
            'access_token' => Str::random(32),
            'is_data_completed' => false,
        ]);

        $this->reset(['quickName', 'quickPhone']);
        $this->loadData();

        Flux::toast(__('تم إنشاء حساب المعلم بنجاح'), variant: 'success');
    }

    public function resetToken($id)
    {
        $teacher = Teacher::find($id);
        if ($teacher) {
            $teacher->update([
                'access_token' => Str::random(32),
            ]);
            $this->loadData();
            if ($this->viewingTeacher && $this->viewingTeacher->id === $teacher->id) {
                $this->viewingTeacher->access_token = $teacher->access_token;
            }
            Flux::toast(__('تم إعادة إنشاء الرابط السحري بنجاح'), variant: 'success');
        }
    }

    public $viewingTeacher = null;

    public function edit($id)
    {
        $this->viewingTeacher = Teacher::with('circles')->find($id);

        if (! $this->viewingTeacher) {
            Flux::toast(__('المعلم غير موجود'), variant: 'danger');

            return;
        }

        $this->editingTeacherId = $this->viewingTeacher->id;
        $this->name = $this->viewingTeacher->name;
        $this->email = $this->viewingTeacher->email;
        $this->phone = $this->viewingTeacher->phone ?? '';
        $this->selectedCircles = $this->viewingTeacher->circles->pluck('id')->toArray();
        Flux::modal('teacher-modal')->show();
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$this->editingTeacherId,
            'phone' => 'nullable|string|max:20',
        ]);

        $teacher = Teacher::find($this->editingTeacherId);
        $teacher->update([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
        ]);

        $teacher->circles()->sync($this->selectedCircles);

        Flux::toast(__('تم تحديث بيانات المعلم بنجاح'), variant: 'success');
        $this->reset(['name', 'email', 'phone', 'selectedCircles', 'editingTeacherId']);
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
        $this->reset(['name', 'email', 'phone', 'selectedCircles', 'editingTeacherId']);
    }

    /**
     * Everyone this directory may act on. A manager sees the whole academy, so
     * nothing is excluded here — the narrowing happens in filteredQuery().
     */
    protected function selectableQuery()
    {
        return Teacher::query();
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
        return 'teacher.magic-link';
    }

    protected function magicLinkAudience(): string
    {
        return 'المعلمون';
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
        return view('livewire.manager.teachers', [
            'selectedMagicLinksText' => $this->buildSelectedMagicLinksText(),
            'selectedOutsideFilters' => $this->selectedOutsideFiltersCount(),
        ]);
    }
}
