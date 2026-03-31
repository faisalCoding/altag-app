<x-layouts.role-shell>
    <x-slot:title>
        {{ __('لوحة تحكم الطالب') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('student.sidebar-nav')
    </x-slot:sidebar>

    <div class="p-6 md:p-8 space-y-8" dir="rtl">
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">
                {{ __('لوحة تحكم الطالب') }}
            </flux:heading>
            <flux:subheading class="text-zinc-500 dark:text-zinc-400">
                {{ __('مرحباً بك مجدداً في رحلتك مع القرآن الكريم') }}
            </flux:subheading>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-6 shadow-xs overflow-hidden">
                <div class="flex items-center gap-4 mb-4">
                    <div class="p-3 bg-emerald-50 dark:bg-emerald-950 rounded-xl text-emerald-600 dark:text-emerald-400">
                        <flux:icon icon="book-open" variant="mini" />
                    </div>
                    <flux:heading size="lg">حفظي الحالي</flux:heading>
                </div>
                <p class="text-zinc-600 dark:text-zinc-400">تابع مستوى تقدمك في الحفظ والمراجعة من هنا.</p>
            </div>

            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-6 shadow-xs overflow-hidden">
                <div class="flex items-center gap-4 mb-4">
                    <div class="p-3 bg-blue-50 dark:bg-blue-950 rounded-xl text-blue-600 dark:text-blue-400">
                        <flux:icon icon="chart-bar-square" variant="mini" />
                    </div>
                    <flux:heading size="lg">الإحصائيات</flux:heading>
                </div>
                <p class="text-zinc-600 dark:text-zinc-400">شاهد إنجازاتك الأسبوعية والشهرية.</p>
            </div>

            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-6 shadow-xs overflow-hidden">
                <div class="flex items-center gap-4 mb-4">
                    <div class="p-3 bg-purple-50 dark:bg-purple-950 rounded-xl text-purple-600 dark:text-purple-400">
                        <flux:icon icon="calendar" variant="mini" />
                    </div>
                    <flux:heading size="lg">المواعيد</flux:heading>
                </div>
                <p class="text-zinc-600 dark:text-zinc-400">جدول الحلقات القادمة والاختبارات.</p>
            </div>
        </div>
    </div>
</x-layouts.role-shell>
