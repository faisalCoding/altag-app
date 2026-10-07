<x-layouts.role-shell>
    <x-slot:title>
        {{ __('سجل حضوري') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('teacher.sidebar-nav')
    </x-slot:sidebar>

    <livewire:teacher.my-attendance />
</x-layouts.role-shell>
