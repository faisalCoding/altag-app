<x-layouts.role-shell :title="__('الحلقات')">
    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>
    <livewire:manager.circles />
</x-layouts.role-shell>
