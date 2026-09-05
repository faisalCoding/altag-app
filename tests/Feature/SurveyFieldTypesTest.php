<?php

use App\Livewire\Public\FormSubmit;
use App\Livewire\Supervisor\FormBuilder;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Supervisor;
use App\Support\SurveyFieldTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The registry is the single definition every reader shares — the builder, the
 * public page, the parser and the results screen. These tests hold the three
 * that ship in this phase to it, so a type cannot be offered in one place and
 * unknown in another.
 */
beforeEach(function () {
    $this->supervisor = Supervisor::factory()->create();
    $this->actingAs($this->supervisor, 'supervisor');
});

/** @param array<int, array<string, mixed>> $fields */
function surveyWith(array $fields): Form
{
    return Form::create([
        'supervisor_id' => auth()->guard('supervisor')->id(),
        'title' => 'استبانة رضا',
        'slug' => 'satisfaction-'.uniqid(),
        'color' => '#7a2727',
        'fields' => $fields,
    ]);
}

function scaleField(string $type, array $extra = []): array
{
    return array_merge([
        'id' => 'q_'.$type,
        'type' => $type,
        'label' => 'سؤال '.$type,
        'required' => true,
        'options' => [],
    ], SurveyFieldTypes::defaultsFor($type), $extra);
}

it('knows every satisfaction type the builder offers', function (string $type) {
    expect(SurveyFieldTypes::exists($type))->toBeTrue();
    expect(SurveyFieldTypes::label($type))->not->toBe($type);
})->with(['rating', 'likert', 'nps', 'yesno', 'long_text', 'section']);

it('bounds each scale to its own range', function () {
    expect(SurveyFieldTypes::scaleBounds(scaleField('nps')))->toBe(['min' => 0, 'max' => 10]);
    expect(SurveyFieldTypes::scaleBounds(scaleField('likert')))->toBe(['min' => 1, 'max' => 5]);
    expect(SurveyFieldTypes::scaleBounds(scaleField('rating')))->toBe(['min' => 1, 'max' => 5]);
    expect(SurveyFieldTypes::scaleBounds(scaleField('rating', ['max' => 7])))->toBe(['min' => 1, 'max' => 7]);
});

it('clamps a rating ceiling that was tampered with', function () {
    expect(SurveyFieldTypes::scaleBounds(scaleField('rating', ['max' => 99]))['max'])->toBe(10);
    expect(SurveyFieldTypes::scaleBounds(scaleField('rating', ['max' => 1]))['max'])->toBe(3);
});

it('treats only a section as layout, and only lists as carrying options', function () {
    expect(SurveyFieldTypes::isLayout('section'))->toBeTrue();
    expect(SurveyFieldTypes::isLayout('rating'))->toBeFalse();
    expect(SurveyFieldTypes::hasOptions('select'))->toBeTrue();
    expect(SurveyFieldTypes::hasOptions('multiselect'))->toBeTrue();
    expect(SurveyFieldTypes::hasOptions('rating'))->toBeFalse();
});

it('lets the builder save a satisfaction survey', function () {
    Livewire::test(FormBuilder::class)
        ->set('title', 'رضا أولياء الأمور')
        ->set('slug', 'guardian-satisfaction')
        ->set('fields', [
            scaleField('section', ['label' => 'الحلقة', 'required' => false]),
            scaleField('rating', ['label' => 'ما مدى رضاك عن الحلقة؟']),
            scaleField('likert', ['label' => 'المعلم متعاون']),
            scaleField('nps', ['label' => 'هل ترشّح الأكاديمية؟']),
            scaleField('yesno', ['label' => 'هل تواصل معك المعلم؟']),
            scaleField('long_text', ['label' => 'اقتراحاتك']),
        ])
        ->call('save')
        ->assertHasNoErrors();

    $form = Form::where('slug', 'guardian-satisfaction')->sole();

    expect($form->fields)->toHaveCount(6);
    expect(collect($form->fields)->pluck('type')->all())
        ->toBe(['section', 'rating', 'likert', 'nps', 'yesno', 'long_text']);
});

it('refuses a field type the registry does not know', function () {
    Livewire::test(FormBuilder::class)
        ->set('title', 'استبانة')
        ->set('slug', 'bad-type')
        ->set('fields', [scaleField('text', ['type' => 'telepathy'])])
        ->call('save')
        ->assertHasErrors('fields.0.type');

    expect(Form::where('slug', 'bad-type')->exists())->toBeFalse();
});

it('refuses a rating ceiling outside the allowed range', function () {
    Livewire::test(FormBuilder::class)
        ->set('title', 'استبانة')
        ->set('slug', 'bad-scale')
        ->set('fields', [scaleField('rating', ['max' => 50])])
        ->call('save')
        ->assertHasErrors('fields.0.max');
});

it('never adds a field of an unknown type', function () {
    $component = Livewire::test(FormBuilder::class)->set('fields', []);

    $component->call('addField', 'telepathy', 'سؤال');
    expect($component->get('fields'))->toHaveCount(0);

    $component->call('addField', 'rating', 'رضاك؟');
    expect($component->get('fields'))->toHaveCount(1);
    expect($component->get('fields')[0]['max'])->toBe(5); // its default arrives with it
});

it('never marks a section as required', function () {
    $component = Livewire::test(FormBuilder::class)
        ->set('fields', [])
        ->call('addField', 'section', 'قسم', true);

    expect($component->get('fields')[0]['required'])->toBeFalse();
});

it('accepts a satisfaction answer inside the scale', function () {
    $form = surveyWith([
        scaleField('section', ['label' => 'القسم الأول', 'required' => false]),
        scaleField('rating', ['label' => 'رضاك؟']),
        scaleField('nps', ['label' => 'ترشيحك؟']),
        scaleField('yesno', ['label' => 'تواصل معك؟']),
    ]);

    Livewire::test(FormSubmit::class, ['slug' => $form->slug])
        ->set('answers.q_rating', 4)
        ->set('answers.q_nps', 9)
        ->set('answers.q_yesno', 'نعم')
        ->call('submit')
        ->assertHasNoErrors();

    $answers = FormResponse::sole()->answers;

    expect($answers['q_rating'])->toEqual(4);
    expect($answers['q_nps'])->toEqual(9);
    expect($answers['q_yesno'])->toBe('نعم');
    expect($answers)->not->toHaveKey('q_section'); // a heading stores nothing
});

it('refuses a rating above the ceiling the question declares', function () {
    $form = surveyWith([scaleField('rating', ['label' => 'رضاك؟', 'max' => 5])]);

    Livewire::test(FormSubmit::class, ['slug' => $form->slug])
        ->set('answers.q_rating', 9)
        ->call('submit')
        ->assertHasErrors('answers.q_rating');

    expect(FormResponse::count())->toBe(0);
});

it('refuses a recommendation score above ten', function () {
    $form = surveyWith([scaleField('nps', ['label' => 'ترشيحك؟'])]);

    Livewire::test(FormSubmit::class, ['slug' => $form->slug])
        ->set('answers.q_nps', 11)
        ->call('submit')
        ->assertHasErrors('answers.q_nps');
});

it('refuses a yes/no answer that is neither', function () {
    $form = surveyWith([scaleField('yesno', ['label' => 'تواصل معك؟'])]);

    Livewire::test(FormSubmit::class, ['slug' => $form->slug])
        ->set('answers.q_yesno', 'ربما')
        ->call('submit')
        ->assertHasErrors('answers.q_yesno');
});

it('leaves an optional scale blank without complaint', function () {
    $form = surveyWith([scaleField('rating', ['label' => 'رضاك؟', 'required' => false])]);

    Livewire::test(FormSubmit::class, ['slug' => $form->slug])
        ->call('submit')
        ->assertHasNoErrors();

    expect(FormResponse::count())->toBe(1);
});

it('renders the satisfaction questions on the public page', function () {
    $form = surveyWith([
        scaleField('section', ['label' => 'رضاك عن الحلقة', 'required' => false]),
        scaleField('rating', ['label' => 'ما مدى رضاك؟']),
        scaleField('likert', ['label' => 'المعلم متعاون']),
        scaleField('nps', ['label' => 'هل ترشّح الأكاديمية؟']),
    ]);

    $this->get(route('forms.submit', $form->slug))
        ->assertSuccessful()
        ->assertSee('رضاك عن الحلقة')
        ->assertSee('ما مدى رضاك؟')
        ->assertSee('موافق بشدة')
        ->assertSee('لا أُرشّح إطلاقاً');
});

it('keeps section dividers out of the responses table and reads scales back', function () {
    $form = surveyWith([
        scaleField('section', ['label' => 'قسم بلا إجابة', 'required' => false]),
        scaleField('rating', ['label' => 'رضاك؟']),
        scaleField('likert', ['label' => 'المعلم متعاون']),
    ]);

    FormResponse::create([
        'form_id' => $form->id,
        'answers' => ['q_rating' => 4, 'q_likert' => 5],
    ]);

    $html = $this->get(route('supervisor.forms.responses', $form->id))
        ->assertSuccessful()
        ->getContent();

    // The divider is a heading, so it is never a column of answers.
    expect($html)->not->toContain('قسم بلا إجابة');
    // A scale reads as the respondent saw it, not as a bare number.
    expect($html)->toContain('4');
    expect($html)->toContain('موافق بشدة');
});
