<x-layouts.role-shell>
    <x-slot:title>
        {{ __('إدارة المراحل') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>

    <livewire:manager.stages />
</x-layouts.role-shell>
