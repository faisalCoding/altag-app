<x-layouts.role-shell :title="__('الطلاب')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.students />
</x-layouts.role-shell>
