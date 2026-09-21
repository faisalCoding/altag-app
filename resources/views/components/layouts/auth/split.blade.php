@props(['title' => null, 'panelVariant' => 'light'])
{{--
    One centred column, not two.

    The old layout gave half the viewport to an illustrated panel and the other
    half to a 380px form, so the eye had to choose between a drawing and the
    thing it came to do. The ornament here is the typography: the name set once,
    the form breathing, and an ayah beneath it in a quiet hand.

    $panelVariant is kept so the pages that pass it still work; it no longer
    picks a panel, only how dark the ground is.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">

<head>
    @include('partials.head')
    <title>{{ $title ?? App\Support\Branding::siteName() }}</title>
</head>

<body class="min-h-screen bg-zinc-50 antialiased dark:bg-zinc-950">
    <x-site-header />

    <main class="flex min-h-[calc(100dvh-4rem)] flex-col items-center justify-center px-5 py-10 sm:py-14">
        <div class="w-full max-w-sm">

            {{-- The logo alone: the bar above already names the academy, and
                 saying it twice on one screen was half of what made the old
                 pages feel unfinished. --}}
            <a href="{{ route('home') }}" wire:navigate class="mb-8 flex justify-center">
                <img src="{{ App\Support\Branding::logoUrl() }}"
                    alt="{{ App\Support\Branding::siteName() }}" class="h-16 object-contain" />
            </a>

            <div class="rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-sm sm:p-8 dark:border-zinc-800 dark:bg-zinc-900">
                {{ $slot }}
            </div>

            <x-auth-ayah class="mt-10" />
        </div>
    </main>

    @fluxScripts
</body>

</html>
