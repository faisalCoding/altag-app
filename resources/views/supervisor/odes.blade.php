<x-layouts.role-shell :title="__('إدارة المنظومات')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.manage-odes />
</x-layouts.role-shell>
