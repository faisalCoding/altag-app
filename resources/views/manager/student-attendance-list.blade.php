<x-layouts.role-shell>
    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>

    <livewire:manager.student-attendance-list :circle-id="request()->route('circleId')" :date="request()->route('date')" />
</x-layouts.role-shell>
