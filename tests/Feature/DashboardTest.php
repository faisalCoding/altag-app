<?php

use App\Models\Manager;

test('guests are redirected to the home page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('home'));
});

test('authenticated users are redirected to their specific dashboard', function () {
    $user = Manager::factory()->create();
    $this->actingAs($user, 'manager');

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('manager.dashboard'));
});