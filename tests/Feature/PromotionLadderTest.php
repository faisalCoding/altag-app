<?php

use App\Livewire\Manager\PromotionLadder as LadderScreen;
use App\Models\Circle;
use App\Models\Manager;
use App\Models\Stage;
use App\Models\Student;
use App\Services\PromotionLadder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A small academy shaped like the real one: two stages, a pair of sections in
 * the middle, and a circle deliberately left off the ladder.
 */
function ladderFixture(): array
{
    $primary = Stage::factory()->create(['name' => 'ابتدائية', 'level' => 1]);
    $middle = Stage::factory()->create(['name' => 'متوسطة', 'level' => 2]);

    return [
        'first' => Circle::factory()->create(['name' => 'أول ابتدائي', 'stage_id' => $primary->id, 'level' => 1]),
        'sixth' => Circle::factory()->create(['name' => 'سادس ابتدائي', 'stage_id' => $primary->id, 'level' => 2]),
        'seventhA' => Circle::factory()->create(['name' => 'أول متوسط 1', 'stage_id' => $middle->id, 'level' => 1]),
        'seventhB' => Circle::factory()->create(['name' => 'أول متوسط 2', 'stage_id' => $middle->id, 'level' => 1]),
        'eighth' => Circle::factory()->create(['name' => 'ثاني متوسط', 'stage_id' => $middle->id, 'level' => 2]),
        'offLadder' => Circle::factory()->create(['name' => 'النورانية', 'stage_id' => $primary->id, 'level' => null]),
    ];
}

it('orders the rungs across stage boundaries', function () {
    ladderFixture();

    $rungs = (new PromotionLadder)->rungs();

    expect($rungs->map(fn ($r) => $r['circles']->pluck('name')->implode('+'))->all())->toBe([
        'أول ابتدائي',
        'سادس ابتدائي',
        'أول متوسط 1+أول متوسط 2',
        'ثاني متوسط',
    ]);
});

it('treats two circles of one rank as sections, not successive grades', function () {
    $c = ladderFixture();

    // The pair shares a rung, so neither promotes into the other.
    expect((new PromotionLadder)->nextCircleFor($c['seventhA'])->name)->toBe('ثاني متوسط');
    expect((new PromotionLadder)->nextCircleFor($c['seventhB'])->name)->toBe('ثاني متوسط');
});

it('crosses into the next stage when a stage runs out of rungs', function () {
    $c = ladderFixture();

    expect((new PromotionLadder)->nextCircleFor($c['sixth'])->stage->name)->toBe('متوسطة');
});

it('keeps a student in the same section number where one exists', function () {
    $c = ladderFixture();

    $ninthA = Circle::factory()->create(['name' => 'ثالث متوسط 1', 'stage_id' => $c['eighth']->stage_id, 'level' => 3]);
    Circle::factory()->create(['name' => 'ثالث متوسط 2', 'stage_id' => $c['eighth']->stage_id, 'level' => 3]);

    // Section 2 is emptier, but section 1 wins because the source is section 1.
    Student::factory()->create(['circle_id' => $ninthA->id]);

    $eighthA = Circle::factory()->create(['name' => 'ثاني متوسط 1', 'stage_id' => $c['eighth']->stage_id, 'level' => 2]);

    expect((new PromotionLadder)->nextCircleFor($eighthA)->name)->toBe('ثالث متوسط 1');
});

it('sends an unnumbered circle to the smallest section', function () {
    $c = ladderFixture();

    Student::factory()->count(3)->create(['circle_id' => $c['seventhA']->id]);
    Student::factory()->create(['circle_id' => $c['seventhB']->id]);

    expect((new PromotionLadder)->nextCircleFor($c['sixth'])->name)->toBe('أول متوسط 2');
});

it('never moves a circle that has no rank', function () {
    $c = ladderFixture();

    expect((new PromotionLadder)->nextCircleFor($c['offLadder']))->toBeNull();
    expect((new PromotionLadder)->rungs()->flatMap(fn ($r) => $r['circles']->pluck('name')))
        ->not->toContain('النورانية');
});

it('reports the top of the ladder as a graduation, not a promotion', function () {
    $c = ladderFixture();

    expect((new PromotionLadder)->nextCircleFor($c['eighth']))->toBeNull();
});

it('warns about a populated circle left off the ladder', function () {
    $c = ladderFixture();
    Student::factory()->count(4)->create(['circle_id' => $c['offLadder']->id]);

    expect((new PromotionLadder)->warnings()->pluck('text')->implode(' '))
        ->toContain('النورانية')
        ->toContain('4');
});

it('warns when two stages claim the same rank', function () {
    ladderFixture();
    Stage::factory()->create(['name' => 'مكرّرة', 'level' => 1]);

    expect((new PromotionLadder)->warnings()->pluck('text')->implode(' '))->toContain('فريدة');
});

it('warns about a stage that holds circles but has no rank', function () {
    ladderFixture();
    $orphan = Stage::factory()->create(['name' => 'بلا رتبة', 'level' => null]);
    Circle::factory()->create(['stage_id' => $orphan->id, 'level' => 1]);

    expect((new PromotionLadder)->warnings()->pluck('text')->implode(' '))->toContain('بلا رتبة');
});

it('lets the manager save the ranks and clear one back off the ladder', function () {
    $c = ladderFixture();

    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(LadderScreen::class)
        ->set("circleLevels.{$c['first']->id}", 5)
        ->set("circleLevels.{$c['sixth']->id}", '')
        ->call('save')
        ->assertHasNoErrors();

    expect($c['first']->fresh()->level)->toBe(5);
    expect($c['sixth']->fresh()->level)->toBeNull();
});

it('refuses a rank that is not a positive number', function () {
    $c = ladderFixture();

    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(LadderScreen::class)
        ->set("circleLevels.{$c['first']->id}", 0)
        ->call('save')
        ->assertHasErrors("circleLevels.{$c['first']->id}");

    expect($c['first']->fresh()->level)->toBe(1);
});

it('shows the new order immediately after the ranks are saved', function () {
    $c = ladderFixture();

    $this->actingAs(Manager::factory()->create(), 'manager');

    // Before: the top of the primary stage feeds the middle stage.
    expect((new PromotionLadder)->nextCircleFor($c['sixth'])->stage->name)->toBe('متوسطة');

    // Slot a new grade in between and the destination must change in the same
    // response, not on the next page load.
    $seventhZero = Circle::factory()->create([
        'name' => 'صف مستحدث',
        'stage_id' => $c['sixth']->stage_id,
        'level' => null,
    ]);

    Livewire::test(LadderScreen::class)
        ->set("circleLevels.{$seventhZero->id}", 3)
        ->call('save')
        ->assertSee('صف مستحدث');

    expect((new PromotionLadder)->nextCircleFor($c['sixth'])->name)->toBe('صف مستحدث');
});

it('gives every row in the ladder a stable key', function () {
    $c = ladderFixture();
    Student::factory()->create(['circle_id' => $c['offLadder']->id]);

    $this->actingAs(Manager::factory()->create(), 'manager');

    // Without a key per row Livewire may carry a rank into a neighbouring row
    // when it patches the DOM, since every row is the same shape.
    Livewire::test(LadderScreen::class)
        ->assertSeeHtml('wire:key="ladder-circle-'.$c['first']->id.'"')
        ->assertSeeHtml('wire:key="ladder-stage-'.$c['first']->stage_id.'"')
        ->assertSeeHtml('wire:key="ladder-warning-circle-unranked-'.$c['offLadder']->id.'"');
});

it('keys a warning by what it is about, not by its position in the list', function () {
    $c = ladderFixture();
    Student::factory()->create(['circle_id' => $c['offLadder']->id]);

    $second = Circle::factory()->create(['name' => 'حلقة أخرى', 'stage_id' => $c['first']->stage_id, 'level' => null]);
    Student::factory()->create(['circle_id' => $second->id]);

    $keys = (new PromotionLadder)->warnings()->pluck('key');

    expect($keys->unique())->toHaveCount($keys->count());
    expect($keys)->toContain("circle-unranked-{$c['offLadder']->id}", "circle-unranked-{$second->id}");

    // Ranking the first one must not renumber the other's key.
    $c['offLadder']->update(['level' => 3]);

    expect((new PromotionLadder)->warnings()->pluck('key'))->toContain("circle-unranked-{$second->id}");
});
