<?php

namespace App\Support;

/**
 * Converts between Western digits (0-9) and the Eastern Arabic digits
 * (٠-٩) the printed schedules use.
 */
class ArabicDigits
{
    private const EASTERN = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    public static function toEastern(string|int|null $value): string
    {
        return str_replace(range(0, 9), self::EASTERN, (string) $value);
    }

    public static function toWestern(string|int|null $value): string
    {
        return str_replace(self::EASTERN, range(0, 9), (string) $value);
    }
}
