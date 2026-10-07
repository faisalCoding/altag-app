<?php

namespace App\Services;

use App\Http\Resources\TeacherResource;
use App\Http\Resources\V1\SyncAttendanceResource;
use App\Http\Resources\V1\SyncCircleResource;
use App\Http\Resources\V1\SyncCompetitionResource;
use App\Http\Resources\V1\SyncPeerPairResource;
use App\Http\Resources\V1\SyncScoreResource;
use App\Http\Resources\V1\SyncStudentResource;
use App\Http\Resources\V1\SyncTurnResource;
use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\AttendanceRevision;
use App\Models\Circle;
use App\Models\Leaderboard;
use App\Models\LeaderboardScore;
use App\Models\PeerPair;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\HijriDate;
use App\Support\RolePages;
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
 * Beside attendance and criteria it carries the Quran plans the teacher
 * grades while the tasmeeh screen is switched on for teachers, and the exams
 * their students await while either the tasmeeh or the exams screen is: the
 * tasmeeh tab shows each student's next exam even where teachers may not
 * schedule one.
 *
 * Attendance is bounded to a window — from the first day of the previous Hijri
 * month to today — while the calendar runs two weeks beyond today, so the app
 * can keep marking the days that arrive while it is offline. Before the window
 * only what the student card reads travels: each circle's attendance period
 * and the attendance of its days before the window, read-only.
 */
class TeacherSyncSnapshot
{
    /** The academy's clock. The app's own timezone is UTC. */
    public const TIMEZONE = 'Asia/Riyadh';

    /** How far past today the calendar reaches, for days marked offline. */
    public const OFFLINE_HORIZON_DAYS = 14;

    /**
     * How many Hijri months the exam date picker offers from the one holding
     * today, that one included. The months sent reach at least this far, and
     * further when a plan's last day lies beyond.
     */
    public const CALENDAR_MONTHS = 6;

    /**
     * How many years either side of today a plan's days may stretch the Hijri
     * months. A date further off is a mistyped one, not a plan's: the app
     * labels it in Gregorian rather than receiving a month for every year
     * between.
     */
    public const PLAN_MONTHS_REACH_YEARS = 2;

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
            ->with(['statusHistories', 'guardian:id,name,phone'])
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
        $stageIds = $circles->pluck('stage_id')->unique();

        $workingDays = $stageIds->mapWithKeys(fn (int $stageId) => [
            $stageId => AcademicCalendarEvent::workingDaysBetween($from, $to, $stageId),
        ]);

        $periods = $stageIds->mapWithKeys(fn (int $stageId) => [
            $stageId => self::attendancePeriod($stageId, $today),
        ]);

        // The student card counts a period's attendance from its first day,
        // which may lie before the window; those earlier days travel apart.
        $periodsFrom = $periods->filter()->pluck('start')->min();

        // The competition each circle records criteria in, and what the
        // teacher's students already hold in it inside the window.
        $gradingCompetitions = GradingCompetitions::forCircles($circles->modelKeys());
        $competitionIds = $gradingCompetitions->pluck('id')->unique()->values();

        $scores = LeaderboardScore::whereIn('leaderboard_id', $competitionIds)
            ->whereIn('student_id', $students->modelKeys())
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $today)
            ->get(['leaderboard_id', 'leaderboard_criterion_id', 'student_id', 'date']);

        // The screens beyond attendance the app shows, each only while the
        // academy has it switched on for teachers.
        $pages = [
            'tasmeeh' => RolePages::isEnabled('teacher', 'teacher.tasmeeh'),
            'student_exams' => RolePages::isEnabled('teacher', 'teacher.student-exams'),
            'pairs' => RolePages::isEnabled('teacher', 'teacher.pairs'),
        ];

        $tasmeeh = $pages['tasmeeh'] ? TasmeehSnapshot::for($students->modelKeys(), $from, $today) : TasmeehSnapshot::empty();

        // The app shows each plan whole, from its first day to its last, which
        // may lie well before the window or months beyond today.
        $planDays = self::withinPlanReach(TasmeehSnapshot::daySpan($tasmeeh), $today);

        return [
            'today' => $today,
            'window' => ['from' => $from, 'to' => $to],
            'pages' => $pages,
            'teacher' => new TeacherResource($teacher),
            'circles' => $circles->map(fn (Circle $circle) => new SyncCircleResource(
                $circle,
                $workingDays[$circle->stage_id],
                $periods[$circle->stage_id],
            ))->values(),
            'students' => SyncStudentResource::collection($students),
            'attendances' => SyncAttendanceResource::collection($attendances),
            'attendance_history' => $periodsFrom !== null && $periodsFrom < $from
                ? self::attendanceHistory($students->modelKeys(), $periodsFrom, $from)
                : [],
            'competitions' => $gradingCompetitions->unique('id')->map(fn (Leaderboard $competition) => new SyncCompetitionResource(
                $competition,
                $gradingCompetitions->filter(fn (Leaderboard $graded) => $graded->id === $competition->id)->keys()->all(),
            ))->values(),
            'scores' => SyncScoreResource::collection($scores),
            'extra_points' => self::extraPoints($competitionIds->all(), $students->modelKeys(), $from, $today),
            ...$tasmeeh,
            // The mutual-recitation pairs of the teacher's circles in the window.
            'peer_pairs' => $pages['pairs']
                ? SyncPeerPairResource::collection(PeerPair::whereIn('circle_id', $circles->modelKeys())
                    ->where('date', '>=', $from)
                    ->where('date', '<=', $today)
                    ->orderBy('date')
                    ->orderBy('circle_id')
                    ->orderBy('position')
                    ->get())
                : [],
            // The turns students booked in the teacher's circles' queues.
            'turns' => $pages['tasmeeh']
                ? SyncTurnResource::collection(TurnBooking::turnsBetween($circles->modelKeys(), $from, $today))
                : [],
            ...($pages['tasmeeh'] || $pages['student_exams'] ? ExamSnapshot::for($students->modelKeys()) : ExamSnapshot::empty()),
            'hijri_months' => self::hijriMonths(min($from, $periodsFrom ?? $from, $planDays[0] ?? $from), $today, $planDays[1] ?? null),
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
        $calendar = self::hijriCalendarAt($today);
        $calendar->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $calendar->add(\IntlCalendar::FIELD_MONTH, -1);

        return CarbonImmutable::createFromTimestamp((int) ($calendar->getTime() / 1000), self::TIMEZONE)->toDateString();
    }

    /**
     * The Hijri months the app labels dates and draws the exam date picker
     * with: from the one holding the earliest date it shows — the window's
     * start, an attendance period's before it, or the first day of a plan it
     * grades — through the later of the last month the picker offers and the
     * one holding a plan's last day.
     *
     * Plans stretch the range only while the tasmeeh page sends them. The
     * picker still offers no month past CALENDAR_MONTHS from today's: the app
     * stops it there itself, however far a plan carries the months.
     *
     * @return array<int, array{key: string, title: string, first_day: string, length: int}>
     */
    private static function hijriMonths(string $from, string $today, ?string $through = null): array
    {
        $last = self::hijriMonthIndex($today) + self::CALENDAR_MONTHS - 1;

        if ($through !== null) {
            $last = max($last, self::hijriMonthIndex($through));
        }

        return HijriDate::months($from, $last - self::hijriMonthIndex($from) + 1);
    }

    /**
     * The first and last plan days, each held to PLAN_MONTHS_REACH_YEARS of
     * today, so a single mistyped date cannot send decades of months.
     *
     * @param  array{0: string, 1: string}|null  $span
     * @return array{0: string, 1: string}|null
     */
    private static function withinPlanReach(?array $span, string $today): ?array
    {
        if ($span === null) {
            return null;
        }

        $day = CarbonImmutable::parse($today);
        $earliest = $day->subYears(self::PLAN_MONTHS_REACH_YEARS)->toDateString();
        $latest = $day->addYears(self::PLAN_MONTHS_REACH_YEARS)->toDateString();

        return [max($span[0], $earliest), min($span[1], $latest)];
    }

    /**
     * A Hijri month as a single running number, so two can be subtracted.
     */
    private static function hijriMonthIndex(string $date): int
    {
        $calendar = self::hijriCalendarAt($date);

        return $calendar->get(\IntlCalendar::FIELD_YEAR) * 12 + $calendar->get(\IntlCalendar::FIELD_MONTH);
    }

    /**
     * An Umm al-Qura calendar set to a date, read at noon so moving it across
     * midnight in either timezone can never land on a neighbouring day.
     */
    private static function hijriCalendarAt(string $date): \IntlCalendar
    {
        $calendar = \IntlCalendar::createInstance(self::TIMEZONE, 'ar_SA@calendar=islamic-umalqura');
        $calendar->setTime(CarbonImmutable::parse($date.' 12:00:00', self::TIMEZONE)->getTimestamp() * 1000);

        return $calendar;
    }

    /**
     * The attendance period a stage is in today, with the days it has met so
     * far: from its first day to today, or to its last if it has ended.
     *
     * @return array{start: string, end: string|null, working_days: array<int, string>}|null
     */
    private static function attendancePeriod(int $stageId, string $today): ?array
    {
        $period = AcademicCalendarEvent::attendancePeriodOn($today, $stageId);

        if ($period === null) {
            return null;
        }

        $through = $period['end'] !== null && $period['end'] < $today ? $period['end'] : $today;

        return [
            'start' => $period['start'],
            'end' => $period['end'],
            'working_days' => AcademicCalendarEvent::workingDaysBetween($period['start'], $through, $stageId),
        ];
    }

    /**
     * The attendance of the students on the days of their periods that fall
     * before the window, for the student card to read: the window's own days
     * already travel, editable, in `attendances`.
     *
     * @param  array<int, int>  $studentIds
     * @return array<int, array{student_id: int, date: string, status: string}>
     */
    private static function attendanceHistory(array $studentIds, string $from, string $windowFrom): array
    {
        return Attendance::whereIn('student_id', $studentIds)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<', $windowFrom)
            ->orderBy('student_id')
            ->orderBy('date')
            ->get(['student_id', 'date', 'status'])
            ->map(fn (Attendance $attendance) => [
                'student_id' => $attendance->student_id,
                'date' => $attendance->date->toDateString(),
                'status' => $attendance->status,
            ])
            ->all();
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
