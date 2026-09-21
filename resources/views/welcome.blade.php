@php
    $name = App\Support\Branding::siteName();
    $ar = fn ($n) => App\Support\HijriDate::arabicDigits($n);

    $signedIn = collect(['manager', 'supervisor', 'teacher', 'student', 'guardian', 'staff'])
        ->first(fn ($guard) => auth()->guard($guard)->check());

    // Shown only when there is something to show. A new academy reading
    // "إحصاءات حية" beside three zeros learns only that the page is untended.
    $figures = collect([
        ['icon' => 'academic-cap', 'value' => (int) ($stats['teachers'] ?? 0), 'label' => 'معلم'],
        ['icon' => 'user-group', 'value' => (int) ($stats['students'] ?? 0), 'label' => 'طالب'],
        ['icon' => 'globe-alt', 'value' => (int) ($stats['circles'] ?? 0), 'label' => 'حلقة'],
    ]);
    $hasFigures = $figures->sum('value') > 0;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $name }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <x-branding-styles />
</head>

<body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">

    <x-site-header :show-auth-actions="false" />

    {{-- ═════════════════════ الباب ═════════════════════ --}}
    {{-- The first screen is the thing almost everyone came for. No drawing, no
         slogan repeated three times over: the mark, the name once, and the
         form. Whoever came to read about the academy scrolls. --}}
    <section class="flex min-h-[calc(100dvh-4rem)] flex-col items-center justify-center px-5 py-12">
        <div class="w-full max-w-sm">

            <div class="flex flex-col items-center text-center">
                <img src="{{ App\Support\Branding::logoUrl() }}" alt="{{ $name }}"
                    class="h-20 object-contain" />
                <h1 class="mt-5 text-2xl font-bold tracking-tight sm:text-3xl">{{ $name }}</h1>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    منصة رقمية متكاملة لتحفيظ القرآن الكريم
                </p>
            </div>

            <div class="mt-8 rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-sm sm:p-7 dark:border-zinc-800 dark:bg-zinc-900">
                @if ($signedIn)
                    <p class="text-center text-sm text-zinc-500 dark:text-zinc-400">
                        أنت مسجَّل الدخول بالفعل.
                    </p>
                    <flux:button variant="primary" class="mt-4 w-full !h-11" href="{{ route('dashboard') }}">
                        الذهاب إلى لوحتك
                    </flux:button>
                @else
                    <livewire:auth.login />
                @endif
            </div>

            <x-auth-ayah class="mt-12" />
        </div>

        <a href="#about" class="mt-12 flex flex-col items-center gap-1 text-xs font-medium text-zinc-400 transition-colors hover:text-maroon dark:hover:text-red-secondary">
            تعرّف على المجمع
            <flux:icon icon="chevron-down" class="size-4" />
        </a>
    </section>

    {{-- ═════════════════════ من نحن ═════════════════════ --}}
    <section id="about" class="scroll-mt-16 border-t border-zinc-100 px-5 py-20 dark:border-zinc-900">
        <div class="mx-auto max-w-2xl text-center">
            <p class="text-xs font-bold tracking-[0.2em] text-maroon dark:text-red-secondary">من نحن</p>
            <h2 class="mt-3 text-2xl font-bold sm:text-3xl">مكان واحد لرحلة الحفظ كاملة</h2>
            <p class="mt-5 text-base leading-loose text-zinc-500 dark:text-zinc-400">
                يجمع {{ $name }} الطالب والمعلم والمشرف وولي الأمر في منصة واحدة:
                من متابعة خطط الحفظ والمراجعة يوماً بيوم، إلى إدارة الحلقات والحضور،
                إلى تقارير الإنجاز التي تصل الأسرة أولاً بأول.
            </p>
        </div>
    </section>

    {{-- ═════════════════════ ما يقدّمه ═════════════════════ --}}
    <section id="features" class="scroll-mt-16 bg-zinc-50 px-5 py-20 dark:bg-zinc-900/40">
        <div class="mx-auto max-w-5xl">
            <div class="text-center">
                <p class="text-xs font-bold tracking-[0.2em] text-maroon dark:text-red-secondary">ما يقدّمه</p>
                <h2 class="mt-3 text-2xl font-bold sm:text-3xl">أربعة أعمدة تقوم عليها المتابعة</h2>
            </div>

            <div class="mt-12 grid grid-cols-1 gap-px overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-200 sm:grid-cols-2 lg:grid-cols-4 dark:border-zinc-800 dark:bg-zinc-800">
                @foreach ([
                    ['icon' => 'book-open', 'title' => 'خطط حفظ ومراجعة', 'desc' => 'خطة لكل طالب تناسب مستواه واتجاه حفظه، تُتابَع يوماً بيوم.'],
                    ['icon' => 'check-circle', 'title' => 'حضور وانضباط', 'desc' => 'تسجيل دقيق للحضور، وتنبيه فوري عند تجاوز حدّ الغياب أو التأخّر.'],
                    ['icon' => 'trophy', 'title' => 'تحفيز وإنجازات', 'desc' => 'نقاط وأوسمة ولوحات صدارة تحمل الطالب على الاستمرار.'],
                    ['icon' => 'home-modern', 'title' => 'وصلٌ بالأسرة', 'desc' => 'يتابع وليّ الأمر إنجاز ابنه أولاً بأول من واجهته الخاصة.'],
                ] as $feature)
                    <div class="bg-white p-7 dark:bg-zinc-950">
                        <flux:icon icon="{{ $feature['icon'] }}" class="size-6 text-maroon dark:text-red-secondary" />
                        <h3 class="mt-4 font-bold">{{ $feature['title'] }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $feature['desc'] }}</p>
                    </div>
                @endforeach
            </div>

            @if ($hasFigures)
                <div id="figures" class="mt-12 grid grid-cols-3 gap-6 rounded-2xl border border-zinc-200 bg-white px-6 py-8 text-center dark:border-zinc-800 dark:bg-zinc-950">
                    @foreach ($figures as $figure)
                        <div>
                            <div class="text-3xl font-bold text-maroon sm:text-4xl dark:text-red-secondary">
                                {{ $ar($figure['value']) }}
                            </div>
                            <div class="mt-1 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $figure['label'] }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- ═════════════════════ أسئلة شائعة ═════════════════════ --}}
    <section id="faq" class="scroll-mt-16 px-5 py-20">
        <div class="mx-auto max-w-2xl">
            <div class="text-center">
                <p class="text-xs font-bold tracking-[0.2em] text-maroon dark:text-red-secondary">أسئلة شائعة</p>
                <h2 class="mt-3 text-2xl font-bold sm:text-3xl">ما قد يخطر لك</h2>
            </div>

            <div class="mt-10 divide-y divide-zinc-100 border-y border-zinc-100 dark:divide-zinc-900 dark:border-zinc-900">
                @foreach ([
                    ['q' => 'كيف أُنشئ حساباً جديداً؟', 'a' => 'من رابط «إنشاء حساب جديد» أسفل نموذج الدخول. تملأ بياناتك، فيصل طلبك إلى إدارة المجمع للمراجعة.'],
                    ['q' => 'كم يستغرق قبول طلب التسجيل؟', 'a' => 'تراجع الإدارة الطلبات أولاً بأول، وتصلك رسالة فور قبول طلبك أو رفضه.'],
                    ['q' => 'نسيت كلمة المرور.', 'a' => 'اضغط «نسيت كلمة المرور؟» في نموذج الدخول، ويصلك رابط لاستعادتها على بريدك.'],
                    ['q' => 'أحتاج مساعدة أخرى.', 'a' => 'تجد وسائل التواصل مع إدارة المجمع في آخر هذه الصفحة.'],
                ] as $item)
                    <details class="group py-4">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-sm font-bold">
                            {{ $item['q'] }}
                            <flux:icon icon="plus" class="size-4 shrink-0 text-zinc-400 transition-transform group-open:rotate-45" />
                        </summary>
                        <p class="mt-3 text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $item['a'] }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ═════════════════════ تواصل ═════════════════════ --}}
    <section id="contact" class="scroll-mt-16 bg-zinc-50 px-5 py-20 dark:bg-zinc-900/40">
        <div class="mx-auto max-w-3xl">
            <div class="text-center">
                <p class="text-xs font-bold tracking-[0.2em] text-maroon dark:text-red-secondary">تواصل</p>
                <h2 class="mt-3 text-2xl font-bold sm:text-3xl">نحن قريبون</h2>
            </div>

            <div class="mt-10 grid grid-cols-1 gap-8 sm:grid-cols-3">
                @foreach ([
                    ['icon' => 'map-pin', 'title' => 'الموقع', 'value' => 'جدة، حي الواحة، خلف هيئة المساحة الجيولوجية', 'dir' => 'rtl'],
                    ['icon' => 'phone', 'title' => 'الهاتف', 'value' => '0508822794', 'dir' => 'ltr'],
                    ['icon' => 'share', 'title' => 'حساباتنا', 'value' => '@altag_jeddah', 'dir' => 'ltr'],
                ] as $item)
                    <div class="text-center">
                        <flux:icon icon="{{ $item['icon'] }}" class="mx-auto size-5 text-maroon dark:text-red-secondary" />
                        <h3 class="mt-3 text-sm font-bold">{{ $item['title'] }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-zinc-500 dark:text-zinc-400" dir="{{ $item['dir'] }}">
                            {{ $item['value'] }}
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <footer class="border-t border-zinc-100 px-5 py-8 text-center dark:border-zinc-900">
        <p class="text-xs text-zinc-400">
            © {{ $ar(date('Y')) }} {{ $name }} · جميع الحقوق محفوظة
        </p>
    </footer>

    @fluxScripts
</body>

</html>
