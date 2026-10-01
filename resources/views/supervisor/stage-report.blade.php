<x-layouts.role-shell :title="__('تقرير المرحلة')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.stage-report :stage-id="$stageId" />
</x-layouts.role-shell>
