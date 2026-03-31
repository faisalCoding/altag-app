<x-layouts.role-shell>
    <x-slot:title>
        {{ __('لوحة تحكم المدير') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>

    <div class="p-6 md:p-8 space-y-8" dir="rtl">
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">
                {{ __('لوحة تحكم الإدارة') }}
            </flux:heading>
            <flux:subheading class="text-zinc-500 dark:text-zinc-400">
                {{ __('مرحباً بك مجدداً في نظام إدارة مجمع التاج القرآني') }}
            </flux:subheading>
        </div>

        <div
            class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-6 shadow-xs overflow-hidden">
            <livewire:manager.guardians />
        </div>
    </div>
</x-layouts.role-shell>
