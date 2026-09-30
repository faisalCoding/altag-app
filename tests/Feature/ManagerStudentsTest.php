<?php

use App\Livewire\Manager\Students;
use App\Models\Circle;
use App\Models\Manager;
use App\Models\Student;
use Livewire\Livewire;

it('allows manager to edit and save a student using Students livewire component', function () {
    $manager = Manager::factory()->create();
    $circle = Circle::factory()->create();
    $student = Student::factory()->create([
        'circle_id' => $circle->id,
        'status' => 'active',
    ]);

    $this->actingAs($manager, 'manager');

    Livewire::test(Students::class)
        ->call('edit', $student->id)
        ->assertSet('editingStudentId', $student->id)
        ->set('name', 'Updated Student Name')
        ->call('save')
        ->assertHasNoErrors();

    expect($student->refresh()->name)->toBe('Updated Student Name');
});

it('changes the student status through the dedicated status manager', function () {
    $manager = Manager::factory()->create();
    $circle = Circle::factory()->create();
    $student = Student::factory()->create([
        'circle_id' => $circle->id,
        'status' => 'active',
    ]);

    $this->actingAs($manager, 'manager');

    Livewire::test('shared.⚡student-status-manager')
        ->call('open', $student->id)
        ->set('newStatus', 'suspended')
        ->set('reason', 'انقطاع متكرر عن الحضور')
        ->call('saveStatus')
        ->assertHasNoErrors();

    expect($student->refresh()->status)->toBe('suspended');
});

/**
 * Riyadh is UTC+3, so from 21:00 UTC the local date is already tomorrow by
 * UTC's reckoning. The form fills in the Riyadh date, so a validation rule
 * measured against UTC rejected it as "in the future" — every night between
 * midnight and 3am local, nobody could change a student's status.
 */
it('accepts the Riyadh date when UTC is still on the previous day', function () {
    $this->travelTo('2026-07-27 22:00:00'); // 01:00 in Riyadh on the 28th

    expect(now()->format('Y-m-d'))->toBe('2026-07-27')
        ->and(now('Asia/Riyadh')->format('Y-m-d'))->toBe('2026-07-28');

    $manager = Manager::factory()->create();
    $student = Student::factory()->create([
        'circle_id' => Circle::factory()->create()->id,
        'status' => 'active',
    ]);

    $this->actingAs($manager, 'manager');

    Livewire::test('shared.⚡student-status-manager')
        ->call('open', $student->id)
        ->assertSet('effectiveDate', '2026-07-28')
        ->set('newStatus', 'suspended')
        ->set('reason', 'انقطاع متكرر عن الحضور')
        ->call('saveStatus')
        ->assertHasNoErrors();

    expect($student->refresh()->status)->toBe('suspended');
});

it('builds a copyable text of selected student names and magic links', function () {
    $manager = Manager::factory()->create();
    $circle = Circle::factory()->create();
    $selected = Student::factory()->create([
        'circle_id' => $circle->id,
        'name' => 'أحمد',
        'access_token' => 'manager-token-one',
    ]);
    $unselected = Student::factory()->create([
        'circle_id' => $circle->id,
        'name' => 'غير محدد',
        'access_token' => 'manager-token-two',
    ]);

    $this->actingAs($manager, 'manager');

    $component = Livewire::test(Students::class)
        ->set('selectedIds', [(string) $selected->id])
        ->assertSee('نسخ الأسماء والروابط')
        ->call('buildSelectedMagicLinksText')
        ->assertHasNoErrors();

    expect($component->effects['returns'][0])
        ->toContain('أحمد')
        ->toContain(route('magic-link', ['token' => 'manager-token-one']))
        ->not->toContain('غير محدد')
        ->not->toContain('manager-token-two');
});

it('keeps selected students while the manager searches for more', function () {
    $manager = Manager::factory()->create();
    $circle = Circle::factory()->create();
    $first = Student::factory()->create(['circle_id' => $circle->id, 'name' => 'أحمد']);
    $second = Student::factory()->create(['circle_id' => $circle->id, 'name' => 'بدر']);

    $this->actingAs($manager, 'manager');

    Livewire::test(Students::class)
        ->set('selectedIds', [(string) $first->id])
        // Searching for someone else must not drop the first pick.
        ->set('search', 'بدر')
        ->assertSet('selectedIds', [(string) $first->id])
        ->set('selectedIds', [(string) $first->id, (string) $second->id])
        // Nor must changing a filter afterwards.
        ->set('circleFilter', $circle->id)
        ->assertSet('selectedIds', [(string) $first->id, (string) $second->id])
        ->assertSee('نسخ الأسماء والروابط');
});

it('clears the header checkbox but not the selection when filters change', function () {
    $manager = Manager::factory()->create();
    $student = Student::factory()->create(['circle_id' => Circle::factory()->create()->id]);

    $this->actingAs($manager, 'manager');

    Livewire::test(Students::class)
        ->set('selectAll', true)
        ->assertSet('selectAll', true)
        ->set('search', 'لا أحد')
        ->assertSet('selectAll', false)
        ->assertSet('selectedIds', [(string) $student->id]);
});

it('reports how many selected students the active filters hide', function () {
    $manager = Manager::factory()->create();
    $circle = Circle::factory()->create();
    $visible = Student::factory()->create(['circle_id' => $circle->id, 'name' => 'ظاهر']);
    $hidden = Student::factory()->create([
        'circle_id' => Circle::factory()->create()->id,
        'name' => 'مخفي',
    ]);

    $this->actingAs($manager, 'manager');

    Livewire::test(Students::class)
        ->set('selectedIds', [(string) $visible->id, (string) $hidden->id])
        ->set('circleFilter', $circle->id)
        ->assertSee('خارج نتائج البحث الحالية')
        ->call('selectedOutsideFiltersCount')
        ->assertReturned(1);
});

it('lets the manager clear the whole selection at once', function () {
    $manager = Manager::factory()->create();
    $student = Student::factory()->create(['circle_id' => Circle::factory()->create()->id]);

    $this->actingAs($manager, 'manager');

    Livewire::test(Students::class)
        ->set('selectedIds', [(string) $student->id])
        ->call('resetSelection')
        ->assertSet('selectedIds', [])
        ->assertSet('selectAll', false);
});

it('selects every filtered student when the manager ticks select all', function () {
    $manager = Manager::factory()->create();
    $circle = Circle::factory()->create();
    $otherCircle = Circle::factory()->create();
    $inCircle = Student::factory()->create(['circle_id' => $circle->id]);
    $outOfCircle = Student::factory()->create(['circle_id' => $otherCircle->id]);

    $this->actingAs($manager, 'manager');

    Livewire::test(Students::class)
        ->set('circleFilter', $circle->id)
        ->set('selectAll', true)
        ->assertSet('selectedIds', [(string) $inCircle->id]);

    expect($outOfCircle->exists)->toBeTrue();
});

it('issues a magic link token to selected students that never had one', function () {
    $manager = Manager::factory()->create();
    $student = Student::factory()->create([
        'circle_id' => Circle::factory()->create()->id,
        'access_token' => null,
    ]);

    $this->actingAs($manager, 'manager');

    $component = Livewire::test(Students::class)
        ->set('selectedIds', [(string) $student->id])
        ->call('buildSelectedMagicLinksText');

    $token = $student->refresh()->access_token;

    expect($token)->not->toBeEmpty();
    expect($component->effects['returns'][0])->toContain(route('magic-link', ['token' => $token]));
});
