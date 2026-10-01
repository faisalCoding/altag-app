<x-layouts.role-shell :title="__('لائحة التجاوزات')">
    <x-slot:sidebar>
        @include('teacher.sidebar-nav')
    </x-slot:sidebar>
    <livewire:shared.exceeded-limits />
</x-layouts.role-shell>
