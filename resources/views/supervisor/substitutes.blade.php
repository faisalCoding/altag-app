<x-layouts.role-shell :title="__('صلاحيات البدلاء')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>

    <livewire:shared.substitute-assignments role="supervisor" />
</x-layouts.role-shell>
