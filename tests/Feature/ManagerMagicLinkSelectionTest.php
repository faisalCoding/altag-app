<?php

use App\Livewire\Manager\Guardians;
use App\Livewire\Manager\Students;
use App\Livewire\Manager\Supervisors;
use App\Livewire\Manager\Teachers;
use App\Models\Guardian;
use App\Models\Manager;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(Manager::factory()->create(), 'manager');
});

/**
 * The four directories and what each one's tokens open. Selection used to exist
 * on the students tab alone.
 *
 * @return array<int, array{0: class-string, 1: class-string, 2: string}>
 */
dataset('directories', [
    'students' => [Students::class, Student::class, '/magic/'],
    'teachers' => [Teachers::class, Teacher::class, '/teacher-magic/'],
    'supervisors' => [Supervisors::class, Supervisor::class, '/supervisor-magic/'],
    'guardians' => [Guardians::class, Guardian::class, '/guardian-magic/'],
]);

it('copies names and links for whoever is selected', function (string $component, string $model, string $path) {
    $picked = $model::factory()->create(['name' => 'المختار', 'access_token' => str_repeat('a', 32)]);
    $other = $model::factory()->create(['name' => 'غير المختار']);

    $text = Livewire::test($component)
        ->set('selectedIds', [(string) $picked->id])
        ->instance()
        ->buildSelectedMagicLinksText();

    expect($text)->toContain('المختار')
        ->toContain($path.str_repeat('a', 32))
        ->not->toContain('غير المختار');
})->with('directories');

it('issues a token to anyone who never had one', function (string $component, string $model, string $path) {
    $person = $model::factory()->create(['name' => 'بلا رمز', 'access_token' => null]);

    $text = Livewire::test($component)
        ->set('selectedIds', [(string) $person->id])
        ->instance()
        ->buildSelectedMagicLinksText();

    expect($person->fresh()->access_token)->not->toBeNull();
    expect($text)->toContain($path.$person->fresh()->access_token);
})->with('directories');

it('says nothing when nobody is selected', function (string $component) {
    expect(Livewire::test($component)->instance()->buildSelectedMagicLinksText())->toBe('');
})->with('directories');

it('takes everyone the filters show when the header box is ticked', function (string $component, string $model) {
    $model::factory()->count(3)->create();

    Livewire::test($component)
        ->set('selectAll', true)
        ->assertCount('selectedIds', 3)
        ->set('selectAll', false)
        ->assertCount('selectedIds', 0);
})->with('directories');

it('keeps picks made under an earlier search', function (string $component, string $model) {
    $first = $model::factory()->create(['name' => 'عبدالله الأول']);
    $second = $model::factory()->create(['name' => 'سعد الثاني']);

    $page = Livewire::test($component)
        ->set('selectedIds', [(string) $first->id])
        ->set('search', 'سعد')
        ->set('selectAll', true);

    // Gathering a list over several searches is the whole point of the box.
    expect($page->get('selectedIds'))->toContain((string) $first->id, (string) $second->id);
})->with('directories');

it('counts the selected the current filter hides', function (string $component, string $model) {
    $hidden = $model::factory()->create(['name' => 'خارج البحث']);
    $shown = $model::factory()->create(['name' => 'داخل البحث']);

    $page = Livewire::test($component)
        ->set('selectedIds', [(string) $hidden->id, (string) $shown->id])
        ->set('search', 'داخل');

    expect($page->instance()->selectedOutsideFiltersCount())->toBe(1);
})->with('directories');

it('replaces the links of the selected and leaves the rest alone', function (string $component, string $model) {
    $picked = $model::factory()->create(['access_token' => str_repeat('a', 32)]);
    $other = $model::factory()->create(['access_token' => str_repeat('b', 32)]);

    Livewire::test($component)
        ->set('selectedIds', [(string) $picked->id])
        ->call('regenerateSelected')
        ->assertSet('selectedIds', []);

    expect($picked->fresh()->access_token)->not->toBe(str_repeat('a', 32));
    expect($other->fresh()->access_token)->toBe(str_repeat('b', 32));
})->with('directories');

it('will not act on an id outside what the page may touch', function (string $component, string $model) {
    // The ids come from a browser, so the selection is re-resolved through the
    // component's own scope before anything is read or written.
    $page = Livewire::test($component)->set('selectedIds', ['999999']);

    expect($page->instance()->buildSelectedMagicLinksText())->toBe('');
})->with('directories');
