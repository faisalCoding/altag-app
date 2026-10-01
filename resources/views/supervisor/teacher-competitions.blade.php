<x-layouts.role-shell :title="__('مسابقة المعلمين')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.teacher-competitions />
</x-layouts.role-shell>
