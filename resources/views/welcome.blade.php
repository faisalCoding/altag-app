<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>بوابة مجمع التاج القرآني الرقمية</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .animate-fade-in-up {
            opacity: 0;
            animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .delay-100 { animation-delay: 100ms; }
        .delay-150 { animation-delay: 150ms; }
        .delay-200 { animation-delay: 200ms; }
        .delay-250 { animation-delay: 250ms; }
        .delay-300 { animation-delay: 300ms; }
        .delay-400 { animation-delay: 400ms; }
        .delay-500 { animation-delay: 500ms; }
    </style>
</head>

<body class="bg-white dark:bg-accent-dark text-[#1b1b18] dark:text-[#EDEDEC] font-sans antialiased overflow-x-hidden selection:bg-maroon selection:text-white">

    <x-site-header />

    {{-- ============================== قسم الترحيب والبوابات ============================== --}}
    <div class="relative bg-gradient-to-b from-[#f7efe0] via-[#faf5ea] to-white dark:from-zinc-900 dark:via-zinc-900 dark:to-zinc-950 px-5 py-14 md:py-20 overflow-hidden">
        {{-- زخرفة إسلامية خفيفة --}}
        <div class="absolute inset-0 opacity-[0.04] dark:opacity-[0.06] pointer-events-none">
            <svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
                <pattern id="islamic-pattern" width="80" height="80" patternUnits="userSpaceOnUse">
                    <path d="M40 0 L80 40 L40 80 L0 40 Z M40 10 L70 40 L40 70 L10 40 Z" fill="none" stroke="currentColor" stroke-width="1"/>
                    <circle cx="40" cy="40" r="8" fill="none" stroke="currentColor" stroke-width="0.75"/>
                </pattern>
                <rect width="100%" height="100%" fill="url(#islamic-pattern)"/>
            </svg>
        </div>

        {{-- فانوس معلّق أعلى اليسار --}}
        <svg viewBox="0 0 120 220" class="hidden md:block absolute top-6 left-6 lg:left-16 w-20 lg:w-28 h-auto opacity-90 pointer-events-none" xmlns="http://www.w3.org/2000/svg" fill="none">
            <path d="M60 0v20" stroke="#a9762f" stroke-width="2" stroke-linecap="round" />
            <path d="M60 20c-14 0-24 8-24 8h48s-10-8-24-8Z" stroke="#a9762f" stroke-width="2" stroke-linejoin="round" />
            <rect x="38" y="28" width="44" height="70" rx="6" stroke="#c68a2e" stroke-width="2" />
            <path d="M38 40h44M38 86h44" stroke="#c68a2e" stroke-width="1.5" />
            <circle cx="60" cy="63" r="14" fill="#f6d98a" opacity="0.55" />
            <path d="M50 98h20l6 14H44l6-14Z" stroke="#a9762f" stroke-width="2" stroke-linejoin="round" />
            <circle cx="60" cy="118" r="3" fill="#a9762f" />
        </svg>

        {{-- مصحف على رحلة + مسبحة أسفل اليمين --}}
        <svg viewBox="0 0 220 140" class="hidden md:block absolute bottom-4 right-6 lg:right-16 w-40 lg:w-56 h-auto opacity-90 pointer-events-none" xmlns="http://www.w3.org/2000/svg" fill="none">
            <g stroke="#8a6a3c" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 110h100" />
                <path d="M30 110l14-16h20v-2h20v2h20l14 16" />
                <path d="M64 75v17M84 75v17" />
                <path d="M43 94c8-3 16-3 21 0M85 94c8-3 16-3 21 0" />
                <path d="M20 110l6 8h132l6-8" />
            </g>
            <g fill="#c68a2e" opacity="0.8">
                <circle cx="175" cy="60" r="2.6" />
                <circle cx="183" cy="68" r="2.6" />
                <circle cx="187" cy="79" r="2.6" />
                <circle cx="184" cy="90" r="2.6" />
                <circle cx="175" cy="96" r="2.8" />
            </g>
            <path d="M175 60v-14" stroke="#c68a2e" stroke-width="1.6" stroke-linecap="round" />
        </svg>

        <div class="relative max-w-6xl mx-auto">
            {{-- الشعار والترحيب --}}
            <div class="animate-fade-in-up max-w-xl mx-auto text-center">
                <img src="{{ asset('images/altag_logo.png') }}" alt="مجمع التاج القرآني" class="h-16 md:h-20 object-contain mx-auto mb-3 drop-shadow-sm" />
                <h1 class="text-xl md:text-2xl font-extrabold text-maroon dark:text-white">مجمع التاج القرآني</h1>
                <p class="text-xs md:text-sm text-neutral-grey dark:text-zinc-400 font-medium mt-1">منصة رقمية متكاملة لتحفيظ القرآن الكريم</p>

                <h2 class="text-2xl md:text-3xl font-bold text-zinc-900 dark:text-white leading-relaxed mt-6">
                    أهلاً بكم في بوابة <span class="text-maroon dark:text-red-secondary">مجمع التاج القرآني</span>
                </h2>
                <p class="text-xs md:text-sm text-neutral-grey dark:text-zinc-400 mt-2">سجّل دخولك ببريدك وكلمة المرور، وهيتعرّف النظام تلقائياً على نوع حسابك ويوجّهك لصفحتك الخاصة</p>

                <div class="flex items-center gap-2 mt-6 w-full max-w-[200px] mx-auto">
                    <span class="h-px flex-1 bg-maroon/20 dark:bg-white/20"></span>
                    <flux:icon icon="sparkles" class="size-4 text-maroon/60 dark:text-white/60" />
                    <span class="h-px flex-1 bg-maroon/20 dark:bg-white/20"></span>
                </div>
            </div>

            {{-- زر الدخول الموحّد --}}
            <div class="animate-fade-in-up delay-100 max-w-sm mx-auto mt-10 flex flex-col items-center gap-3">
                <flux:button href="{{ route('login') }}" variant="primary" wire:navigate class="w-full !bg-maroon hover:!bg-burgundy !text-base !py-3">
                    <flux:icon icon="arrow-left" class="size-4" />
                    تسجيل الدخول
                </flux:button>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">
                    ليس لديك حساب؟
                    <flux:link :accent="false" :href="route('register')" wire:navigate>إنشاء حساب جديد</flux:link>
                </div>
            </div>

            {{-- شريط المساعدة --}}
            <div class="animate-fade-in-up delay-500 mt-12 text-center">
                <p class="text-sm font-bold text-zinc-700 dark:text-zinc-300 mb-5">هل لديك مشكلة في تسجيل الدخول؟</p>
                <div class="flex items-center justify-center gap-10 md:gap-16">
                    <a href="#contact" class="group flex flex-col items-center gap-1.5">
                        <flux:icon icon="envelope" class="size-5 text-rose-500 group-hover:scale-110 transition-transform" />
                        <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">تواصل معنا</span>
                    </a>
                    <a href="#contact" class="group flex flex-col items-center gap-1.5">
                        <flux:icon icon="lifebuoy" class="size-5 text-amber-500 group-hover:scale-110 transition-transform" />
                        <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">الدعم الفني</span>
                    </a>
                    <a href="{{ route('password.request') }}" wire:navigate class="group flex flex-col items-center gap-1.5">
                        <flux:icon icon="lock-closed" class="size-5 text-indigo-500 group-hover:scale-110 transition-transform" />
                        <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">نسيت كلمة المرور</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================== من نحن ============================== --}}
    <div id="about" class="bg-white dark:bg-zinc-950 px-5 pt-16 md:pt-20">
        <div class="max-w-3xl mx-auto text-center animate-fade-in-up">
            <span class="text-xs md:text-sm text-maroon dark:text-red-secondary font-bold tracking-widest uppercase">من نحن</span>
            <h3 class="text-2xl md:text-3xl font-bold mt-2 mb-4">مجمع التاج القرآني</h3>
            <p class="text-sm md:text-base text-neutral-grey dark:text-zinc-400 leading-relaxed">
                مجمع التاج القرآني منصة رقمية متكاملة لتحفيظ القرآن الكريم، بتخدم الطلاب والمعلمين والمشرفين وأولياء الأمور في مكان واحد —
                من متابعة خطط الحفظ والمراجعة يومياً، لإدارة الحلقات والحضور، لحد تقارير الإنجاز الفورية للأسرة.
            </p>
        </div>
    </div>

    {{-- ============================== قسم لماذا مجمع التاج (الخدمات/البرامج) ============================== --}}
    <div id="features" class="bg-white dark:bg-zinc-950 px-5 py-16 md:py-20">
        <div class="max-w-5xl mx-auto">
            <div class="animate-fade-in-up text-center mb-10">
                <span class="text-xs md:text-sm text-maroon dark:text-red-secondary font-bold tracking-widest uppercase">لماذا مجمع التاج</span>
                <h3 class="text-2xl md:text-3xl font-bold mt-2">منصة متكاملة لرحلة الحفظ</h3>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                @foreach([
                    ['icon' => 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25', 'title' => 'خطط حفظ ومراجعة مخصصة', 'desc' => 'متابعة يومية دقيقة لكل طالب بخطة تناسب مستواه واتجاه حفظه.'],
                    ['icon' => 'M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z', 'title' => 'متابعة حضور وانضباط', 'desc' => 'تسجيل حضور دقيق وتنبيهات فورية عند تجاوز حدود الغياب أو التأخر.'],
                    ['icon' => 'M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0', 'title' => 'نظام تحفيزي وإنجازات', 'desc' => 'نقاط وأوسمة ولوحات صدارة تحفّز الطالب على الاستمرار والتميز.'],
                    ['icon' => 'm2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25', 'title' => 'تواصل مباشر مع الأسرة', 'desc' => 'يتابع ولي الأمر إنجاز ابنه أولاً بأول من تطبيق مخصص له.'],
                ] as $feature)
                    <div class="p-6 rounded-3xl bg-zinc-50 dark:bg-zinc-900/60 border border-zinc-100 dark:border-zinc-800/80 shadow-sm hover:shadow-lg hover:-translate-y-1 transition-all duration-300">
                        <div class="bg-maroon/10 dark:bg-maroon/20 p-3 rounded-2xl w-fit mb-4 text-maroon dark:text-red-secondary shadow-inner">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $feature['icon'] }}" />
                            </svg>
                        </div>
                        <h4 class="font-bold text-base mb-1.5 text-zinc-900 dark:text-white">{{ $feature['title'] }}</h4>
                        <p class="text-xs text-neutral-grey dark:text-zinc-400 leading-relaxed">{{ $feature['desc'] }}</p>
                    </div>
                @endforeach
            </div>

            {{-- الإحصاءات الحية --}}
            <div class="animate-fade-in-up delay-150 mt-14 p-6 md:p-8 rounded-3xl bg-maroon/[0.03] dark:bg-white/[0.02] border border-maroon/10 dark:border-white/5 shadow-sm max-w-3xl mx-auto">
                <div class="text-center mb-6">
                    <span class="text-xs md:text-sm text-maroon dark:text-red-secondary font-bold tracking-widest uppercase">نبض الإنجاز الفعلي للمجمع</span>
                    <h3 class="text-lg md:text-xl font-bold mt-1">إحصاءات حية لإنجاز مجمع التاج</h3>
                </div>

                <div class="grid grid-cols-3 gap-4 text-center divide-x divide-x-reverse divide-zinc-200/50 dark:divide-zinc-800/40">
                    <div class="flex flex-col items-center justify-center space-y-2">
                        <flux:icon icon="academic-cap" class="size-6 text-maroon dark:text-red-secondary" />
                        <span class="text-3xl md:text-4xl font-black text-maroon dark:text-white font-sans tracking-tight" data-countup="{{ (int) ($stats['teachers'] ?? 0) }}">{{ (int) ($stats['teachers'] ?? 0) }}</span>
                        <span class="text-[11px] md:text-xs text-neutral-grey dark:text-zinc-400 font-bold">معلمون أكفاء</span>
                    </div>
                    <div class="flex flex-col items-center justify-center space-y-2">
                        <flux:icon icon="user-group" class="size-6 text-maroon dark:text-red-secondary" />
                        <span class="text-3xl md:text-4xl font-black text-maroon dark:text-white font-sans tracking-tight" data-countup="{{ (int) ($stats['students'] ?? 0) }}">{{ (int) ($stats['students'] ?? 0) }}</span>
                        <span class="text-[11px] md:text-xs text-neutral-grey dark:text-zinc-400 font-bold">طالباً مستمراً</span>
                    </div>
                    <div class="flex flex-col items-center justify-center space-y-2">
                        <flux:icon icon="globe-alt" class="size-6 text-maroon dark:text-red-secondary" />
                        <span class="text-3xl md:text-4xl font-black text-maroon dark:text-white font-sans tracking-tight" data-countup="{{ (int) ($stats['circles'] ?? 0) }}">{{ (int) ($stats['circles'] ?? 0) }}</span>
                        <span class="text-[11px] md:text-xs text-neutral-grey dark:text-zinc-400 font-bold">حلقة نشطة</span>
                    </div>
                </div>
            </div>

            {{-- الأسئلة الشائعة --}}
            <div id="faq" class="animate-fade-in-up delay-200 mt-14 pt-10 border-t border-zinc-100 dark:border-zinc-800/80 max-w-2xl mx-auto">
                <div class="text-center mb-8">
                    <span class="text-xs md:text-sm text-maroon dark:text-red-secondary font-bold tracking-widest uppercase">الأسئلة الشائعة</span>
                    <h3 class="text-xl md:text-2xl font-bold mt-2">هل عندك سؤال؟</h3>
                </div>
                <div class="space-y-3">
                    @foreach([
                        ['q' => 'إزاي أعمل حساب جديد؟', 'a' => 'من زر "إنشاء حساب جديد" في صفحة الدخول — تملأ بياناتك، وطلبك بيتحوّل مباشرة لإدارة المجمع للمراجعة والموافقة.'],
                        ['q' => 'قد إيه ياخد وقت قبول طلب التسجيل؟', 'a' => 'بيتم مراجعة الطلبات من إدارة المجمع، وهتوصلك رسالة فور قبول أو رفض طلبك.'],
                        ['q' => 'نسيت كلمة المرور، أعمل إيه؟', 'a' => 'اضغط "نسيت كلمة المرور؟" في صفحة تسجيل الدخول واستعد الوصول لحسابك بسهولة.'],
                        ['q' => 'محتاج مساعدة تانية؟', 'a' => 'تقدر تتواصل معنا مباشرة من بيانات التواصل تحت.'],
                    ] as $item)
                        <details class="group rounded-xl border border-zinc-100 dark:border-zinc-800 p-4 open:bg-zinc-50 dark:open:bg-zinc-900/60">
                            <summary class="flex items-center justify-between cursor-pointer text-sm font-bold text-zinc-800 dark:text-zinc-100">
                                {{ $item['q'] }}
                                <flux:icon icon="chevron-down" class="size-4 text-zinc-400 group-open:rotate-180 transition-transform" />
                            </summary>
                            <p class="text-xs text-neutral-grey dark:text-zinc-400 mt-2 leading-relaxed">{{ $item['a'] }}</p>
                        </details>
                    @endforeach
                </div>
            </div>

            {{-- معلومات التواصل --}}
            <div id="contact" class="animate-fade-in-up delay-200 grid grid-cols-1 sm:grid-cols-3 gap-6 mt-14 pt-10 border-t border-zinc-100 dark:border-zinc-800/80 max-w-3xl mx-auto">
                <div class="flex flex-col items-center text-center space-y-2">
                    <div class="size-11 rounded-2xl bg-maroon/5 dark:bg-maroon/10 border border-maroon/10 dark:border-maroon/20 flex items-center justify-center text-maroon dark:text-red-secondary">
                        <flux:icon icon="share" class="size-5" />
                    </div>
                    <h4 class="font-bold text-sm">شبكات التواصل</h4>
                    <p class="text-xs text-neutral-grey dark:text-zinc-400 font-medium">altag_jeddah@</p>
                </div>
                <div class="flex flex-col items-center text-center space-y-2">
                    <div class="size-11 rounded-2xl bg-maroon/5 dark:bg-maroon/10 border border-maroon/10 dark:border-maroon/20 flex items-center justify-center text-maroon dark:text-red-secondary">
                        <flux:icon icon="phone" class="size-5" />
                    </div>
                    <h4 class="font-bold text-sm">الاتصال المباشر</h4>
                    <p class="text-xs text-neutral-grey dark:text-zinc-400 font-medium font-sans">0508822794</p>
                </div>
                <div class="flex flex-col items-center text-center space-y-2">
                    <div class="size-11 rounded-2xl bg-maroon/5 dark:bg-maroon/10 border border-maroon/10 dark:border-maroon/20 flex items-center justify-center text-maroon dark:text-red-secondary">
                        <flux:icon icon="map-pin" class="size-5" />
                    </div>
                    <h4 class="font-bold text-sm">موقع المجمع</h4>
                    <p class="text-xs text-neutral-grey dark:text-zinc-400 font-medium leading-relaxed">جدة، حي الواحة، خلف هيئة المساحة الجيولوجية</p>
                </div>
            </div>
        </div>
    </div>

    {{-- تذييل الصفحة --}}
    <footer class="w-full bg-maroon dark:bg-accent-dark py-5 text-center">
        <p class="text-xs md:text-sm text-white/80 font-medium">
            &copy; {{ date('Y') }} مجمع التاج القرآني. جميع الحقوق محفوظة.
        </p>
    </footer>

    <script>
        // Progressive enhancement: animate stat numbers from 0 to their real
        // (already server-rendered) value. No framework dependency, since this
        // guest page doesn't load Alpine/Livewire.
        document.querySelectorAll('[data-countup]').forEach((el) => {
            const target = parseInt(el.getAttribute('data-countup'), 10) || 0;
            const step = Math.max(1, Math.ceil(target / 40));
            let current = 0;
            el.textContent = '0';
            const timer = setInterval(() => {
                current = Math.min(target, current + step);
                el.textContent = current;
                if (current >= target) clearInterval(timer);
            }, 25);
        });
    </script>
</body>

</html>
