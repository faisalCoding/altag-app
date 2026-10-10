<x-layouts.role-shell :title="__('أنظمة الحلقات')">
    <x-slot:sidebar>
        @include('teacher.sidebar-nav')
    </x-slot:sidebar>

    <livewire:shared.rules-acknowledgement role="teacher" />
</x-layouts.role-shell>
