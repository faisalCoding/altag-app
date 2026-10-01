<x-layouts.role-shell>
    <x-slot:title>
        {{ __('جدول البرنامج') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('guardian.sidebar-nav')
    </x-slot:sidebar>

    <livewire:shared.program-schedule role="guardian" />
</x-layouts.role-shell>
