<x-layouts.role-shell :title="__('الإقرارات')">
    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>

    {{-- The reports load when their tab is first opened. --}}
    <div class="space-y-5" x-data="{ tab: new URLSearchParams(location.search).get('tab') === 'reports' ? 'reports' : 'agreements' }">
        <div class="inline-flex rounded-xl bg-zinc-100 dark:bg-zinc-800 p-1 gap-1">
            <button type="button" x-on:click="tab = 'agreements'"
                x-bind:class="tab === 'agreements' ? 'bg-white dark:bg-zinc-700 shadow-sm text-zinc-900 dark:text-white' : 'text-zinc-500'"
                class="px-4 py-1.5 rounded-lg text-sm font-bold">{{ __('الإقرارات والنماذج') }}</button>
            <button type="button" x-on:click="tab = 'reports'" data-agreements-reports-tab
                x-bind:class="tab === 'reports' ? 'bg-white dark:bg-zinc-700 shadow-sm text-zinc-900 dark:text-white' : 'text-zinc-500'"
                class="px-4 py-1.5 rounded-lg text-sm font-bold">{{ __('التقارير') }}</button>
        </div>

        <div x-show="tab === 'agreements'">
            <livewire:manager.agreements />
        </div>
        <div x-show="tab === 'reports'" x-cloak>
            <livewire:manager.agreement-reports lazy />
        </div>
    </div>
</x-layouts.role-shell>
