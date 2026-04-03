<x-layouts.role-shell>
    <x-slot:title>
        {{ __('التحليل الذكي') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>

    <div class="p-6 md:p-8 space-y-8" dir="rtl">
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">
                {{ __('نظام التحليل الذكي للبيانات') }}
            </flux:heading>
            <flux:subheading class="text-zinc-500 dark:text-zinc-400">
                {{ __('استعرض الأنماط والملاحظات المستخرجة بواسطة المساعد الذكي') }}
            </flux:subheading>
        </div>
    
        <livewire:manager.ai-analysis />
    </div>
</x-layouts.role-shell>
