<x-layouts.role-shell :title="__('إنشاء خطة منظومات')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:shared.ode-plan-creator />
</x-layouts.role-shell>
