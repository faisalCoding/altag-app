<x-layouts.role-shell :title="__('تقارير الحضور والغياب')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>

    <livewire:supervisor.attendance-reports />
</x-layouts.role-shell>
