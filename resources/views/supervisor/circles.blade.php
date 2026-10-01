<x-layouts.role-shell :title="__('الحلقات')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.circles />
</x-layouts.role-shell>
