@props([
    'count' => 0,
    'outside' => 0,
    'text' => '',
    'noun' => 'شخص',
    'copied' => 'نُسخت الأسماء والروابط',
])
{{--
    The bar that appears once something is selected, shared by the four
    directories so a manager meets the same thing in each tab.
--}}
@if ($count > 0)
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 rounded-2xl border border-indigo-100 bg-indigo-50 dark:border-indigo-900/50 dark:bg-indigo-950/30">
        <div class="flex items-center gap-2">
            <span class="flex size-2 rounded-full bg-indigo-600 dark:bg-indigo-400"></span>
            <div class="flex flex-col">
                <span class="text-sm font-medium text-indigo-900 dark:text-indigo-200">
                    تم تحديد {{ App\Support\HijriDate::arabicDigits($count) }} {{ $noun }}
                </span>
                @if ($outside > 0)
                    <span class="text-xs text-amber-700 dark:text-amber-400">
                        منهم {{ App\Support\HijriDate::arabicDigits($outside) }} خارج نتائج البحث الحالية
                    </span>
                @endif
            </div>
            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="resetSelection">
                إلغاء التحديد
            </flux:button>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button size="sm" variant="filled" icon="clipboard-document-list"
                x-on:click="navigator.clipboard.writeText(@js($text)); $dispatch('toast', { message: @js($copied), variant: 'success' })">
                نسخ الأسماء والروابط
            </flux:button>

            {{-- A list that leaked can be killed in one act rather than one
                 account at a time. --}}
            <flux:button size="sm" variant="ghost" icon="arrow-path" class="text-red-500 hover:text-red-600"
                wire:click="regenerateSelected"
                wire:confirm="إعادة إنشاء روابط المحدَّدين؟ تبطل روابطهم الحالية فوراً.">
                إعادة إنشاء الروابط
            </flux:button>
        </div>
    </div>
@endif
