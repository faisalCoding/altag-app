<?php

namespace App\Support;

/**
 * The question types a form may hold, in one place.
 *
 * The builder offers them, the public page renders and validates them, the
 * paste parser guesses them, the AI output is checked against them and the
 * results screen aggregates by them — five readers that used to have no shared
 * definition to drift away from. Adding a type here is what makes it real.
 */
class SurveyFieldTypes
{
    /**
     * Every type, with what each reader needs to know about it.
     *
     * options  — the question carries a list the respondent chooses from
     * scale    — the answer is a number on a fixed range, so it can be averaged
     * layout   — the question asks nothing; it only divides the form
     *
     * @return array<string, array{label: string, icon: string, options: bool, scale: bool, layout: bool}>
     */
    public static function all(): array
    {
        return [
            'section' => ['label' => 'فاصل قسم', 'icon' => 'bars-3-bottom-right', 'options' => false, 'scale' => false, 'layout' => true],
            'text' => ['label' => 'نص قصير', 'icon' => 'pencil', 'options' => false, 'scale' => false, 'layout' => false],
            'long_text' => ['label' => 'نص طويل', 'icon' => 'bars-3', 'options' => false, 'scale' => false, 'layout' => false],
            'rating' => ['label' => 'مقياس رضا', 'icon' => 'star', 'options' => false, 'scale' => true, 'layout' => false],
            'likert' => ['label' => 'مدى الموافقة', 'icon' => 'adjustments-horizontal', 'options' => false, 'scale' => true, 'layout' => false],
            'nps' => ['label' => 'مقياس الترشيح', 'icon' => 'megaphone', 'options' => false, 'scale' => true, 'layout' => false],
            'yesno' => ['label' => 'نعم / لا', 'icon' => 'check-circle', 'options' => false, 'scale' => false, 'layout' => false],
            'select' => ['label' => 'قائمة خيارات', 'icon' => 'list-bullet', 'options' => true, 'scale' => false, 'layout' => false],
            'multiselect' => ['label' => 'خيارات متعددة', 'icon' => 'squares-2x2', 'options' => true, 'scale' => false, 'layout' => false],
            'date' => ['label' => 'تاريخ', 'icon' => 'calendar', 'options' => false, 'scale' => false, 'layout' => false],
            'image' => ['label' => 'صورة', 'icon' => 'photo', 'options' => false, 'scale' => false, 'layout' => false],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** The `in:` rule body the builder and the AI validator both check against. */
    public static function validationList(): string
    {
        return implode(',', self::keys());
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::all());
    }

    public static function label(string $type): string
    {
        return self::all()[$type]['label'] ?? $type;
    }

    public static function icon(string $type): string
    {
        return self::all()[$type]['icon'] ?? 'pencil';
    }

    public static function hasOptions(string $type): bool
    {
        return self::all()[$type]['options'] ?? false;
    }

    public static function isScale(string $type): bool
    {
        return self::all()[$type]['scale'] ?? false;
    }

    public static function isLayout(string $type): bool
    {
        return self::all()[$type]['layout'] ?? false;
    }

    /**
     * The five rungs of the agreement scale, strongest first, as both the
     * builder preview and the public page render them.
     *
     * @return array<int, string>
     */
    public static function likertScale(): array
    {
        return [
            5 => 'موافق بشدة',
            4 => 'موافق',
            3 => 'محايد',
            2 => 'غير موافق',
            1 => 'غير موافق بشدة',
        ];
    }

    /**
     * The bounds an answer of this type must fall within, or null when the type
     * is not a scale. Read by the public page's validation and by the results
     * screen, so a stored answer can never sit outside what it claims to be.
     *
     * @param  array<string, mixed>  $field
     * @return array{min: int, max: int}|null
     */
    public static function scaleBounds(array $field): ?array
    {
        $type = $field['type'] ?? '';

        if (! self::isScale($type)) {
            return null;
        }

        return match ($type) {
            'nps' => ['min' => 0, 'max' => 10],
            'likert' => ['min' => 1, 'max' => 5],
            // A rating may be set to any ceiling from 3 to 10; five is the default.
            'rating' => ['min' => 1, 'max' => max(3, min(10, (int) ($field['max'] ?? 5)))],
            default => null,
        };
    }

    /**
     * The extra keys a type needs on top of label/required, so a field created
     * anywhere starts complete rather than filling gaps on first render.
     *
     * @return array<string, mixed>
     */
    public static function defaultsFor(string $type): array
    {
        return match ($type) {
            'rating' => ['max' => 5],
            'select', 'multiselect' => ['options' => []],
            default => [],
        };
    }
}
