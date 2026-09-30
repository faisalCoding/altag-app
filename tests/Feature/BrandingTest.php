<?php

use App\Livewire\Manager\Settings;
use App\Models\Manager;
use App\Models\Setting;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Storage::fake('public');
    $this->actingAs(Manager::factory()->create(), 'manager');
});

it('wears the shipped colour and logo until told otherwise', function () {
    expect(Branding::color())->toBe(Branding::DEFAULT_COLOR);
    expect(Branding::hasCustomLogo())->toBeFalse();
    expect(Branding::logoUrl())->toContain('altag_logo.png');
});

it('recolours every maroon in the interface from one setting', function () {
    Branding::setColor('#1b5e20');

    $css = Branding::cssVariables();

    // The utilities compile to var(--color-maroon), so this one line is what
    // repaints the buttons, the headings and the Flux accent.
    expect($css)->toContain('--color-maroon:#1b5e20');
    expect($css)->toStartWith(':root{')->toEndWith('}');
});

it('derives the companion shades rather than asking for four colours', function () {
    Branding::setColor('#1b5e20');

    $css = Branding::cssVariables();

    expect($css)->toContain('--color-burgundy:')
        ->toContain('--color-accent-dark:')
        ->toContain('--color-red-secondary:');

    // Darker below the primary, lighter above it.
    expect(Branding::shade('#1b5e20', 0.70))->not->toBe('#1b5e20');
    expect(luminance(Branding::shade('#1b5e20', 0.70)))->toBeLessThan(luminance('#1b5e20'));
    expect(luminance(Branding::shade('#1b5e20', 1.26)))->toBeGreaterThan(luminance('#1b5e20'));
});

it('lands within a hair of the shipped palette when the shipped colour is kept', function () {
    // The multipliers were chosen against the palette the app ships with, so a
    // manager who changes nothing sees exactly what they saw before.
    expect(distance(Branding::shade(Branding::DEFAULT_COLOR, 0.70), '#521f1e'))->toBeLessThan(12);
    expect(distance(Branding::shade(Branding::DEFAULT_COLOR, 1.26), '#9d2e33'))->toBeLessThan(12);
    expect(distance(Branding::shade(Branding::DEFAULT_COLOR, 0.55), '#3f1a19'))->toBeLessThan(12);
});

it('refuses anything that is not a colour', function () {
    foreach (['red', '#12345', 'rgb(1,2,3)', '#7a2727; } body{display:none', ''] as $bad) {
        expect(fn () => Branding::setColor($bad))->toThrow(InvalidArgumentException::class);
    }

    expect(Branding::color())->toBe(Branding::DEFAULT_COLOR);
});

it('saves a colour the manager picked', function () {
    Livewire::test(Settings::class)
        ->assertSet('primaryColor', Branding::DEFAULT_COLOR)
        ->set('primaryColor', '#0F4C81')
        ->call('saveColor')
        ->assertHasNoErrors();

    expect(Branding::color())->toBe('#0f4c81');
});

it('will not save a colour the field mangled', function () {
    Livewire::test(Settings::class)
        ->set('primaryColor', 'أزرق')
        ->call('saveColor')
        ->assertHasErrors('primaryColor');

    expect(Branding::color())->toBe(Branding::DEFAULT_COLOR);
});

it('takes a logo and serves it in place of the shipped one', function () {
    Livewire::test(Settings::class)
        ->set('uploadedLogo', UploadedFile::fake()->image('shiny.png'))
        ->call('saveLogo')
        ->assertHasNoErrors();

    expect(Branding::hasCustomLogo())->toBeTrue();
    Storage::disk('public')->assertExists(Branding::logoPath());
    expect(Branding::logoUrl())->not->toContain('altag_logo.png');
});

it('does not leave the replaced logo behind', function () {
    Livewire::test(Settings::class)
        ->set('uploadedLogo', UploadedFile::fake()->image('first.png'))
        ->call('saveLogo');

    $first = Branding::logoPath();

    Livewire::test(Settings::class)
        ->set('uploadedLogo', UploadedFile::fake()->image('second.png'))
        ->call('saveLogo');

    expect(Branding::logoPath())->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
});

it('refuses a logo that is not an image', function () {
    Livewire::test(Settings::class)
        ->set('uploadedLogo', UploadedFile::fake()->create('payload.php', 10))
        ->call('saveLogo')
        ->assertHasErrors('uploadedLogo');

    expect(Branding::hasCustomLogo())->toBeFalse();
});

it('goes back to the shipped face on demand', function () {
    Branding::setColor('#1b5e20');
    Livewire::test(Settings::class)
        ->set('uploadedLogo', UploadedFile::fake()->image('shiny.png'))
        ->call('saveLogo');

    $uploaded = Branding::logoPath();

    Livewire::test(Settings::class)->call('resetBranding');

    expect(Branding::color())->toBe(Branding::DEFAULT_COLOR);
    expect(Branding::hasCustomLogo())->toBeFalse();
    Storage::disk('public')->assertMissing($uploaded);
});

it('falls back to the shipped logo when the file has gone missing', function () {
    Livewire::test(Settings::class)
        ->set('uploadedLogo', UploadedFile::fake()->image('shiny.png'))
        ->call('saveLogo');

    Storage::disk('public')->delete(Branding::logoPath());
    Branding::forget();

    // A record pointing at nothing must not leave every page with a broken image.
    expect(Branding::hasCustomLogo())->toBeFalse();
    expect(Branding::logoUrl())->toContain('altag_logo.png');
});

/** Rough perceived lightness, enough to say "darker" and "lighter". */
function luminance(string $hex): float
{
    [$r, $g, $b] = array_map('hexdec', str_split(substr($hex, 1), 2));

    return 0.299 * $r + 0.587 * $g + 0.114 * $b;
}

/** Straight-line distance between two colours in RGB. */
function distance(string $a, string $b): float
{
    $x = array_map('hexdec', str_split(substr($a, 1), 2));
    $y = array_map('hexdec', str_split(substr($b, 1), 2));

    return sqrt(($x[0] - $y[0]) ** 2 + ($x[1] - $y[1]) ** 2 + ($x[2] - $y[2]) ** 2);
}

it('will not let a bad row in the settings table escape the style tag', function () {
    // Written straight to the table, past setColor's guard — a restored backup
    // or a hand-edited row. The value is interpolated unescaped into <style>,
    // so the reader has to refuse it too.
    Setting::setVal(Branding::COLOR, '#7a2727}body{display:none}');
    Branding::forget();

    expect(Branding::color())->toBe(Branding::DEFAULT_COLOR);
    expect(Branding::cssVariables())->not->toContain('display:none');
});

it('puts the colour into every page, signed in or not', function () {
    Branding::setColor('#1b5e20');

    // The shared head, and the two layouts that carry a head of their own.
    foreach ([
        'resources/views/partials/head.blade.php',
        'resources/views/layouts/blank.blade.php',
        'resources/views/layouts/display.blade.php',
    ] as $layout) {
        expect(file_get_contents(base_path($layout)))->toContain('<x-branding-styles />');
    }

    expect(Blade::render('<x-branding-styles />'))->toContain('--color-maroon:#1b5e20');
});

it('wears the shipped name until told otherwise', function () {
    expect(Branding::siteName())->toBe(Branding::DEFAULT_NAME);
});

it('saves a name the manager chose', function () {
    Livewire::test(Settings::class)
        ->assertSet('siteName', Branding::DEFAULT_NAME)
        ->set('siteName', '  مجمع مبارك القرآني  ')
        ->call('saveName')
        ->assertHasNoErrors();

    expect(Branding::siteName())->toBe('مجمع مبارك القرآني');
});

it('will not take an empty name', function () {
    Livewire::test(Settings::class)
        ->set('siteName', '   ')
        ->call('saveName')
        ->assertHasErrors('siteName');

    expect(Branding::siteName())->toBe(Branding::DEFAULT_NAME);
    expect(fn () => Branding::setSiteName(' '))->toThrow(InvalidArgumentException::class);
});

it('puts the chosen name everywhere the old one was written by hand', function () {
    Branding::setSiteName('مجمع مبارك القرآني');

    // Not a search for the string: the point is that no view still carries the
    // first academy's name in its source.
    $carriers = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($f) => str_contains($f->getContents(), 'مجمع التاج القرآني'))
        ->map(fn ($f) => $f->getRelativePathname());

    expect($carriers)->toBeEmpty();

    expect(Blade::render('<x-app-logo />'))->toContain('مجمع مبارك القرآني');
});

it('serves the uploaded logo to the pages that were bypassing the setting', function () {
    Livewire::test(Settings::class)
        ->set('uploadedLogo', UploadedFile::fake()->image('shiny.png'))
        ->call('saveLogo');

    // Eight views reached for images/altag_logo.png directly and kept showing
    // the shipped logo however the setting was changed.
    $carriers = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($f) => str_contains($f->getContents(), 'altag_logo.png'))
        ->map(fn ($f) => $f->getRelativePathname());

    expect($carriers)->toBeEmpty();
    expect(Blade::render('<x-app-logo />'))->not->toContain('altag_logo.png');
});

it('hands the pdf renderer a file rather than a url', function () {
    // dompdf has no browser to fetch with, so a url would render as nothing.
    expect(Branding::logoFilePath())->toEndWith('.png')->toStartWith('/');
    expect(file_exists(Branding::logoFilePath()))->toBeTrue();

    Livewire::test(Settings::class)
        ->set('uploadedLogo', UploadedFile::fake()->image('shiny.png'))
        ->call('saveLogo');

    expect(Branding::logoFilePath())->toContain('branding/');
});

it('takes the name back to the shipped one on reset', function () {
    Branding::setSiteName('مجمع مبارك القرآني');

    Livewire::test(Settings::class)->call('resetBranding');

    expect(Branding::siteName())->toBe(Branding::DEFAULT_NAME);
});

it('carries the colour into every page that loads the stylesheet', function () {
    // Enumerated rather than listed: a view that pulls in app.css draws its own
    // head, and a new one that forgets the style tag keeps the old colour
    // silently — which is exactly how the front page was missed.
    $missing = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($f) => str_contains($f->getContents(), '@vite'))
        ->reject(fn ($f) => str_contains($f->getContents(), '<x-branding-styles />'))
        ->map(fn ($f) => $f->getRelativePathname())
        ->values();

    expect($missing)->toBeEmpty("these load app.css without the colour: {$missing->implode(', ')}");
});

it('paints the front page in the chosen colour', function () {
    Branding::setColor('#1b5e20');
    Branding::setSiteName('مجمع مبارك القرآني');

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('--color-maroon:#1b5e20')
        ->toContain('مجمع مبارك القرآني')
        ->not->toContain('مجمع التاج القرآني');
});

it('shows the uploaded logo on the front page too', function () {
    Livewire::test(Settings::class)
        ->set('uploadedLogo', UploadedFile::fake()->image('shiny.png'))
        ->call('saveLogo');

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain(Branding::logoUrl())->not->toContain('altag_logo.png');
});

it('leaves no brand colour written by hand in a gradient', function () {
    // The hero panel ran from-[#3f1a19] via-maroon to-[#5c231f]: the middle
    // stop followed the setting and the two ends did not, so a blue academy
    // got blue between two maroons.
    $shipped = ['#7a2727', '#521f1e', '#9d2e33', '#3f1a19', '#5c231f'];

    // Only arbitrary values are hunted — «-[#7a2727]» is paint Tailwind cannot
    // follow, while the same hex as a placeholder is just an example of one.
    $carriers = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($f) => collect($shipped)->contains(
            fn ($hex) => str_contains(strtolower($f->getContents()), '-['.$hex.']')
        ))
        ->map(fn ($f) => $f->getRelativePathname())
        ->values();

    expect($carriers)->toBeEmpty("these paint with a brand colour Tailwind cannot follow: {$carriers->implode(', ')}");
});
