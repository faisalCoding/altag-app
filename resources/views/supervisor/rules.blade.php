<x-layouts.role-shell :title="__('أنظمة الحلقات')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>

    <livewire:shared.rules-acknowledgement role="supervisor" />
</x-layouts.role-shell>
