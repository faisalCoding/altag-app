<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>مرحباً بكم - مجمع التاج القرآني</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body
    class="bg-zinc-50 dark:bg-accent-dark text-[#1b1b18] dark:text-[#EDEDEC] flex flex-col min-h-screen font-sans antialiased">
    <header
        class="w-full bg-white dark:bg-accent-dark/50 border-b border-zinc-200 dark:border-zinc-800 p-4 sticky top-0 z-50 backdrop-blur-sm">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="bg-maroon p-2 rounded-lg shadow-sm">
                    <x-app-logo-icon class="size-6 text-white" />
                </div>
                <div class="flex flex-col">
                    <span class="font-bold text-lg leading-tight">مجمع التاج القرآني</span>
                    <span class="text-xs text-neutral-grey dark:text-zinc-400">بجامع الزبيدي</span>
                </div>
            </div>
        </div>
    </header>

    <main
        class="grow flex flex-col items-center px-4 py-8 md:py-16 bg-linear-to-b from-white to-zinc-50 dark:from-accent-dark dark:to-accent-dark/95">
        <div class="w-full max-w-2xl mx-auto text-center space-y-6 mb-12">

            <h1 class="text-3xl md:text-5xl font-extrabold tracking-tight text-maroon dark:text-white leading-tight">
                أهلاً بكم في <br class="md:hidden"> <span class="text-red-secondary">بوابة مجمع التاج</span>
            </h1>
            <div
                class="inline-flex items-center px-3 py-1 rounded-full bg-maroon/10 text-maroon dark:bg-red-secondary/20 dark:text-red-secondary text-sm font-medium mb-2">
                {{ __('جمعية خيركم لتحفيظ القرآن بجدة') }}
            </div>
        </div>

        <div class="w-full max-w-lg mx-auto grid grid-cols-1 sm:grid-cols-2 gap-4">
            <a href="{{ route('teacher.login') }}"
                class="group relative overflow-hidden flex flex-col items-center justify-center p-6 border-2 rounded-2xl bg-white dark:bg-zinc-900 border-zinc-200 dark:border-zinc-800 hover:border-maroon dark:hover:border-red-secondary transition-all shadow-sm hover:shadow-xl hover:-translate-y-1">
                <div
                    class="absolute top-0 right-0 w-16 h-16 bg-maroon/5 group-hover:bg-maroon/10 transition-colors rounded-bl-full -mr-8 -mt-8">
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                    class="w-12 h-12 mb-4 text-maroon dark:text-red-secondary group-hover:scale-110 transition-transform">
                    <path d="M22 10v6M2 10l10-5 10 5-10 5z" />
                    <path d="M6 12v5c3 3 9 3 12 0v-5" />
                </svg>
                <span class="font-bold text-xl mb-1">بوابة المعلم</span>
                <span class="text-xs text-neutral-grey text-center">إدارة الحلقات والطلاب</span>
            </a>

            <a href="{{ route('supervisor.login') }}"
                class="group relative overflow-hidden flex flex-col items-center justify-center p-6 border-2 rounded-2xl bg-white dark:bg-zinc-900 border-zinc-200 dark:border-zinc-800 hover:border-blue-600 transition-all shadow-sm hover:shadow-xl hover:-translate-y-1">
                <div
                    class="absolute top-0 right-0 w-16 h-16 bg-blue-500/5 group-hover:bg-blue-500/10 transition-colors rounded-bl-full -mr-8 -mt-8">
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                    class="w-12 h-12 mb-4 text-blue-600 group-hover:scale-110 transition-transform">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                    <path d="m9 12 2 2 4-4" />
                </svg>
                <span class="font-bold text-xl mb-1">بوابة المشرف</span>
                <span class="text-xs text-neutral-grey text-center">متابعة الأداء والتقارير</span>
            </a>

            <a href="{{ route('student.login') }}"
                class="group relative overflow-hidden flex flex-col items-center justify-center p-6 border-2 rounded-2xl bg-white dark:bg-zinc-900 border-zinc-200 dark:border-zinc-800 hover:border-orange-500 transition-all shadow-sm hover:shadow-xl hover:-translate-y-1">
                <div
                    class="absolute top-0 right-0 w-16 h-16 bg-orange-500/5 group-hover:bg-orange-500/10 transition-colors rounded-bl-full -mr-8 -mt-8">
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                    class="w-12 h-12 mb-4 text-orange-600 group-hover:scale-110 transition-transform">
                    <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
                    <circle cx="12" cy="7" r="4" />
                </svg>
                <span class="font-bold text-xl mb-1">طالب</span>
                <span class="text-xs text-neutral-grey text-center">مراجعة الحفظ والخطة</span>
            </a>

            <a href="{{ route('parent.login') }}"
                class="group relative overflow-hidden flex flex-col items-center justify-center p-6 border-2 rounded-2xl bg-white dark:bg-zinc-900 border-zinc-200 dark:border-zinc-800 hover:border-purple-600 transition-all shadow-sm hover:shadow-xl hover:-translate-y-1">
                <div
                    class="absolute top-0 right-0 w-16 h-16 bg-purple-500/5 group-hover:bg-purple-500/10 transition-colors rounded-bl-full -mr-8 -mt-8">
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                    class="w-12 h-12 mb-4 text-purple-600 group-hover:scale-110 transition-transform">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                </svg>
                <span class="font-bold text-xl mb-1">بوابة ولي الأمر</span>
                <span class="text-xs text-neutral-grey text-center">متابعة الأبناء والنتائج</span>
            </a>
        </div>

        <div
            class="mt-12 md:mt-24 w-full max-w-4xl grid grid-cols-1 md:grid-cols-3 gap-8 px-4 border-t border-zinc-200 dark:border-zinc-800 pt-12">
            <div class="flex flex-col items-center text-center space-y-2">
                <div
                    class="w-10 h-10 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-maroon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" />
                        <circle cx="12" cy="10" r="3" />
                    </svg>
                </div>
                <h3 class="font-bold">الموقع</h3>
                <p class="text-sm text-neutral-grey dark:text-zinc-400">جدة، حي الواحة، خلف المساحة الجيولوجية</p>
            </div>
            <div class="flex flex-col items-center text-center space-y-2">
                <div
                    class="w-10 h-10 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-maroon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path
                            d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                    </svg>
                </div>
                <h3 class="font-bold">اتصل بنا</h3>
                <p class="text-sm text-neutral-grey dark:text-zinc-400">0508822794</p>
            </div>
            <div class="flex flex-col items-center text-center space-y-2">
                <div
                    class="w-10 h-10 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-maroon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path
                            d="M3 9a2 2 0 0 1 2-2h.93a2 2 0 0 0 1.664-.89l.812-1.22A2 2 0 0 1 10.07 4h3.86a2 2 0 0 1 1.664.89l.812 1.22A2 2 0 0 0 18.07 7H19a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9Z" />
                        <circle cx="12" cy="13" r="3" />
                    </svg>
                </div>
                <h3 class="font-bold">تابعنا</h3>
                <p class="text-sm text-neutral-grey dark:text-zinc-400">@altag_jeddah</p>
            </div>
        </div>
    </main>

    <footer class="w-full bg-white dark:bg-accent-dark border-t border-zinc-200 dark:border-zinc-800 py-6 text-center">
        <p class="text-sm text-neutral-grey dark:text-zinc-400">
            &copy; {{ date('Y') }} مجمع التاج القرآني. جميع الحقوق محفوظة.
        </p>
    </footer>
</body>

</html>
