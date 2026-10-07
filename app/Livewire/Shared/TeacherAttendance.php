<?php

namespace App\Livewire\Shared;

use App\Models\AcademicCalendarEvent;
use App\Models\Circle;
use App\Models\Teacher;
use App\Models\TeacherAttendance as Record;
use App\Models\TeacherAttendanceRevision;
use App\Services\TeacherAttendanceService;
use App\Support\HijriDate;
use App\Support\TeacherAttendanceSettings;
use App\Support\TeacherRollScope;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The roll call for the teachers — the same four states the students'
 * register uses, so the two read alike in reports.
 *
 * A supervisor's covers the teachers holding a circle in their stages; the
 * manager's covers every approved teacher, so the teachers of a stage no
 * supervisor covers, and those with no circle at all, are not left out.
 *
 * Only a day that has come, and only a working day of the teacher's stage:
 * a mark on a holiday would count against the teacher in every report.
 */
class TeacherAttendance extends Component
{
    /** Whose roll call this is: the guard it was opened under. */
    #[Locked]
    public string $role = 'supervisor';

    public string $date = '';

    /** @var array<int, string> teacher id => status */
    public array $records = [];

    /**
     * Kept as a property so a supervisor who reloads keeps what they typed, but
     * the filtering itself happens in the browser — the whole list is already
     * there, and a round trip per keystroke is the opposite of smooth.
     */
    public string $search = '';

    public ?int $circleFilter = null;

    /**
     * Whether the chosen day is past the window a supervisor may still change —
     * read by the browser to grey the buttons out; every write checks again.
     */
    #[Locked]
    public bool $locked = false;

    /**
     * The ids in the order they are listed, so the browser can walk them
     * without asking the server where to go next.
     *
     * @var array<int, int>
     */
    public array $teacherOrder = [];

    /** @var EloquentCollection<int, Teacher>|null */
    private ?EloquentCollection $teacherCache = null;

    /** @var EloquentCollection<int, Teacher>|null */
    private ?EloquentCollection $rollCache = null;

    /** @var Collection<int, Record>|null */
    private ?Collection $dayCache = null;

    private ?TeacherRollScope $scopeCache = null;

    /** @var EloquentCollection<int, Teacher>|null */
    private ?EloquentCollection $substituteCache = null;

    public function mount(string $role = 'supervisor'): void
    {
        $this->role = $role;
        $this->scope();

        $this->date = $this->today();
        $this->loadRecords();
    }

    public function updatedDate(): void
    {
        if (! $this->isMarkableDay()) {
            if ($this->isValidDate()) {
                Flux::toast(__('لا يُحضَّر يوم لم يأتِ بعد.'), variant: 'warning');
            }

            $this->date = $this->today();
        }

        $this->loadRecords();
    }

    public function updatedCircleFilter(): void
    {
        $this->loadRecords();
    }

    /**
     * Read back whatever was already marked for the chosen day.
     */
    public function loadRecords(): void
    {
        $this->forget();

        $this->records = $this->dayRecords()->map->status->all();
        $this->teacherOrder = $this->rollTeachers()->modelKeys();
        $this->locked = $this->isLocked();

        // Tells the browser to start the walk over, the way the students'
        // register does when its list changes underneath it.
        $this->dispatch('teachersLoaded');
    }

    public function mark(int $teacherId, string $status): void
    {
        if (! in_array($status, Record::STATUSES, true) || ! $this->isWritable()) {
            return;
        }

        $teacher = $this->rollTeachers()->firstWhere('id', $teacherId);

        if (! $teacher) {
            Flux::toast($this->teachers()->contains('id', $teacherId)
                ? __('هذا اليوم ليس يوم دوام لهذا المعلم.')
                : __('هذا المعلم خارج نطاق صلاحياتك.'), variant: 'danger');

            return;
        }

        $this->file($teacher, $status);

        $this->records[$teacherId] = $status;
        $this->dayCache = null;
    }

    /**
     * The reason for a day — and for a late teacher when they arrived, for an
     * absent one who covered — added once the day is marked, from the list.
     */
    public function saveNote(int $teacherId, string $notes = '', ?string $arrivedAt = null, int|string|null $substituteId = null): void
    {
        if (! $this->isWritable() || ! $this->rollTeachers()->contains('id', $teacherId)) {
            return;
        }

        $substituteId = $substituteId ? (int) $substituteId : null;

        if (mb_strlen($notes) > 500
            || ($arrivedAt && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $arrivedAt))
            || ($substituteId && ($substituteId === $teacherId || ! $this->substitutes()->contains('id', $substituteId)))) {
            Flux::toast(__('تعذّر الحفظ: السبب أطول من ٥٠٠ حرف، أو الوقت أو المعلم البديل غير صحيح.'), variant: 'danger');

            return;
        }

        $record = Record::whereDate('date', $this->date)->where('teacher_id', $teacherId)->first();

        if (! $record) {
            Flux::toast(__('حضّر المعلم أولاً ثم أضف السبب.'), variant: 'warning');

            return;
        }

        TeacherAttendanceService::annotate($record, $notes, $arrivedAt ?: null, $substituteId, $this->scope()->editorId(), $this->role);

        // The substitute works the absent teacher's circles that day — those
        // this roll call reaches.
        $absent = $this->rollTeachers()->firstWhere('id', $teacherId);
        $circleIds = $this->role === 'manager'
            ? $absent->circles->modelKeys()
            : $absent->circles->whereIn('stage_id', $this->scope()->stageIds())->modelKeys();

        TeacherAttendanceService::grantSubstitute($record->fresh(), $circleIds, $this->scope()->editorId(), $this->role);

        $this->dayCache = null;
    }

    /**
     * Mark everyone still unmarked as present — the common case, and the reason
     * a roll call of thirty people does not take thirty taps.
     *
     * The browser passes the ids its search leaves showing, so "the rest" is
     * the rest the supervisor can see, never teachers the search has hidden.
     * What is already on the day stays as it is, even a mark someone else made
     * since this page was opened.
     *
     * @param  array<int, int|string>|null  $teacherIds
     */
    public function markRemainingPresent(?array $teacherIds = null): void
    {
        if (! $this->isWritable()) {
            return;
        }

        $teachers = $this->rollTeachers();

        if ($teacherIds !== null) {
            $teachers = $teachers->whereIn('id', array_map('intval', $teacherIds));
        }

        $taken = Record::whereDate('date', $this->date)->whereIn('teacher_id', $teachers->modelKeys())->pluck('teacher_id')->all();

        foreach ($teachers->whereNotIn('id', $taken) as $teacher) {
            try {
                $this->file($teacher, 'present');
            } catch (UniqueConstraintViolationException) {
                // Marked by someone else in the meantime: theirs stands.
            }
        }

        $this->dayCache = null;
        $this->records = $this->dayRecords()->map->status->all();

        Flux::toast(__('حُضِّر الباقون.'), variant: 'success');
    }

    public function clearDay(): void
    {
        if (! $this->isWritable()) {
            return;
        }

        TeacherAttendanceService::clear(
            Record::whereDate('date', $this->date)->whereIn('teacher_id', $this->teachers()->modelKeys())->get(),
            $this->scope()->editorId(),
            $this->role,
        );

        $this->records = [];
        $this->dayCache = null;

        Flux::toast(__('حُذف تحضير هذا اليوم.'), variant: 'success');
    }

    /**
     * Every teacher this roll call reaches on the chosen day, holiday or not,
     * narrowed by the circle filter.
     *
     * A supervisor's also keeps a teacher who has since left their stages on
     * the days filed under it, so a past day reads as it was taken.
     *
     * @return EloquentCollection<int, Teacher>
     */
    protected function teachers(): EloquentCollection
    {
        return $this->teacherCache ??= $this->scope()->teachers($this->date, $this->date, null, $this->circleFilter);
    }

    /**
     * The teachers the day is a working day for — the ones the page lists.
     *
     * @return EloquentCollection<int, Teacher>
     */
    protected function rollTeachers(): EloquentCollection
    {
        return $this->rollCache ??= $this->teachers()
            ->filter(fn (Teacher $teacher) => collect($this->stagesOf($teacher))
                ->contains(fn (int $stageId) => AcademicCalendarEvent::isWorkingDay($this->date, $stageId)))
            ->values();
    }

    /**
     * The stages a teacher's day is read against: the one it was filed under,
     * else the stages of their circles this roll call covers, else — a teacher
     * with no circle — every stage, so they are off only when all of them are.
     *
     * @return array<int, int>
     */
    private function stagesOf(Teacher $teacher): array
    {
        $filed = $this->dayRecords()->get($teacher->id)?->stage_id;

        if ($filed) {
            return [$filed];
        }

        return $this->scope()->stagesOf($teacher);
    }

    private function file(Teacher $teacher, string $status): void
    {
        TeacherAttendanceService::mark($teacher, $this->date, $status, $this->scope()->circleStages($teacher)[0] ?? null, $this->scope()->editorId(), $this->role);
    }

    /**
     * The day's trail for the teachers this roll call reaches, newest first.
     *
     * @return EloquentCollection<int, TeacherAttendanceRevision>
     */
    private function revisions(): EloquentCollection
    {
        return TeacherAttendanceRevision::whereDate('date', $this->date)
            ->whereIn('teacher_id', $this->teachers()->modelKeys())
            ->with(['teacher:id,name', 'editedBy:id,name', 'newSubstitute:id,name'])
            ->latest('id')
            ->get();
    }

    /**
     * @return Collection<int, Record> keyed by teacher id
     */
    private function dayRecords(): Collection
    {
        return $this->dayCache ??= Record::whereDate('date', $this->date)
            ->whereIn('teacher_id', $this->teachers()->modelKeys())
            ->with('substitute:id,name')
            ->get(['id', 'teacher_id', 'date', 'status', 'arrived_at', 'notes', 'substitute_teacher_id', 'stage_id'])
            ->keyBy('teacher_id');
    }

    private function scope(): TeacherRollScope
    {
        return $this->scopeCache ??= TeacherRollScope::for($this->role);
    }

    private function forget(): void
    {
        $this->teacherCache = null;
        $this->rollCache = null;
        $this->dayCache = null;
        $this->substituteCache = null;
    }

    private function today(): string
    {
        return now('Asia/Riyadh')->format('Y-m-d');
    }

    private function isValidDate(): bool
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) === 1
            ? Carbon::createFromFormat('!Y-m-d', $this->date)
            : null;

        return $parsed !== null && $parsed->format('Y-m-d') === $this->date;
    }

    private function isMarkableDay(): bool
    {
        return $this->isValidDate() && $this->date <= $this->today();
    }

    /**
     * A supervisor changes only the last few days; older ones are the
     * manager's, so a month's record cannot be rewritten after the fact.
     */
    private function isLocked(): bool
    {
        $days = TeacherAttendanceSettings::lockDays();

        return $this->role === 'supervisor'
            && $days > 0
            && $this->date < now('Asia/Riyadh')->subDays($days)->format('Y-m-d');
    }

    private function isWritable(): bool
    {
        if (! $this->isMarkableDay()) {
            return false;
        }

        if ($this->isLocked()) {
            Flux::toast(__('هذا اليوم مقفل، ولا يعدّل تحضيره إلا المدير.'), variant: 'warning');

            return false;
        }

        return true;
    }

    /**
     * Who may stand in for an absent teacher: any approved teacher of the
     * academy, of whatever stage — the one with a free hour may be anywhere.
     *
     * @return EloquentCollection<int, Teacher>
     */
    private function substitutes(): EloquentCollection
    {
        return $this->substituteCache ??= Teacher::whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->with('circles:id,stage_id', 'circles.stage:id,name')
            ->orderBy('name')
            ->get(['users.id', 'users.name']);
    }

    public function render()
    {
        $teachers = $this->rollTeachers();
        $circles = Circle::whereIn('id', $this->scope()->circleIds())->orderBy('name')->get(['id', 'name']);
        $circleName = $this->circleFilter ? $circles->firstWhere('id', $this->circleFilter)?->name : null;

        return view('livewire.shared.teacher-attendance', [
            'teachers' => $teachers,
            'details' => $this->dayRecords(),
            'substitutes' => $this->substitutes(),
            'lockDays' => TeacherAttendanceSettings::lockDays(),
            'revisions' => $this->revisions(),
            'circles' => $circles,
            'offDutyCount' => $this->teachers()->count() - $teachers->count(),
            'hijri' => HijriDate::withWeekday(Carbon::parse($this->date)),
            'today' => $this->today(),
            'clearPrompt' => $circleName
                ? "حذف تحضير معلمي حلقة {$circleName} في هذا اليوم؟"
                : 'حذف تحضير هذا اليوم كاملاً؟',
            'emptyMessage' => $this->role === 'manager'
                ? 'لا يوجد معلمون مطابقون لهذه التصفية.'
                : 'لا يوجد معلمون في حلقات مراحلك مطابقون لهذه التصفية.',
        ]);
    }
}
