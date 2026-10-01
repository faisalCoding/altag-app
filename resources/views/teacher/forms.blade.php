<x-layouts.role-shell :title="__('النماذج')">
    <x-slot:sidebar>
        @include('teacher.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.manage-forms />
</x-layouts.role-shell>
