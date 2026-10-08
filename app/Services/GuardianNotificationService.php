<?php

namespace App\Services;

use App\Jobs\SendGuardianWhatsappJob;
use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\GuardianNotification;
use App\Models\Leaderboard;
use App\Models\Student;
use App\Support\DisciplineWindow;
use App\Support\HijriDate;
use Carbon\Carbon;

class GuardianNotificationService
{
    /**
     * Record an in-app guardian notification and best-effort push it over WhatsApp.
     *
     * @param  array<string, mixed>  $data
     */
    public static function record(
        int $guardianId,
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?int $studentId = null,
        ?string $senderClientId = null,
    ): GuardianNotification {
        $notification = GuardianNotification::create([
            'guardian_id' => $guardianId,
            'student_id' => $studentId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        self::pushWhatsapp($notification, $senderClientId);

        return $notification;
    }

    /**
     * Notify a student's guardian about an absence or late arrival, skipping if the
     * student has no linked guardian or the same alert was already recorded today.
     * An explicit sender session (e.g. the supervisor broadcasting manually) takes
     * precedence over the auto-resolved one.
     */
    public static function notifyAbsence(Student $student, string $status, string $date, ?string $senderClientId = null): ?GuardianNotification
    {
        if (! in_array($status, ['absent', 'late'], true) || ! $student->guardian_id) {
            return null;
        }

        $type = $status === 'late' ? 'late' : 'absence';

        $alreadyNotified = GuardianNotification::where('guardian_id', $student->guardian_id)
            ->where('student_id', $student->id)
            ->where('type', $type)
            ->where('data->date', $date)
            ->exists();

        if ($alreadyNotified) {
            return null;
        }

        $parts = self::absenceMessageParts($student, $status, $date);

        $notification = self::record(
            guardianId: $student->guardian_id,
            type: $type,
            title: $parts['title'],
            body: $parts['body'],
            data: ['date' => $date, 'status' => $status],
            studentId: $student->id,
        );

        $senderClientId ??= self::resolveWhatsappSender($student);
        $guardian = $notification->guardian;
        if ($senderClientId && $guardian && $guardian->phone) {
            SendGuardianWhatsappJob::dispatch($guardian->phone, $parts['whatsapp'], $senderClientId);
        }

        return $notification;
    }

    /**
     * Build the absence/late alert shared by the automatic notification and the
     * supervisor's manual broadcast: `title`/`body` for the in-app notification and
     * `whatsapp` for the letter-style WhatsApp message. The date is shown in Hijri
     * as "weekday day month" without the year, and the occurrence number counts the
     * student's absences (or late arrivals) since the current attendance period began.
     *
     * @return array{title: string, body: string, whatsapp: string}
     */
    public static function absenceMessageParts(Student $student, string $status, string $date): array
    {
        $statusText = $status === 'late' ? 'بتأخر' : 'بغياب';
        $hijriDate = self::formatHijriDayMonth($date);
        $ordinal = self::arabicOrdinal(self::occurrenceNumber($student, $status, $date));

        $body = "نشعركم {$statusText} الطالب ({$student->name}) ليوم {$hijriDate} وذلك للمرة {$ordinal}";

        return [
            'title' => $status === 'late' ? 'تنبيه تأخّر' : 'تنبيه غياب',
            'body' => $body,
            'whatsapp' => "السلام عليكم ورحمة الله وبركاته\n{$body}",
        ];
    }

    /**
     * How many times the student has been absent (or late) since the start of the
     * current attendance period, counting up to and including the given date. Falls
     * back to the rolling discipline window when no attendance period covers the date.
     */
    public static function occurrenceNumber(Student $student, string $status, string $date): int
    {
        $period = AcademicCalendarEvent::where('is_attendance_period', true)
            ->where('start_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $date);
            })
            ->orderByDesc('start_date')
            ->first();

        $periodStart = $period
            ? $period->start_date->format('Y-m-d')
            : DisciplineWindow::for($date)['from'];

        // Nothing before the day the manager set counting to start from.
        $periodStart = max($periodStart, DisciplineWindow::countsFrom() ?? $periodStart);

        $count = Attendance::where('student_id', $student->id)
            ->where('status', $status === 'late' ? 'late' : 'absent')
            ->whereDate('date', '>=', $periodStart)
            ->whereDate('date', '<=', $date)
            ->count();

        return max(1, $count);
    }

    /**
     * Feminine Arabic ordinal for the occurrence number ("المرة الثالثة").
     */
    protected static function arabicOrdinal(int $number): string
    {
        return match ($number) {
            1 => 'الأولى',
            2 => 'الثانية',
            3 => 'الثالثة',
            4 => 'الرابعة',
            5 => 'الخامسة',
            6 => 'السادسة',
            7 => 'السابعة',
            8 => 'الثامنة',
            9 => 'التاسعة',
            10 => 'العاشرة',
            default => "رقم {$number}",
        };
    }

    /**
     * Format a Y-m-d Gregorian date as a Hijri "weekday day month" string
     * (e.g. "الأربعاء 24 ذو الحجة"), falling back to the raw date on failure.
     */
    public static function formatHijriDayMonth(string $date): string
    {
        try {
            return HijriDate::format(Carbon::parse($date, 'Asia/Riyadh'), 'EEEE d MMMM');
        } catch (\Exception $e) {
            return $date;
        }
    }

    /**
     * Resolve the WhatsApp sender session for a student via the supervisor of their
     * circle's active gamification competition. Returns null when none is available.
     */
    public static function resolveWhatsappSender(Student $student): ?string
    {
        if (! $student->circle_id) {
            return null;
        }

        $leaderboard = Leaderboard::whereHas('circles', fn ($q) => $q->where('circles.id', $student->circle_id))
            ->whereNotNull('supervisor_id')
            ->where('is_active', true)
            ->latest()
            ->first();

        return $leaderboard ? 'supervisor_'.$leaderboard->supervisor_id : null;
    }

    /**
     * Queue the WhatsApp push for a notification, only when the guardian has a phone
     * and a sender session was resolved. Failures are swallowed by the job itself.
     */
    private static function pushWhatsapp(GuardianNotification $notification, ?string $senderClientId): void
    {
        if (! $senderClientId) {
            return;
        }

        $guardian = $notification->guardian;
        if (! $guardian || ! $guardian->phone) {
            return;
        }

        SendGuardianWhatsappJob::dispatch(
            $guardian->phone,
            $notification->title."\n".$notification->body,
            $senderClientId,
        );
    }
}
