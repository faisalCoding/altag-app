<?php

namespace App\Livewire\Manager;

use App\Concerns\CopiesMagicLinks;
use App\Models\Stage;
use App\Models\Supervisor;
use App\Support\HijriDate;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Supervisors extends Component
{
    use CopiesMagicLinks;

    public $supervisors;

    public $stages;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public array $selectedStages = [];

    public string $password = '';

    #[Locked]
    public $editingSupervisorId = null;

    public string $quickName = '';

    public string $quickPhone = '';

    public string $search = '';

    public string $statusFilter = 'all';

    public string $stageFilter = 'all';

    public function mount()
    {
        $this->stages = Stage::all();
        $this->loadData();
    }

    /**
     * The list as the filters on screen describe it. Shared with the selection,
     * so ticking the header checkbox picks exactly the rows a manager can see.
     */
    protected function listQuery()
    {
        $query = Supervisor::with('stages');

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

        if ($this->stageFilter !== 'all') {
            $query->whereHas('stages', function ($q) {
                $q->where('stages.id', $this->stageFilter);
            });
        }

        return $query->latest();
    }

    public function loadData()
    {
        $this->supervisors = $this->listQuery()->get();
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

    public function createQuickSupervisor()
    {
        $this->validate([
            'quickName' => 'required|string|min:2|max:255',
            'quickPhone' => 'nullable|string|max:20',
        ]);

        Supervisor::create([
            'name' => $this->quickName,
            'phone' => $this->quickPhone,
            'email' => 'supervisor_'.Str::random(10).'@uncompleted.altag.app',
            'password' => Hash::make(Str::random(10)),
            'is_approved' => true,
            'approved_by' => auth()->id(),
            'access_token' => Str::random(32),
            'is_data_completed' => false,
        ]);

        $this->reset(['quickName', 'quickPhone']);
        $this->loadData();

        Flux::toast(__('تم إنشاء حساب المشرف بنجاح'), variant: 'success');
    }

    public function resetToken($id)
    {
        $supervisor = Supervisor::find($id);
        if ($supervisor) {
            $supervisor->update([
                'access_token' => Str::random(32),
            ]);
            $this->loadData();
            if ($this->viewingSupervisor && $this->viewingSupervisor->id === $supervisor->id) {
                $this->viewingSupervisor->access_token = $supervisor->access_token;
            }
            Flux::toast(__('تم إعادة إنشاء الرابط السحري بنجاح'), variant: 'success');
        }
    }

    public $viewingSupervisor = null;

    public function edit($id)
    {
        $this->viewingSupervisor = Supervisor::with('stages')->find($id);

        if (! $this->viewingSupervisor) {
            Flux::toast(__('المشرف غير موجود'), variant: 'danger');

            return;
        }

        $this->editingSupervisorId = $this->viewingSupervisor->id;
        $this->name = $this->viewingSupervisor->name;
        $this->email = $this->viewingSupervisor->email;
        $this->phone = $this->viewingSupervisor->phone ?? '';
        $this->selectedStages = $this->viewingSupervisor->stages->pluck('id')->toArray();
        $this->password = '';
        Flux::modal('supervisor-modal')->show();
    }

    public function add()
    {
        $this->reset(['name', 'email', 'phone', 'password', 'selectedStages', 'editingSupervisorId', 'viewingSupervisor']);
        Flux::modal('supervisor-modal')->show();
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$this->editingSupervisorId,
            'phone' => 'nullable|string|max:20',
            'password' => $this->editingSupervisorId ? 'nullable|min:8' : 'required|min:8',
        ]);

        if ($this->editingSupervisorId) {
            $supervisor = Supervisor::find($this->editingSupervisorId);
            $supervisor->update([
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone,
            ]);
            if ($this->password) {
                $supervisor->update(['password' => bcrypt($this->password)]);
            }
            Flux::toast(__('تم تحديث بيانات المشرف بنجاح'), variant: 'success');
        } else {
            $supervisor = Supervisor::create([
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone,
                'password' => bcrypt($this->password),
                'is_approved' => true,
                'approved_by' => auth()->id(),
            ]);
            Flux::toast(__('تم إضافة المشرف بنجاح'), variant: 'success');
        }

        $supervisor->stages()->sync($this->selectedStages);

        $this->reset(['name', 'email', 'phone', 'password', 'selectedStages', 'editingSupervisorId', 'viewingSupervisor']);
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
        $this->reset(['name', 'email', 'phone', 'password', 'selectedStages', 'editingSupervisorId', 'viewingSupervisor']);
    }

    /**
     * Everyone this directory may act on. A manager sees the whole academy, so
     * nothing is excluded here — the narrowing happens in filteredQuery().
     */
    protected function selectableQuery()
    {
        return Supervisor::query();
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
        return 'supervisor.magic-link';
    }

    protected function magicLinkAudience(): string
    {
        return 'المشرفون';
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
        return view('livewire.manager.supervisors', [
            'selectedMagicLinksText' => $this->buildSelectedMagicLinksText(),
            'selectedOutsideFilters' => $this->selectedOutsideFiltersCount(),
        ]);
    }
}
