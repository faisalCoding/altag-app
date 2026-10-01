<x-layouts.role-shell :title="__('تعديل النموذج')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.form-builder :formId="$formId" />
</x-layouts.role-shell>
