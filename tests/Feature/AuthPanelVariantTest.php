<?php

it('gives the register page a dark decorative panel in the academy colour', function () {
    // Named shades rather than written-out hexes: the two ends used to be fixed
    // maroons around a middle that followed the setting, so an academy in blue
    // got blue between two maroons.
    $this->get(route('register'))
        ->assertSuccessful()
        ->assertSee('bg-gradient-to-b from-accent-dark via-maroon to-burgundy', false);
});

it('gives the login page a light cream decorative panel', function () {
    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSee('from-[#f7efe0] via-[#faf5ea] to-[#fdfaf3]', false);
});

it('renders the full page shell (doctype, site header) on the unified login page', function () {
    $response = $this->get(route('login'));

    $response->assertSuccessful();
    $content = $response->getContent();

    expect($content)->toContain('<!DOCTYPE html>');
    expect($content)->toContain('مساعدة');
});
