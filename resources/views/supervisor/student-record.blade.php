<x-layouts.role-shell :title="__('سجل الطلاب')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>

    <livewire:supervisor.student-record />
</x-layouts.role-shell>
