<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                <flux:icon icon="arrows-right-left" />
            </div>
            <div>
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">ترحيل الطلاب</flux:heading>
                <flux:subheading>نقل الطلاب من حلقاتهم إلى حلقات السنة التالية</flux:subheading>
            </div>
        </div>

        <div class="flex items-center gap-2">
            @if ($runs->isNotEmpty())
                <flux:select wire:model.live="selectedRunId" class="w-48">
                    @foreach ($runs as $option)
                        <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:button x-on:click="$flux.modal('new-run-modal').show()" variant="primary" icon="plus"
                class="bg-white text-maroon border-zinc-200 hover:bg-zinc-50 shadow-xs dark:bg-zinc-800 dark:text-white dark:border-zinc-700">
                ترحيل سنة جديدة
            </flux:button>
        </div>
    </div>

    @if (! $run)
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-16 text-center">
            <flux:icon icon="arrows-right-left" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
            <flux:heading size="lg" class="text-zinc-500 dark:text-zinc-400">لا توجد عمليات ترحيل بعد</flux:heading>
            <flux:subheading class="text-zinc-400 dark:text-zinc-500">
                ابدأ عملية جديدة، فيقترح النظام وجهة كل طالب من سلّم الترحيل — ولا يتحرك أحد حتى تضغط «تطبيق».
            </flux:subheading>
        </div>
    @else
        {{-- ─────────── حالة العملية ─────────── --}}
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                @if ($run->isDraft())
                    <flux:badge color="amber">مسوّدة</flux:badge>
                @elseif ($run->isApplied())
                    <flux:badge color="emerald">مطبَّقة</flux:badge>
                @else
                    <flux:badge color="zinc">ملغاة</flux:badge>
                @endif

                <div class="text-sm text-zinc-600 dark:text-zinc-300">
                    <span class="font-bold">{{ $run->name }}</span>
                    <span class="text-zinc-400">· {{ $run->items_count ?? $run->items()->count() }} طالب</span>
                    @if ($run->applied_at)
                        <span class="text-zinc-400">· طُبِّقت {{ $run->applied_at->diffForHumans() }}</span>
                    @endif
                </div>
            </div>

            <div class="flex items-center gap-2">
                @if ($run->isDraft())
                    <flux:button size="sm" variant="ghost" wire:click="deleteRun"
                        wire:confirm="حذف هذه المسوّدة؟ لم يتحرك أي طالب بعد.">حذف المسوّدة</flux:button>
                    <flux:button size="sm" variant="primary" icon="check"
                        x-on:click="$flux.modal('apply-modal').show()">تطبيق الترحيل</flux:button>
                @elseif ($run->isApplied())
                    <flux:button size="sm" variant="danger" icon="arrow-uturn-right"
                        x-on:click="$flux.modal('revert-modal').show()">تراجع عن الترحيل</flux:button>
                @endif
            </div>
        </div>

        @if ($run->isDraft())
            {{-- ─────────── ما ينبغي أن يراه قبل التطبيق ─────────── --}}
            @if ($competitions->isNotEmpty())
                <div class="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300 px-4 py-3 text-sm">
                    <flux:icon icon="exclamation-triangle" class="size-4 mt-0.5 shrink-0" />
                    <span>
                        هناك {{ $competitions->count() }} مسابقة نشطة الآن
                        ({{ $competitions->pluck('name')->take(3)->implode('، ') }}).
                        المسابقة مرتبطة بالحلقة، فالترحيل ينقل الطلاب بينها في منتصف الموسم. الأفضل أن تنتظر انتهاءها.
                    </span>
                </div>
            @endif

            @if ($orphans->isNotEmpty())
                <div class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300 px-4 py-3 text-sm">
                    <flux:icon icon="exclamation-triangle" class="size-4 mt-0.5 shrink-0" />
                    <span>{{ $orphans->count() }} طالباً نشطاً بلا حلقة، فلم يدخلوا هذه العملية. عيّن لهم حلقات من صفحة الطلاب.</span>
                </div>
            @endif

            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-900/40 p-4 text-sm text-zinc-600 dark:text-zinc-300 leading-relaxed">
                هذه <strong>مسوّدة</strong> — لم يتحرك أي طالب. الاقتراحات من سلّم الترحيل، وتستطيع تغيير مصير أي طالب
                قبل التطبيق. وبعد التطبيق يبقى التراجع متاحاً.
            </div>
        @endif

        {{-- ─────────── الطلاب مجمَّعين بحلقاتهم ─────────── --}}
        @forelse ($groups as $fromCircleId => $items)
            <div wire:key="promo-group-{{ $fromCircleId ?: 'none' }}"
                class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
                <div class="px-4 py-3 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/70 dark:bg-zinc-800/40 flex items-center justify-between gap-3">
                    <div class="font-bold text-zinc-900 dark:text-white truncate">
                        {{ $items->first()->fromCircle?->name ?? 'بلا حلقة' }}
                    </div>
                    <span class="text-xs text-zinc-400 shrink-0">{{ $items->count() }} طالب</span>
                </div>

                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($items as $item)
                        <div wire:key="promo-item-{{ $item->id }}"
                            class="flex flex-col sm:flex-row sm:items-center gap-3 px-4 py-2.5">
                            <div class="min-w-0 flex-1 text-sm font-medium text-zinc-800 dark:text-zinc-100 truncate">
                                {{ $item->student?->name ?? '—' }}
                            </div>

                            @if ($run->isDraft())
                                <div class="flex items-center gap-2 shrink-0">
                                    <div class="w-44">
                                        <flux:select size="sm" wire:change="setDestination({{ $item->id }}, $event.target.value)">
                                            <flux:select.option value="">— يبقى مكانه —</flux:select.option>
                                            @foreach ($circles as $circle)
                                                <flux:select.option :value="$circle->id"
                                                    :selected="$item->to_circle_id === $circle->id">{{ $circle->name }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    </div>

                                    <flux:button size="sm"
                                        variant="{{ $item->action === 'graduate' ? 'primary' : 'ghost' }}"
                                        wire:click="setAction({{ $item->id }}, '{{ $item->action === 'graduate' ? 'hold' : 'graduate' }}')">
                                        تخرّج
                                    </flux:button>
                                </div>
                            @else
                                <div class="text-xs text-zinc-500 dark:text-zinc-400 shrink-0">
                                    @if ($item->action === 'promote')
                                        <span class="inline-flex items-center gap-1">
                                            <flux:icon icon="arrow-left" class="size-3" />
                                            {{ $item->toCircle?->name }}
                                        </span>
                                    @elseif ($item->action === 'graduate')
                                        <flux:badge size="sm" color="emerald">تخرّج</flux:badge>
                                    @else
                                        <span class="text-zinc-400">بقي مكانه</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-10 text-center text-sm text-zinc-400">
                لا طلاب في هذه العملية.
            </div>
        @endforelse
    @endif

    {{-- ─────────── النوافذ ─────────── --}}
    <flux:modal name="new-run-modal" class="min-w-[26rem]">
        <form wire:submit="createRun" class="space-y-4">
            <div>
                <flux:heading size="lg">ترحيل سنة جديدة</flux:heading>
                <flux:subheading>سيقترح النظام وجهة كل طالب نشط من السلّم. لن يتحرك أحد قبل التطبيق.</flux:subheading>
            </div>

            <flux:input wire:model="newRunName" label="اسم العملية" placeholder="١٤٤٨/١٤٤٩" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" x-on:click="$flux.modal('new-run-modal').close()">إلغاء</flux:button>
                <flux:button type="submit" variant="primary">إنشاء المسوّدة</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="apply-modal" class="min-w-[26rem]">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">تطبيق الترحيل</flux:heading>
                <flux:subheading>
                    سيُنقل كل طالب إلى وجهته المعروضة، ويُسجَّل المتخرّجون كمغادرين.
                    يبقى التراجع متاحاً بعد التطبيق.
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" x-on:click="$flux.modal('apply-modal').close()">إلغاء</flux:button>
                <flux:button variant="primary" wire:click="apply">تطبيق</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="revert-modal" class="min-w-[26rem]">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">تراجع عن الترحيل</flux:heading>
                <flux:subheading>
                    سيعود كل طالب إلى حلقته وحالته قبل هذه العملية. اكتب
                    «<span class="font-bold">{{ $run?->name }}</span>» للتأكيد.
                </flux:subheading>
            </div>

            <flux:input wire:model="confirmRevertInput" placeholder="{{ $run?->name }}" />

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" x-on:click="$flux.modal('revert-modal').close()">إلغاء</flux:button>
                <flux:button variant="danger" wire:click="revert">تراجع</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
