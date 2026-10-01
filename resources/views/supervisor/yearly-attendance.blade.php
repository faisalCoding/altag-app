<x-layouts.role-shell :title="__('متابعة تحضير الحلقات')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>

    <livewire:supervisor.yearly-attendance />
</x-layouts.role-shell>
