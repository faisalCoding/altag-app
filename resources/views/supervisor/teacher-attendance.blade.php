<x-layouts.role-shell>
    <x-slot:title>
        {{ __('تحضير المعلمين') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>

    <livewire:supervisor.teacher-attendance />
</x-layouts.role-shell>
