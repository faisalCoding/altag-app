<?php

use App\Livewire\Supervisor\Students;
use App\Models\Circle;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Supervisor;
use Livewire\Livewire;

beforeEach(function () {
    $this->stage = Stage::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $this->circle2 = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $this->supervisor = Supervisor::factory()->create();
    $this->supervisor->stages()->attach($this->stage->id);
});

it('allows supervisor to bulk select and change circle of students', function () {
    $student1 = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'status' => 'active',
    ]);
    $student2 = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'status' => 'active',
    ]);

    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(Students::class)
        ->set('selectedIds', [(string) $student1->id, (string) $student2->id])
        ->set('bulkCircleId', $this->circle2->id)
        ->call('applyBulkCircle')
        ->assertHasNoErrors();

    expect($student1->refresh()->circle_id)->toBe($this->circle2->id);
    expect($student2->refresh()->circle_id)->toBe($this->circle2->id);
});

it('allows supervisor to bulk change joined_at date of students', function () {
    $student1 = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'joined_at' => '2026-01-01',
    ]);
    $student2 = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'joined_at' => '2026-01-01',
    ]);

    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(Students::class)
        ->set('selectedIds', [(string) $student1->id, (string) $student2->id])
        ->set('bulkJoinedAt', '2026-06-01')
        ->call('applyBulkJoinedAt')
        ->assertHasNoErrors();

    expect($student1->refresh()->joined_at->format('Y-m-d'))->toBe('2026-06-01');
    expect($student2->refresh()->joined_at->format('Y-m-d'))->toBe('2026-06-01');
});

it('allows supervisor to bulk change status of students', function () {
    $student1 = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'status' => 'active',
    ]);
    $student2 = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'status' => 'active',
    ]);

    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(Students::class)
        ->set('selectedIds', [(string) $student1->id, (string) $student2->id])
        ->set('bulkStatus', 'suspended')
        ->call('applyBulkStatus')
        ->assertHasNoErrors();

    expect($student1->refresh()->status)->toBe('suspended');
    expect($student2->refresh()->status)->toBe('suspended');
});

/** Same UTC-versus-Riyadh trap as the single status change. */
it('accepts a Riyadh-dated bulk status change while UTC lags a day behind', function () {
    $this->travelTo('2026-07-27 22:00:00'); // 01:00 in Riyadh on the 28th

    $student = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'status' => 'active',
    ]);

    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(Students::class)
        ->set('selectedIds', [(string) $student->id])
        ->set('bulkStatus', 'suspended')
        ->set('bulkStatusDate', now('Asia/Riyadh')->format('Y-m-d'))
        ->call('applyBulkStatus')
        ->assertHasNoErrors();

    expect($student->refresh()->status)->toBe('suspended');
});

it('allows supervisor to bulk reset access tokens', function () {
    $student1 = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'access_token' => 'old-token-1',
    ]);
    $student2 = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'access_token' => 'old-token-2',
    ]);

    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(Students::class)
        ->set('selectedIds', [(string) $student1->id, (string) $student2->id])
        ->call('applyBulkResetMagicLinks')
        ->assertHasNoErrors();

    expect($student1->refresh()->access_token)->not->toBe('old-token-1');
    expect($student2->refresh()->access_token)->not->toBe('old-token-2');
    expect($student1->refresh()->access_token)->not->toBeEmpty();
    expect($student2->refresh()->access_token)->not->toBeEmpty();
});

it('builds a copyable text of selected student names and magic links', function () {
    $student1 = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'name' => 'أحمد',
        'access_token' => 'token-one',
    ]);
    $student2 = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'name' => 'بدر',
        'access_token' => 'token-two',
    ]);
    $outsider = Student::factory()->create([
        'circle_id' => Circle::factory()->create(['stage_id' => Stage::factory()->create()->id])->id,
        'name' => 'خارج النطاق',
        'access_token' => 'token-outsider',
    ]);

    $this->actingAs($this->supervisor, 'supervisor');

    $component = Livewire::test(Students::class)
        ->set('selectedIds', [(string) $student1->id, (string) $student2->id, (string) $outsider->id])
        ->assertSee('نسخ الأسماء والروابط')
        ->call('buildSelectedMagicLinksText')
        ->assertHasNoErrors();

    $text = $component->effects['returns'][0];

    expect($text)
        ->toContain('أحمد')
        ->toContain(route('magic-link', ['token' => 'token-one']))
        ->toContain('بدر')
        ->toContain(route('magic-link', ['token' => 'token-two']))
        ->not->toContain('خارج النطاق')
        ->not->toContain('token-outsider');
});

it('issues a magic link token to selected students that never had one', function () {
    $student = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'name' => 'طالب بلا رابط',
        'access_token' => null,
    ]);

    $this->actingAs($this->supervisor, 'supervisor');

    $text = Livewire::test(Students::class)
        ->set('selectedIds', [(string) $student->id])
        ->call('buildSelectedMagicLinksText')
        ->effects['returns'][0];

    $token = $student->refresh()->access_token;

    expect($token)->not->toBeEmpty();
    expect($text)->toContain(route('magic-link', ['token' => $token]));
});

it('limits select all to the supervisor scope and the active filters', function () {
    $inScope = Student::factory()->create(['circle_id' => $this->circle->id]);
    $inScopeOtherCircle = Student::factory()->create(['circle_id' => $this->circle2->id]);
    Student::factory()->create([
        'circle_id' => Circle::factory()->create(['stage_id' => Stage::factory()->create()->id])->id,
    ]);

    $this->actingAs($this->supervisor, 'supervisor');

    // Without filters, select all covers the supervisor's stage only.
    Livewire::test(Students::class)
        ->set('selectAll', true)
        ->assertCount('selectedIds', 2)
        ->assertSet('selectedIds', function (array $ids) use ($inScope, $inScopeOtherCircle) {
            sort($ids);
            $expected = [(string) $inScope->id, (string) $inScopeOtherCircle->id];
            sort($expected);

            return $ids === $expected;
        });

    // With a circle filter, select all narrows to that circle.
    Livewire::test(Students::class)
        ->set('circleFilter', $this->circle->id)
        ->set('selectAll', true)
        ->assertSet('selectedIds', [(string) $inScope->id]);
});

it('adds to the selection when select all is ticked under a new filter', function () {
    $inCircle = Student::factory()->create(['circle_id' => $this->circle->id]);
    $inCircle2 = Student::factory()->create(['circle_id' => $this->circle2->id]);

    $this->actingAs($this->supervisor, 'supervisor');

    $component = Livewire::test(Students::class)
        ->set('circleFilter', $this->circle->id)
        ->set('selectAll', true)
        ->assertSet('selectedIds', [(string) $inCircle->id])
        // Switching filter and ticking again accumulates instead of replacing.
        ->set('circleFilter', $this->circle2->id)
        ->set('selectAll', true);

    $selected = $component->get('selectedIds');
    sort($selected);
    $expected = [(string) $inCircle->id, (string) $inCircle2->id];
    sort($expected);

    expect($selected)->toBe($expected);

    // Unticking only drops the students the current filter shows.
    $component->set('selectAll', false)
        ->assertSet('selectedIds', [(string) $inCircle->id]);
});

it('keeps selected students while the supervisor searches for more', function () {
    $first = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'أحمد']);
    Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'بدر']);

    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(Students::class)
        ->set('selectedIds', [(string) $first->id])
        ->set('search', 'بدر')
        ->assertSet('selectedIds', [(string) $first->id])
        ->set('statusFilter', 'approved')
        ->assertSet('selectedIds', [(string) $first->id]);
});

it('returns an empty magic links text when nothing is selected', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $text = Livewire::test(Students::class)
        ->call('buildSelectedMagicLinksText')
        ->effects['returns'][0];

    expect($text)->toBe('');
});

it('allows supervisor to bulk delete students with strict confirmation', function () {
    $student1 = Student::factory()->create([
        'circle_id' => $this->circle->id,
    ]);
    $student2 = Student::factory()->create([
        'circle_id' => $this->circle->id,
    ]);

    $this->actingAs($this->supervisor, 'supervisor');

    // Test with wrong confirmation input first
    Livewire::test(Students::class)
        ->set('selectedIds', [(string) $student1->id, (string) $student2->id])
        ->set('deleteConfirmationInput', 'wrong input')
        ->call('confirmBulkDelete')
        ->assertHasNoErrors();

    expect(Student::find($student1->id))->not->toBeNull();
    expect(Student::find($student2->id))->not->toBeNull();

    // Test with correct confirmation input
    Livewire::test(Students::class)
        ->set('selectedIds', [(string) $student1->id, (string) $student2->id])
        ->set('deleteConfirmationInput', 'تأكيد الحذف')
        ->call('confirmBulkDelete')
        ->assertHasNoErrors();

    expect(Student::find($student1->id))->toBeNull();
    expect(Student::find($student2->id))->toBeNull();
});
