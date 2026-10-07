<x-layouts.role-shell :title="__('تقرير حضور المعلمين')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>

    <livewire:shared.teacher-attendance-report role="supervisor" />
</x-layouts.role-shell>
