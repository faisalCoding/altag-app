<?php

use App\Models\FormResponse;
use App\Support\SurveyResults;
use Illuminate\Support\Collection;

/**
 * A satisfaction survey is unreadable as raw answers. These hold each question
 * type to the summary its own scale is meant to be read by.
 */
function field(string $type, array $extra = []): array
{
    return array_merge([
        'id' => 'q', 'type' => $type, 'label' => 'سؤال', 'required' => true, 'options' => [],
    ], $extra);
}

function answers(array $values): Collection
{
    return collect($values)->map(fn ($v) => new FormResponse(['answers' => ['q' => $v]]));
}

it('averages a rating and reports how it was spread', function () {
    $summary = SurveyResults::summarise(
        [field('rating', ['max' => 5])],
        answers([5, 4, 4, 3, 5]),
    )[0];

    expect($summary['kind'])->toBe('scale');
    expect($summary['average'])->toBe(4.2);
    expect($summary['max'])->toBe(5);
    expect($summary['counts'])->toBe([1 => 0, 2 => 0, 3 => 1, 4 => 2, 5 => 2]);
    expect($summary['answered'])->toBe(5);
});

it('reads the positive share as the top two rungs', function () {
    // Four of five answered 4 or 5.
    $summary = SurveyResults::summarise([field('rating', ['max' => 5])], answers([5, 4, 4, 5, 1]))[0];

    expect($summary['positive_rate'])->toBe(80);
});

it('labels a likert scale in words rather than numbers', function () {
    $summary = SurveyResults::summarise([field('likert')], answers([5, 3]))[0];

    expect($summary['labels'][5])->toBe('موافق بشدة');
    expect($summary['labels'][1])->toBe('غير موافق بشدة');
    expect($summary['max'])->toBe(5);
});

it('reads a recommendation score on its own nought-to-ten scale', function () {
    $summary = SurveyResults::summarise([field('nps')], answers([10, 9, 0]))[0];

    expect($summary['min'])->toBe(0);
    expect($summary['max'])->toBe(10);
    expect($summary['average'])->toBe(6.3);
});

it('ignores an answer that sits outside its own scale', function () {
    // A tampered 99 must not drag the average with it.
    $summary = SurveyResults::summarise([field('rating', ['max' => 5])], answers([5, 99, 5]))[0];

    expect($summary['total'])->toBe(2);
    expect($summary['average'])->toBe(5.0);
});

it('tallies a yes/no question', function () {
    $summary = SurveyResults::summarise([field('yesno')], answers(['نعم', 'نعم', 'لا']))[0];

    expect($summary['kind'])->toBe('choice');
    expect($summary['counts'])->toBe(['نعم' => 2, 'لا' => 1]);
    expect($summary['shares']['نعم'])->toBe(67);
});

it('keeps a choice question in the order it was asked, zeroes included', function () {
    $summary = SurveyResults::summarise(
        [field('select', ['options' => ['ممتاز', 'جيد', 'ضعيف']])],
        answers(['ضعيف', 'ممتاز']),
    )[0];

    expect(array_keys($summary['counts']))->toBe(['ممتاز', 'جيد', 'ضعيف']);
    expect($summary['counts']['جيد'])->toBe(0);
});

it('keeps an "other" answer nobody listed', function () {
    $summary = SurveyResults::summarise(
        [field('select', ['options' => ['ممتاز', 'جيد']])],
        answers(['ممتاز', 'أخرى: لا أعرف']),
    )[0];

    expect($summary['counts'])->toHaveKey('أخرى: لا أعرف');
    expect($summary['counts']['أخرى: لا أعرف'])->toBe(1);
});

it('counts every pick of a multi-choice question', function () {
    $summary = SurveyResults::summarise(
        [field('multiselect', ['options' => ['المعلم', 'التنظيم', 'المكان']])],
        answers([['المعلم', 'التنظيم'], ['المعلم']]),
    )[0];

    expect($summary['counts']['المعلم'])->toBe(2);
    expect($summary['counts']['التنظيم'])->toBe(1);
    expect($summary['counts']['المكان'])->toBe(0);
});

it('lists text answers rather than averaging them', function () {
    $summary = SurveyResults::summarise([field('long_text')], answers(['زيدوا الوقت', 'كل شيء جيد']))[0];

    expect($summary['kind'])->toBe('text');
    expect($summary['samples'])->toBe(['زيدوا الوقت', 'كل شيء جيد']);
});

it('leaves section dividers out of the summary entirely', function () {
    $summaries = SurveyResults::summarise(
        [field('section', ['id' => 's']), field('rating')],
        answers([4]),
    );

    expect($summaries)->toHaveCount(1);
    expect($summaries[0]['type'])->toBe('rating');
});

it('says nothing rather than dividing by zero when nobody answered', function () {
    $summary = SurveyResults::summarise([field('rating')], collect())[0];

    expect($summary['answered'])->toBe(0);
    expect($summary['average'])->toBeNull();
    expect($summary['positive_rate'])->toBeNull();
});

it('skips blanks left by people who answered other questions', function () {
    $summary = SurveyResults::summarise([field('rating')], answers([4, null, '', 5]))[0];

    expect($summary['answered'])->toBe(2);
    expect($summary['average'])->toBe(4.5);
});
