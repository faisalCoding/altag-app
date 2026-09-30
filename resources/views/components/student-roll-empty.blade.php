@props(['search' => ''])

<div class="py-12 text-center space-y-1">
    <flux:icon icon="user-group" class="size-8 mx-auto text-zinc-300 dark:text-zinc-700" />
    <div class="text-sm text-zinc-500 dark:text-zinc-400">
        {{ $search !== '' ? __('لا طالب بهذا الاسم.') : __('لا طلاب في حلقتك بعد.') }}
    </div>
    @if ($search === '')
        <div class="text-xs text-zinc-400">{{ __('اكتب أسماءهم في الأعلى — سطراً لكل اسم.') }}</div>
    @endif
</div>
