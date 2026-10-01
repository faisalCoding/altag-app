<x-layouts.role-shell :title="__('ردود النموذج')">
    <x-slot:sidebar>
        @include('teacher.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.form-responses :form-id="$formId" />
</x-layouts.role-shell>
