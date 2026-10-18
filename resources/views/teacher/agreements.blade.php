<x-layouts.role-shell :title="__('إقرارات الطلاب')">
    <x-slot:sidebar>
        @include('teacher.sidebar-nav')
    </x-slot:sidebar>

    <livewire:teacher.agreements />
</x-layouts.role-shell>
