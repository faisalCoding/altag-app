<div class="space-y-5">
    {{-- Header --}}
    <div class="flex items-center justify-between gap-4">
        <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">{{ __('المسابقات') }}</flux:heading>
        <flux:button wire:click="create" variant="primary" icon="plus"
            class="bg-amber-500 hover:bg-amber-600 border-none text-amber-950">
            {{ __('مسابقة جديدة') }}
        </flux:button>
    </div>

    {{-- Supervisor Competitions (read-only: grade & report) --}}
    @if (count($supervisorCompetitions) > 0)
        <div class="space-y-3">
            <div class="flex items-center gap-2">
                <flux:icon icon="shield-check" class="size-4 text-indigo-500" />
                <flux:heading size="md" class="text-indigo-700 dark:text-indigo-400">{{ count($substituteCircleIds) > 0 ? __('مسابقات المشرف والحلقات التي تنوب فيها') : __('مسابقات المشرف') }}</flux:heading>
                <flux:badge size="sm" color="indigo">{{ count($supervisorCompetitions) }}</flux:badge>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 items-start">
                @foreach ($supervisorCompetitions as $comp)
                    <div class="flex flex-col rounded-2xl border {{ $comp->is_active ? 'border-indigo-200 dark:border-indigo-800/70' : 'border-zinc-200 dark:border-zinc-700/60' }} bg-white dark:bg-zinc-900 shadow-sm hover:shadow-md hover:border-indigo-300 dark:hover:border-indigo-700 transition-all duration-200 overflow-hidden">
                        <div class="p-4 pb-3 space-y-2">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold {{ $comp->is_active ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                    <span class="size-1.5 rounded-full {{ $comp->is_active ? 'bg-emerald-500' : 'bg-zinc-400' }}"></span>
                                    {{ $comp->is_active ? __('نشطة') : __('مغلقة') }}
                                </span>
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold bg-indigo-50 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-400">
                                    <flux:icon icon="{{ $comp->supervisor_id ? 'shield-check' : 'arrows-right-left' }}" class="size-3" />
                                    {{ $comp->supervisor_id ? __('مشرف') : __('تنوب فيها اليوم') }}
                                </span>
                            </div>
                            <flux:heading size="lg" class="truncate text-zinc-900 dark:text-zinc-100">{{ $comp->title }}</flux:heading>
                            <div class="flex items-center gap-1.5 text-xs text-zinc-500">
                                <flux:icon icon="calendar" class="size-3.5 shrink-0" />
                                <span dir="ltr"><x-hijri-date :date="$comp->start_date" />@if ($comp->end_date) – <x-hijri-date :date="$comp->end_date" />@endif</span>
                            </div>
                        </div>

                        <div class="mt-auto border-t border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-800/20 p-4 flex gap-2">
                            <flux:button href="{{ route('teacher.leaderboards.grade', $comp->id) }}" variant="primary"
                                size="sm" icon="clipboard-document-check"
                                class="flex-1 bg-indigo-500 hover:bg-indigo-600 border-none text-white">
                                {{ __('رصد النقاط') }}
                            </flux:button>
                            <flux:button href="{{ route('teacher.leaderboards.report', $comp->id) }}" variant="ghost"
                                size="sm" icon="chart-bar" title="{{ __('التقرير') }}"
                                class="border border-indigo-200 text-indigo-600 dark:border-indigo-900/50 dark:text-indigo-400" />
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <flux:separator />
    @endif

    {{-- Teacher's Own Leaderboards --}}
    @if (count($leaderboards) > 0)
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 items-start">
            @foreach ($leaderboards as $board)
                <div class="flex flex-col rounded-2xl border border-zinc-200 dark:border-zinc-700/60 bg-white dark:bg-zinc-900 shadow-sm hover:shadow-md hover:border-zinc-300 dark:hover:border-zinc-600 transition-all duration-200 overflow-hidden">

                    {{-- Card head --}}
                    <div class="flex items-start justify-between gap-2 p-4 pb-3">
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold {{ $board->is_active ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                <span class="size-1.5 rounded-full {{ $board->is_active ? 'bg-emerald-500' : 'bg-zinc-400' }}"></span>
                                {{ $board->is_active ? __('نشطة') : __('مغلقة') }}
                            </span>
                            <flux:heading size="lg" class="truncate text-zinc-900 dark:text-zinc-100">{{ $board->title }}</flux:heading>
                            <div class="flex items-center gap-1.5 text-xs text-zinc-500">
                                <flux:icon icon="calendar" class="size-3.5 shrink-0" />
                                <span dir="ltr"><x-hijri-date :date="$board->start_date" />@if ($board->end_date) – <x-hijri-date :date="$board->end_date" />@endif</span>
                            </div>
                        </div>
                        <flux:dropdown position="bottom" align="end">
                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal"
                                class="shrink-0 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" />
                            <flux:menu>
                                <flux:menu.item wire:click="edit({{ $board->id }})" icon="pencil-square">{{ __('تعديل') }}</flux:menu.item>
                                <flux:menu.item href="{{ route('teacher.leaderboards.grade', $board->id) }}"
                                    icon="clipboard-document-check" target="_blank">{{ __('رصد النقاط اليدوية') }}</flux:menu.item>
                                <flux:menu.separator />
                                <flux:menu.item wire:click="deleteLeaderboard({{ $board->id }})" icon="trash"
                                    class="text-rose-500 hover:text-rose-600">{{ __('حذف') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>

                    {{-- Footer --}}
                    <div class="mt-auto border-t border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-800/20 p-4 space-y-3">
                        <div class="flex items-center justify-between text-sm">
                            <span class="flex items-center gap-1.5 text-zinc-500 dark:text-zinc-400">
                                <flux:icon icon="star" class="size-4 text-emerald-500" />
                                {{ __('بنود التقييم') }}
                            </span>
                            <span class="font-bold text-zinc-900 dark:text-zinc-100">{{ $board->criteria_count }}</span>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <flux:button wire:click="toggleActive({{ $board->id }})" variant="ghost" size="sm"
                                :icon="$board->is_active ? 'pause-circle' : 'play-circle'"
                                class="w-full border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                                {{ $board->is_active ? __('إيقاف') : __('تنشيط') }}
                            </flux:button>
                            <flux:button wire:click="toggleActiveForGrading({{ $board->id }})" variant="ghost" size="sm"
                                icon="star"
                                title="{{ $board->is_active_for_grading ? __('المسابقة الأساسية للتسجيل') : __('تعيين كأساسية للتسجيل') }}"
                                class="w-full border {{ $board->is_active_for_grading ? 'bg-amber-50 dark:bg-amber-900/20 text-amber-600 border-amber-300 dark:border-amber-700' : 'border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900' }}">
                                {{ $board->is_active_for_grading ? __('أساسية') : __('تعيين') }}
                            </flux:button>
                        </div>

                        <div class="flex gap-2">
                            <flux:button href="{{ route('teacher.leaderboards.report', $board->id) }}" variant="ghost" size="sm"
                                icon="chart-bar" title="{{ __('التقرير') }}"
                                class="border border-emerald-200 text-emerald-600 hover:bg-emerald-50 dark:border-emerald-900/50 dark:text-emerald-400 dark:hover:bg-emerald-900/20" />
                            <flux:button href="{{ route('teacher.leaderboards.grade', $board->id) }}" variant="primary" size="sm"
                                icon="clipboard-document-check"
                                class="flex-1 bg-amber-500 hover:bg-amber-600 border-none text-amber-950">
                                {{ __('رصد النقاط') }}
                            </flux:button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-12 bg-zinc-50 dark:bg-zinc-800/20 rounded-xl border border-dashed border-zinc-200 dark:border-zinc-700">
            <div class="bg-amber-100 dark:bg-amber-900/30 text-amber-500 w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-3">
                <flux:icon icon="trophy" class="size-7" />
            </div>
            <flux:heading size="lg" class="mb-4">{{ __('لا توجد مسابقات') }}</flux:heading>
            <flux:button wire:click="create" variant="primary" icon="plus"
                class="bg-amber-500 hover:bg-amber-600 border-none text-white">
                {{ __('إنشاء أول مسابقة') }}
            </flux:button>
        </div>
    @endif

    <!-- Leaderboard Modal Form -->
    <flux:modal wire:model="showModal" class="md:w-[800px] w-full">
        <div class="space-y-5">
            <flux:heading size="lg">{{ $isEditing ? __('تعديل مسابقة') : __('مسابقة جديدة') }}</flux:heading>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Basic Info -->
                <div class="space-y-4">
                    <flux:input wire:model="title" label="{{ __('اسم المسابقة') }}"
                        placeholder="{{ __('مثال: نجوم التحفيظ لشهر شوال') }}" />

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <livewire:shared.hijri-datepicker wire:model="start_date" label="{{ __('تاريخ البداية') }}" />
                        <livewire:shared.hijri-datepicker wire:model="end_date" label="{{ __('تاريخ النهاية') }}" />
                    </div>

                    <flux:switch wire:model="is_active" label="{{ __('المسابقة نشطة') }}" />
                </div>

                <!-- Custom Criteria List -->
                <div
                    class="space-y-3 p-4 bg-zinc-50 dark:bg-zinc-800/40 rounded-xl border border-zinc-100 dark:border-zinc-700/50 max-h-[300px] overflow-y-auto">
                    <div class="flex items-center justify-between mb-2">
                        <flux:heading size="sm">{{ __('بنود التقييم') }}</flux:heading>
                        <flux:button wire:click="addCriterion" size="xs" variant="ghost" icon="plus"
                            class="text-emerald-600 bg-emerald-50 hover:bg-emerald-100 dark:text-emerald-400 dark:bg-emerald-900/30 dark:hover:bg-emerald-900/50">
                            {{ __('إضافة بند') }}
                        </flux:button>
                    </div>

                    @foreach ($criteria as $index => $criterion)
                        <div class="flex items-center gap-2" wire:key="criterion-{{ $index }}">
                            <div class="flex-1">
                                <flux:input wire:model="criteria.{{ $index }}.name"
                                    placeholder="{{ __('اسم البند مثال: الهدوء') }}" />
                            </div>
                            <div class="w-24">
                                <flux:input type="number" wire:model="criteria.{{ $index }}.points"
                                    placeholder="{{ __('النقاط') }}" />
                            </div>
                            <flux:button wire:click="removeCriterion({{ $index }})" variant="ghost" icon="trash"
                                class="text-rose-500 shrink-0 mt-8" />
                        </div>
                    @endforeach

                    @if (count($criteria) === 0)
                        <div class="text-center py-6 text-sm text-zinc-400">
                            {{ __('لم تقم بإضافة بنود يدوية بعد.') }}
                        </div>
                    @endif
                </div>
            </div>

            <flux:separator />

            <!-- Automated Settings -->
            <div>
                <flux:heading size="md" class="mb-3 flex items-center gap-2">
                    <flux:icon icon="cog-6-tooth" class="size-5 text-indigo-500" />
                    {{ __('النقاط التلقائية') }}
                </flux:heading>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Hifz -->
                    <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-700 space-y-3">
                        <flux:switch wire:model="hifz_enabled" label="{{ __('نقاط الحفظ') }}" />
                        <div x-show="$wire.hifz_enabled"
                            class="space-y-2 mt-2 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                            <flux:input type="number" size="sm" wire:model="hifz_excellent"
                                label="{{ __('تقييم ممتاز') }}" />
                            <flux:input type="number" size="sm" wire:model="hifz_good" label="{{ __('تقييم جيد') }}" />
                            <flux:input type="number" size="sm" wire:model="hifz_acceptable"
                                label="{{ __('تقييم مقبول') }}" />
                        </div>
                    </div>

                    <!-- Review -->
                    <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-700 space-y-3">
                        <flux:switch wire:model="review_enabled" label="{{ __('نقاط المراجعة') }}" />
                        <div x-show="$wire.review_enabled"
                            class="space-y-2 mt-2 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                            <flux:input type="number" size="sm" wire:model="review_excellent"
                                label="{{ __('تقييم ممتاز') }}" />
                            <flux:input type="number" size="sm" wire:model="review_good"
                                label="{{ __('تقييم جيد وجيد جدا') }}" />
                        </div>
                    </div>

                    <!-- Attendance -->
                    <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-700 space-y-3">
                        <flux:switch wire:model="attendance_enabled" label="{{ __('نقاط الحضور') }}" />
                        <div x-show="$wire.attendance_enabled"
                            class="space-y-2 mt-2 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                            <flux:input type="number" size="sm" wire:model="attendance_present"
                                label="{{ __('حاضر بوقت') }}" />
                            <flux:input type="number" size="sm" wire:model="attendance_late"
                                label="{{ __('حاضر متأخر') }}" />
                        </div>
                    </div>

                    <!-- Extra Points -->
                    <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-700 space-y-3">
                        <flux:switch wire:model="extra_points_enabled" label="{{ __('النقاط الإضافية اليدوية') }}" />
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-4">
                <flux:button wire:click="$set('showModal', false)" variant="ghost">{{ __('إلغاء') }}</flux:button>
                <flux:button wire:click="save" variant="primary"
                    class="bg-amber-500 hover:bg-amber-600 border-none text-white">{{ __('حفظ التغييرات') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
