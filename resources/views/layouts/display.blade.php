<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl" class="dark">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="color-scheme" content="dark light">

    <title>{{ $title ?? 'شاشة العرض' }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">

    <link rel="preconnect" href="https://fonts.bunny.net">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <x-branding-styles />

    {{-- No @fluxAppearance here on purpose: it re-applies the app/system
         appearance and would fight the page's own theme toggle. This page is
         dark by default and the toggle persists the operator's choice. --}}
    <script>
        if (localStorage.getItem('resultsDisplayTheme') === 'light') {
            document.documentElement.classList.remove('dark');
        }
    </script>
</head>
<body class="min-h-screen bg-zinc-100 text-zinc-900 dark:bg-zinc-950 dark:text-white antialiased overflow-hidden">
    {{ $slot }}

    @fluxScripts
</body>
</html>
