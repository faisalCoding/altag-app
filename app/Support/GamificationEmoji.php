<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * A theme's coin or team symbol, drawn for the student's gamification page.
 *
 * The supervisor sets these per competition, as an emoji or an uploaded
 * image. An uploaded image is drawn as an <img>; the emojis the theme picker
 * offers are drawn as SVG icons, so they look the same on every phone; and
 * anything else the supervisor typed is printed as it is, escaped.
 *
 * Moved here unchanged from the dashboard's renderEmoji(), which still calls
 * it, so the page's partials can draw the coin too. Tailwind reads this file
 * for the classes below (see resources/css/app.css).
 */
class GamificationEmoji
{
    public static function render(mixed $emoji, string $class = 'size-5 inline-block align-middle'): string
    {
        if (str_contains((string) $emoji, '/') || str_ends_with((string) $emoji, '.webp')) {
            $url = Storage::url($emoji);

            return '<img src="'.e($url).'" class="'.e($class).'" alt="" />';
        }

        $cleanEmoji = trim((string) $emoji);

        switch ($cleanEmoji) {
            case '💎':
            case '🪙':
                return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-amber-500 fill-amber-500/10 '.$class.'">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>';
            case '🦪':
                return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-teal-600 fill-teal-500/10 '.$class.'">
                            <circle cx="12" cy="12" r="9" />
                            <circle cx="12" cy="12" r="3.5" class="fill-white" />
                        </svg>';
            case '✨':
                return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-indigo-500 fill-indigo-500/10 '.$class.'">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 21l-1.813-5.096L2.091 14.09 7.187 12.28 9 7.187l1.813 5.093 5.096 1.813-5.096 1.811z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.071 4.929l-.929 2.071-2.071.929 2.071.929.929 2.071.929-2.071 2.071-.929-2.071-.929z" />
                        </svg>';
            case '🌊':
                return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-sky-500 '.$class.'">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2 17c4-2 8-2 12 0s8 2 12 0M2 12c4-2 8-2 12 0s8 2 12 0" />
                        </svg>';
            case '🌌':
                return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-purple-500 fill-purple-500/10 '.$class.'">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.02 12.02l.707.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
                        </svg>';
            case '🔥':
                return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-orange-500 fill-orange-500/10 '.$class.'">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.362 5.214A8.252 8.252 0 0 1 12 21 8.25 8.25 0 0 1 6.038 7.047 8.287 8.287 0 0 0 9 9.601a8.983 8.983 0 0 1 3.361-6.867 8.21 8.21 0 0 0 3 2.48Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18a3.75 3.75 0 0 0 .495-7.467 5.99 5.99 0 0 0-1.925 3.546 5.974 5.974 0 0 1-2.133-1A3.75 3.75 0 0 0 12 18Z" />
                        </svg>';
            case '⚔️':
                return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-amber-600 '.$class.'">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 20L20 4M14 4l6 6M4 20l6-6M4 20l-1-1M20 4l1 1" />
                        </svg>';
            case '🛡️':
                return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-blue-500 fill-blue-500/10 '.$class.'">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                        </svg>';
            default:
                return e($emoji);
        }
    }
}
