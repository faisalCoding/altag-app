<x-layouts.role-shell>
    <x-slot:title>
        {{ __('إنشاء خطة طالب') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('teacher.sidebar-nav')
    </x-slot:sidebar>

    <livewire:teacher.plan-creator />
</x-layouts.role-shell>
