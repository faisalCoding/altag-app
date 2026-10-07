<?php

namespace App\Support;

use App\Models\AcademicCalendarEvent;
use App\Models\Stage;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A teacher's own roll-call record for one Hijri month: every working day of
 * their stages up to today, with what was marked on it — the same days the
 * supervisor's report counts. Read by the site's page and by the app alike.
 */
final class TeacherAttendanceMonth
{
    /**
     * @return array{month: string, title: string, previous: string, next: ?string, days: Collection<int, string>, records: Collection<string, TeacherAttendance>, counts: array<string, int>, rate: ?int, unrecorded: int}
     */
    public static function for(Teacher $teacher, string $day): array
    {
        $today = now('Asia/Riyadh')->format('Y-m-d');
        $month = HijriDate::months(min($day, $today), 1)[0];
        $from = $month['first_day'];
        $after = Carbon::parse($from)->addDays($month['length'])->format('Y-m-d');
        $to = min(Carbon::parse($after)->subDay()->format('Y-m-d'), $today);

        $records = TeacherAttendance::where('teacher_id', $teacher->id)
            ->with('substitute:id,name')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->get()
            ->keyBy(fn (TeacherAttendance $record) => $record->date->format('Y-m-d'));

        // The working days of the teacher's stages; with no circle, of any stage.
        $stageIds = $teacher->circles()->pluck('circles.stage_id')->filter()->unique()->values()->all() ?: Stage::pluck('id')->all();
        $days = collect($stageIds)
            ->flatMap(fn (int $stageId) => AcademicCalendarEvent::workingDaysBetween($from, $to, $stageId))
            ->merge($records->keys())
            ->unique()
            ->sortDesc()
            ->values();

        $counts = collect(TeacherAttendance::STATUSES)
            ->mapWithKeys(fn (string $status) => [$status => $records->where('status', $status)->count()])
            ->all();
        $present = $counts['present'] + $counts['late'];
        $counted = $present + $counts['absent'];

        return [
            'month' => $from,
            'title' => $month['title'],
            'previous' => HijriDate::months(Carbon::parse($from)->subDay()->format('Y-m-d'), 1)[0]['first_day'],
            // Nothing is marked ahead of today, so there is no month to move on to.
            'next' => $after <= $today ? $after : null,
            'days' => $days,
            'records' => $records,
            'counts' => $counts,
            // Present and late over the days counted; an excused day is not an absence.
            'rate' => $counted > 0 ? (int) round($present / $counted * 100) : null,
            'unrecorded' => $days->reject(fn (string $day) => $records->has($day))->count(),
        ];
    }
}
