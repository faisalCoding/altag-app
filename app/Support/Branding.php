<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The academy's own face: its logo and its colour.
 *
 * The application is deployed once per academy, each with its own database, so
 * these belong in settings rather than in the build — a second academy should
 * not need a developer and a compile to stop wearing the first one's colours.
 *
 * The colour works at all because Tailwind v4 compiles `bg-maroon` down to
 * `background-color: var(--color-maroon)`. Redefining that variable in a style
 * tag after the stylesheet recolours all 183 uses of it across the views, with
 * no rebuild and no second copy of the palette to keep in step.
 */
class Branding
{
    public const COLOR = 'branding.primary_color';

    public const LOGO = 'branding.logo_path';

    /** The colour the application ships in, and what "reset" returns to. */
    public const DEFAULT_COLOR = '#7a2727';

    /** The logo the application ships with. */
    public const DEFAULT_LOGO = 'images/altag_logo.png';

    private const CACHE_KEY = 'branding.resolved';

    /**
     * The chosen colour, or the shipped one. Never anything else: the value is
     * read back out of a database that a form wrote to, and it is about to be
     * interpolated into a style tag.
     */
    public static function color(): string
    {
        return self::resolved()['color'];
    }

    /**
     * A url for the logo — the uploaded one if there is one, else the shipped one.
     */
    public static function logoUrl(): string
    {
        $path = self::resolved()['logo'];

        return $path !== null ? Storage::disk('public')->url($path) : asset(self::DEFAULT_LOGO);
    }

    /** The stored path of an uploaded logo, or null while the shipped one is in use. */
    public static function logoPath(): ?string
    {
        return self::resolved()['logo'];
    }

    public static function hasCustomLogo(): bool
    {
        return self::logoPath() !== null;
    }

    /**
     * The `:root` overrides, ready to drop into a style tag.
     *
     * The three companion shades are derived rather than asked for: a manager
     * choosing four related colours by hand is four chances to choose four that
     * do not belong together. The multipliers below reproduce the shipped
     * palette almost exactly when the shipped colour is the one chosen.
     */
    public static function cssVariables(): string
    {
        $primary = self::color();

        $declarations = [
            '--color-maroon' => $primary,
            '--color-burgundy' => self::shade($primary, 0.70),
            '--color-accent-dark' => self::shade($primary, 0.55),
            // Flux's accent in dark mode reads this one, so it lifts rather than sinks.
            '--color-red-secondary' => self::shade($primary, 1.26),
        ];

        $body = collect($declarations)->map(fn ($value, $name) => "{$name}:{$value}")->implode(';');

        return ":root{{$body}}";
    }

    /**
     * Store a new colour. Anything that is not a six-digit hex is refused here
     * as well as in the form, because this is the last gate before a style tag.
     */
    public static function setColor(string $hex): void
    {
        $hex = self::normalize($hex);

        if ($hex === null) {
            throw new \InvalidArgumentException('اللون يكون بصيغة #RRGGBB.');
        }

        Setting::setVal(self::COLOR, $hex);
        self::forget();
    }

    public static function setLogoPath(?string $path): void
    {
        $previous = self::logoPath();

        Setting::setVal(self::LOGO, $path);
        self::forget();

        // The replaced file is of no use to anyone and would otherwise pile up
        // one upload at a time in a directory nobody ever looks at.
        if ($previous !== null && $previous !== $path && Storage::disk('public')->exists($previous)) {
            Storage::disk('public')->delete($previous);
        }
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{color: string, logo: string|null}
     */
    private static function resolved(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $color = self::normalize((string) Setting::getVal(self::COLOR, ''));
            $logo = (string) Setting::getVal(self::LOGO, '');

            return [
                'color' => $color ?? self::DEFAULT_COLOR,
                'logo' => $logo !== '' && Storage::disk('public')->exists($logo) ? $logo : null,
            ];
        });
    }

    /**
     * A six-digit hex colour, or null for anything that is not one.
     */
    public static function normalize(?string $hex): ?string
    {
        $hex = strtolower(trim((string) $hex));

        return preg_match('/^#[0-9a-f]{6}$/', $hex) ? $hex : null;
    }

    /**
     * The same colour at a different lightness — below 1 darker, above 1 lighter.
     *
     * Safe on any string, because the preview in the settings form calls it on
     * whatever the manager has typed so far, which is half a colour most of the
     * time and «أزرق» some of it.
     */
    public static function shade(?string $hex, float $factor): string
    {
        $hex = self::normalize($hex) ?? self::DEFAULT_COLOR;

        [$h, $s, $l] = self::toHsl($hex);

        return self::toHex($h, $s, max(0.0, min(1.0, $l * $factor)));
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private static function toHsl(string $hex): array
    {
        [$r, $g, $b] = array_map(fn ($c) => hexdec($c) / 255, str_split(substr($hex, 1), 2));

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d === 0.0) {
            return [0.0, 0.0, $l];
        }

        $denominator = $l > 0.5 ? (2 - $max - $min) : ($max + $min);
        $s = $denominator > 0 ? $d / $denominator : 0.0;

        $h = match (true) {
            $max === $r => fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6),
            $max === $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        } / 6;

        return [$h, $s, $l];
    }

    private static function toHex(float $h, float $s, float $l): string
    {
        if ($s === 0.0) {
            $r = $g = $b = $l;
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;

            $r = self::channel($p, $q, $h + 1 / 3);
            $g = self::channel($p, $q, $h);
            $b = self::channel($p, $q, $h - 1 / 3);
        }

        return '#'.collect([$r, $g, $b])
            ->map(fn ($c) => str_pad(dechex((int) round($c * 255)), 2, '0', STR_PAD_LEFT))
            ->implode('');
    }

    private static function channel(float $p, float $q, float $t): float
    {
        $t = fmod($t + 1, 1);

        return match (true) {
            $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
            $t < 1 / 2 => $q,
            $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
            default => $p,
        };
    }
}
