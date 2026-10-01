<?php

namespace App\Livewire\Supervisor;

use App\Models\Circle;
use App\Models\Setting;
use App\Models\Teacher;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Teachers extends Component
{
    public $teachers;

    public $circles;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public array $selectedCircles = [];

    public array $permissions = [
        'can_manage_students' => false,
        'can_change_student_status' => false,
        'can_create_students' => false,
        'can_manage_hadith_paths' => false,
        'can_manage_ode_paths' => false,
        'can_manage_gamification_tracks' => false,
    ];

    /** Whether the editing teacher uses custom permissions (override) instead of global defaults */
    public bool $useCustomPermissions = false;

    /** The global default permissions applied to all teachers without a custom override */
    public array $globalPermissions = [
        'can_manage_students' => true,
        'can_change_student_status' => true,
        'can_create_students' => true,
        'can_manage_hadith_paths' => true,
        'can_manage_ode_paths' => true,
        'can_manage_gamification_tracks' => true,
    ];

    #[Locked]
    public $editingTeacherId = null;

    public string $quickName = '';

    public string $quickPhone = '';

    public ?int $quickCircleId = null;

    public string $search = '';

    public string $statusFilter = 'all';

    public string $circleFilter = 'all';

    public function mount()
    {
        $global = Setting::getVal('default_teacher_permissions');
        if (is_string($global)) {
            $global = json_decode($global, true);
        }
        // Merge over canonical defaults so any newly-introduced permission key is
        // present (and enabled) even if the stored settings row predates it.
        $this->globalPermissions = array_merge(
            Teacher::defaultPermissions(),
            is_array($global) ? $global : []
        );
        $this->loadData();
    }

    public function saveGlobalPermissions()
    {
        Setting::setVal('default_teacher_permissions', json_encode(
            $this->normalizePermissions($this->globalPermissions)
        ));
        Flux::toast(__('تم حفظ الصلاحيات الافتراضية بنجاح'), variant: 'success');
    }

    private function getSupervisorCircleIds()
    {
        $supervisor = auth()->guard('supervisor')->user();

        return Circle::whereIn('stage_id', $supervisor->stages()->pluck('stages.id'))->pluck('id')->toArray();
    }

    /**
     * Coerce a permissions form array into the canonical set of boolean keys.
     *
     * @param  array<string, mixed>  $permissions
     * @return array<string, bool>
     */
    private function normalizePermissions(array $permissions): array
    {
        return collect(Teacher::defaultPermissions())
            ->map(fn ($default, $key) => (bool) ($permissions[$key] ?? false))
            ->all();
    }

    public function loadData()
    {
        $circleIds = $this->getSupervisorCircleIds();
        $this->circles = Circle::with('stage')->whereIn('id', $circleIds)->get();

        $query = Teacher::with('circles')->whereHas('circles', function ($q) use ($circleIds) {
            $q->whereIn('circles.id', $circleIds);
        });

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
        $circleIds = $this->getSupervisorCircleIds();
        $teacher = Teacher::whereHas('circles', function ($q) use ($circleIds) {
            $q->whereIn('circles.id', $circleIds);
        })->find($id);

        if (! $teacher) {
            Flux::toast(__('المعلم غير موجود أو ليس ضمن صلاحياتك'), variant: 'danger');

            return;
        }

        $teacher->update([
            'is_approved' => true,
            'approved_by' => auth('manager')->id(),
        ]);
        $this->loadData();
        Flux::toast(__('تمت الموافقة على المعلم بنجاح'), variant: 'success');
    }

    public function createQuickTeacher()
    {
        $this->validate([
            'quickName' => 'required|string|min:2|max:255',
            'quickPhone' => 'nullable|string|max:20',
            'quickCircleId' => 'required|integer',
        ], [
            'quickCircleId.required' => 'يجب تحديد حلقة للمعلم الجديد حتى يظهر في قائمتك.',
        ]);

        $circleIds = $this->getSupervisorCircleIds();
        if (! in_array($this->quickCircleId, $circleIds)) {
            Flux::toast(__('الحلقة المحددة ليست ضمن صلاحياتك'), variant: 'danger');

            return;
        }

        $teacher = Teacher::create([
            'name' => $this->quickName,
            'phone' => $this->quickPhone,
            'email' => 'teacher_'.Str::random(10).'@uncompleted.altag.app',
            'password' => Hash::make(Str::random(10)),
            'is_approved' => true,
            'approved_by' => auth('manager')->id(),
            'access_token' => Str::random(32),
            'is_data_completed' => false,
        ]);

        $teacher->circles()->attach($this->quickCircleId);

        $this->reset(['quickName', 'quickPhone', 'quickCircleId']);
        $this->loadData();

        Flux::toast(__('تم إنشاء حساب المعلم بنجاح'), variant: 'success');
    }

    public function resetToken($id)
    {
        $circleIds = $this->getSupervisorCircleIds();
        $teacher = Teacher::whereHas('circles', function ($q) use ($circleIds) {
            $q->whereIn('circles.id', $circleIds);
        })->find($id);

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
        $circleIds = $this->getSupervisorCircleIds();
        $this->viewingTeacher = Teacher::with('circles')->whereHas('circles', function ($q) use ($circleIds) {
            $q->whereIn('circles.id', $circleIds);
        })->find($id);

        if (! $this->viewingTeacher) {
            Flux::toast(__('المعلم غير موجود أو ليس ضمن صلاحياتك'), variant: 'danger');

            return;
        }

        $this->editingTeacherId = $this->viewingTeacher->id;
        $this->name = $this->viewingTeacher->name;
        $this->email = $this->viewingTeacher->email;
        $this->phone = $this->viewingTeacher->phone ?? '';
        $this->selectedCircles = $this->viewingTeacher->circles->pluck('id')->toArray();

        if ($this->viewingTeacher->permissions !== null) {
            $this->useCustomPermissions = true;
            $this->permissions = $this->viewingTeacher->permissions;
        } else {
            $this->useCustomPermissions = false;
            // Pre-fill with global defaults as a starting point if supervisor enables override
            $this->permissions = $this->globalPermissions;
        }

        Flux::modal('teacher-modal')->show();
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$this->editingTeacherId,
            'phone' => 'nullable|string|max:20',
        ]);

        $circleIds = $this->getSupervisorCircleIds();
        $teacher = Teacher::whereHas('circles', function ($q) use ($circleIds) {
            $q->whereIn('circles.id', $circleIds);
        })->find($this->editingTeacherId);

        if (! $teacher) {
            Flux::toast(__('خطأ في الصلاحيات'), variant: 'danger');

            return;
        }

        // Ensure that selected circles are within supervisor's scope
        $validSelectedCircles = collect($this->selectedCircles)->intersect($circleIds)->toArray();

        $teacher->update([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            // null = inherit global defaults; array = custom override
            'permissions' => $this->useCustomPermissions
                ? $this->normalizePermissions($this->permissions)
                : null,
        ]);

        $teacher->circles()->sync($validSelectedCircles);

        Flux::toast(__('تم تحديث بيانات المعلم بنجاح'), variant: 'success');
        $this->reset(['name', 'email', 'phone', 'selectedCircles', 'permissions', 'useCustomPermissions', 'editingTeacherId']);
        $this->loadData();
        Flux::modal('teacher-modal')->close();
    }

    public function delete($id)
    {
        $circleIds = $this->getSupervisorCircleIds();
        $teacher = Teacher::whereHas('circles', function ($q) use ($circleIds) {
            $q->whereIn('circles.id', $circleIds);
        })->find($id);

        if ($teacher) {
            $teacher->delete();
            $this->loadData();
            Flux::toast(__('تم حذف المعلم بنجاح'), variant: 'success');
        } else {
            Flux::toast(__('المعلم غير موجود أو ليس ضمن صلاحياتك'), variant: 'danger');
        }
    }

    public function cancel()
    {
        $this->reset(['name', 'email', 'phone', 'selectedCircles', 'permissions', 'editingTeacherId']);
    }

    public function render()
    {
        return view('livewire.supervisor.teachers', [
            'globalPermissions' => $this->globalPermissions,
        ])->layout('layouts.role-shell');
    }
}
