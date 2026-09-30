<?php

use App\Livewire\Supervisor\Settings;
use App\Models\Stage;
use App\Models\Supervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stage = Stage::factory()->create(['name' => 'مرحلتي']);
    $this->supervisor = Supervisor::factory()->create();
    $this->supervisor->stages()->attach($this->stage->id);

    $this->actingAs($this->supervisor, 'supervisor');
});

it('shows only the stages this supervisor holds', function () {
    Stage::factory()->create(['name' => 'مرحلة غيري']);

    Livewire::test(Settings::class)
        ->assertSee('مرحلتي')
        ->assertDontSee('مرحلة غيري');
});

it('turns the reason requirement off and on again', function () {
    Livewire::test(Settings::class)
        ->call('toggleReason', $this->stage->id);

    expect($this->stage->fresh()->require_edit_reason)->toBeFalse();

    Livewire::test(Settings::class)
        ->call('toggleReason', $this->stage->id);

    expect($this->stage->fresh()->require_edit_reason)->toBeTrue();
});

it('refuses to change a stage the supervisor does not hold', function () {
    $other = Stage::factory()->create();

    Livewire::test(Settings::class)
        ->call('toggleReason', $other->id);

    expect($other->fresh()->require_edit_reason)->toBeTrue();
});

it('leaves other stages alone when one is changed', function () {
    $second = Stage::factory()->create();
    $this->supervisor->stages()->attach($second->id);

    Livewire::test(Settings::class)
        ->call('toggleReason', $this->stage->id);

    expect($this->stage->fresh()->require_edit_reason)->toBeFalse();
    expect($second->fresh()->require_edit_reason)->toBeTrue();
});
