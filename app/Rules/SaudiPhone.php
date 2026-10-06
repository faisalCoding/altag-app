<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class SaudiPhone implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match('/^(05|5|9665)\d{8}$/', $value)) {
            $fail('يجب أن يكون رقم الهاتف سعودياً صحيحاً ومكوناً من 9 أرقام تبدأ بـ 5، أو يبدأ بـ 05.');
        }
    }

    /**
     * A number as typed — Arabic-Indic digits, spaces, a plus or dashes — as
     * the digits the rule checks; null when there are none.
     */
    public static function digits(mixed $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $latin = strtr((string) $phone, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $digits = preg_replace('/\D+/', '', $latin);

        return $digits === '' ? null : $digits;
    }

    /**
     * Format the phone number to 9665XXXXXXXX for database storage.
     */
    public static function format($phone)
    {
        if (! $phone) {
            return null;
        }

        $phone = preg_replace('/[^0-9]/', '', (string) $phone);

        if (str_starts_with($phone, '05')) {
            return '966'.substr($phone, 1);
        } elseif (str_starts_with($phone, '5')) {
            return '966'.$phone;
        }

        return $phone;
    }
}
