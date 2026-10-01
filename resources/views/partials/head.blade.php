<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.App\Support\Branding::siteName() : App\Support\Branding::siteName() }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
{{-- Added to a phone's home screen, the app opens as its own, named and coloured as the academy. --}}
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="{{ App\Support\Branding::color() }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ App\Support\Branding::siteName() }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">


@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

<x-branding-styles />
