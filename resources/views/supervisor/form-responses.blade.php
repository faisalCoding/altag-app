<x-layouts.role-shell :title="__('ردود النموذج')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>
    <livewire:supervisor.form-responses :formId="$formId" />
</x-layouts.role-shell>
