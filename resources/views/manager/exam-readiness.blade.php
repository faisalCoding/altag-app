<x-layouts.role-shell :title="__('دفعات الاختبارات')">
    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>

    {{-- Both tabs stay mounted: a batch made in one shows at once in the other. --}}
    <div class="space-y-5" x-data="{ tab: new URLSearchParams(location.search).get('tab') === 'batches' ? 'batches' : 'readiness' }">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ __('دفعات الاختبارات') }}</flux:heading>
            <flux:subheading>{{ __('يرشّح المعلمون طلابهم، وتجمعهم في دفعات تحدد موعدها، وتطبع أسماءها أو تصدّرها.') }}</flux:subheading>
        </div>

        <div class="inline-flex rounded-xl bg-zinc-100 dark:bg-zinc-800 p-1 gap-1">
            <button type="button" x-on:click="tab = 'readiness'"
                x-bind:class="tab === 'readiness' ? 'bg-white dark:bg-zinc-700 shadow-sm text-zinc-900 dark:text-white' : 'text-zinc-500'"
                class="px-4 py-1.5 rounded-lg text-sm font-bold">{{ __('الجاهزية') }}</button>
            <button type="button" x-on:click="tab = 'batches'"
                x-bind:class="tab === 'batches' ? 'bg-white dark:bg-zinc-700 shadow-sm text-zinc-900 dark:text-white' : 'text-zinc-500'"
                class="px-4 py-1.5 rounded-lg text-sm font-bold">{{ __('الدفعات') }}</button>
        </div>

        <div x-show="tab === 'readiness'">
            <livewire:shared.exam-readiness-list role="manager" />
        </div>
        <div x-show="tab === 'batches'" x-cloak>
            <livewire:manager.exam-batches />
        </div>
    </div>
</x-layouts.role-shell>
