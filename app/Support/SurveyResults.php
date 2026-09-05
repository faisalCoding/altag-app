<?php

namespace App\Support;

use App\Models\FormResponse;
use Illuminate\Support\Collection;

/**
 * What a set of answers says, read by the kind of question that was asked.
 *
 * A satisfaction survey is unreadable as a table of raw answers: a column of
 * fours and fives tells a supervisor nothing until it becomes "4.3 out of 5,
 * and eight in ten answered four or above". Each type is summarised the way its
 * own scale is meant to be read, and text questions are simply listed, since
 * averaging prose is not a thing.
 */
class SurveyResults
{
    /**
     * Summarise every answerable question of a form.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  Collection<int, FormResponse>  $responses
     * @return array<int, array<string, mixed>>
     */
    public static function summarise(array $fields, Collection $responses): array
    {
        $summaries = [];

        foreach ($fields as $field) {
            if (SurveyFieldTypes::isLayout($field['type'] ?? '')) {
                continue;
            }

            $answers = $responses
                ->map(fn ($response) => $response->answers[$field['id']] ?? null)
                ->reject(fn ($answer) => $answer === null || $answer === '' || $answer === []);

            $summaries[] = array_merge(
                [
                    'id' => $field['id'],
                    'label' => $field['label'] ?? '',
                    'type' => $field['type'],
                    'answered' => $answers->count(),
                ],
                self::forType($field, $answers),
            );
        }

        return $summaries;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  Collection<int, mixed>  $answers
     * @return array<string, mixed>
     */
    private static function forType(array $field, Collection $answers): array
    {
        $type = $field['type'];

        if (SurveyFieldTypes::isScale($type)) {
            return self::scale($field, $answers);
        }

        return match ($type) {
            'yesno' => self::distribution($answers, ['نعم', 'لا']),
            'select' => self::distribution($answers, $field['options'] ?? []),
            'multiselect' => self::distribution($answers->flatten(), $field['options'] ?? []),
            'long_text', 'text' => ['kind' => 'text', 'samples' => $answers->take(20)->values()->all()],
            default => ['kind' => 'raw'],
        };
    }

    /**
     * A scale's average, and how the answers sat along it.
     *
     * The positive share counts the top two rungs — "راضٍ ومتحمّس" as against
     * merely "not unhappy" — which is the figure an academy acts on, and it is
     * reported alongside the average rather than instead of it, because an
     * average of three can hide a room split between ones and fives.
     *
     * @param  array<string, mixed>  $field
     * @param  Collection<int, mixed>  $answers
     * @return array<string, mixed>
     */
    private static function scale(array $field, Collection $answers): array
    {
        $bounds = SurveyFieldTypes::scaleBounds($field) ?? ['min' => 1, 'max' => 5];
        $numeric = $answers->map(fn ($a) => (int) $a)
            ->filter(fn (int $a) => $a >= $bounds['min'] && $a <= $bounds['max'])
            ->values();

        $counts = [];
        for ($value = $bounds['min']; $value <= $bounds['max']; $value++) {
            $counts[$value] = $numeric->filter(fn (int $a) => $a === $value)->count();
        }

        $total = $numeric->count();
        $positiveFrom = $bounds['max'] - 1;
        $positive = $numeric->filter(fn (int $a) => $a >= $positiveFrom)->count();

        return [
            'kind' => 'scale',
            'min' => $bounds['min'],
            'max' => $bounds['max'],
            'average' => $total > 0 ? round($numeric->sum() / $total, 1) : null,
            'counts' => $counts,
            'total' => $total,
            'positive_rate' => $total > 0 ? (int) round($positive / $total * 100) : null,
            'labels' => $field['type'] === 'likert' ? SurveyFieldTypes::likertScale() : null,
        ];
    }

    /**
     * How often each choice was picked, listed choices first so a question reads
     * in the order it was asked, with anything unexpected appended rather than
     * dropped — "أخرى: ..." answers are real answers.
     *
     * @param  Collection<int, mixed>  $answers
     * @param  array<int, string>  $options
     * @return array<string, mixed>
     */
    private static function distribution(Collection $answers, array $options): array
    {
        $tally = $answers->map(fn ($a) => (string) $a)->countBy();
        $total = $answers->count();

        $ordered = collect($options)
            ->mapWithKeys(fn (string $option) => [$option => $tally->get($option, 0)]);

        foreach ($tally as $answer => $count) {
            if (! $ordered->has($answer)) {
                $ordered->put($answer, $count);
            }
        }

        return [
            'kind' => 'choice',
            'total' => $total,
            'counts' => $ordered->all(),
            'shares' => $ordered->map(fn (int $c) => $total > 0 ? (int) round($c / $total * 100) : 0)->all(),
        ];
    }
}
