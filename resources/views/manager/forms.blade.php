<x-layouts.role-shell :title="__('النماذج')">
    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.manage-forms />
</x-layouts.role-shell>
