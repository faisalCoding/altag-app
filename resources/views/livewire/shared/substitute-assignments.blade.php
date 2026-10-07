{{--
    Who stands in for which circle on a day: granted here, or by naming the
    substitute of an absent teacher on the roll call. The supervisor's and the
    manager's alike; only the circles they reach differ.
--}}
@php
    $stagesOf = fn ($teacher) => $teacher?->circles->pluck('stage.name')->filter()->unique()->implode('، ') ?? '';
@endphp

<div class="space-y-6">
    <div class="flex items-center gap-3">
        <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
            <flux:icon icon="arrows-right-left" />
        </div>
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">صلاحيات البدلاء</flux:heading>
            <flux:subheading>امنح معلماً من أي مرحلة صلاحية العمل في حلقة يوماً واحداً، كمعلمها ولتاريخ ذلك اليوم فقط.</flux:subheading>
        </div>
    </div>

    {{-- ─────────── اليوم والمنح ─────────── --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-4 space-y-4">
        <div class="w-full sm:w-56">
            <livewire:manager.hijri-datepicker wire:model.live="date" label="اليوم" />
        </div>

        @if ($open)
            <form wire:submit="grant" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                <flux:select wire:model.live="circleId" label="الحلقة">
                    <flux:select.option value="">اختر الحلقة</flux:select.option>
                    @foreach ($circles as $circle)
                        <flux:select.option :value="$circle->id">{{ $circle->name }}{{ $circle->stage ? ' — '.$circle->stage->name : '' }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="absentTeacherId" label="عن المعلم" :disabled="$circleTeachers->isEmpty()">
                    <flux:select.option value="">بلا تحديد</flux:select.option>
                    @foreach ($circleTeachers as $circleTeacher)
                        <flux:select.option :value="$circleTeacher->id">{{ $circleTeacher->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="teacherId" label="المعلم البديل" :disabled="! $circleId">
                    <flux:select.option value="">اختر المعلم</flux:select.option>
                    @foreach ($candidates as $candidate)
                        <flux:select.option :value="$candidate->id">{{ $candidate->name }}{{ $stagesOf($candidate) !== '' ? ' — '.$stagesOf($candidate) : '' }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:button type="submit" variant="primary" icon="check">منح الصلاحية</flux:button>
            </form>
        @else
            <p class="flex items-center gap-1.5 text-sm text-zinc-500 dark:text-zinc-400">
                <flux:icon icon="lock-closed" class="size-4" />
                مضى هذا اليوم، فما يلي سجلّ لمن غطّى الحلقات فيه.
            </p>
        @endif
    </div>

    {{-- ─────────── البدلاء في اليوم ─────────── --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <div class="px-5 py-4 border-b border-zinc-100 dark:border-zinc-800">
            <flux:heading size="sm">البدلاء — {{ $hijri }}</flux:heading>
        </div>

        @if ($grants->isEmpty())
            <p class="text-sm text-zinc-400 text-center py-10">لا بدلاء في هذا اليوم.</p>
        @else
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($grants as $grant)
                    <div wire:key="grant-{{ $grant->id }}" class="flex flex-col sm:flex-row sm:items-center gap-3 px-5 py-3">
                        <div class="flex-1 min-w-0 space-y-0.5">
                            <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100">
                                {{ $grant->teacher?->name }}
                                @if ($stagesOf($grant->teacher) !== '')
                                    <span class="font-normal text-xs text-zinc-400">({{ $stagesOf($grant->teacher) }})</span>
                                @endif
                            </div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                في حلقة {{ $grant->circle?->name }}{{ $grant->circle?->stage ? ' — '.$grant->circle->stage->name : '' }}
                                @if ($grant->absentTeacher)
                                    · عن {{ $grant->absentTeacher->name }}
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            @if ($grant->teacher_attendance_id)
                                <flux:badge size="sm" color="sky">من التحضير</flux:badge>
                            @endif
                            <span class="text-xs text-zinc-400">
                                {{ $grant->granted_by_role === 'manager' ? 'المدير' : 'المشرف' }} {{ $grant->grantedBy?->name }}
                            </span>
                            @if ($open)
                                <flux:button size="sm" variant="ghost" icon="x-mark" class="text-red-500 hover:text-red-600"
                                    wire:click="revoke({{ $grant->id }})" wire:confirm="سحب صلاحية {{ $grant->teacher?->name }} في هذه الحلقة؟">سحب</flux:button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
