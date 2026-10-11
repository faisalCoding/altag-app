<?php

namespace App\Ai\Tools;

use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Student;
use App\Support\ArabicSearch;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class getAttendanceData implements Tool
{
    /**
     * A whole academy-wide day is a few hundred rows; this keeps a careless
     * range from flooding the model's context.
     */
    private const MAX_ROWS = 3000;

    /**
     * Get the description of the tool's purpose.
     */
    /** The most students a per-student summary lists, the most absent first. */
    private const MAX_STUDENTS = 200;

    public function description(): Stringable|string
    {
        return 'Get attendance (التحضير): each student marked حاضر/غائب/متأخر/مستأذن. Always pass "from" and "to" (Y-m-d) to bound '
            .'the range, and narrow with "circle", "stage" or "student". For any question about counts, rates or who was absent most, '
            .'set "summary" to true: it counts every matching record, grouped by "group_by" — circle (by stage and circle, the default), '
            .'student (the most absent and late first) or date. Without "summary" you get the name-by-name list, capped, '
            .'which only suits a few days.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = Attendance::query();

        if ($from = ($request['from'] ?? null)) {
            $query->whereDate('date', '>=', $from);
        }

        if ($to = ($request['to'] ?? null)) {
            $query->whereDate('date', '<=', $to);
        }

        if ($circle = ($request['circle'] ?? null)) {
            $query->whereHas('circle', fn ($q) => ArabicSearch::where($q, 'name', $circle));
        }

        if ($stage = ($request['stage'] ?? null)) {
            $query->whereHas('circle.stage', fn ($q) => ArabicSearch::where($q, 'name', $stage));
        }

        if ($student = ($request['student'] ?? null)) {
            $query->whereHas('student', fn ($q) => ArabicSearch::where($q, 'name', $student));
        }

        $total = (clone $query)->count();

        if ($request['summary'] ?? null) {
            $groupBy = in_array($request['group_by'] ?? null, ['student', 'date'], true) ? $request['group_by'] : 'circle';

            return json_encode([
                'matching_records' => $total,
                'group_by' => $groupBy,
                'summary' => match ($groupBy) {
                    'student' => $this->byStudent($query),
                    'date' => $this->byDate($query),
                    default => $this->byCircle($query),
                },
            ], JSON_UNESCAPED_UNICODE);
        }

        $records = $query->with([
            'student:name,id',
            'teacher:name,id',
            'circle:name,id,stage_id',
            'circle.stage:name,id',
        ])->orderBy('date')->limit(self::MAX_ROWS)->get(['date', 'status', 'student_id', 'teacher_id', 'circle_id']);

        return json_encode([
            'matching_records' => $total,
            'returned_records' => $records->count(),
            'note' => $total > $records->count()
                ? 'Only the first '.self::MAX_ROWS.' records are included. Use "summary" for counts over every record.'
                : null,
            'attendance' => $this->detail($records),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Counts per stage and circle, over every matching record.
     *
     * @return array<string, array<string, array<string, int>>>
     */
    private function byCircle($query): array
    {
        $rows = (clone $query)->select('circle_id', 'status', DB::raw('count(*) as total'))->groupBy('circle_id', 'status')->get();
        $circles = Circle::with('stage:id,name')->whereIn('id', $rows->pluck('circle_id')->unique())->get()->keyBy('id');

        $summary = [];

        foreach ($rows->groupBy('circle_id') as $circleId => $statuses) {
            $circle = $circles->get($circleId);
            $summary[$circle?->stage?->name ?? 'غير محدد'][$circle?->name ?? 'غير محدد'] = $this->counts($statuses);
        }

        return $summary;
    }

    /**
     * Counts per student, the most absent and then the latest first.
     *
     * @return array<string, mixed>
     */
    private function byStudent($query): array
    {
        $rows = (clone $query)->select('student_id', 'status', DB::raw('count(*) as total'))->groupBy('student_id', 'status')->get();
        $students = Student::with('circle:id,name')->whereIn('id', $rows->pluck('student_id')->unique())->get(['id', 'name', 'circle_id'])->keyBy('id');

        $list = $rows->groupBy('student_id')
            ->map(fn ($statuses, $studentId) => [
                'student' => trim((string) $students->get($studentId)?->name) ?: 'غير محدد',
                'circle' => $students->get($studentId)?->circle?->name,
            ] + $this->counts($statuses))
            ->sortByDesc(fn (array $row) => [$row['غائب'], $row['متأخر']])
            ->values();

        return [
            'students' => $list->take(self::MAX_STUDENTS)->all(),
            'note' => $list->count() > self::MAX_STUDENTS
                ? 'Only the '.self::MAX_STUDENTS.' students with the most absences are listed, of '.$list->count().'.'
                : null,
        ];
    }

    /**
     * Counts per day.
     *
     * @return array<string, array<string, int>>
     */
    private function byDate($query): array
    {
        return (clone $query)->select(DB::raw('date(date) as day'), 'status', DB::raw('count(*) as total'))
            ->groupBy('day', 'status')
            ->orderBy('day')
            ->get()
            ->groupBy('day')
            ->map(fn ($statuses) => $this->counts($statuses))
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function counts($statuses): array
    {
        $counts = ['حاضر' => 0, 'غائب' => 0, 'متأخر' => 0, 'مستأذن' => 0];

        foreach ($statuses as $row) {
            $label = $this->statusLabel($row->status);

            if (isset($counts[$label])) {
                $counts[$label] += (int) $row->total;
            }
        }

        return $counts + ['الإجمالي' => array_sum($counts)];
    }

    /**
     * Name-by-name attendance, nested date > stage > circle > teacher > student.
     *
     * @param  Collection<int, Attendance>  $records
     */
    private function detail($records): mixed
    {
        return $records
            ->groupBy(fn ($a) => Carbon::parse($a->date)->format('Y-m-d'))
            ->map(function ($dateGroup) {
                return $dateGroup->groupBy(fn ($a) => $a->circle?->stage?->name ?? 'غير محدد')
                    ->map(function ($stageGroup) {
                        return $stageGroup->groupBy(fn ($a) => $a->circle?->name ?? 'غير محدد')
                            ->map(function ($circleGroup) {
                                return $circleGroup->groupBy(fn ($a) => $a->teacher?->name ?? 'غير محدد')
                                    ->map(function ($teacherGroup) {
                                        return $teacherGroup->mapWithKeys(fn ($a) => [
                                            $a->student?->name ?? 'غير محدد' => $this->statusLabel($a->status),
                                        ]);
                                    });
                            });
                    });
            });
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'present' => 'حاضر',
            'absent' => 'غائب',
            'excused' => 'مستأذن',
            'late' => 'متأخر',
            default => (string) $status,
        };
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Start of the range (Y-m-d).'),
            'to' => $schema->string()->description('End of the range (Y-m-d).'),
            'circle' => $schema->string()->description('Circle name (حلقة), or part of it.'),
            'stage' => $schema->string()->description('Stage name (مرحلة), or part of it.'),
            'student' => $schema->string()->description('Student name, or part of it.'),
            'summary' => $schema->boolean()->description('Return counts over every matching record instead of the name-by-name list.'),
            'group_by' => $schema->string()->enum(['circle', 'student', 'date'])->description('How a summary is grouped. Defaults to circle.'),
        ];
    }
}
