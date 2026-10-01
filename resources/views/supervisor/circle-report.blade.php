<x-layouts.role-shell :title="__('تقرير الحلقة')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.circle-report :circle-id="$circleId" />
</x-layouts.role-shell>
