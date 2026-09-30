@props(['showAuthActions' => true])

@php
    $home = route('home');
    $anchor = fn (string $id) => request()->routeIs('home') ? "#{$id}" : "{$home}#{$id}";

    // Six links were five too many for a bar this size, and three of them
    // pointed at the same section. What is left is what the page actually has.
    $links = [
        'عن المجمع' => $anchor('about'),
        'ما يقدّمه' => $anchor('features'),
        'أسئلة شائعة' => $anchor('faq'),
        'تواصل معنا' => $anchor('contact'),
    ];
@endphp

<header class="sticky top-0 z-50 w-full bg-maroon dark:bg-accent-dark" x-data="{ open: false }">
    <div class="max-w-6xl mx-auto px-5 h-16 flex items-center justify-between gap-4">

        {{-- الاسم والشعار: أول ما يُقرأ في اتجاه القراءة --}}
        <a href="{{ $home }}" wire:navigate class="flex items-center gap-2.5 shrink-0 min-w-0">
            <img src="{{ App\Support\Branding::logoUrl() }}"
                alt="{{ App\Support\Branding::siteName() }}" class="h-9 w-9 object-contain shrink-0" />
            <span class="font-bold text-white text-sm truncate">{{ App\Support\Branding::siteName() }}</span>
        </a>

        <nav class="hidden md:flex items-center gap-7 text-sm font-medium text-white/80">
            @foreach ($links as $label => $href)
                <a href="{{ $href }}" wire:navigate class="hover:text-white transition-colors">{{ $label }}</a>
            @endforeach
        </nav>

        <div class="flex items-center gap-2 shrink-0">
            {{-- Not on the pages that are already the sign-in door. --}}
            @if ($showAuthActions && ! request()->routeIs('home', 'login', 'register', 'password.*'))
                <a href="{{ $home }}" wire:navigate
                    class="hidden sm:inline-flex items-center rounded-full bg-white/10 hover:bg-white/20 px-3.5 py-1.5 text-xs font-bold text-white transition-colors">
                    تسجيل الدخول
                </a>
            @endif

            {{-- Nothing opened this bar on a phone before: the links were simply
                 hidden below md and no button took their place. --}}
            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'"
                class="md:hidden inline-flex items-center justify-center size-9 rounded-lg text-white/90 hover:bg-white/10 transition-colors"
                aria-label="القائمة">
                <flux:icon icon="bars-3" class="size-5" x-show="! open" />
                <flux:icon icon="x-mark" class="size-5" x-show="open" x-cloak />
            </button>
        </div>
    </div>

    <div class="md:hidden border-t border-white/10" x-show="open" x-collapse x-cloak>
        <nav class="max-w-6xl mx-auto px-5 py-3 flex flex-col">
            @foreach ($links as $label => $href)
                <a href="{{ $href }}" wire:navigate x-on:click="open = false"
                    class="py-2.5 text-sm font-medium text-white/85 hover:text-white transition-colors">{{ $label }}</a>
            @endforeach
        </nav>
    </div>
</header>
