<x-layouts.role-shell :title="__('إدارة الأحاديث')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.manage-hadiths />
</x-layouts.role-shell>
