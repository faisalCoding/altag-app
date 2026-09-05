<?php

use App\Ai\SurveyQuestionExtractor;

/**
 * The model is a source of suggestions, not of truth. These tests hold the
 * boundary: whatever comes back is checked against the field registry before it
 * can reach the builder, and anything that fails simply does not arrive.
 */
it('keeps a well-formed reply', function () {
    $fields = SurveyQuestionExtractor::sanitize(json_encode([
        ['type' => 'section', 'label' => 'رضاك عن الحلقة', 'required' => true],
        ['type' => 'rating', 'label' => 'ما مدى رضاك؟', 'required' => true, 'max' => 5],
        ['type' => 'long_text', 'label' => 'اقتراحاتك', 'required' => false],
    ]));

    expect($fields)->toHaveCount(3);
    expect(collect($fields)->pluck('type')->all())->toBe(['section', 'rating', 'long_text']);
    expect($fields[1]['max'])->toBe(5);
    expect($fields[0]['required'])->toBeFalse(); // a heading is never required
});

it('peels a markdown fence off the reply', function () {
    $reply = "إليك الأسئلة:\n```json\n".json_encode([
        ['type' => 'yesno', 'label' => 'هل تواصل معك المعلم؟', 'required' => true],
    ])."\n```";

    expect(SurveyQuestionExtractor::sanitize($reply))->toHaveCount(1);
});

it('recovers json wrapped in a sentence', function () {
    $reply = 'بالطبع، هذه هي: '.json_encode([
        ['type' => 'text', 'label' => 'اسمك', 'required' => true],
    ]).' أرجو أن تفيدك.';

    expect(SurveyQuestionExtractor::sanitize($reply))->toHaveCount(1);
});

it('drops a question of a type the registry never heard of', function () {
    $fields = SurveyQuestionExtractor::sanitize(json_encode([
        ['type' => 'telepathy', 'label' => 'ما الذي تفكر فيه؟', 'required' => true],
        ['type' => 'rating', 'label' => 'رضاك؟', 'required' => true],
    ]));

    expect($fields)->toHaveCount(1);
    expect($fields[0]['type'])->toBe('rating');
});

it('drops a question with no text', function () {
    expect(SurveyQuestionExtractor::sanitize(json_encode([
        ['type' => 'rating', 'label' => '   ', 'required' => true],
    ])))->toBeEmpty();
});

it('drops a list question that lists nothing', function () {
    expect(SurveyQuestionExtractor::sanitize(json_encode([
        ['type' => 'select', 'label' => 'اختر', 'required' => true, 'options' => []],
    ])))->toBeEmpty();
});

it('clamps a rating ceiling the model invented', function () {
    $fields = SurveyQuestionExtractor::sanitize(json_encode([
        ['type' => 'rating', 'label' => 'رضاك؟', 'required' => true, 'max' => 500],
        ['type' => 'rating', 'label' => 'وهذا؟', 'required' => true, 'max' => 0],
    ]));

    expect($fields[0]['max'])->toBe(10);
    expect($fields[1]['max'])->toBe(3);
});

it('returns nothing for a reply that is not json at all', function () {
    expect(SurveyQuestionExtractor::sanitize('عذراً، لا أستطيع مساعدتك في ذلك.'))->toBeEmpty();
});

it('returns nothing for an empty reply', function () {
    expect(SurveyQuestionExtractor::sanitize(''))->toBeEmpty();
});

it('survives a reply that is json but not a list of questions', function () {
    expect(SurveyQuestionExtractor::sanitize('{"error":"rate limited"}'))->toBeEmpty();
    expect(SurveyQuestionExtractor::sanitize('[1, 2, "three"]'))->toBeEmpty();
    expect(SurveyQuestionExtractor::sanitize('[null]'))->toBeEmpty();
});

it('never asks the provider anything for empty text', function () {
    // No provider is configured in tests; an empty input must short-circuit
    // before any call is attempted rather than failing its way to empty.
    expect(SurveyQuestionExtractor::extract('   '))->toBeEmpty();
});

it('gives every question the keys the builder expects', function () {
    $field = SurveyQuestionExtractor::sanitize(json_encode([
        ['type' => 'multiselect', 'label' => 'ما الذي يعجبك؟', 'required' => true, 'options' => ['المعلم', 'التنظيم']],
    ]))[0];

    expect($field)->toHaveKeys(['id', 'type', 'label', 'required', 'options', 'is_student_name', 'is_student_username']);
    expect($field['options'])->toBe(['المعلم', 'التنظيم']);
});
