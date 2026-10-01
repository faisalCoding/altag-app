<x-layouts.role-shell :title="__('الانضباط والنسخ الاحتياطي')">
    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>
    <livewire:manager.settings />
</x-layouts.role-shell>
