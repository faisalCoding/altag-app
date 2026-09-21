@props([
    'verse' => 'وَرَتِّلِ الْقُرْآنَ تَرْتِيلًا',
    'verseReference' => 'سورة المزمل - الآية 4',
    'variant' => 'light',
])

@php
    $isDark = $variant === 'dark';
@endphp

<div {{ $attributes->merge([
    'class' => 'relative hidden lg:flex flex-col justify-between h-full p-10 overflow-hidden '
        .($isDark
            ? 'bg-gradient-to-b from-accent-dark via-maroon to-burgundy'
            : 'bg-gradient-to-b from-[#f7efe0] via-[#faf5ea] to-[#fdfaf3]'),
]) }}>
    {{-- زخرفة إسلامية خفيفة --}}
    <div class="absolute inset-0 opacity-[0.05] pointer-events-none {{ $isDark ? 'text-amber-200' : '' }}">
        <svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
            <pattern id="hero-panel-pattern-{{ $variant }}" width="80" height="80" patternUnits="userSpaceOnUse">
                <path d="M40 0 L80 40 L40 80 L0 40 Z M40 10 L70 40 L40 70 L10 40 Z" fill="none" stroke="currentColor" stroke-width="1"/>
                <circle cx="40" cy="40" r="8" fill="none" stroke="currentColor" stroke-width="0.75"/>
            </pattern>
            <rect width="100%" height="100%" fill="url(#hero-panel-pattern-{{ $variant }})"/>
        </svg>
    </div>

    {{-- الشعار --}}
    <a href="{{ route('home') }}" class="relative z-10 flex flex-col items-center text-center" wire:navigate>
        <img src="{{ App\Support\Branding::logoUrl() }}" alt="{{ App\Support\Branding::siteName() }}" class="h-14 object-contain mb-2 drop-shadow-sm" />
        <span class="font-extrabold text-lg {{ $isDark ? 'text-white' : 'text-maroon' }}">{{ App\Support\Branding::siteName() }}</span>
        <span class="text-xs font-medium mt-0.5 {{ $isDark ? 'text-white/70' : 'text-neutral-grey' }}">منصة رقمية متكاملة لتحفيظ القرآن الكريم</span>
    </a>

    @if($isDark)
        {{-- فانوس مضيء بسيط على الخلفية الداكنة --}}
        <div class="relative z-10 flex-1 flex items-center justify-center my-8">
            <div class="absolute w-40 h-40 rounded-full bg-amber-400/20 blur-3xl"></div>
            <svg viewBox="0 0 120 220" class="relative w-32 h-auto" xmlns="http://www.w3.org/2000/svg" fill="none">
                <path d="M60 0v20" stroke="#f0c869" stroke-width="2" stroke-linecap="round" />
                <path d="M60 20c-14 0-24 8-24 8h48s-10-8-24-8Z" stroke="#f0c869" stroke-width="2" stroke-linejoin="round" />
                <rect x="38" y="28" width="44" height="70" rx="6" stroke="#f0c869" stroke-width="2" />
                <path d="M38 40h44M38 86h44" stroke="#f0c869" stroke-width="1.5" />
                <circle cx="60" cy="63" r="14" fill="#f6d98a" opacity="0.6" />
                <path d="M50 98h20l6 14H44l6-14Z" stroke="#f0c869" stroke-width="2" stroke-linejoin="round" />
                <circle cx="60" cy="118" r="3" fill="#f0c869" />
            </svg>
        </div>
    @else
        {{-- مشهد توضيحي: مصحف على رحلة + فانوس + هلال، برسم خطي أنيق --}}
        <div class="relative z-10 flex-1 flex items-center justify-center my-8">
            <div class="relative w-full max-w-xs aspect-square rounded-3xl bg-gradient-to-br from-accent-dark via-maroon to-burgundy shadow-lg overflow-hidden">
                <div class="absolute -left-6 top-1/3 w-32 h-32 rounded-full bg-amber-400/25 blur-3xl"></div>
                <div class="absolute right-0 bottom-0 w-28 h-28 rounded-full bg-red-secondary/20 blur-2xl"></div>

                <svg viewBox="0 0 240 240" class="relative w-full h-full p-8" xmlns="http://www.w3.org/2000/svg" fill="none">
                    {{-- الهلال والنجمة --}}
                    <g stroke="#f0c869" stroke-width="1.5" stroke-linecap="round" opacity="0.9">
                        <path d="M165 30a14 14 0 1 0 3 27.6 11 11 0 1 1 0-20.4A14 14 0 0 0 165 30Z" fill="#f0c869" opacity="0.85" />
                        <path d="M188 34l1.6 3.4 3.6.5-2.6 2.6.6 3.7-3.2-1.8-3.2 1.8.6-3.7-2.6-2.6 3.6-.5z" fill="#f0c869" opacity="0.7" />
                    </g>
                    {{-- الفانوس --}}
                    <g stroke="#f0c869" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" opacity="0.85">
                        <path d="M40 40v8M40 40h10M40 40h-10" />
                        <path d="M33 48h14l-3 34H37l-3-34Z" />
                        <path d="M36 82h8v8h-8z" />
                        <circle cx="40" cy="65" r="6" fill="#f0c869" opacity="0.5" />
                    </g>
                    {{-- المصحف على الرحلة --}}
                    <g stroke="#faf1dd" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M60 170h120" />
                        <path d="M72 170l14-16h20v-2h20v2h20l14 16" />
                        <path d="M106 135v17M126 135v17" />
                        <path d="M85 154c8-3 16-3 21 0M127 154c8-3 16-3 21 0" />
                        <path d="M60 170l6 8h132l6-8" />
                    </g>
                    {{-- المسبحة --}}
                    <g fill="#f0c869" opacity="0.75">
                        <circle cx="200" cy="130" r="2.6" />
                        <circle cx="208" cy="138" r="2.6" />
                        <circle cx="212" cy="149" r="2.6" />
                        <circle cx="209" cy="160" r="2.6" />
                        <circle cx="200" cy="166" r="2.8" />
                    </g>
                </svg>
            </div>
        </div>
    @endif

    {{-- الآية --}}
    <div class="relative z-10 text-center">
        <p class="font-zain text-xl leading-relaxed {{ $isDark ? 'text-amber-100' : 'text-maroon' }}">{{ '﴿ '.$verse.' ﴾' }}</p>
        <p class="text-xs mt-2 {{ $isDark ? 'text-white/60' : 'text-neutral-grey' }}">{{ $verseReference }}</p>
    </div>
</div>
