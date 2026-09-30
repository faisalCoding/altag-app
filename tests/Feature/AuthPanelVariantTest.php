<?php

use App\Support\Branding;
use Illuminate\Support\Facades\Blade;

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

it('holds the public pages in the light theme whatever the device prefers', function () {
    // The academy's face should not invert itself because a parent opened the
    // site at night. Three doors had to be shut: the class the appearance
    // script writes, the browser's own dark form controls, and the background
    // a dark OS paints before the stylesheet lands.
    foreach ([route('home'), route('login'), route('register')] as $url) {
        $content = $this->get($url)->assertSuccessful()->getContent();

        expect($content)->toContain('dir="rtl" class="light"')
            ->toContain('color-scheme: only light')
            ->toContain("classList.remove('dark')")
            ->toContain('prefers-color-scheme: dark');
    }
});

it('reads before it writes when stripping the dark class', function () {
    // classList.remove() writes the class attribute even when the token was
    // already absent, and that write is itself an attribute mutation. An
    // observer that removes unconditionally feeds itself and the tab spins
    // forever — the page never finishes loading. This shipped once.
    $script = Blade::render('<x-light-only />');

    expect($script)->toContain("classList.contains('dark')");

    // Every removal inside the observer must sit behind that check.
    $observer = substr($script, (int) strpos($script, 'MutationObserver'));
    expect(substr_count($observer, "classList.remove('dark')"))
        ->toBe(substr_count($observer, "classList.contains('dark')"));
});

it('keeps the bus links light too, since they share the component', function () {
    // blank.blade.php carried its own guarded copy until it was folded into the
    // component; the pages a supervisor books from went with it.
    expect(file_get_contents(base_path('resources/views/layouts/blank.blade.php')))
        ->toContain('<x-light-only />');
});
