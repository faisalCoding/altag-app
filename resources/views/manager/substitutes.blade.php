<x-layouts.role-shell :title="__('صلاحيات البدلاء')">
    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>

    <livewire:shared.substitute-assignments role="manager" />
</x-layouts.role-shell>
