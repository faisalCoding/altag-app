<?php

namespace App\Support;

/**
 * The words the academy uses for a student's standing.
 *
 * Gathered here because the same four labels were being retyped wherever a
 * status was shown, and a fifth spelling — "منقطع" where the rest of the app
 * says "غادر الحلقات" — reads as a different fact rather than the same one,
 * leaving a teacher unable to match what the sheet says to what the student's
 * own page shows.
 */
class StudentStatus
{
    /** @var array<string, string> */
    public const LABELS = [
        'active' => 'مشارك',
        'registering' => 'تحت التسجيل',
        'suspended' => 'موقوف',
        'left' => 'غادر الحلقات',
    ];

    /**
     * The badge colour for each standing, one set for every page that shows
     * it — the directories had drifted apart, «تحت التسجيل» blue on one and
     * amber on another.
     *
     * @var array<string, string>
     */
    public const COLORS = [
        'active' => 'green',
        'registering' => 'blue',
        'suspended' => 'amber',
        'left' => 'red',
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? 'غير معروفة';
    }

    public static function color(?string $status): string
    {
        return self::COLORS[$status] ?? 'zinc';
    }
}
