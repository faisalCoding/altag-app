<x-layouts.role-shell>
    <x-slot:title>
        {{ __('محرر أسطر المصحف') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>

    <livewire:manager.quran-line-editor />
</x-layouts.role-shell>
