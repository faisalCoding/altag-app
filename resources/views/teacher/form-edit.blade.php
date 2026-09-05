<x-layouts.role-shell>
    <x-slot:sidebar>
        @include('teacher.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.form-builder :form-id="$formId" />
</x-layouts.role-shell>
