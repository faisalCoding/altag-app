<x-layouts.role-shell :title="__('إدارة التلعيب')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.manage-gamification :competition-id="$competitionId" />
</x-layouts.role-shell>
