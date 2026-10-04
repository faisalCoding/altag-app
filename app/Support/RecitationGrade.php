<?php

namespace App\Support;

/**
 * How a Quran recitation was graded. «لم يسمع» is a grade of its own, kept
 * apart from "not graded yet" (null), but it is not a recitation: it earns
 * nothing and never counts as memorised.
 */
enum RecitationGrade: int
{
    case NotHeard = 0;
    case Acceptable = 1;
    case Good = 2;
    case Excellent = 3;

    public function label(): string
    {
        return match ($this) {
            self::NotHeard => 'لم يسمع',
            self::Acceptable => 'مقبول',
            self::Good => 'جيد',
            self::Excellent => 'ممتاز',
        };
    }

    /** Whether a stored grade means the student actually recited. */
    public static function isRecited(?int $grade): bool
    {
        return $grade !== null && $grade >= self::Acceptable->value && $grade <= self::Excellent->value;
    }
}
