<x-layouts.role-shell :title="__('متابعة الإقرار')">
    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>

    <livewire:manager.agreement-follow-up :agreement-id="$agreementId" />
</x-layouts.role-shell>
