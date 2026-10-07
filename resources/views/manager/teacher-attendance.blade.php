<x-layouts.role-shell :title="__('تحضير المعلمين')">
    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>

    <livewire:shared.teacher-attendance role="manager" />
</x-layouts.role-shell>
