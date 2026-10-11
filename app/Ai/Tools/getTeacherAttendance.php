<?php

namespace App\Ai\Tools;

use App\Models\TeacherAttendance;
use App\Models\User;
use App\Support\ArabicSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The teachers' own register (تحضير المعلمين), as the site's teacher
 * attendance report reads it: each day filed under the stage it was taken in,
 * the rate counting present and late over present, late and absent — a day
 * excused is not an absence — and who covered for a teacher away.
 */
class getTeacherAttendance implements Tool
{
    private const MAX_DAYS = 500;

    private const LABELS = ['present' => 'حاضر', 'late' => 'متأخر', 'absent' => 'غائب', 'excused' => 'مستأذن'];

    public function description(): Stringable|string
    {
        return 'Get the teachers\' attendance (تحضير المعلمين): per teacher the days present, late, absent and excused and their '
            .'attendance rate, the most absent first, with totals per stage. Always pass "from" and "to" (Y-m-d); narrow with "stage" '
            .'or "teacher". Set "details" to true for the day-by-day list, with arrival times, notes and who substituted for an absent teacher.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = TeacherAttendance::query();

        if ($from = ($request['from'] ?? null)) {
            $query->whereDate('date', '>=', $from);
        }

        if ($to = ($request['to'] ?? null)) {
            $query->whereDate('date', '<=', $to);
        }

        if ($stage = ($request['stage'] ?? null)) {
            $query->whereHas('stage', fn ($q) => ArabicSearch::where($q, 'name', $stage));
        }

        if ($teacher = ($request['teacher'] ?? null)) {
            $query->whereIn('teacher_id', ArabicSearch::where(User::query(), 'name', $teacher)->select('id'));
        }

        $records = $query->with('teacher:id,name', 'stage:id,name', 'substitute:id,name')->orderBy('date')->get();

        $result = [
            'matching_days' => $records->count(),
            'rate_formula' => '(حاضر + متأخر) ÷ (حاضر + متأخر + غائب)؛ المستأذن لا يُحسب غياباً',
            'totals' => $this->counts($records),
            'stages' => $records->groupBy(fn ($record) => $record->stage?->name ?? 'غير محدد')
                ->map(fn (Collection $days) => $this->counts($days))
                ->all(),
            'teachers' => $records->groupBy('teacher_id')
                ->map(fn (Collection $days) => ['teacher' => $days->first()->teacher?->name ?? 'معلم محذوف'] + $this->counts($days))
                ->sortByDesc(fn (array $row) => [$row['غائب'], $row['متأخر']])
                ->values()
                ->all(),
        ];

        if ($request['details'] ?? null) {
            $result['days'] = $records->take(self::MAX_DAYS)->map(fn (TeacherAttendance $record) => [
                'date' => $record->date?->format('Y-m-d'),
                'teacher' => $record->teacher?->name,
                'stage' => $record->stage?->name,
                'status' => self::LABELS[$record->status] ?? $record->status,
                'arrived_at' => $record->arrived_at,
                'substitute' => $record->substitute?->name,
                'notes' => $record->notes,
            ])->values()->all();
            $result['note'] = $records->count() > self::MAX_DAYS ? 'Only the first '.self::MAX_DAYS.' days are listed; the counts cover all.' : null;
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  Collection<int, TeacherAttendance>  $days
     * @return array<string, int|null>
     */
    private function counts(Collection $days): array
    {
        $counts = [];

        foreach (self::LABELS as $status => $label) {
            $counts[$label] = $days->where('status', $status)->count();
        }

        $present = $counts['حاضر'] + $counts['متأخر'];
        $counted = $present + $counts['غائب'];

        return $counts + ['نسبة الحضور' => $counted > 0 ? (int) round($present / $counted * 100) : null];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Start of the range (Y-m-d).'),
            'to' => $schema->string()->description('End of the range (Y-m-d).'),
            'stage' => $schema->string()->description('Stage name (مرحلة), or part of it.'),
            'teacher' => $schema->string()->description('Teacher name, or part of it.'),
            'details' => $schema->boolean()->description('Include the day-by-day list with arrival times, notes and substitutes.'),
        ];
    }
}
