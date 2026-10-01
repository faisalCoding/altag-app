<x-layouts.role-shell :title="__('المهام')">
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <div class="p-2 bg-indigo-100 dark:bg-indigo-900/30 rounded-lg">
                <flux:icon icon="clipboard-document-list" class="text-indigo-600 dark:text-indigo-400 size-6" />
            </div>
            <div>
                <flux:heading size="lg">إدارة المهام</flux:heading>
                <flux:subheading>إدارة وتتبع المهام المرتبطة بالأحداث الأكاديمية</flux:subheading>
            </div>
        </div>
    </x-slot>
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <livewire:supervisor.tasks-manager />
        </div>
    </div>
</x-layouts.role-shell>