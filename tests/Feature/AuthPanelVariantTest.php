<?php

use App\Support\Branding;

it('gives the sign-in page one centred column rather than an illustrated half', function () {
    $content = $this->get(route('login'))->assertSuccessful()->getContent();

    // The panel took half the viewport for a drawing; what is left is the form,
    // centred, with the academy named once above it.
    expect($content)->not->toContain('decorative-hero-panel')
        ->not->toContain('lg:grid-cols-2');
    // Counted in what a reader sees, not in the markup: the name in a title
    // tag and in an alt attribute is not the name said twice on the screen.
    $body = substr($content, (int) strpos($content, '<body'));
    $visible = preg_replace('/\s+/', ' ', strip_tags($body));
    expect(substr_count($visible, Branding::siteName()))->toBe(1);
});

it('names the academy and its logo on the sign-in page', function () {
    Branding::setSiteName('مجمع مبارك القرآني');

    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSee('مجمع مبارك القرآني')
        ->assertSee(Branding::logoUrl(), false);
});

it('sets the ayah citation in the digits the rest of the app uses', function () {
    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSee('الآية ٤')
        ->assertDontSee('الآية 4');
});

it('gives the header a way to open on a phone', function () {
    // The links were hidden below md and nothing took their place, so a phone
    // had no navigation at all.
    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSee('aria-label="القائمة"', false);
});

it('renders the full page shell on the sign-in page', function () {
    $content = $this->get(route('login'))->assertSuccessful()->getContent();

    expect($content)->toContain('<!DOCTYPE html>')
        ->toContain('تسجيل الدخول');
});
