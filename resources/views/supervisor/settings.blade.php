<x-layouts.role-shell>
    <x-slot:title>
        {{ __('إعدادات المراحل') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>

    <livewire:supervisor.settings />
</x-layouts.role-shell>
