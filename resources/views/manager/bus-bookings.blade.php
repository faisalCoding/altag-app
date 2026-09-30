<x-layouts.role-shell>
    <x-slot:title>
        {{ __('حجز الباصات') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>

    <livewire:manager.bus-bookings />
</x-layouts.role-shell>
