<x-layouts.role-shell :title="__('متابعة الإقرار')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>

    <livewire:supervisor.agreement-follow-up :agreement-id="$agreementId" />
</x-layouts.role-shell>
