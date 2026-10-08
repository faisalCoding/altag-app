<?php

namespace App\Support;

use App\Models\Teacher;

/**
 * A WhatsApp message asking a circle's teacher to take a day's roll, with a
 * link that signs them in and opens that day's roll call. The supervisor's
 * daily follow-up and the manager's attendance report both send it.
 */
final class RollCallReminder
{
    /**
     * The wa.me address that opens the message to the teacher; null when the
     * teacher has no phone or no sign-in link to send.
     */
    public static function whatsappUrl(?Teacher $teacher, string $date): ?string
    {
        if (! $teacher?->phone || ! $teacher->access_token) {
            return null;
        }

        return 'https://wa.me/'.self::phone($teacher->phone).'?text='.urlencode(self::message($teacher, $date));
    }

    public static function message(Teacher $teacher, string $date): string
    {
        $firstName = explode(' ', trim($teacher->name))[0];
        $link = route('teacher.magic-link', [
            'token' => $teacher->access_token,
            // The magic link takes the teacher back only into this site.
            'redirect' => route('teacher.attendance', ['date' => $date]),
        ]);

        return "السلام عليكم ورحمة الله وبركاته\n"
            ."كيف حالك أ. {$firstName}\n"
            .'أرجو أن تكمل تحضير يوم '.HijriDate::dayMonth($date)."\n"
            ."تصل إلى صفحة التحضير لهذا اليوم عبر الرابط\n"
            .$link;
    }

    /**
     * A Saudi number as wa.me takes it: digits only, the country code in
     * place of the leading zero.
     */
    public static function phone(string $phone): string
    {
        $digits = ltrim(preg_replace('/\D/', '', $phone), '0');

        return str_starts_with($digits, '966') ? $digits : '966'.$digits;
    }
}
