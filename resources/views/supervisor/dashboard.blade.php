<x-layouts.role-shell>
    <x-slot:title>
        {{ __('لوحة تحكم المشرف') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>

    <div class="p-6 md:p-8 space-y-8" dir="rtl">
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">
                {{ __('لوحة تحكم الإشراف') }}
            </flux:heading>
            <flux:subheading class="text-zinc-500 dark:text-zinc-400">
                {{ __('مرحباً بك مجدداً في نظام إدارة مجمع التاج القرآني') }}
            </flux:subheading>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Placeholders for supervisor stats -->
            <div class="bg-white dark:bg-zinc-900 p-6 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs h-40 flex flex-col justify-center items-center text-center">
                <flux:icon icon="circle-stack" class="size-8 text-zinc-300" />
                <flux:heading size="lg">الحلقات</flux:heading>
                <flux:subheading>إدارة وتوجيه الحلقات</flux:subheading>
            </div>
            <div class="bg-white dark:bg-zinc-900 p-6 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs h-40 flex flex-col justify-center items-center text-center">
                <flux:icon icon="users" class="size-8 text-zinc-300" />
                <flux:heading size="lg">المعلمون</flux:heading>
                <flux:subheading>متابعة أداء المعلمين</flux:subheading>
            </div>
        </div>
    </div>
</x-layouts.role-shell>
