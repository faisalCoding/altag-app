<?php

namespace App\Support;

use App\Models\AcademicCalendarEvent;
use App\Models\Circle;
use App\Models\Stage;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Which teachers a roll call or a report on their attendance reaches — one
 * definition for the supervisor's and the manager's, for the page that marks
 * and for the pages that read.
 *
 * A supervisor's reaches the teachers holding a circle in their stages, and a
 * teacher who has since moved on for the days filed under those stages. The
 * manager's reaches every approved teacher: those of a stage no supervisor
 * covers, and those with no circle at all.
 */
final class TeacherRollScope
{
    public const ROLES = ['supervisor', 'manager'];

    /** @var array<int, int>|null */
    private ?array $stageCache = null;

    private function __construct(public readonly string $role) {}

    /**
     * The scope of whoever is signed in under the role — refused outright to
     * anyone who is not.
     */
    public static function for(string $role): self
    {
        abort_unless(in_array($role, self::ROLES, true) && auth()->guard($role)->check(), 403);

        return new self($role);
    }

    public function editorId(): ?int
    {
        return auth()->guard($this->role)->id();
    }

    /**
     * @return array<int, int>
     */
    public function stageIds(): array
    {
        return $this->stageCache ??= $this->role === 'manager'
            ? Stage::pluck('id')->all()
            : auth()->guard('supervisor')->user()->stages()->pluck('stages.id')->all();
    }

    /**
     * @return array<int, int>
     */
    public function circleIds(): array
    {
        return $this->role === 'manager'
            ? Circle::pluck('id')->all()
            : Circle::whereIn('stage_id', $this->stageIds())->pluck('id')->all();
    }

    /**
     * The approved teachers this scope reaches between two days: a circle's
     * teachers when one is chosen; else those of the chosen stages (or all of
     * the scope's), counting a teacher who has since moved on for the days
     * filed under them. The manager with nothing chosen gets every teacher.
     *
     * @param  array<int, int>|null  $stageIds
     * @return EloquentCollection<int, Teacher>
     */
    public function teachers(string $from, string $to, ?array $stageIds = null, ?int $circleId = null): EloquentCollection
    {
        $query = Teacher::with('circles:id,name,stage_id')
            ->whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->orderBy('name');

        if ($circleId !== null) {
            return $query->whereHas('circles', fn ($q) => $q->whereIn('circles.id', array_intersect($this->circleIds(), [$circleId])))->get();
        }

        if ($stageIds === null && $this->role === 'manager') {
            return $query->get();
        }

        $stages = $this->narrow($stageIds);

        return $query->where(fn ($q) => $q
            ->whereHas('circles', fn ($q) => $q->whereIn('circles.stage_id', $stages))
            ->orWhereIn('users.id', TeacherAttendance::select('teacher_id')
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->whereIn('stage_id', $stages)))
            ->get();
    }

    /**
     * The chosen stages this scope may read — all of its own when none are.
     *
     * @param  array<int, int|string>|null  $stageIds
     * @return array<int, int>
     */
    public function narrow(?array $stageIds): array
    {
        return $stageIds === null || $stageIds === []
            ? $this->stageIds()
            : array_values(array_intersect($this->stageIds(), array_map('intval', $stageIds)));
    }

    /**
     * The stages a teacher's days are read against: those of their circles
     * this scope covers, else — a teacher with no circle — every stage of it,
     * so they are off only when all of them are.
     *
     * @return array<int, int>
     */
    public function stagesOf(Teacher $teacher): array
    {
        return $this->circleStages($teacher) ?: $this->stageIds();
    }

    /**
     * The stages of the teacher's circles this scope covers — the first is
     * where a new day is filed. Empty for a teacher with no circle.
     *
     * @return array<int, int>
     */
    public function circleStages(Teacher $teacher): array
    {
        return $teacher->circles->pluck('stage_id')->filter()
            ->intersect($this->stageIds())
            ->unique()->values()->all();
    }

    /**
     * Today's roll call, stage by stage, for the dashboards: how many of each
     * stage's teachers are marked, and how many of those are away. A stage
     * not working today is left out.
     *
     * @return array<int, array{stage: string, supervisors: string, expected: int, marked: int, absent: int, late: int, excused: int}>
     */
    public function today(): array
    {
        $today = now('Asia/Riyadh')->format('Y-m-d');

        $stages = Stage::whereIn('id', $this->stageIds())
            ->with('supervisors:id,name')
            ->get()
            ->filter(fn (Stage $stage) => AcademicCalendarEvent::isWorkingDay($today, $stage->id));

        if ($stages->isEmpty()) {
            return [];
        }

        $expected = Teacher::whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->join('circle_teacher', 'circle_teacher.teacher_id', '=', 'users.id')
            ->join('circles', 'circles.id', '=', 'circle_teacher.circle_id')
            ->whereIn('circles.stage_id', $stages->modelKeys())
            ->selectRaw('circles.stage_id, count(distinct users.id) as total')
            ->groupBy('circles.stage_id')
            ->pluck('total', 'circles.stage_id');

        $marked = TeacherAttendance::whereDate('date', $today)
            ->whereIn('stage_id', $stages->modelKeys())
            ->selectRaw('stage_id, status, count(*) as total')
            ->groupBy('stage_id', 'status')
            ->get()
            ->groupBy('stage_id');

        return $stages
            ->map(function (Stage $stage) use ($expected, $marked) {
                $counts = ($marked->get($stage->id) ?? collect())->pluck('total', 'status');

                return [
                    'stage' => $stage->name,
                    'supervisors' => $stage->supervisors->pluck('name')->implode('، '),
                    'expected' => (int) ($expected[$stage->id] ?? 0),
                    'marked' => (int) $counts->sum(),
                    'absent' => (int) ($counts['absent'] ?? 0),
                    'late' => (int) ($counts['late'] ?? 0),
                    'excused' => (int) ($counts['excused'] ?? 0),
                ];
            })
            ->filter(fn (array $row) => $row['expected'] > 0)
            ->values()
            ->all();
    }
}
