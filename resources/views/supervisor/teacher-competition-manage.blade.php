<x-layouts.role-shell :title="__('إدارة مسابقة المعلمين')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.teacher-competition-manage :competition-id="$competitionId" />
</x-layouts.role-shell>
