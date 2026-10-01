<x-layouts.role-shell :title="__('تقارير الحضور والغياب')">
    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>

    <livewire:manager.attendance-reports />
</x-layouts.role-shell>
