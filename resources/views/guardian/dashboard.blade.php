<x-layouts.role-shell :title="__('لوحة تحكم ولي الأمر')">
    <x-slot:sidebar>
        @include('guardian.sidebar-nav')
    </x-slot:sidebar>

    <livewire:guardian.dashboard />
</x-layouts.role-shell>
