<x-layouts.role-shell :title="__('تقرير حضور المعلمين')">
    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>

    <livewire:shared.teacher-attendance-report role="manager" />
</x-layouts.role-shell>
