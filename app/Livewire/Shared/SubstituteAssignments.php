<?php

namespace App\Livewire\Shared;

use App\Models\Circle;
use App\Models\SubstituteAssignment;
use App\Models\Teacher;
use App\Support\HijriDate;
use App\Support\TeacherRollScope;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Who stands in for which circle on a day — granted here directly, for today
 * or a day ahead, or by naming the substitute of an absent teacher on the roll
 * call. A supervisor grants over the circles of their stages, the manager
 * over every circle; the substitute may be any teacher of the academy.
 *
 * On its day the circle is the substitute's to work in as its teacher is, for
 * that day's records only. A day gone by is kept as the record of who covered
 * and can no longer change.
 */
class SubstituteAssignments extends Component
{
    #[Locked]
    public string $role = 'supervisor';

    public string $date = '';

    public ?int $circleId = null;

    /** Whom the substitute stands in for: one of the circle's teachers, or none. */
    public ?int $absentTeacherId = null;

    public ?int $teacherId = null;

    private ?TeacherRollScope $scopeCache = null;

    public function mount(string $role = 'supervisor'): void
    {
        $this->role = $role;
        $this->scope();
        $this->date = $this->today();
    }

    public function updatedDate(): void
    {
        if (! $this->isValidDate()) {
            $this->date = $this->today();
        }
    }

    public function updatedCircleId(): void
    {
        // The circle's teacher is the one most often stood in for.
        $this->absentTeacherId = $this->circleTeachers()->first()?->id;
        $this->teacherId = null;
    }

    public function grant(): void
    {
        if (! $this->isOpen()) {
            Flux::toast(__('لا تُمنح الصلاحية ليوم مضى.'), variant: 'warning');

            return;
        }

        $this->validate([
            'circleId' => ['required', 'integer'],
            'teacherId' => ['required', 'integer'],
        ], [], ['circleId' => 'الحلقة', 'teacherId' => 'المعلم البديل']);

        $circle = Circle::whereIn('id', $this->scope()->circleIds())->find($this->circleId);
        $circleTeachers = $this->circleTeachers();
        $substitute = $this->candidates()->firstWhere('id', $this->teacherId);

        if (! $circle || ! $substitute || $circleTeachers->contains('id', $substitute->id)) {
            Flux::toast(__('اختر حلقة من حلقاتك، ومعلماً بديلاً ليس من معلميها.'), variant: 'danger');

            return;
        }

        $absentId = $circleTeachers->contains('id', $this->absentTeacherId) ? $this->absentTeacherId : null;

        // whereDate: the column carries a midnight time, so a bare "Y-m-d" would
        // miss a grant the roll call already made for the day.
        $grant = SubstituteAssignment::where('circle_id', $circle->id)
            ->where('teacher_id', $substitute->id)
            ->whereDate('date', $this->date)
            ->first() ?? new SubstituteAssignment([
                'circle_id' => $circle->id,
                'teacher_id' => $substitute->id,
                'date' => $this->date,
            ]);

        $grant->fill([
            'absent_teacher_id' => $absentId,
            'granted_by_id' => $this->scope()->editorId(),
            'granted_by_role' => $this->role,
        ])->save();

        $this->teacherId = null;

        Flux::toast(__('مُنح :name صلاحية الحلقة في هذا اليوم.', ['name' => $substitute->name]), variant: 'success');
    }

    public function revoke(int $grantId): void
    {
        $grant = SubstituteAssignment::whereIn('circle_id', $this->scope()->circleIds())->find($grantId);

        if (! $grant) {
            return;
        }

        if ($grant->date->format('Y-m-d') < $this->today()) {
            Flux::toast(__('مضى هذا اليوم، فيبقى سجلاً لمن غطّى الحلقة.'), variant: 'warning');

            return;
        }

        $grant->delete();

        Flux::toast(__('سُحبت الصلاحية.'), variant: 'success');
    }

    /**
     * The circle's own teachers — not candidates to stand in for it.
     *
     * @return EloquentCollection<int, Teacher>
     */
    private function circleTeachers(): EloquentCollection
    {
        if (! $this->circleId || ! in_array($this->circleId, $this->scope()->circleIds(), true)) {
            return new EloquentCollection;
        }

        return Teacher::whereHas('circles', fn ($q) => $q->whereKey($this->circleId))
            ->orderBy('name')
            ->get(['users.id', 'users.name']);
    }

    /**
     * Any approved teacher of the academy, of whatever stage.
     *
     * @return EloquentCollection<int, Teacher>
     */
    private function candidates(): EloquentCollection
    {
        return Teacher::whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->with('circles:id,stage_id', 'circles.stage:id,name')
            ->orderBy('name')
            ->get(['users.id', 'users.name']);
    }

    /** Today or a day ahead: a day gone by is the record of who covered. */
    private function isOpen(): bool
    {
        return $this->isValidDate() && $this->date >= $this->today();
    }

    private function isValidDate(): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) === 1
            && Carbon::createFromFormat('!Y-m-d', $this->date)->format('Y-m-d') === $this->date;
    }

    private function scope(): TeacherRollScope
    {
        return $this->scopeCache ??= TeacherRollScope::for($this->role);
    }

    private function today(): string
    {
        return now('Asia/Riyadh')->format('Y-m-d');
    }

    public function render()
    {
        $circleTeachers = $this->circleTeachers();

        return view('livewire.shared.substitute-assignments', [
            'grants' => SubstituteAssignment::whereDate('date', $this->date)
                ->whereIn('circle_id', $this->scope()->circleIds())
                ->with(['circle.stage:id,name', 'teacher.circles.stage:id,name', 'absentTeacher:id,name', 'grantedBy:id,name'])
                ->latest('id')
                ->get(),
            'circles' => Circle::whereIn('id', $this->scope()->circleIds())->with('stage:id,name')->orderBy('name')->get(['id', 'name', 'stage_id']),
            'circleTeachers' => $circleTeachers,
            'candidates' => $this->candidates()->reject(fn (Teacher $teacher) => $circleTeachers->contains('id', $teacher->id)),
            'open' => $this->isOpen(),
            'hijri' => HijriDate::withWeekday(Carbon::parse($this->date)),
        ]);
    }
}
