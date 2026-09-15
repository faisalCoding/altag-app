<?php

namespace App\Livewire\Supervisor;

use App\Concerns\CopiesStudentMagicLinks;
use App\Models\Circle;
use App\Models\Guardian;
use App\Models\Student;
use App\Services\StudentStatusService;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class Students extends Component
{
    use CopiesStudentMagicLinks, WithPagination;

    public $circles;

    public string $name = '';

    public string $email = '';

    public $circle_id = null;

    public $editingStudentId = null;

    public string $search = '';

    public string $statusFilter = 'all';

    public string $circleFilter = '';

    public $guardiansList = [];

    public $guardian_id = null;

    public string $editJoinedAt = '';

    public $viewingStudent = null;

    public array $stats = [];

    public string $deleteConfirmationInput = '';

    public $bulkCircleId = null;

    public string $bulkJoinedAt = '';

    public string $bulkStatus = 'active';

    public string $bulkStatusDate = '';

    public function mount(): void
    {
        $this->circles = Circle::with('stage')->whereIn('id', $this->getSupervisorCircleIds())->get();
        $this->guardiansList = Guardian::whereRoleState(fn ($q) => $q->where('is_approved', true))->get();
    }

    private function getSupervisorStageIds(): array
    {
        return auth()->guard('supervisor')->user()->stages()->pluck('stages.id')->toArray();
    }

    private function getSupervisorCircleIds(): array
    {
        return Circle::whereIn('stage_id', $this->getSupervisorStageIds())->pluck('id')->toArray();
    }

    /**
     * Constrain a student query to the supervisor's scope: students in their
     * circles, plus circle-less students assigned directly to one of their stages.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function scopeToSupervisor($query)
    {
        $circleIds = $this->getSupervisorCircleIds();
        $stageIds = $this->getSupervisorStageIds();

        return $query->where(function ($q) use ($circleIds, $stageIds) {
            $q->whereIn('circle_id', $circleIds)
                ->orWhere(function ($sub) use ($stageIds) {
                    $sub->whereNull('circle_id')->whereIn('stage_id', $stageIds);
                });
        });
    }

    protected function selectableStudentsQuery()
    {
        return $this->scopeToSupervisor(Student::query());
    }

    protected function filteredStudentsQuery()
    {
        $query = $this->scopeToSupervisor(Student::with(['circle.stage', 'stage', 'guardian']));

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

        if ($this->circleFilter) {
            $query->where('circle_id', $this->circleFilter);
        }

        return $query->latest();
    }

    public function updatedSearch(): void
    {
        $this->resetSelectAllToggle();
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetSelectAllToggle();
        $this->resetPage();
    }

    public function updatedCircleFilter(): void
    {
        $this->resetSelectAllToggle();
        $this->resetPage();
    }

    public function resetSelection(): void
    {
        $this->resetStudentSelection();
        $this->deleteConfirmationInput = '';
    }

    public function applyBulkCircle(): void
    {
        $this->validate([
            'bulkCircleId' => 'nullable|exists:circles,id',
        ]);

        $circleIds = $this->getSupervisorCircleIds();

        if ($this->bulkCircleId && ! in_array((int) $this->bulkCircleId, $circleIds)) {
            Flux::toast(__('الحلقة المختارة خارج نطاق صلاحياتك'), variant: 'danger');

            return;
        }

        $count = $this->selectedStudentsQuery()->update([
            'circle_id' => $this->bulkCircleId ?: null,
        ]);

        $this->resetSelection();
        $this->bulkCircleId = null;

        Flux::modal('bulk-circle-modal')->close();
        Flux::toast(__('تم تغيير حلقة '.$count.' طلاب بنجاح'), variant: 'success');
    }

    public function applyBulkJoinedAt(): void
    {
        $this->validate([
            'bulkJoinedAt' => 'required|date',
        ]);

        $count = $this->selectedStudentsQuery()->update([
            'joined_at' => $this->bulkJoinedAt,
        ]);

        $this->resetSelection();
        $this->bulkJoinedAt = '';

        Flux::modal('bulk-joined-at-modal')->close();
        Flux::toast(__('تم تغيير تاريخ التحاق '.$count.' طلاب بنجاح'), variant: 'success');
    }

    public function applyBulkStatus(): void
    {
        $this->validate([
            'bulkStatus' => 'required|in:active,registering,suspended,left',
            // Riyadh, not app.timezone: see the note in the student status manager.
            'bulkStatusDate' => 'nullable|date|before_or_equal:'.now('Asia/Riyadh')->format('Y-m-d'),
        ], [
            'bulkStatusDate.before_or_equal' => __('تاريخ سريان الحالة لا يمكن أن يكون في المستقبل'),
        ]);

        $students = $this->selectedStudentsQuery()->get();
        $count = 0;
        $skipped = collect();

        foreach ($students as $student) {
            try {
                StudentStatusService::changeStatus($student, $this->bulkStatus, $this->bulkStatusDate ?: null);
                $count++;
            } catch (\InvalidArgumentException $e) {
                $skipped->push($student->name);
            }
        }

        // Nothing changed, so nothing is closed or cleared: the selection and the
        // chosen date stay put to be corrected. Reporting success here — which is
        // what a bare count did — is how "the status does not change" becomes a
        // thing that happens silently.
        if ($count === 0 && $skipped->isNotEmpty()) {
            Flux::toast(
                __('لم تتغيّر أي حالة. التاريخ المختار يسبق آخر سجل حالة لهؤلاء الطلاب: ')
                    .$skipped->take(3)->implode('، ')
                    .($skipped->count() > 3 ? __(' وغيرهم') : '')
                    .__('. اختر تاريخاً أحدث، أو صحّح سجل الحالة من صفحة الطالب.'),
                variant: 'danger',
            );

            return;
        }

        if ($skipped->isNotEmpty()) {
            Flux::toast(
                __('تُخطّي '.$skipped->count().' طالباً لأن التاريخ يسبق آخر سجل حالة لهم: ').$skipped->take(3)->implode('، '),
                variant: 'warning',
            );
        }

        $this->resetSelection();
        $this->bulkStatus = 'active';
        $this->bulkStatusDate = '';

        Flux::modal('bulk-status-modal')->close();
        Flux::toast(__('تم تغيير حالة '.$count.' طالباً بنجاح'), variant: 'success');
    }

    public function applyBulkResetMagicLinks(): void
    {
        $students = $this->selectedStudentsQuery()->get();
        $count = $students->count();

        foreach ($students as $student) {
            $student->update([
                'access_token' => Str::random(32),
            ]);
        }

        $this->resetSelection();

        Flux::toast(__('تم تحديث الروابط السحرية لـ '.$count.' طلاب بنجاح'), variant: 'success');
    }

    public function confirmBulkDelete(): void
    {
        if ($this->deleteConfirmationInput !== 'تأكيد الحذف') {
            Flux::toast(__('يرجى إدخال نص التأكيد بشكل صحيح'), variant: 'danger');

            return;
        }

        $students = $this->selectedStudentsQuery()->get();
        $count = $students->count();

        foreach ($students as $student) {
            $student->delete();
        }

        $this->resetSelection();

        Flux::modal('bulk-delete-modal')->close();
        Flux::toast(__('تم حذف '.$count.' طلاب بنجاح'), variant: 'success');
    }

    public function approve($id): void
    {
        $student = $this->scopeToSupervisor(Student::query())->find($id);

        if (! $student) {
            Flux::toast(__('الطالب غير موجود أو ليس ضمن صلاحياتك'), variant: 'danger');

            return;
        }

        $student->update([
            'is_approved' => 1,
            'approved_by' => auth('manager')->id(),
        ]);

        Flux::toast(__('تمت الموافقة على الطالب بنجاح'), variant: 'success');
    }

    public function edit($id): void
    {
        $this->viewingStudent = $this->scopeToSupervisor(Student::with([
            'circle.stage',
            'guardian',
            'plans' => fn ($q) => $q->latest(),
            'odePlans' => fn ($q) => $q->latest(),
            'odePlans.path.ode',
            'odePlans.path.days',
            'attendances',
            'statusHistories',
        ]))->find($id);

        if (! $this->viewingStudent) {
            Flux::toast(__('الطالب غير موجود أو ليس ضمن صلاحياتك'), variant: 'danger');

            return;
        }

        $this->editingStudentId = $this->viewingStudent->id;
        $this->name = $this->viewingStudent->name;
        $this->email = $this->viewingStudent->email;
        $this->circle_id = $this->viewingStudent->circle_id;
        $this->guardian_id = $this->viewingStudent->guardian_id;
        $this->editJoinedAt = $this->viewingStudent->joined_at ? $this->viewingStudent->joined_at->format('Y-m-d') : '';

        $this->stats = [
            'present' => $this->viewingStudent->attendances->where('status', 'present')->count(),
            'absent' => $this->viewingStudent->attendances->where('status', 'absent')->count(),
            'late' => $this->viewingStudent->attendances->where('status', 'late')->count(),
        ];

        Flux::modal('student-modal')->show();
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$this->editingStudentId,
            'circle_id' => 'nullable|exists:circles,id',
            'guardian_id' => 'nullable|exists:users,id',
            'editJoinedAt' => 'nullable|date',
        ]);

        $circleIds = $this->getSupervisorCircleIds();

        // Validate that the chosen circle is within scope
        if ($this->circle_id && ! in_array($this->circle_id, $circleIds)) {
            Flux::toast(__('الحلقة المختارة خارج نطاق صلاحياتك'), variant: 'danger');

            return;
        }

        Student::find($this->editingStudentId)->update([
            'name' => $this->name,
            'email' => $this->email,
            'circle_id' => $this->circle_id,
            'guardian_id' => $this->guardian_id,
            'joined_at' => $this->editJoinedAt ?: null,
        ]);

        Flux::toast(__('تم تحديث بيانات الطالب بنجاح'), variant: 'success');
        $this->reset(['name', 'email', 'circle_id', 'guardian_id', 'editJoinedAt', 'editingStudentId']);
        Flux::modal('student-modal')->close();
    }

    #[On('student-status-updated')]
    public function refreshViewingStudent(): void
    {
        if ($this->viewingStudent) {
            $this->edit($this->viewingStudent->id);
        }
    }

    public function resetToken($id): void
    {
        $student = $this->scopeToSupervisor(Student::query())->find($id);

        if ($student) {
            $student->update(['access_token' => Str::random(32)]);
            if ($this->viewingStudent && $this->viewingStudent->id === $student->id) {
                $this->viewingStudent->access_token = $student->access_token;
            }
            Flux::toast(__('تم إنشاء رابط الدخول بنجاح'), variant: 'success');
        }
    }

    public function render()
    {
        return view('livewire.supervisor.students', [
            'students' => $this->filteredStudentsQuery()->paginate(20),
            'selectedMagicLinksText' => $this->buildSelectedMagicLinksText(),
            'selectedOutsideFilters' => $this->selectedOutsideFiltersCount(),
        ])->layout('layouts.role-shell');
    }
}
