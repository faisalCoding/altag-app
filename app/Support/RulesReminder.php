<?php

namespace App\Support;

use App\Models\RuleVersion;
use App\Models\User;

/**
 * A WhatsApp message asking a teacher or a supervisor to read the circles'
 * rules and give their word, with a link that signs them in and opens the
 * rules page.
 */
final class RulesReminder
{
    /**
     * The wa.me address that opens the message to the person; null when they
     * have no phone or no sign-in link to send.
     */
    public static function whatsappUrl(User $person, string $role, RuleVersion $version): ?string
    {
        if (! $person->phone || ! $person->access_token) {
            return null;
        }

        return 'https://wa.me/'.RollCallReminder::phone($person->phone).'?text='.urlencode(self::message($person, $role, $version));
    }

    public static function message(User $person, string $role, RuleVersion $version): string
    {
        $firstName = explode(' ', trim($person->name))[0];
        $link = route("{$role}.magic-link", [
            'token' => $person->access_token,
            // The magic link takes them back only into this site.
            'redirect' => route("{$role}.rules"),
        ]);

        return "السلام عليكم ورحمة الله وبركاته\n"
            ."كيف حالك أ. {$firstName}\n"
            .'صدر الإصدار '.$version->number." من أنظمة الحلقات، أرجو الاطلاع عليه والإقرار به\n"
            ."تصل إلى صفحة الأنظمة عبر الرابط\n"
            .$link;
    }
}
