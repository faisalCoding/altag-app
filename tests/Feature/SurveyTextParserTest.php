<?php

use App\Livewire\Supervisor\FormBuilder;
use App\Models\Supervisor;
use App\Services\SurveyTextParser;
use App\Support\SurveyFieldTypes;

/**
 * The parser's promise is that nothing pasted is lost and nothing is invented.
 * These tests hold it to both, and to the type guesses a writer would expect
 * without having learned any syntax.
 */
function parseTypes(string $text): array
{
    return collect(SurveyTextParser::parse($text))->pluck('type')->all();
}

function parseLabels(string $text): array
{
    return collect(SurveyTextParser::parse($text))->pluck('label')->all();
}

it('reads a whole pasted satisfaction survey', function () {
    $fields = SurveyTextParser::parse(<<<'TEXT'
        رضاك عن الحلقة:
        1. ما مدى رضاك عن أداء المعلم؟
        2. هل تواصل معك المعلم هذا الشهر؟
        3. كيف تقيّم مستوى ابنك؟

        التطبيق:
        ٤- هل ترشّح الأكاديمية لغيرك؟
        ٥- ما الجهاز الذي تستخدمه؟
        - جوال
        - حاسب
        - لوحي
        ٦- اقتراحاتك لتطوير الحلقة
        TEXT);

    expect(collect($fields)->pluck('type')->all())->toBe([
        'section',   // رضاك عن الحلقة:
        'rating',    // ما مدى رضاك
        'yesno',     // هل تواصل
        'rating',    // تقيّم
        'section',   // التطبيق:
        'nps',       // ترشّح
        'select',    // له خيارات
        'long_text', // اقتراحاتك
    ]);

    expect($fields[6]['options'])->toBe(['جوال', 'حاسب', 'لوحي']);
    expect($fields[0]['label'])->toBe('رضاك عن الحلقة');
    expect($fields[1]['label'])->toBe('ما مدى رضاك عن أداء المعلم؟');
});

it('strips numbering in both digit sets', function () {
    expect(parseLabels("1. سؤال أول\n٢- سؤال ثانٍ\n3) سؤال ثالث"))
        ->toBe(['سؤال أول', 'سؤال ثانٍ', 'سؤال ثالث']);
});

it('recognises a heading only when it is marked as one', function () {
    expect(parseTypes('بيانات ولي الأمر:'))->toBe(['section']);
    expect(parseTypes('# بيانات ولي الأمر'))->toBe(['section']);
    expect(parseTypes('[بيانات ولي الأمر]'))->toBe(['section']);

    // Short, but never declared a heading — so it stays a question.
    expect(parseTypes('اسمك'))->toBe(['text']);
});

it('does not mistake a question ending in a colon-less question mark for a heading', function () {
    expect(parseTypes('ما رأيك في التنظيم؟'))->toBe(['text']);
});

it('reads an agreement ladder as a Likert item whatever the wording', function () {
    $fields = SurveyTextParser::parse(<<<'TEXT'
        المعلم متعاون مع أولياء الأمور
        - موافق بشدة
        - موافق
        - محايد
        - غير موافق
        - غير موافق بشدة
        TEXT);

    expect($fields[0]['type'])->toBe('likert');
    expect($fields[0]['options'])->toBe([]); // the scale is its own, not a stored list
});

it('reads a list as multiselect when the question invites more than one', function () {
    expect(parseTypes("اختر ما ينطبق عليك\n- أحضر اللقاءات\n- أتابع التقارير"))->toBe(['multiselect']);
    expect(parseTypes("ما مصدر معرفتك بنا؟\n- صديق\n- إعلان"))->toBe(['select']);
});

it('gives a rating question its default ceiling', function () {
    $fields = SurveyTextParser::parse('ما مدى رضاك عن الحلقة؟');

    expect($fields[0]['type'])->toBe('rating');
    expect($fields[0]['max'])->toBe(5);
});

it('keeps a heading from capturing the options of a later question', function () {
    $fields = SurveyTextParser::parse(<<<'TEXT'
        ما جهازك؟
        القسم الثاني:
        - خيار تائه
        TEXT);

    // The bullet after the heading has no question above it, so it becomes one
    // rather than attaching itself across the divider.
    expect(collect($fields)->pluck('type')->all())->toBe(['text', 'section', 'text']);
    expect($fields[0]['options'])->toBe([]);
    expect($fields[2]['label'])->toBe('خيار تائه');
});

it('turns an orphan bullet into a question instead of dropping it', function () {
    $fields = SurveyTextParser::parse("- اسم الطالب\n- اسم ولي الأمر");

    expect($fields)->toHaveCount(2);
    expect(collect($fields)->pluck('label')->all())->toBe(['اسم الطالب', 'اسم ولي الأمر']);
});

it('loses no line of what was pasted', function () {
    $text = <<<'TEXT'
        القسم:
        سؤال بلا علامة
        هل سؤال نعم ولا؟
        - خيار
        اقتراحاتك
        TEXT;

    // Five written lines, five fields — the bullet folds into its question,
    // so four fields plus the option it carries.
    $fields = SurveyTextParser::parse($text);

    expect($fields)->toHaveCount(4);
    expect($fields[2]['options'])->toBe(['خيار']);
});

it('ignores blank lines and stray whitespace', function () {
    expect(SurveyTextParser::parse("\n\n   \n\nسؤال\n\n   \n"))->toHaveCount(1);
});

it('returns nothing for empty input', function () {
    expect(SurveyTextParser::parse(''))->toBe([]);
    expect(SurveyTextParser::parse("   \n  \n"))->toBe([]);
});

it('only ever produces types the registry knows', function () {
    $fields = SurveyTextParser::parse(<<<'TEXT'
        قسم:
        ما مدى رضاك؟
        هل توصي بنا؟
        متى التحق ابنك؟
        اشرح السبب
        سؤال عادي
        - أ
        - ب
        TEXT);

    foreach ($fields as $field) {
        expect(SurveyFieldTypes::exists($field['type']))->toBeTrue();
    }
});

it('gives every field the keys the builder expects', function () {
    foreach (SurveyTextParser::parse("قسم:\nما مدى رضاك؟\n- أ\n- ب") as $field) {
        expect($field)->toHaveKeys(['id', 'type', 'label', 'required', 'options']);
        expect($field['id'])->toStartWith('field_');
    }
});

it('never marks a heading as required', function () {
    $fields = SurveyTextParser::parse("القسم الأول:\nسؤال");

    expect($fields[0]['required'])->toBeFalse();
    expect($fields[1]['required'])->toBeTrue();
});

it('matches keywords through spelling and harakat differences', function () {
    expect(parseTypes('ما مدى رضاكَ عن الأداء؟'))->toBe(['rating']);
    expect(parseTypes('هل ترشّح الأكاديميه؟'))->toBe(['nps']);
});

it('reads a numbered line starting with a dash as a question, not a bullet', function () {
    expect(parseTypes("سؤال أول\n1- سؤال ثانٍ"))->toBe(['text', 'text']);
});

it('pastes questions into the builder only after they are accepted', function () {
    $this->actingAs(Supervisor::factory()->create(), 'supervisor');

    $component = Livewire\Livewire::test(FormBuilder::class)
        ->set('fields', [])
        ->set('pastedQuestions', "رضاك:\n1. ما مدى رضاك عن المعلم؟\n2. هل توصي بنا؟")
        ->call('parsePastedQuestions');

    // Parsed and shown, but the form itself is untouched until accepted.
    expect($component->get('parsedPreview'))->toHaveCount(3);
    expect($component->get('fields'))->toHaveCount(0);

    $component->call('applyParsedQuestions');

    expect($component->get('fields'))->toHaveCount(3);
    expect($component->get('parsedPreview'))->toBe([]);
    expect($component->get('pastedQuestions'))->toBe('');
    expect(collect($component->get('fields'))->pluck('type')->all())->toBe(['section', 'rating', 'nps']);
});

it('discards a parse without touching the form', function () {
    $this->actingAs(Supervisor::factory()->create(), 'supervisor');

    Livewire\Livewire::test(FormBuilder::class)
        ->set('fields', [])
        ->set('pastedQuestions', 'سؤال')
        ->call('parsePastedQuestions')
        ->call('discardParsedQuestions')
        ->assertSet('fields', [])
        ->assertSet('parsedPreview', [])
        ->assertSet('pastedQuestions', '');
});

it('appends pasted questions after what is already built', function () {
    $this->actingAs(Supervisor::factory()->create(), 'supervisor');

    $component = Livewire\Livewire::test(FormBuilder::class)
        ->call('addField', 'text', 'اسم الطالب')
        ->set('pastedQuestions', 'ما مدى رضاك؟')
        ->call('parsePastedQuestions')
        ->call('applyParsedQuestions');

    $labels = collect($component->get('fields'))->pluck('label')->all();

    expect($labels[0])->toBe('الاسم الكامل');   // the builder's own default first field
    expect(end($labels))->toBe('ما مدى رضاك؟');
});
