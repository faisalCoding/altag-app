<x-layouts.role-shell :title="__('مسارات حفظ المتون')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.manage-hadith-paths />
</x-layouts.role-shell>
