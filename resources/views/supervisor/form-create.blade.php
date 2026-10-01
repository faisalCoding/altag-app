<x-layouts.role-shell :title="__('إنشاء نموذج')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.form-builder />
</x-layouts.role-shell>
