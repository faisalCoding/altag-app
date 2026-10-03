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

it('saves the WhatsApp group a stage opens after the absence message is copied', function () {
    Livewire::test(Settings::class)
        ->set("whatsappGroupUrls.{$this->stage->id}", 'انضم إلى مجموعتي: chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv')
        ->call('saveWhatsappGroupUrl', $this->stage->id)
        ->assertHasNoErrors()
        ->assertSet("whatsappGroupUrls.{$this->stage->id}", 'https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv');

    expect($this->stage->fresh()->whatsapp_group_url)->toBe('https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv');
});

it('refuses a group link that is not a WhatsApp invitation', function () {
    Livewire::test(Settings::class)
        ->set("whatsappGroupUrls.{$this->stage->id}", 'https://example.com/group')
        ->call('saveWhatsappGroupUrl', $this->stage->id)
        ->assertHasErrors("whatsappGroupUrls.{$this->stage->id}");

    expect($this->stage->fresh()->whatsapp_group_url)->toBeNull();
});

it('clears the stage group when the field is emptied', function () {
    $this->stage->update(['whatsapp_group_url' => 'https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv']);

    Livewire::test(Settings::class)
        ->assertSet("whatsappGroupUrls.{$this->stage->id}", 'https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv')
        ->set("whatsappGroupUrls.{$this->stage->id}", '')
        ->call('saveWhatsappGroupUrl', $this->stage->id)
        ->assertHasNoErrors();

    expect($this->stage->fresh()->whatsapp_group_url)->toBeNull();
});

it('refuses to set the group of a stage the supervisor does not hold', function () {
    $other = Stage::factory()->create();

    Livewire::test(Settings::class)
        ->set("whatsappGroupUrls.{$other->id}", 'https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv')
        ->call('saveWhatsappGroupUrl', $other->id);

    expect($other->fresh()->whatsapp_group_url)->toBeNull();
});
