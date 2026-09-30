<?php

use App\Livewire\Public\FormSubmit;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Manager;
use Livewire\Livewire;

/**
 * Covers 2026_09_29_110122_seed_student_uniform_order_form: the uniform survey
 * is seeded as a manager's draft, priced so only the first set is free, and a
 * respondent can place an order through the public page.
 */
function uniformOrderMigration(): object
{
    return require database_path('migrations/2026_09_29_110122_seed_student_uniform_order_form.php');
}

it('seeds the uniform survey as a draft owned by the first manager', function () {
    $manager = Manager::factory()->create();

    uniformOrderMigration()->up();

    $form = Form::where('slug', 'uniform')->firstOrFail();

    expect($form->status)->toBe('draft')
        ->and($form->created_by_type)->toBe('manager')
        ->and($form->created_by_id)->toBe($manager->id)
        ->and(collect($form->fields)->pluck('label')->all())->toBe(['اسم الطالب', 'مقاس الطقم', 'عدد الأطقم'])
        ->and(collect($form->fields)->firstWhere('is_student_name', true)['id'])->toBe('field_student_name');
});

it('prices only the first set as free and every extra one at fifty riyals', function () {
    Manager::factory()->create();

    uniformOrderMigration()->up();

    $counts = collect(Form::where('slug', 'uniform')->firstOrFail()->fields)
        ->firstWhere('id', 'field_uniform_count')['options'];

    expect($counts)->toBe([
        'طقم واحد — مجاناً',
        'طقمان — ٥٠ ريالاً',
        '٣ أطقم — ١٠٠ ريال',
        '٤ أطقم — ١٥٠ ريالاً',
        '٥ أطقم — ٢٠٠ ريال',
    ]);
});

it('does not seed a second copy or seed without a manager', function () {
    uniformOrderMigration()->up();
    expect(Form::where('slug', 'uniform')->exists())->toBeFalse();

    Manager::factory()->create();
    uniformOrderMigration()->up();
    uniformOrderMigration()->up();
    expect(Form::where('slug', 'uniform')->count())->toBe(1);
});

it('takes an order through the public page', function () {
    Manager::factory()->create();
    uniformOrderMigration()->up();

    Livewire::test(FormSubmit::class, ['slug' => 'uniform'])
        ->assertSee(['طلب طقم الطالب', 'الطقم الأول مجاني لكل طالب', 'مقاس الطقم', 'طقمان — ٥٠ ريالاً'])
        ->set('answers.field_student_name', 'عبدالله محمد')
        ->set('answers.field_uniform_size', 'M')
        ->set('answers.field_uniform_count', '٣ أطقم — ١٠٠ ريال')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);

    expect(FormResponse::sole()->answers)->toBe([
        'field_student_name' => 'عبدالله محمد',
        'field_uniform_size' => 'M',
        'field_uniform_count' => '٣ أطقم — ١٠٠ ريال',
    ]);
});

it('requires the name, size and count', function () {
    Manager::factory()->create();
    uniformOrderMigration()->up();

    Livewire::test(FormSubmit::class, ['slug' => 'uniform'])
        ->call('submit')
        ->assertHasErrors([
            'answers.field_student_name',
            'answers.field_uniform_size',
            'answers.field_uniform_count',
        ]);

    expect(FormResponse::count())->toBe(0);
});

it('keeps the survey on rollback once someone has ordered', function () {
    Manager::factory()->create();
    uniformOrderMigration()->up();

    $form = Form::where('slug', 'uniform')->firstOrFail();
    FormResponse::create(['form_id' => $form->id, 'answers' => [], 'is_processed' => false]);

    uniformOrderMigration()->down();

    expect(Form::where('slug', 'uniform')->exists())->toBeTrue();
});
