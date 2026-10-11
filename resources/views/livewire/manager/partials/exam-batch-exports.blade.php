{{-- A batch's names: a sheet to print for the day, or a CSV for Excel. --}}
<div class="flex items-center gap-1" data-batch-exports>
    <flux:button size="sm" variant="ghost" icon="printer" :href="route('manager.exam-batches.print', $batch)" target="_blank">{{ __('طباعة الأسماء') }}</flux:button>
    <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('manager.exam-batches.csv', $batch)">{{ __('تصدير CSV') }}</flux:button>
</div>
