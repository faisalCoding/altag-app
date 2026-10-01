<x-layouts.role-shell :title="__('المسابقات')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.competitions />
</x-layouts.role-shell>
