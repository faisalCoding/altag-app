<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                <flux:icon icon="clipboard-document-check" />
            </div>
            <div>
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">تحضير المعلمين</flux:heading>
                <flux:subheading>{{ $hijri }}</flux:subheading>
            </div>
        </div>

        <div class="flex items-center gap-2">
            @if ($markedCount < $teachers->count())
                <flux:button size="sm" icon="check-circle" wire:click="markRemainingPresent">تحضير الباقين</flux:button>
            @endif

            @if ($markedCount > 0)
                <flux:button size="sm" variant="ghost" icon="trash" class="text-red-500 hover:text-red-600"
                    wire:click="clearDay" wire:confirm="حذف تحضير هذا اليوم كاملاً؟">حذف التحضير</flux:button>
            @endif
        </div>
    </div>

    {{-- ─────────── اليوم والتصفية ─────────── --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-4">
        <div class="flex flex-col md:flex-row gap-4 items-end">
            <div class="w-full md:w-56">
                <livewire:manager.hijri-datepicker wire:model.live="date" label="اليوم" />
            </div>

            <div class="w-full md:w-56">
                <flux:select wire:model.live="circleFilter" label="الحلقة">
                    <flux:select.option value="">كل الحلقات</flux:select.option>
                    @foreach ($circles as $circle)
                        <flux:select.option :value="$circle->id">{{ $circle->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex-1">
                <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" label="بحث"
                    placeholder="اسم المعلم..." />
            </div>
        </div>

        @if ($teachers->isNotEmpty())
            <div class="mt-4">
                <div class="flex items-center justify-between text-sm text-zinc-500 dark:text-zinc-400 mb-1.5">
                    <span>حُضِّر <span class="font-bold">{{ $markedCount }}</span> من {{ $teachers->count() }}</span>
                    @if ($markedCount === $teachers->count())
                        <span class="text-green-600 dark:text-green-400 font-medium flex items-center gap-1">
                            <flux:icon icon="check-circle" class="size-4" />
                            مكتمل
                        </span>
                    @endif
                </div>
                <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-2 overflow-hidden">
                    <div class="h-2 rounded-full duration-500 ease-out {{ $markedCount === $teachers->count() ? 'bg-green-500' : 'bg-maroon' }}"
                        style="width: {{ $teachers->count() > 0 ? ($markedCount / $teachers->count()) * 100 : 0 }}%"></div>
                </div>
            </div>
        @endif
    </div>

    {{-- ─────────── المعلمون ─────────── --}}
    @php
        $options = [
            'present' => ['حاضر', 'emerald'],
            'late' => ['متأخر', 'amber'],
            'excused' => ['مستأذن', 'blue'],
            'absent' => ['غائب', 'rose'],
        ];
    @endphp

    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($teachers as $teacher)
                @php $status = $records[$teacher->id] ?? null; @endphp
                <div wire:key="teacher-attendance-{{ $teacher->id }}"
                    class="flex flex-col sm:flex-row sm:items-center gap-3 px-4 py-3">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <span class="size-9 shrink-0 rounded-full flex items-center justify-center font-bold text-[11px]"
                            style="{{ $teacher->avatarStyle() }}">{{ $teacher->initials() }}</span>
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-zinc-800 dark:text-zinc-100 truncate">{{ $teacher->name }}</div>
                            <div class="text-xs text-zinc-400 truncate">{{ $teacher->circles->pluck('name')->implode('، ') ?: 'بلا حلقة' }}</div>
                        </div>
                    </div>

                    <div class="flex items-center gap-1 shrink-0">
                        @foreach ($options as $value => [$label, $colour])
                            <flux:button size="sm"
                                variant="{{ $status === $value ? 'primary' : 'ghost' }}"
                                wire:click="mark({{ $teacher->id }}, '{{ $value }}')">{{ $label }}</flux:button>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="p-16 text-center">
                    <flux:icon icon="users" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
                    <flux:heading size="lg" class="text-zinc-500 dark:text-zinc-400">لا معلمين</flux:heading>
                    <flux:subheading class="text-zinc-400 dark:text-zinc-500">
                        لا يوجد معلمون في حلقات مراحلك مطابقون لهذه التصفية.
                    </flux:subheading>
                </div>
            @endforelse
        </div>
    </div>
</div>
