<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * An invite link to a WhatsApp group. Teachers' phones open it the moment the
 * absence message is copied, so nothing else may stand in for it — a
 * supervisor cannot point a teacher at any other site.
 */
class WhatsappGroupLink implements ValidationRule
{
    /** The invite code, then the query WhatsApp may append when sharing (?mode=…). */
    private const PATTERN = '#^https://chat\.whatsapp\.com/[A-Za-z0-9]{10,64}(\?[A-Za-z0-9_=&.-]*)?$#';

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(self::PATTERN, $value)) {
            $fail('أدخل رابط دعوة لمجموعة واتساب، يبدأ بـ https://chat.whatsapp.com/');
        }
    }

    /**
     * Tidy a pasted link before it is checked and stored: the link alone when
     * it came inside WhatsApp's "join my group" message, with the https it may
     * have been pasted without, and null when the field was emptied.
     */
    public static function format(?string $link): ?string
    {
        $link = trim((string) $link);

        if ($link === '') {
            return null;
        }

        if (preg_match('#(?:https?://)?chat\.whatsapp\.com/\S+#i', $link, $match)) {
            return 'https://'.preg_replace('#^(?:https?://)?chat\.whatsapp\.com/#i', 'chat.whatsapp.com/', $match[0]);
        }

        return $link;
    }
}
