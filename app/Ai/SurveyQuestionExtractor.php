<?php

namespace App\Ai;

use App\Support\AiSettings;
use App\Support\SurveyFieldTypes;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads a block of pasted text and returns it as survey questions.
 *
 * The rule-based parser handles ordinary, tidy text on its own and costs
 * nothing; this exists for the text that defeats it — questions run together in
 * a paragraph, options implied rather than listed, a rating scale described in
 * words. It is only ever reached by a deliberate press of "improve", never on
 * the way to saving.
 *
 * Nothing it returns is trusted. Every question is checked against
 * SurveyFieldTypes before it is offered, and the supervisor reviews the result
 * in the builder before any of it becomes the survey.
 */
class SurveyQuestionExtractor implements Agent
{
    use Promptable;

    /**
     * The manager's configured provider chain, so this fails over exactly as the
     * assistant does rather than keeping a second, divergent configuration.
     *
     * @return array<string, string|null>
     */
    public function provider(): array
    {
        AiSettings::apply();

        return AiSettings::providerChain();
    }

    public function instructions(): Stringable|string
    {
        $types = collect(SurveyFieldTypes::all())
            ->reject(fn (array $meta) => $meta['layout'])
            ->map(fn (array $meta, string $key) => "- {$key}: {$meta['label']}")
            ->implode("\n");

        return <<<INSTRUCTIONS
        You turn pasted Arabic text into the questions of a satisfaction survey for a
        Quran memorization academy.

        Return ONLY a JSON array. No prose, no markdown fence, no explanation.

        Each element is an object with:
          "type"     one of the allowed types below, or "section"
          "label"    the question text, in Arabic, cleaned of numbering and bullets
          "required" true or false
          "options"  array of strings — ONLY for select and multiselect, otherwise []
          "max"      integer 3..10 — ONLY for rating, otherwise omit

        Allowed question types:
        {$types}
        - section: a heading that divides the survey; it asks nothing

        Choosing the type:
        - "ما مدى رضاك" / "قيّم" / a described 1..5 scale  -> rating
        - a statement to agree or disagree with            -> likert
        - "هل ترشّح" / a described 0..10 scale             -> nps
        - a yes/no question                                -> yesno
        - "اقتراحاتك" / "ملاحظاتك" / "لماذا"               -> long_text
        - a question with listed choices                   -> select
        - a question that says more than one may be chosen -> multiselect
        - anything else short                              -> text

        Rules:
        - Preserve the author's wording. Do not invent questions they did not write.
        - Group related questions under a section when the text clearly has parts.
        - Never output a type outside the list above.
        INSTRUCTIONS;
    }

    /**
     * The pasted text as validated question definitions, ready for review.
     *
     * A provider that is down, a reply that is not JSON, or a question of an
     * invented type all end the same way — an empty result — because the caller
     * already holds what the rule parser made of the same text, and half-trusted
     * output is worse than none.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function extract(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        try {
            $reply = (string) (new self)->prompt($text);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        return self::sanitize($reply);
    }

    /**
     * Take the model's reply apart and keep only what the builder can honour.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function sanitize(string $reply): array
    {
        $decoded = json_decode(self::unwrap($reply), true);

        if (! is_array($decoded)) {
            return [];
        }

        $fields = [];

        foreach ($decoded as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $type = is_string($raw['type'] ?? null) ? $raw['type'] : '';
            $label = is_string($raw['label'] ?? null) ? trim($raw['label']) : '';

            // The registry is the authority, not the model.
            if (! SurveyFieldTypes::exists($type) || $label === '') {
                continue;
            }

            $field = array_merge([
                'id' => 'field_'.uniqid('', true),
                'type' => $type,
                'label' => mb_substr($label, 0, 255),
                'required' => SurveyFieldTypes::isLayout($type) ? false : (bool) ($raw['required'] ?? false),
                'options' => [],
                'is_student_name' => false,
                'is_student_username' => false,
            ], SurveyFieldTypes::defaultsFor($type));

            if (SurveyFieldTypes::hasOptions($type)) {
                $options = array_values(array_filter(
                    array_map(
                        fn ($o) => is_string($o) ? trim($o) : '',
                        is_array($raw['options'] ?? null) ? $raw['options'] : []
                    ),
                    fn (string $o) => $o !== ''
                ));

                // A list question with nothing to choose from is not a list question.
                if ($options === []) {
                    continue;
                }

                $field['options'] = $options;
            }

            if ($type === 'rating' && isset($raw['max'])) {
                $field['max'] = max(3, min(10, (int) $raw['max']));
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Models often wrap JSON in a markdown fence however firmly they are asked
     * not to, so the fence is peeled rather than treated as a failure.
     */
    private static function unwrap(string $reply): string
    {
        $reply = trim($reply);

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $reply, $matches)) {
            return trim($matches[1]);
        }

        // Or surrounded by a sentence: keep from the first bracket to the last.
        $start = strpos($reply, '[');
        $end = strrpos($reply, ']');

        if ($start !== false && $end !== false && $end > $start) {
            return substr($reply, $start, $end - $start + 1);
        }

        return $reply;
    }
}
