<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl" class="light">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="color-scheme" content="light">

    @stack('meta')

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <link rel="preconnect" href="https://fonts.bunny.net">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance

    <x-branding-styles />

    @stack('styles')

    <script>
        document.documentElement.classList.remove('dark');
        // Keep public pages light-only even if the appearance script re-applies
        // a stored dark preference at runtime.
        const lightOnlyObserver = new MutationObserver(() => {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
            }
        });
        lightOnlyObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    </script>
    <style>
        :root, html, body {
            color-scheme: only light !important;
        }
        @media (prefers-color-scheme: dark) {
            html, body {
                background-color: #fafafa !important;
                color: #171717 !important;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased py-8 px-4 flex flex-col items-center">
    
    {{ $slot }}

    @fluxScripts
</body>
</html>
