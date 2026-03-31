<x-layouts.role-shell>
    <x-slot:title>
        {{ __('لوحة تحكم المعلم') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('teacher.sidebar-nav')
    </x-slot:sidebar>

    <div class="p-6 md:p-8 space-y-8" dir="rtl">
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">
                {{ __('لوحة تحكم التعليم') }}
            </flux:heading>
            <flux:subheading class="text-zinc-500 dark:text-zinc-400">
                {{ __('مرحباً بك مجدداً في نظام إدارة مجمع التاج القرآني') }}
            </flux:subheading>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-white dark:bg-zinc-900 p-6 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs h-40 flex flex-col justify-center items-center text-center">
                <flux:icon icon="users" class="size-8 text-zinc-300" />
                <flux:heading size="lg">طلابي</flux:heading>
                <flux:subheading>متابعة الطلاب في حلقاتك</flux:subheading>
            </div>
            <div class="bg-white dark:bg-zinc-900 p-6 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs h-40 flex flex-col justify-center items-center text-center">
                <flux:icon icon="book-open" class="size-8 text-zinc-300" />
                <flux:heading size="lg">المناهج</flux:heading>
                <flux:subheading>إدارة خطط الحفظ</flux:subheading>
            </div>
            <div class="bg-white dark:bg-zinc-900 p-6 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs h-40 flex flex-col justify-center items-center text-center">
                <flux:icon icon="calendar" class="size-8 text-zinc-300" />
                <flux:heading size="lg">الحضور والغياب</flux:heading>
                <flux:subheading>تسجيل حضور الطلاب</flux:subheading>
            </div>
        </div>
    </div>
</x-layouts.role-shell>
