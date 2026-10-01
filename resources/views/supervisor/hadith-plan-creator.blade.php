<x-layouts.role-shell :title="__('إنشاء خطة متون')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:shared.hadith-plan-creator />
</x-layouts.role-shell>
