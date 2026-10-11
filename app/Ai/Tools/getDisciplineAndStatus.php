<?php

namespace App\Ai\Tools;

use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentStatusHistory;
use App\Services\TeacherSyncSnapshot;
use App\Support\ArabicSearch;
use App\Support\DisciplineWindow;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Discipline and standing: the students over the absence or lateness limit —
 * counted over the same window the site's over-the-limit list, the roll
 * call's warnings and the guardians' messages use — the students suspended
 * now, and the changes of status over a span.
 */
class getDisciplineAndStatus implements Tool
{
    private const MAX_ROWS = 300;

    private const STATUSES = ['active' => 'مشارك', 'registering' => 'تحت التسجيل', 'suspended' => 'موقوف', 'left' => 'غادر الحلقات'];

    public function description(): Stringable|string
    {
        return 'Get discipline and student status (الانضباط والحالات): the students over the absence or lateness limit in the current '
            .'counting window (as the site\'s over-the-limit page counts them), the students suspended now and until when, and the status '
            .'changes (suspended, left, back to active…) between "from" and "to" (Y-m-d, the last 30 days when left out). '
            .'Narrow with "circle" or "stage".';
    }

    public function handle(Request $request): Stringable|string
    {
        $absenceLimit = (int) Setting::getVal('absence_limit', 3);
        $latenessLimit = (int) Setting::getVal('lateness_limit', 5);
        $window = DisciplineWindow::for();
        $to = ($request['to'] ?? null) ?: TeacherSyncSnapshot::today();
        $from = ($request['from'] ?? null) ?: Carbon::parse($to)->subDays(29)->toDateString();

        $overLimit = $this->students($request)
            ->whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->withCount([
                'attendances as absences' => fn ($q) => $q->where('status', 'absent')->whereDate('date', '>=', $window['from'])->whereDate('date', '<=', $window['to']),
                'attendances as lateness' => fn ($q) => $q->where('status', 'late')->whereDate('date', '>=', $window['from'])->whereDate('date', '<=', $window['to']),
            ])
            ->get()
            ->filter(fn (Student $student) => $student->absences >= $absenceLimit || $student->lateness >= $latenessLimit)
            ->sortByDesc(fn (Student $student) => [$student->absences, $student->lateness])
            ->values();

        $suspended = $this->students($request)->where('status', 'suspended')
            ->with(['statusHistories' => fn ($q) => $q->where('status', 'suspended')->orderByDesc('start_date')])
            ->get();

        $changes = StudentStatusHistory::query()
            ->whereDate('start_date', '>=', $from)
            ->whereDate('start_date', '<=', $to)
            ->whereIn('student_id', $this->students($request)->select('id'))
            ->with('student:id,name,circle_id', 'student.circle:id,name')
            ->orderByDesc('start_date')
            ->limit(self::MAX_ROWS)
            ->get();

        return json_encode([
            'limits' => [
                'absences' => $absenceLimit,
                'lateness' => $latenessLimit,
                'counted_from' => $window['from'],
                'counted_to' => $window['to'],
            ],
            'over_limit' => [
                'count' => $overLimit->count(),
                'students' => $overLimit->take(self::MAX_ROWS)->map(fn (Student $student) => [
                    'student' => trim((string) $student->name),
                    'circle' => $student->circle?->name,
                    'stage' => $student->circle?->stage?->name,
                    'absences' => $student->absences,
                    'lateness' => $student->lateness,
                    'over' => array_values(array_filter([
                        $student->absences >= $absenceLimit ? 'الغياب' : null,
                        $student->lateness >= $latenessLimit ? 'التأخر' : null,
                    ])),
                    'guardian' => $student->guardian?->name,
                ])->all(),
            ],
            'suspended_now' => $suspended->map(fn (Student $student) => [
                'student' => trim((string) $student->name),
                'circle' => $student->circle?->name,
                'since' => $student->statusHistories->first()?->start_date?->format('Y-m-d'),
                'until' => $student->statusHistories->first()?->end_date?->format('Y-m-d'),
                'notes' => $student->statusHistories->first()?->notes,
            ])->all(),
            'status_changes' => [
                'from' => $from,
                'to' => $to,
                'changes' => $changes->map(fn (StudentStatusHistory $change) => [
                    'student' => trim((string) $change->student?->name),
                    'circle' => $change->student?->circle?->name,
                    'status' => self::STATUSES[$change->status] ?? $change->status,
                    'from' => $change->start_date?->format('Y-m-d'),
                    'until' => $change->end_date?->format('Y-m-d'),
                    'notes' => $change->notes,
                    'changed_by' => $change->changed_by_name,
                ])->all(),
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /** @return Builder<Student> */
    private function students(Request $request): Builder
    {
        return Student::query()
            ->with('circle:id,name,stage_id', 'circle.stage:id,name', 'guardian:id,name')
            ->when($request['circle'] ?? null, fn ($q, $circle) => $q->whereHas('circle', fn ($c) => ArabicSearch::where($c, 'name', $circle)))
            ->when($request['stage'] ?? null, fn ($q, $stage) => $q->whereHas('circle.stage', fn ($s) => ArabicSearch::where($s, 'name', $stage)));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('First day of the status changes listed (Y-m-d). 30 days back when left out.'),
            'to' => $schema->string()->description('Last day of the status changes listed (Y-m-d). Today when left out.'),
            'circle' => $schema->string()->description('Circle name (حلقة), or part of it.'),
            'stage' => $schema->string()->description('Stage name (مرحلة), or part of it.'),
        ];
    }
}
