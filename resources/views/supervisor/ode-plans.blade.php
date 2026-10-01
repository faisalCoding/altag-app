<x-layouts.role-shell :title="__('خطط المنظومات المنشأة')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:shared.ode-plans-list role="supervisor" />
</x-layouts.role-shell>
