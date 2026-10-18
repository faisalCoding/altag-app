<x-layouts.role-shell :title="__('الإقرارات')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>

    <livewire:supervisor.agreements />
</x-layouts.role-shell>
