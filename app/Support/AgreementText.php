<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * The supervisor's light markup in an agreement: text in parentheses is
 * highlighted yellow, the parentheses kept, and text between two asterisks
 * shows bold in a calm deep red, the asterisks dropped. Everything else reads
 * as written — escaped first, so nothing typed becomes markup of its own.
 */
final class AgreementText
{
    public static function format(string $text): HtmlString
    {
        $html = preg_replace('/\*([^*\n]+?)\*/u', '<strong class="agreement-strong">$1</strong>', e($text));
        $html = preg_replace('/\(([^()\n]+?)\)/u', '<mark class="agreement-mark">($1)</mark>', $html);

        return new HtmlString($html);
    }
}
