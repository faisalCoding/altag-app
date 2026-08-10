<x-layouts.role-shell>
    <x-slot:title>
        {{ __('تقارير مخصصة') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>

    <livewire:supervisor.custom-report />
</x-layouts.role-shell>
