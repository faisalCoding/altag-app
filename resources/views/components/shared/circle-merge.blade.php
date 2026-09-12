@props(['circles', 'source' => null, 'preview' => null, 'merges' => null])

{{-- The merge dialogue and the trail of merges that can still be undone. --}}

@if ($merges && $merges->isNotEmpty())
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <div class="px-4 py-3 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/70 dark:bg-zinc-800/40">
            <span class="text-sm font-bold text-zinc-700 dark:text-zinc-200">عمليات دمج يمكن التراجع عنها</span>
        </div>
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach ($merges as $merge)
                <div wire:key="merge-run-{{ $merge->id }}" class="flex items-center justify-between gap-3 px-4 py-2.5">
                    <div class="min-w-0">
                        <div class="text-sm text-zinc-800 dark:text-zinc-100 truncate">{{ $merge->name }}</div>
                        <div class="text-xs text-zinc-400">
                            {{ $merge->items_count }} طالب · {{ $merge->applied_at?->diffForHumans() }}
                        </div>
                    </div>
                    <flux:button size="sm" variant="ghost" wire:click="revertMerge({{ $merge->id }})"
                        wire:confirm="إعادة {{ $merge->items_count }} طالباً إلى حلقتهم السابقة؟">تراجع</flux:button>
                </div>
            @endforeach
        </div>
    </div>
@endif

<flux:modal name="merge-circle-modal" class="min-w-[28rem]">
    <form wire:submit="mergeCircles" class="space-y-4">
        <div>
            <flux:heading size="lg">دمج حلقة</flux:heading>
            <flux:subheading>
                @if ($source)
                    سيُنقل كل طلاب «<span class="font-bold">{{ $source->name }}</span>» إلى الحلقة التي تختارها.
                @else
                    اختر الحلقة التي ستستقبل الطلاب.
                @endif
            </flux:subheading>
        </div>

        <flux:select wire:model="mergeTargetId" label="تُدمج في">
            <flux:select.option value="">— اختر حلقة —</flux:select.option>
            @foreach ($circles as $circle)
                @if (! $source || $circle->id !== $source->id)
                    <flux:select.option :value="$circle->id">{{ $circle->name }}</flux:select.option>
                @endif
            @endforeach
        </flux:select>

        <flux:error name="mergeTargetId" />

        @if ($preview)
            <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300 px-4 py-3 text-sm leading-relaxed">
                <div class="flex items-start gap-2">
                    <flux:icon icon="exclamation-triangle" class="size-4 mt-0.5 shrink-0" />
                    <div>
                        <div>سيُنقل <strong>{{ $preview['students'] }}</strong> طالباً.</div>
                        <div class="mt-1">
                            تبقى الحلقة الأصلية قائمة وفارغة — فيها
                            <strong>{{ $preview['attendances'] }}</strong> سجل حضور
                            @if ($preview['competitions'] > 0)
                                و<strong>{{ $preview['competitions'] }}</strong> مسابقة
                            @endif
                            ولا يجوز حذفها، فحذفها يمحو هذا التاريخ.
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="flex justify-end gap-2">
            <flux:button type="button" variant="ghost" x-on:click="$flux.modal('merge-circle-modal').close()">إلغاء</flux:button>
            <flux:button type="submit" variant="primary">دمج</flux:button>
        </div>
    </form>
</flux:modal>
