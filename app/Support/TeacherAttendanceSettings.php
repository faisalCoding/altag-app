<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The manager's choices for the teachers' roll call, read in one place.
 */
final class TeacherAttendanceSettings
{
    /**
     * How many days back a supervisor may still change a roll call; older
     * days are the manager's alone. Zero leaves every day open.
     */
    public static function lockDays(): int
    {
        return max(0, (int) Setting::getVal('teacher_attendance_lock_days', 7));
    }

    /**
     * Whether a teacher marked absent or late is told over WhatsApp, from the
     * account of whoever marked them. Off until the manager turns it on.
     */
    public static function notifiesTeachers(): bool
    {
        return (bool) Setting::getVal('teacher_attendance_whatsapp', false);
    }
}
