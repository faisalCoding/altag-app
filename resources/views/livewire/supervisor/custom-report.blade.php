@php
    $selectedCircleIds = array_map('strval', $circleIds);
    $selectedStageIds = array_map('strval', $stageIds);
    $selectionCount = count($selectedCircleIds) + count($selectedStageIds);
@endphp

<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">تقارير مخصصة</flux:heading>
            <flux:subheading>
                اجمع أكثر من حلقة أو مرحلة في تقرير إنجاز واحد ·
                <x-hijri-date :date="$from" /> إلى <x-hijri-date :date="$to" />
            </flux:subheading>
        </div>

        @if($hasSelection)
            <flux:badge color="indigo" size="sm" class="shrink-0">
                {{ $students->count() }} طالب · {{ $selectionCount }} تحديد
            </flux:badge>
        @endif
    </div>

    {{-- Builder --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-4 space-y-5">
        {{-- Period --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <flux:select label="الفترة" wire:model.live="preset">
                @foreach($presets as $key => $label)
                    <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            @if($preset === 'custom')
                <div class="grid grid-cols-2 gap-2 md:col-span-2">
                    <livewire:shared.hijri-datepicker wire:model.live="fromDate" label="من (هجري)" />
                    <livewire:shared.hijri-datepicker wire:model.live="toDate" label="إلى (هجري)" />
                </div>
            @endif
        </div>

        <flux:separator />

        {{-- Stage + circle pickers --}}
        <div class="space-y-4">
            <div class="flex items-center justify-between gap-3">
                <span class="text-sm font-bold text-zinc-700 dark:text-zinc-200">اختر المراحل والحلقات</span>
                @if($selectionCount > 0)
                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="clearSelection">مسح التحديد</flux:button>
                @endif
            </div>

            @forelse($stages as $stage)
                @php $stageSelected = in_array((string) $stage->id, $selectedStageIds, true); @endphp
                <div class="rounded-xl border border-zinc-100 dark:border-zinc-800 overflow-hidden">
                    {{-- Whole stage --}}
                    <button type="button" wire:click="toggleStage({{ $stage->id }})"
                        class="w-full flex items-center justify-between gap-3 px-4 py-3 text-right transition-colors {{ $stageSelected ? 'bg-indigo-50 dark:bg-indigo-500/10' : 'bg-zinc-50/70 dark:bg-zinc-900/40 hover:bg-zinc-100 dark:hover:bg-zinc-800/60' }}">
                        <span class="flex items-center gap-2.5 min-w-0">
                            <span class="size-4 rounded border flex items-center justify-center shrink-0 {{ $stageSelected ? 'bg-indigo-600 border-indigo-600' : 'border-zinc-300 dark:border-zinc-600' }}">
                                @if($stageSelected)
                                    <flux:icon.check class="size-3 text-white" />
                                @endif
                            </span>
                            <span class="font-bold text-sm text-zinc-800 dark:text-zinc-100 truncate">مرحلة {{ $stage->name }}</span>
                            <span class="text-xs text-zinc-400">({{ $stage->circles->count() }} حلقة)</span>
                        </span>
                        @if($stageSelected)
                            <flux:badge size="sm" variant="subtle" color="indigo">المرحلة كاملة</flux:badge>
                        @endif
                    </button>

                    {{-- Individual circles --}}
                    @if($stage->circles->isNotEmpty())
                        <div class="flex flex-wrap gap-2 p-3 border-t border-zinc-100 dark:border-zinc-800">
                            @foreach($stage->circles as $circle)
                                @php
                                    $circleSelected = in_array((string) $circle->id, $selectedCircleIds, true);
                                    // A whole-stage pick already covers its circles.
                                    $coveredByStage = $stageSelected && ! $circleSelected;
                                @endphp
                                <button type="button" wire:click="toggleCircle({{ $circle->id }})"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border text-xs font-semibold transition
                                        {{ $circleSelected
                                            ? 'bg-indigo-600 border-indigo-600 text-white'
                                            : ($coveredByStage
                                                ? 'border-indigo-200 dark:border-indigo-500/30 text-indigo-400 dark:text-indigo-500/70 bg-white dark:bg-zinc-900'
                                                : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-300 bg-white dark:bg-zinc-900 hover:bg-zinc-50 dark:hover:bg-zinc-800') }}">
                                    {{ $circle->name }}
                                    @if($coveredByStage)
                                        <span class="text-[10px]">مشمولة</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div class="text-sm text-zinc-500 text-center py-6">لا توجد مراحل ضمن إشرافك.</div>
            @endforelse
        </div>
    </div>

    {{-- Report body --}}
    @if($hasSelection)
        <x-reports.circle-summary :report="$report" :show-circle-column="true" />
    @else
        <flux:card class="py-12 text-center text-zinc-500">
            <div class="flex flex-col items-center justify-center gap-3">
                <div class="p-3 bg-zinc-50 dark:bg-zinc-950 text-zinc-400 rounded-full">
                    <flux:icon.chart-bar class="size-7" />
                </div>
                <span>اختر مرحلة أو حلقة واحدة على الأقل لعرض التقرير المجمّع.</span>
            </div>
        </flux:card>
    @endif
</div>
