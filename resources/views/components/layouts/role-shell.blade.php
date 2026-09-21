<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">

<head>
    @include('partials.head')
    <title>{{ $title ?? App\Support\Branding::siteName() }}</title>
    @if(auth('student')->check())
        <script>
            document.documentElement.classList.remove('dark');
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    if (mutation.attributeName === 'class' && document.documentElement.classList.contains('dark')) {
                        document.documentElement.classList.remove('dark');
                    }
                });
            });
            observer.observe(document.documentElement, { attributes: true });
        </script>
    @endif
</head>

<body class="min-h-screen bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 antialiased">
    <!-- Offline Indicator -->
    <div x-data="{ online: navigator.onLine }" 
         @online.window="online = true" 
         @offline.window="online = false"
         x-show="!online" 
         x-transition.opacity.duration.500ms
         style="display: none;"
         class="fixed top-0 left-0 right-0 z-[100] bg-red-500 text-white text-center py-1.5 px-4 text-sm font-bold shadow-md flex items-center justify-center gap-2">
         <flux:icon icon="exclamation-triangle" class="size-4" />
         <span>أنت غير متصل بشبكة الإنترنت حالياً. يرجى التحقق من اتصالك.</span>
    </div>
    @php
        /**
         * Every role shares this shell, so the sidebar looks the same for all of
         * them: black at the foot, lifting to a dark grey at the head.
         */
        $usesBrandSidebar = auth('manager')->check() || auth('student')->check()
            || auth('teacher')->check() || auth('supervisor')->check() || auth('guardian')->check()
            || auth('staff')->check();
    @endphp
    <flux:sidebar sticky collapsible="mobile"
        @class([
            'self-start border-e relative overflow-hidden',
            'dark bg-gradient-to-t from-black via-zinc-900 to-zinc-800 border-transparent' => $usesBrandSidebar,
            'bg-white border-zinc-100 dark:border-zinc-800 dark:bg-zinc-900' => ! $usesBrandSidebar,
        ])>
        @if($usesBrandSidebar)
            {{-- زخرفة إسلامية خفيفة على خلفية السايدبار --}}
            <div class="absolute inset-0 opacity-[0.06] pointer-events-none">
                <svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
                    <pattern id="sidebar-islamic-pattern" width="70" height="70" patternUnits="userSpaceOnUse">
                        <path d="M35 0 L70 35 L35 70 L0 35 Z M35 9 L61 35 L35 61 L9 35 Z" fill="none" stroke="white" stroke-width="1" />
                        <circle cx="35" cy="35" r="7" fill="none" stroke="white" stroke-width="0.75" />
                    </pattern>
                    <rect width="100%" height="100%" fill="url(#sidebar-islamic-pattern)" />
                </svg>
            </div>
        @endif

        <flux:sidebar.header class="relative lg:hidden">
            <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>

        <div class="relative flex-1 flex flex-col min-h-0">
            {{ $sidebar ?? '' }}

            <flux:spacer />

            <flux:sidebar.nav>
                @if(auth('student')->check())
                    <flux:sidebar.item class="[&_svg]:bg-[#71717a] hover:[&_svg]:bg-[#52525b]" icon="cog" :href="route('student.settings')" :current="request()->routeIs('student.settings')" wire:navigate>
                        {{ __('إعدادات الحساب') }}
                    </flux:sidebar.item>
                @else
                    <flux:sidebar.item class="[&_svg]:bg-[#71717a] hover:[&_svg]:bg-[#52525b]" icon="cog" :href="route('profile.edit')" :current="request()->routeIs('profile.edit')" wire:navigate>
                        {{ __('إعدادات الحساب') }}
                    </flux:sidebar.item>
                @endif

                @if(auth('student')->check())
                    <form method="POST" action="{{ route('student.logout') }}" class="w-full">
                @else
                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                @endif
                    @csrf
                    <flux:sidebar.item as="button" type="submit" icon="arrow-right-start-on-rectangle"
                        class="[&_svg]:bg-[#71717a] hover:[&_svg]:bg-[#52525b] w-full cursor-pointer">
                        {{ __('تسجيل الخروج') }}
                    </flux:sidebar.item>
                </form>
            </flux:sidebar.nav>

            @if($usesBrandSidebar)
                {{-- كارت الدعم الفني --}}
                <a href="https://wa.me/966500000000" target="_blank" rel="noopener"
                    class="mx-3 mb-3 mt-2 flex items-center gap-3 rounded-xl bg-white/10 hover:bg-white/15 p-3 text-white">
                    <span class="flex items-center justify-center size-9 rounded-full bg-white/15 shrink-0">
                        <flux:icon icon="lifebuoy" class="size-5" />
                    </span>
                    <span class="text-sm">
                        <span class="block font-bold">{{ __('الدعم الفني') }}</span>
                        <span class="block text-white/70 text-xs">{{ __('تحتاج مساعدة؟ تواصل معنا') }}</span>
                    </span>
                </a>
            @else
                @php
                    $currentUser = auth()->user();
                @endphp
                <x-desktop-user-menu class="hidden lg:block" :name="$currentUser?->name" />
            @endif
        </div>
    </flux:sidebar>

    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
        <flux:spacer />
        <x-layouts.app.header-user-menu />
    </flux:header>

    <flux:header class="hidden lg:flex !min-h-0 !p-0 min-w-0">
        <x-layouts.app.desktop-header />
    </flux:header>

    <flux:main class="!p-1 !pb-32 md:!p-8 md:!pb-32 lg:!pb-8 min-w-0">
        {{ $slot }}
    </flux:main>

    @if(str_contains(request()->url(), '/teacher/'))
        <x-teacher-bottom-nav />
    @endif

    {{ $bottomNav ?? '' }}

    @persist('toast')
        <flux:toast />
    @endpersist

    @fluxScripts
</body>

</html>