<x-layouts.role-shell :title="__('إنشاء نموذج')">
    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.form-builder />
</x-layouts.role-shell>
