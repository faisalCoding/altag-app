<?php

namespace App\Ai\Tools;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use App\Services\TeacherSyncSnapshot;
use App\Support\ArabicSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * What was recited (التسميع) over a span: every graded session of a plan day
 * — each session kept apart in plan_day_attempts, on the day it was recited —
 * and every free recitation, by circle, by student and by the teacher who
 * graded it. A session graded «لم يسمع» (0) is a grade but not a recitation,
 * so a student with only those counts among those who did not recite.
 */
class getRecitationActivity implements Tool
{
    private const MAX_STUDENTS = 300;

    private const GRADES = [3 => 'ممتاز', 2 => 'جيد', 1 => 'مقبول', 0 => 'لم يسمع'];

    public function description(): Stringable|string
    {
        return 'Get the Quran recitation activity (التسميع) over a range of days: graded sessions per circle by grade (ممتاز/جيد/مقبول/لم يسمع), '
            .'how many students recited and the list of active students who did not recite at all, with their absences in the range, '
            .'and how many sessions each teacher graded. A session graded لم يسمع is not a recitation. Pass "from" and "to" (Y-m-d, '
            .'today when left out); narrow with "circle" or "stage". Set "per_student" to true for every student\'s own counts.';
    }

    public function handle(Request $request): Stringable|string
    {
        $from = ($request['from'] ?? null) ?: TeacherSyncSnapshot::today();
        $to = ($request['to'] ?? null) ?: $from;

        $students = Student::query()
            ->where('status', 'active')
            ->whereNotNull('circle_id')
            ->when($request['circle'] ?? null, fn ($q, $circle) => $q->whereHas('circle', fn ($c) => ArabicSearch::where($c, 'name', $circle)))
            ->when($request['stage'] ?? null, fn ($q, $stage) => $q->whereHas('circle.stage', fn ($s) => ArabicSearch::where($s, 'name', $stage)))
            ->with('circle:id,name,stage_id', 'circle.stage:id,name')
            ->get(['id', 'name', 'circle_id'])
            ->keyBy('id');

        $sessions = $this->sessions($students->keys()->all(), $from, $to);
        $byStudent = $sessions->groupBy('student_id');

        $absences = Attendance::query()
            ->whereIn('student_id', $students->keys())
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->where('status', 'absent')
            ->selectRaw('student_id, count(*) as total')
            ->groupBy('student_id')
            ->pluck('total', 'student_id');

        $recited = fn (int $studentId) => $byStudent->get($studentId, collect())->contains(fn ($session) => $session->grade >= 1);

        $notRecited = $students->reject(fn (Student $student) => $recited($student->id))
            ->map(fn (Student $student) => [
                'student' => trim((string) $student->name),
                'circle' => $student->circle?->name,
                'stage' => $student->circle?->stage?->name,
                'absent_days' => (int) ($absences[$student->id] ?? 0),
                'graded_not_heard' => $byStudent->get($student->id, collect())->where('grade', 0)->count(),
            ])
            ->sortBy([['circle', 'asc'], ['student', 'asc']])
            ->values();

        $recorders = User::whereIn('id', $sessions->pluck('recorded_by')->filter()->unique())->pluck('name', 'id');

        $result = [
            'from' => $from,
            'to' => $to,
            'totals' => ['students' => $students->count(), 'recited' => $students->count() - $notRecited->count(), 'did_not_recite' => $notRecited->count()]
                + $this->grades($sessions),
            'circles' => $students->groupBy(fn (Student $student) => $student->circle?->name ?? 'بلا حلقة')
                ->map(fn (Collection $circleStudents) => [
                    'stage' => $circleStudents->first()->circle?->stage?->name,
                    'students' => $circleStudents->count(),
                    'recited' => $circleStudents->filter(fn (Student $student) => $recited($student->id))->count(),
                ] + $this->grades($sessions->whereIn('student_id', $circleStudents->keys())))
                ->all(),
            'did_not_recite' => $notRecited->take(self::MAX_STUDENTS)->all(),
            'teachers' => $sessions->whereNotNull('recorded_by')->groupBy('recorded_by')
                ->map(fn (Collection $graded, $userId) => ['teacher' => $recorders[$userId] ?? 'غير معروف'] + $this->grades($graded))
                ->sortByDesc(fn (array $row) => $row['sessions'])
                ->values()
                ->all(),
            'note' => $notRecited->count() > self::MAX_STUDENTS ? 'Only '.self::MAX_STUDENTS.' of the students who did not recite are listed.' : null,
        ];

        if ($request['per_student'] ?? null) {
            $result['per_student'] = $students->take(self::MAX_STUDENTS)
                ->map(fn (Student $student) => [
                    'student' => trim((string) $student->name),
                    'circle' => $student->circle?->name,
                    'absent_days' => (int) ($absences[$student->id] ?? 0),
                ] + $this->grades($byStudent->get($student->id, collect())))
                ->values()
                ->all();
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /**
     * The graded sessions of the students in the range: plan-day sessions by the
     * day they were recited, and free recitations.
     *
     * @param  array<int, int>  $studentIds
     * @return Collection<int, object{student_id: int, part: string, grade: int, recorded_by: ?int}>
     */
    private function sessions(array $studentIds, string $from, string $to): Collection
    {
        $plan = DB::table('plan_day_attempts as attempts')
            ->join('student_plan_days as days', 'days.id', '=', 'attempts.student_plan_day_id')
            ->join('student_plans as plans', 'plans.id', '=', 'days.student_plan_id')
            ->whereIn('plans.student_id', $studentIds)
            ->whereDate('attempts.recited_on', '>=', $from)
            ->whereDate('attempts.recited_on', '<=', $to)
            ->whereNotNull('attempts.grade')
            ->get(['plans.student_id', 'attempts.part', 'attempts.grade', 'attempts.recorded_by']);

        $free = DB::table('free_recitations')
            ->whereIn('student_id', $studentIds)
            ->whereDate('recited_on', '>=', $from)
            ->whereDate('recited_on', '<=', $to)
            ->whereNotNull('achievement')
            ->get(['student_id', 'type as part', 'achievement as grade', 'recorded_by']);

        return $plan->concat($free)->map(function ($session) {
            $session->grade = (int) $session->grade;

            return $session;
        });
    }

    /**
     * @param  Collection<int, object>  $sessions
     * @return array<string, int>
     */
    private function grades(Collection $sessions): array
    {
        $counts = ['sessions' => $sessions->count()];

        foreach (self::GRADES as $grade => $label) {
            $counts[$label] = $sessions->where('grade', $grade)->count();
        }

        return $counts;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('First day recited (Y-m-d). Today when left out.'),
            'to' => $schema->string()->description('Last day recited (Y-m-d). The same as "from" when left out.'),
            'circle' => $schema->string()->description('Circle name (حلقة), or part of it.'),
            'stage' => $schema->string()->description('Stage name (مرحلة), or part of it.'),
            'per_student' => $schema->boolean()->description('Include every student\'s own session counts.'),
        ];
    }
}
