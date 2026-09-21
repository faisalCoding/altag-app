@props(['showAuthActions' => true])

@php
    $home = route('home');
    $anchor = fn (string $id) => request()->routeIs('home') ? "#{$id}" : "{$home}#{$id}";
@endphp

<header class="sticky top-0 z-50 w-full bg-maroon dark:bg-accent-dark">
    <div class="max-w-6xl mx-auto px-5 h-16 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3 shrink-0">
            <span class="hidden sm:flex items-center gap-1 text-xs font-bold text-white/80 hover:text-white px-2.5 py-1.5 rounded-full border border-white/20 transition-colors cursor-default">
                <flux:icon icon="language" class="size-3.5" />
                العربية
                <flux:icon icon="chevron-down" class="size-3" />
            </span>
            <a href="{{ $anchor('contact') }}" wire:navigate class="hidden sm:flex items-center gap-1.5 text-xs font-bold text-white bg-white/10 hover:bg-white/20 px-3 py-1.5 rounded-full transition-colors">
                <flux:icon icon="lifebuoy" class="size-3.5" />
                مساعدة
            </a>
        </div>

        <nav class="hidden lg:flex items-center gap-6 text-sm font-semibold text-white/85">
            <a href="{{ $anchor('contact') }}" wire:navigate class="hover:text-white transition-colors">اتصل بنا</a>
            <a href="{{ $anchor('faq') }}" wire:navigate class="hover:text-white transition-colors">الأسئلة الشائعة</a>
            <a href="{{ $anchor('features') }}" wire:navigate class="hover:text-white transition-colors">البرامج</a>
            <a href="{{ $anchor('features') }}" wire:navigate class="hover:text-white transition-colors">الخدمات</a>
            <a href="{{ $anchor('about') }}" wire:navigate class="hover:text-white transition-colors">عن المجمع</a>
            <a href="{{ $home }}" wire:navigate class="hover:text-white transition-colors">الرئيسية</a>
        </nav>

        <a href="{{ $home }}" wire:navigate class="flex items-center gap-2.5 shrink-0">
            <div class="text-end hidden sm:block">
                <div class="font-extrabold text-white text-sm leading-tight">{{ App\Support\Branding::siteName() }}</div>
                <div class="text-[10px] text-white/70">منصة رقمية متكاملة لتحفيظ القرآن الكريم</div>
            </div>
            <img src="{{ App\Support\Branding::logoUrl() }}" alt="{{ App\Support\Branding::siteName() }}" class="h-9 object-contain" />
        </a>
    </div>
</header>
