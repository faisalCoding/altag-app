<?php

namespace App\Services;

use App\Http\Resources\TeacherResource;
use App\Http\Resources\V1\SyncAttendanceResource;
use App\Http\Resources\V1\SyncCircleResource;
use App\Http\Resources\V1\SyncCompetitionResource;
use App\Http\Resources\V1\SyncScoreResource;
use App\Http\Resources\V1\SyncStudentResource;
use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\AttendanceRevision;
use App\Models\Circle;
use App\Models\Leaderboard;
use App\Models\LeaderboardScore;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\HijriDate;
use App\Support\StudentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Everything the teacher app keeps on the phone to take attendance and grade
 * competition criteria offline, read in one go.
 *
 * The phone replaces its copy wholesale with each snapshot rather than merging
 * deltas: a teacher's circles are small, and a full read can never miss a row
 * removed in bulk (clearing a day deletes without events) or written by a
 * transaction that committed after a timestamp cursor had moved past it.
 *
 * Attendance is bounded to a window — from the first day of the previous Hijri
 * month to today — while the calendar runs two weeks beyond today, so the app
 * can keep marking the days that arrive while it is offline.
 */
class TeacherSyncSnapshot
{
    /** The academy's clock. The app's own timezone is UTC. */
    public const TIMEZONE = 'Asia/Riyadh';

    /** How far past today the calendar reaches, for days marked offline. */
    public const OFFLINE_HORIZON_DAYS = 14;

    /**
     * @return array<string, mixed>
     */
    public static function for(Teacher $teacher): array
    {
        $today = self::today();
        $from = self::windowStart($today);
        $to = CarbonImmutable::parse($today)->addDays(self::OFFLINE_HORIZON_DAYS)->toDateString();

        $circles = $teacher->circles()->with('stage')->orderBy('circles.id')->get();

        $students = Student::whereIn('circle_id', $circles->modelKeys())
            ->whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->with('statusHistories')
            ->orderBy('name')
            ->get();

        // By student rather than by circle: a student who moved into one of the
        // teacher's circles keeps their earlier rows under the old circle, and
        // marking that day again rewrites the same row.
        $attendances = Attendance::whereIn('student_id', $students->modelKeys())
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $today)
            ->with('teacher:id,name')
            ->get();

        // Read once per stage: circles of the same stage share one calendar.
        $workingDays = $circles->pluck('stage_id')->unique()->mapWithKeys(fn (int $stageId) => [
            $stageId => AcademicCalendarEvent::workingDaysBetween($from, $to, $stageId),
        ]);

        // The competition each circle records criteria in, and what the
        // teacher's students already hold in it inside the window.
        $gradingCompetitions = GradingCompetitions::forCircles($circles->modelKeys());
        $competitionIds = $gradingCompetitions->pluck('id')->unique()->values();

        $scores = LeaderboardScore::whereIn('leaderboard_id', $competitionIds)
            ->whereIn('student_id', $students->modelKeys())
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $today)
            ->get(['leaderboard_id', 'leaderboard_criterion_id', 'student_id', 'date']);

        return [
            'today' => $today,
            'window' => ['from' => $from, 'to' => $to],
            'teacher' => new TeacherResource($teacher),
            'circles' => $circles->map(fn (Circle $circle) => new SyncCircleResource(
                $circle,
                $workingDays[$circle->stage_id],
            ))->values(),
            'students' => SyncStudentResource::collection($students),
            'attendances' => SyncAttendanceResource::collection($attendances),
            'competitions' => $gradingCompetitions->unique('id')->map(fn (Leaderboard $competition) => new SyncCompetitionResource(
                $competition,
                $gradingCompetitions->filter(fn (Leaderboard $graded) => $graded->id === $competition->id)->keys()->all(),
            ))->values(),
            'scores' => SyncScoreResource::collection($scores),
            'extra_points' => self::extraPoints($competitionIds->all(), $students->modelKeys(), $from, $today),
            'days' => self::days($from, $to),
            'labels' => [
                'attendance' => AttendanceRevision::statusLabels(),
                'student_status' => StudentStatus::LABELS,
            ],
            'server_time' => now()->toISOString(),
        ];
    }

    /**
     * Today as the academy reads it. On UTC the date would still be yesterday
     * for the first three hours of every Riyadh day.
     */
    public static function today(): string
    {
        return now(self::TIMEZONE)->toDateString();
    }

    /**
     * The first day of the Hijri month before the one holding today.
     *
     * Read at noon so moving the calendar across midnight in either timezone
     * can never land the result on a neighbouring Gregorian day.
     */
    public static function windowStart(string $today): string
    {
        $calendar = \IntlCalendar::createInstance(self::TIMEZONE, 'ar_SA@calendar=islamic-umalqura');
        $calendar->setTime(CarbonImmutable::parse($today.' 12:00:00', self::TIMEZONE)->getTimestamp() * 1000);
        $calendar->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $calendar->add(\IntlCalendar::FIELD_MONTH, -1);

        return CarbonImmutable::createFromTimestamp((int) ($calendar->getTime() / 1000), self::TIMEZONE)->toDateString();
    }

    /**
     * Extra points awarded in the window. The table has no model — the web
     * grading page and the gamification service both reach it directly — so
     * neither does this.
     *
     * @param  array<int, int>  $competitionIds
     * @param  array<int, int>  $studentIds
     * @return array<int, array{id: int, uuid: ?string, competition_id: int, student_id: int, date: string, points: int, notes: ?string}>
     */
    private static function extraPoints(array $competitionIds, array $studentIds, string $from, string $today): array
    {
        return DB::table('leaderboard_extra_points')
            ->whereIn('leaderboard_id', $competitionIds)
            ->whereIn('student_id', $studentIds)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $today)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row) => CompetitionGradingService::presentExtraPoint($row))
            ->all();
    }

    /**
     * Every day of the window read in Hijri, so the app can label any day it
     * shows without a calendar of its own.
     *
     * @return array<string, array{weekday: string, day: string, month: string, full: string}>
     */
    private static function days(string $from, string $to): array
    {
        $days = [];

        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();

            $days[$date] = [
                'weekday' => HijriDate::weekday($date),
                'day' => HijriDate::format($date, 'd'),
                'month' => HijriDate::monthYear($date),
                'full' => HijriDate::withWeekday($date),
            ];
        }

        return $days;
    }
}
