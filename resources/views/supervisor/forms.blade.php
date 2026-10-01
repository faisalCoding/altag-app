<x-layouts.role-shell :title="__('النماذج')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.manage-forms />
</x-layouts.role-shell>
