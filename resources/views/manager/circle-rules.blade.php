<x-layouts.role-shell :title="__('أنظمة الحلقات')">
    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>

    {{-- Both tabs stay mounted: switching is instant, and a version published
         in one refreshes the other through its event. --}}
    <div class="space-y-5" x-data="{ tab: new URLSearchParams(location.search).get('tab') === 'acknowledgements' ? 'acknowledgements' : 'rules' }">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ __('أنظمة الحلقات') }}</flux:heading>
            <flux:subheading>{{ __('تكتب الأنظمة هنا وتنشرها، ويُقِرّ بها المعلمون والمشرفون من صفحاتهم.') }}</flux:subheading>
        </div>

        <div class="inline-flex rounded-xl bg-zinc-100 dark:bg-zinc-800 p-1 gap-1">
            <button type="button" x-on:click="tab = 'rules'"
                x-bind:class="tab === 'rules' ? 'bg-white dark:bg-zinc-700 shadow-sm text-zinc-900 dark:text-white' : 'text-zinc-500'"
                class="px-4 py-1.5 rounded-lg text-sm font-bold">{{ __('الأنظمة') }}</button>
            <button type="button" x-on:click="tab = 'acknowledgements'"
                x-bind:class="tab === 'acknowledgements' ? 'bg-white dark:bg-zinc-700 shadow-sm text-zinc-900 dark:text-white' : 'text-zinc-500'"
                class="px-4 py-1.5 rounded-lg text-sm font-bold">{{ __('الإقرارات') }}</button>
        </div>

        <div x-show="tab === 'rules'">
            <livewire:manager.rules-editor />
        </div>
        <div x-show="tab === 'acknowledgements'" x-cloak>
            <livewire:manager.rules-acknowledgements />
        </div>
    </div>
</x-layouts.role-shell>
