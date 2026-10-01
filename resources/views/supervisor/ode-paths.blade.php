<x-layouts.role-shell :title="__('مسارات حفظ المنظومات')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.manage-ode-paths />
</x-layouts.role-shell>
