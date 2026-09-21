@props(['title' => null, 'panelVariant' => 'light'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-950">
        <x-site-header />

        <div class="relative grid min-h-[calc(100dvh-4rem)] lg:grid-cols-2">
            <x-decorative-hero-panel :variant="$panelVariant" />

            <div class="w-full flex items-center justify-center p-6 md:p-10">
                <div class="mx-auto flex w-full flex-col justify-center gap-6 sm:w-[380px]">
                    <a href="{{ route('home') }}" class="z-20 flex flex-col items-center gap-2 font-medium lg:hidden" wire:navigate>
                        <img src="{{ App\Support\Branding::logoUrl() }}" alt="{{ App\Support\Branding::siteName() }}" class="h-12 object-contain" />
                        <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                    </a>
                    {{ $slot }}
                </div>
            </div>
        </div>
        @fluxScripts
    </body>
</html>
