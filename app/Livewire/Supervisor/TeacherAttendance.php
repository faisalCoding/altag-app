<?php

namespace App\Livewire\Supervisor;

use App\Models\Circle;
use App\Models\Teacher;
use App\Models\TeacherAttendance as Record;
use App\Support\HijriDate;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The supervisor's roll call for the teachers in their stages — the same four
 * states the students' register uses, so the two read alike in reports.
 */
class TeacherAttendance extends Component
{
    public string $date = '';

    /** @var array<int, string> teacher id => status */
    public array $records = [];

    public string $search = '';

    public ?int $circleFilter = null;

    public function mount(): void
    {
        $this->date = now('Asia/Riyadh')->format('Y-m-d');
        $this->loadRecords();
    }

    public function updatedDate(): void
    {
        $this->loadRecords();
    }

    /**
     * Read back whatever was already marked for the chosen day.
     */
    public function loadRecords(): void
    {
        $this->records = Record::whereDate('date', $this->date)
            ->whereIn('teacher_id', $this->teachers()->pluck('id'))
            ->pluck('status', 'teacher_id')
            ->all();
    }

    public function mark(int $teacherId, string $status): void
    {
        if (! in_array($status, Record::STATUSES, true)) {
            return;
        }

        if (! $this->teachers()->contains('id', $teacherId)) {
            Flux::toast(__('هذا المعلم خارج نطاق صلاحياتك.'), variant: 'danger');

            return;
        }

        // Matched with whereDate rather than updateOrCreate: the column is cast to a
        // date and stored with a midnight time, so a plain equality against
        // "Y-m-d" finds nothing and the unique key rejects the insert that follows.
        $record = Record::whereDate('date', $this->date)->where('teacher_id', $teacherId)->first();

        if ($record) {
            $record->update(['status' => $status, 'recorded_by_id' => Auth::id()]);
        } else {
            Record::create([
                'teacher_id' => $teacherId,
                'date' => $this->date,
                'status' => $status,
                'recorded_by_id' => Auth::id(),
            ]);
        }

        $this->records[$teacherId] = $status;
    }

    /**
     * Mark everyone still unmarked as present — the common case, and the reason
     * a roll call of thirty people does not take thirty taps.
     */
    public function markRemainingPresent(): void
    {
        foreach ($this->teachers() as $teacher) {
            if (! isset($this->records[$teacher->id])) {
                $this->mark($teacher->id, 'present');
            }
        }

        Flux::toast(__('حُضِّر الباقون.'), variant: 'success');
    }

    public function clearDay(): void
    {
        Record::whereDate('date', $this->date)
            ->whereIn('teacher_id', $this->teachers()->pluck('id'))
            ->delete();

        $this->records = [];

        Flux::toast(__('حُذف تحضير هذا اليوم.'), variant: 'success');
    }

    /**
     * The teachers this supervisor may mark: those holding a circle in one of
     * their stages, narrowed by whatever they have typed or filtered.
     *
     * @return Collection<int, Teacher>
     */
    public function teachers(): Collection
    {
        $circleIds = $this->supervisorCircleIds();

        return Teacher::with('circles:id,name')
            ->whereHas('circles', fn ($q) => $q->whereIn('circles.id', $this->circleFilter
                ? array_intersect($circleIds, [$this->circleFilter])
                : $circleIds))
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, int>
     */
    private function supervisorCircleIds(): array
    {
        $supervisor = Auth::guard('supervisor')->user();

        return Circle::whereIn('stage_id', $supervisor->stages()->pluck('stages.id'))->pluck('id')->all();
    }

    public function render()
    {
        $teachers = $this->teachers();

        return view('livewire.supervisor.teacher-attendance', [
            'teachers' => $teachers,
            'circles' => Circle::whereIn('id', $this->supervisorCircleIds())->orderBy('name')->get(['id', 'name']),
            'markedCount' => collect($this->records)->only($teachers->pluck('id'))->count(),
            'hijri' => HijriDate::withWeekday(Carbon::parse($this->date)),
        ]);
    }
}
