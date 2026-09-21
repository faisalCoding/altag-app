<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl" class="light">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    @stack('meta')

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <link rel="preconnect" href="https://fonts.bunny.net">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance

    <x-branding-styles />

    @stack('styles')

    <x-light-only />
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased py-8 px-4 flex flex-col items-center">
    
    {{ $slot }}

    @fluxScripts
</body>
</html>
