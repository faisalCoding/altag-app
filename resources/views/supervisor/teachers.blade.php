<x-layouts.role-shell :title="__('المعلمون')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.teachers />
</x-layouts.role-shell>
