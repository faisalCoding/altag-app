<?php

namespace App\Services;

use App\Support\SurveyFieldTypes;

/**
 * Turns a pasted block of questions into the fields a form is built from.
 *
 * Written to need no network and no provider, so pasting always works and costs
 * nothing; the AI pass sits above this and refines what it produced. The rules
 * are deliberately literal — a heading is a line that ends in a colon, an option
 * is a line that starts with a bullet — because a parser that guesses cleverly
 * is a parser nobody can predict or correct.
 *
 * Nothing is ever dropped: a line that matches no rule becomes a short-text
 * question rather than disappearing between the paste and the builder.
 */
class SurveyTextParser
{
    /** Bullets that mark a line as one of the previous question's options. */
    private const BULLETS = ['-', '–', '—', '*', '•', '●', '◦', '□', '○'];

    /**
     * Parse pasted text into builder-shaped fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parse(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        $fields = [];
        $lastQuestionIndex = null;

        foreach ($lines as $raw) {
            $line = trim($raw);

            if ($line === '') {
                continue;
            }

            if (self::isBullet($line)) {
                $option = self::stripBullet($line);

                if ($option === '') {
                    continue;
                }

                // A bullet with no question above it is a question written
                // without punctuation, not a stray option to be thrown away.
                // It deliberately does not become the owner of what follows: a
                // run of bare bullets is a list of fields, so each one stands
                // alone rather than the first swallowing the rest as options.
                if ($lastQuestionIndex === null) {
                    $fields[] = self::makeField('text', $option);

                    continue;
                }

                $fields[$lastQuestionIndex]['options'][] = $option;

                continue;
            }

            if (self::isHeading($line)) {
                $fields[] = self::makeField('section', self::stripHeading($line));
                // A heading closes the question above it, so the options of a
                // later list cannot drift back across the divider.
                $lastQuestionIndex = null;

                continue;
            }

            $label = self::stripNumbering($line);

            if ($label === '') {
                continue;
            }

            $fields[] = self::makeField('text', $label);
            $lastQuestionIndex = array_key_last($fields);
        }

        return self::assignTypes($fields);
    }

    /**
     * Settle each question's type once its options are known, since a list
     * changes what a question is and the options arrive after its label.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private static function assignTypes(array $fields): array
    {
        foreach ($fields as $index => $field) {
            if ($field['type'] === 'section') {
                continue;
            }

            $type = self::inferType($field['label'], $field['options']);

            $fields[$index] = array_merge(
                $field,
                ['type' => $type],
                SurveyFieldTypes::defaultsFor($type),
            );

            // Only the list types keep their options; a scale carries its own.
            if (! SurveyFieldTypes::hasOptions($type)) {
                $fields[$index]['options'] = [];
            } else {
                $fields[$index]['options'] = $field['options'];
            }
        }

        return $fields;
    }

    /**
     * @param  array<int, string>  $options
     */
    private static function inferType(string $label, array $options): string
    {
        $needle = self::normalise($label);

        if ($options !== []) {
            // A list whose choices are the agreement ladder is a Likert item,
            // whatever its wording.
            if (self::looksLikeAgreementScale($options)) {
                return 'likert';
            }

            return self::matches($needle, ['اختر ما ينطبق', 'اكثر من خيار', 'يمكن اختيار', 'اختيارات متعددة', 'كل ما ينطبق'])
                ? 'multiselect'
                : 'select';
        }

        if (self::matches($needle, ['ترشح', 'توصي', 'تنصح', 'من 0 الى 10', 'من ٠ الى ١٠'])) {
            return 'nps';
        }

        if (self::matches($needle, ['ما مدى رضاك', 'مدى رضا', 'درجة رضا', 'قيم ', 'قيمي ', 'تقييمك', 'تقييم', 'من 1 الى 5', 'من ١ الى ٥', 'من 5', 'راض عن'])) {
            return 'rating';
        }

        if (self::matches($needle, ['اقتراح', 'ملاحظ', 'لماذا', 'اشرح', 'وضح', 'تعليق', 'بالتفصيل', 'ما الذي'])) {
            return 'long_text';
        }

        // "هل …" with nothing to choose from is a yes/no question.
        if (str_starts_with($needle, 'هل ')) {
            return 'yesno';
        }

        if (self::matches($needle, ['تاريخ', 'التاريخ', 'متى'])) {
            return 'date';
        }

        return 'text';
    }

    /**
     * @param  array<int, string>  $options
     */
    private static function looksLikeAgreementScale(array $options): bool
    {
        $scale = array_map(
            fn (string $rung) => self::normalise($rung),
            array_values(SurveyFieldTypes::likertScale())
        );

        $hits = 0;
        foreach ($options as $option) {
            if (in_array(self::normalise($option), $scale, true)) {
                $hits++;
            }
        }

        // Three rungs of the ladder is enough to recognise it even when the
        // middle or the extremes were left out.
        return $hits >= 3;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private static function matches(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, self::normalise($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fold the spellings that differ only by orthography, so a keyword matches
     * however the writer happened to type it. Used for matching only — the
     * label is always stored as it was written.
     */
    private static function normalise(string $value): string
    {
        $value = str_replace(
            ['أ', 'إ', 'آ', 'ٱ', 'ى', 'ة', 'ؤ', 'ئ', 'ـ'],
            ['ا', 'ا', 'ا', 'ا', 'ي', 'ه', 'و', 'ي', ''],
            $value
        );

        $value = str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $value
        );

        // Harakat carry no meaning for matching and are typed inconsistently.
        $value = preg_replace('/[\x{064B}-\x{0652}]/u', '', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($value)) ?? $value);
    }

    private static function isBullet(string $line): bool
    {
        foreach (self::BULLETS as $bullet) {
            if (str_starts_with($line, $bullet.' ') || str_starts_with($line, $bullet)) {
                // A dash followed immediately by a digit is a numbered question
                // ("1-" style), not a bullet.
                return ! preg_match('/^[-–—]\s*\d/u', $line);
            }
        }

        return false;
    }

    private static function stripBullet(string $line): string
    {
        return trim(preg_replace('/^[-–—*•●◦□○]+\s*/u', '', $line) ?? $line);
    }

    /**
     * A heading is named as one: a trailing colon, a leading hash, or brackets
     * around it. Never inferred from a line merely being short.
     */
    private static function isHeading(string $line): bool
    {
        if (str_ends_with($line, '؟') || str_ends_with($line, '?')) {
            return false;
        }

        return str_ends_with($line, ':')
            || str_ends_with($line, '：')
            || str_starts_with($line, '#')
            || (str_starts_with($line, '[') && str_ends_with($line, ']'));
    }

    private static function stripHeading(string $line): string
    {
        $line = trim(preg_replace('/^#+\s*/u', '', $line) ?? $line);
        $line = trim($line, '[]');

        return trim(rtrim($line, ':：'));
    }

    /**
     * Remove list numbering in either digit set: "1." "١-" "3)" and so on.
     */
    private static function stripNumbering(string $line): string
    {
        return trim(preg_replace('/^[\d٠-٩]+\s*[-.)．:]\s*/u', '', $line) ?? $line);
    }

    /**
     * @return array<string, mixed>
     */
    private static function makeField(string $type, string $label): array
    {
        return array_merge([
            'id' => 'field_'.uniqid('', true),
            'type' => $type,
            'label' => $label,
            'required' => $type !== 'section',
            'options' => [],
            'is_student_name' => false,
            'is_student_username' => false,
        ], SurveyFieldTypes::defaultsFor($type));
    }
}
