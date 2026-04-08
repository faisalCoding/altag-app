<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">

<head>
    @include('partials.head')
    <title>{{ $title ?? config('app.name') }}</title>
</head>

<body class="min-h-screen bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 antialiased">
    <flux:sidebar sticky collapsible="mobile"
        class="border-e border-zinc-100 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <flux:sidebar.header>
            <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>

        {{ $sidebar ?? '' }}

        <flux:spacer />

        <flux:sidebar.nav>
            <flux:sidebar.item icon="cog" :href="route('profile.edit')" wire:navigate>
                {{ __('إعدادات الحساب') }}
            </flux:sidebar.item>

            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:sidebar.item as="button" type="submit" icon="arrow-right-start-on-rectangle"
                    class="w-full cursor-pointer">
                    {{ __('تسجيل الخروج') }}
                </flux:sidebar.item>
            </form>
        </flux:sidebar.nav>

        <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
    </flux:sidebar>

    <!-- Mobile Menu -->
    <flux:sidebar collapsible="mobile" sticky
        class="lg:hidden border-e border-zinc-100 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <flux:sidebar.header>
            <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
            <flux:sidebar.collapse
                class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
        </flux:sidebar.header>

        {{ $sidebar ?? '' }}

        <flux:spacer />

        <flux:sidebar.nav>
            <flux:sidebar.item icon="cog" :href="route('profile.edit')" wire:navigate>
                {{ __('الإعدادات') }}
            </flux:sidebar.item>
            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:sidebar.item as="button" type="submit" icon="arrow-right-start-on-rectangle"
                    class="w-full cursor-pointer">
                    {{ __('تسجيل الخروج') }}
                </flux:sidebar.item>
            </form>
        </flux:sidebar.nav>
    </flux:sidebar>

    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
        <flux:spacer />
        <x-layouts.app.header-user-menu />
    </flux:header>

    <flux:main class="!p-1 md:p-8">
        {{ $slot }}
    </flux:main>

    @fluxScripts
</body>

</html>