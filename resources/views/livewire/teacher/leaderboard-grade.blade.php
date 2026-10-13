<div>
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <flux:button href="{{ route('teacher.leaderboards') }}" wire:navigate variant="ghost" size="sm"
                    icon="arrow-right" class="rtl:rotate-180" />
                <flux:heading size="xl">{{ __('الرصد اليدوي: ') }} <span
                        class="text-indigo-600 dark:text-indigo-400">{{ $leaderboard->title }}</span></flux:heading>
            </div>
            <flux:subheading class="ms-10">{{ __('قم بمنح النقاط للطلاب في البنود المخصصة بشكل يومي.') }}
            </flux:subheading>
        </div>

        <div
            class="w-full md:w-64 bg-white dark:bg-zinc-900 p-2 rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm">
            <livewire:teacher.hijri-datepicker wire:model.live="date" />
        </div>
    </div>

    @if($pendingBadges->isNotEmpty())
        <div class="mb-6 p-4 rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-950/10 flex items-center justify-between flex-wrap gap-4">
            <div class="flex items-center gap-3">
                <div class="size-10 rounded-xl bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center animate-bounce text-xl">
                    🏅
                </div>
                <div>
                    <h4 class="font-bold text-zinc-900 dark:text-white text-sm">أوسمة معلقة بانتظار الاعتماد!</h4>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">هناك {{ $pendingBadges->count() }} طلب(ات) منح أوسمة للطلاب بحاجة لموافقتك.</p>
                </div>
            </div>
            <flux:button x-on:click="$flux.modal('pending-badges-modal').show()" variant="primary" color="amber" size="sm" icon="check-badge">مراجعة واعتماد</flux:button>
        </div>
    @endif

    @if($leaderboard->criteria->isEmpty() && empty($leaderboard->settings['extra_points_enabled']))
        <div
            class="text-center py-12 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl border border-dashed border-zinc-200 dark:border-zinc-700">
            <flux:icon icon="clipboard-document" class="size-10 mx-auto text-zinc-400 mb-3" />
            <flux:heading size="md" class="mb-2">{{ __('لا توجد بنود مخصصة') }}</flux:heading>
            <p class="text-zinc-500">
                {{ __('لم تقم بإضافة بنود التقييم أو تفعيل النقاط الإضافية لهذه المسابقة. يمكنك إضافتها من إعدادات اللوحة.') }}
            </p>
        </div>
    @else
        <flux:card class="p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-right">
                    <thead class="bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                        <tr>
                            <th class="p-4 font-semibold text-zinc-800 dark:text-zinc-200 w-1/4">{{ __('اسم الطالب') }}</th>

                            <!-- Manual Criteria Headers -->
                            @foreach($leaderboard->criteria as $criterion)
                                <th class="p-4 font-semibold text-zinc-800 dark:text-zinc-200 text-center">
                                    <div class="flex flex-col items-center">
                                        <span class="flex items-center gap-1 justify-center">
                                            @if($criterion->is_enthusiasm_trigger)
                                                <span title="{{ __('مغذي للحماسة') }}">🔥</span>
                                            @endif
                                            <span>{{ $criterion->name }}</span>
                                        </span>
                                        <flux:badge color="emerald" size="sm" class="mt-1">{{ $criterion->points }}
                                            {{ __('نقاط') }}</flux:badge>
                                    </div>
                                </th>
                            @endforeach

                            <!-- Extra Points Header -->
                            @if(!empty($leaderboard->settings['extra_points_enabled']))
                                <th
                                    class="p-4 font-semibold text-amber-600 dark:text-amber-500 text-center border-r border-zinc-200 dark:border-zinc-700 bg-amber-50/50 dark:bg-amber-900/10">
                                    <div class="flex flex-col items-center">
                                        <flux:icon icon="star" class="size-5 mb-1" />
                                        <span>{{ __('نقاط إضافية') }}</span>
                                    </div>
                                </th>
                            @endif

                            <!-- Automated Points Headers -->
                            <th
                                class="p-2 font-semibold text-zinc-500 dark:text-zinc-400 text-center bg-zinc-100/50 dark:bg-zinc-800/30 border-r border-zinc-200 dark:border-zinc-700 w-16">
                                <div class="flex flex-col items-center gap-1">
                                    <flux:icon icon="book-open" variant="micro" class="size-4" />
                                    <span class="text-[10px]">{{ __('حفظ') }}</span>
                                </div>
                            </th>
                            <th
                                class="p-2 font-semibold text-zinc-500 dark:text-zinc-400 text-center bg-zinc-100/50 dark:bg-zinc-800/30 w-16">
                                <div class="flex flex-col items-center gap-1">
                                    <flux:icon icon="arrow-path" variant="micro" class="size-4" />
                                    <span class="text-[10px]">{{ __('مراجعة') }}</span>
                                </div>
                            </th>
                            <th
                                class="p-2 font-semibold text-zinc-500 dark:text-zinc-400 text-center bg-zinc-100/50 dark:bg-zinc-800/30 w-16">
                                <div class="flex flex-col items-center gap-1">
                                    <flux:icon icon="clock" variant="micro" class="size-4" />
                                    <span class="text-[10px]">{{ __('حضور') }}</span>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach($students as $student)
                                            @php
                                                $studentScoreIds = $scoresMap->get($student->id, []);
                                                $studentExtraPoints = $extraPointsMap->get($student->id, collect());
                                                $dailyTotal = $dailyScores[$student->id]['total'] ?? 0;
                                                $dailyAutomated = $dailyScores[$student->id]['automated'] ?? 0;
                                                $dailyManual = $dailyScores[$student->id]['manual'] ?? 0;
                                                // Add extra points to manual total for display in this view
                                                $dailyManual += $studentExtraPoints->sum('points');
                                                $dailyTotal += $studentExtraPoints->sum('points');
                                            @endphp
                             <tr
                                                class="even:bg-zinc-50/50 odd:bg-white dark:even:bg-zinc-800/20 dark:odd:bg-zinc-900/10 hover:!bg-zinc-100/80 dark:hover:!bg-zinc-800/50   s">
                                                <td class="p-4 font-medium text-zinc-900 dark:text-zinc-100 w-1/4">
                                                    <div class="flex items-center justify-between">
                                                        <div class="flex items-center gap-3">
                                                            <div
                                                                class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/50 flex items-center justify-center text-indigo-600 dark:text-indigo-400 font-bold text-xs">
                                                                {{ mb_substr($student->name, 0, 1) }}
                                                            </div>
                                                            <div class="flex flex-col">
                                                                <span>{{ $student->name }}</span>
                                                                @if($leaderboard->isSupervisorCompetition() && $student->circle)
                                                                    <span class="text-xs text-indigo-500 dark:text-indigo-400 font-normal">
                                                                        <flux:icon icon="circle-stack" variant="micro" class="size-3 inline" />
                                                                        {{ $student->circle->name }}
                                                                    </span>
                                                                @endif
                                                                @if($dailyAutomated > 0)
                                                                    <span class="text-xs text-emerald-600 dark:text-emerald-400 font-normal">
                                                                        <flux:icon icon="cpu-chip" variant="micro" class="size-3 inline" />
                                                                        {{ __('تلقائي اليوم:') }} {{ $dailyAutomated }}
                                                                    </span>
                                                                @endif
                                                            </div>
                                                        </div>
                                                        <div class="bg-indigo-50 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-400 text-xs px-2 py-1 rounded-md font-bold flex items-center gap-1"
                                                            title="{{ __('مجموع نقاط اليوم (التلقائية + اليدوية)') }}">
                                                            {{ $dailyTotal }}
                                                            <flux:icon icon="star" variant="solid" class="size-3" />
                                                        </div>
                                                    </div>
                                                </td>

                                                @foreach($leaderboard->criteria as $criterion)
                                                    @php
                                                        $hasScore = in_array($criterion->id, $studentScoreIds);
                                                    @endphp
                                                    <td class="p-4 text-center border-l border-zinc-50 dark:border-zinc-800/50">
                                                        {{--
                                                        wire:key includes the date so Alpine fully re-initializes
                                                        when the date changes, picking up the correct 'confirmed' value.
                                                        The cell asks for the state it wants and shows the state the
                                                        server answers with; the page drawn again carries that same
                                                        state into 'confirmed' first, so never flip it after the call.
                                                        --}}
                                                        <div wire:key="score-{{ $student->id }}-{{ $criterion->id }}-{{ $date }}" x-data="{
                                                                            confirmed: {{ $hasScore ? 'true' : 'false' }},
                                                                            pending: false,
                                                                            async toggle() {
                                                                                if (this.pending) return;
                                                                                this.pending = true;
                                                                                try {
                                                                                    const granted = await $wire.setScore({{ $student->id }}, {{ $criterion->id }}, !this.confirmed);
                                                                                    if (typeof granted === 'boolean') this.confirmed = granted;
                                                                                } finally {
                                                                                    this.pending = false;
                                                                                }
                                                                            }
                                                                        }">
                                                            <button @click="toggle()"
                                                                :title="confirmed ? '{{ __('إزالة النقطة') }}' : '{{ __('منح النقطة') }}'" :class="{
                                                                                'bg-emerald-500 border-emerald-500 text-white shadow-md shadow-emerald-500/20 scale-110': confirmed && !pending,
                                                                                'bg-zinc-200 border-zinc-300 text-zinc-700 dark:bg-white dark:border-white dark:text-zinc-900 shadow-md scale-105 animate-pulse': pending,
                                                                                'bg-white border-zinc-200 text-zinc-300 hover:border-emerald-200 hover:text-emerald-300 dark:bg-zinc-900 dark:border-zinc-700 dark:text-zinc-700 dark:hover:border-emerald-800 dark:hover:text-emerald-700': !confirmed && !pending
                                                                            }"
                                                                class="inline-flex w-10 h-10 items-center justify-center rounded-xl border-2   duration-200">
                                                                <flux:icon icon="check" variant="micro" class="size-6" />
                                                            </button>
                                                        </div>
                                                    </td>
                                                @endforeach

                                                <!-- Extra Points Cell (Alpine.js inline form) -->
                                                @if(!empty($leaderboard->settings['extra_points_enabled']))
                                                    <td
                                                        class="p-2 text-center border-x border-zinc-100 dark:border-zinc-800 bg-amber-50/30 dark:bg-amber-900/5">
                                                        <div x-data="{
                                                                            open: false,
                                                                            amount: 1,
                                                                            notes: '',
                                                                            saving: false,
                                                                            async save() {
                                                                                if (this.saving || !this.amount || !this.notes.trim()) return;
                                                                                this.saving = true;
                                                                                try {
                                                                                    await $wire.saveExtraPoints({{ $student->id }}, this.amount, this.notes);
                                                                                    this.amount = 1;
                                                                                    this.notes = '';
                                                                                    this.open = false;
                                                                                } finally {
                                                                                    this.saving = false;
                                                                                }
                                                                            }
                                                                        }" class="flex flex-col gap-2 items-center">
                                                            {{-- Existing extra points list --}}
                                                            @foreach($studentExtraPoints as $ep)
                                                                <div x-data="{ deleting: false }"
                                                                    class="group relative flex items-center justify-between w-full max-w-[120px] rounded-lg p-1.5 border shadow-sm text-xs   duration-300"
                                                                    :class="deleting
                                                                                        ? 'opacity-40 scale-95 bg-red-50 border-red-200 dark:bg-red-900/20 dark:border-red-700'
                                                                                        : 'bg-white dark:bg-zinc-800 border-amber-200 dark:border-amber-700'"
                                                                    title="{{ $ep->notes }}">
                                                                    <span
                                                                        class="font-bold text-amber-600 dark:text-amber-400 px-1">+{{ $ep->points }}</span>
                                                                    <span class="truncate max-w-[60px] text-zinc-500">{{ $ep->notes }}</span>
                                                                    <button @click="
                                                                                            if (deleting) return;
                                                                                            deleting = true;
                                                                                            $wire.deleteExtraPoints({{ $ep->id }}).catch(() => { deleting = false; });
                                                                                        "
                                                                        class="text-red-400 hover:text-red-600 flex md:hidden md:group-hover:flex absolute left-1 top-1/2 -translate-y-1/2 bg-white dark:bg-zinc-800 rounded-full p-0.5"
                                                                        title="{{ __('حذف') }}">
                                                                        <flux:icon icon="x-mark" variant="micro" class="size-3" />
                                                                    </button>
                                                                </div>
                                                            @endforeach

                                                            {{-- Inline form toggle --}}
                                                            <button @click="open = !open"
                                                                :class="open ? 'bg-amber-100 dark:bg-amber-900/40 border-amber-400 text-amber-600' : 'border-dashed border-amber-300 text-amber-500 hover:bg-amber-100 dark:border-amber-700 dark:text-amber-600 dark:hover:bg-amber-900/30'"
                                                                class="inline-flex w-8 h-8 items-center justify-center rounded-lg border   s"
                                                                title="{{ __('إضافة نقاط إضافية') }}">
                                                                <flux:icon x-show="!open" icon="plus" variant="micro" class="size-4" />
                                                                <flux:icon x-show="open" icon="x-mark" variant="micro" class="size-4" />
                                                            </button>

                                                            {{-- Inline form --}}
                                                            <div x-show="open" x-transition:enter="ease-out duration-200"
                                                                x-transition:enter-start="opacity-0 scale-95"
                                                                x-transition:enter-end="opacity-100 scale-100"
                                                                x-transition:leave="ease-in duration-150"
                                                                x-transition:leave-start="opacity-100 scale-100"
                                                                x-transition:leave-end="opacity-0 scale-95" style="display:none;"
                                                                class="w-full max-w-[150px] bg-white dark:bg-zinc-800 border border-amber-200 dark:border-amber-700 rounded-xl p-2 shadow-lg space-y-1.5"
                                                                @keydown.escape.window="open = false">
                                                                <input x-model="amount" type="number" min="1" placeholder="{{ __('النقاط') }}"
                                                                    class="w-full text-xs rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-amber-400 text-zinc-700 dark:text-zinc-300" />
                                                                <input x-model="notes" type="text" placeholder="{{ __('السبب...') }}"
                                                                    @keydown.enter="save()"
                                                                    class="w-full text-xs rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-amber-400 text-zinc-700 dark:text-zinc-300" />
                                                                <button @click="save()" :disabled="saving || !amount || !notes.trim()"
                                                                    :class="saving ? 'opacity-50 cursor-wait' : 'hover:bg-amber-600'"
                                                                    class="w-full text-xs bg-amber-500 text-white rounded-lg py-1 font-bold   s">
                                                                    <span x-show="!saving">{{ __('حفظ') }}</span>
                                                                    <span x-show="saving">...</span>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </td>
                                                @endif

                                                <!-- Automated Points Cells -->
                                                <td
                                                    class="p-2 text-center bg-zinc-50/50 dark:bg-zinc-800/30 border-r border-zinc-200/50 dark:border-zinc-700/50">
                                                    @if(($dailyScores[$student->id]['hifz'] ?? 0) > 0)
                                                        <span
                                                            class="inline-flex items-center justify-center px-1.5 py-0.5 rounded bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-400 font-bold text-xs">
                                                            +{{ $dailyScores[$student->id]['hifz'] }}
                                                        </span>
                                                    @else
                                                        <span class="text-zinc-300 dark:text-zinc-600 text-sm">-</span>
                                                    @endif
                                                </td>
                                                <td class="p-2 text-center bg-zinc-50/50 dark:bg-zinc-800/30">
                                                    @if(($dailyScores[$student->id]['review'] ?? 0) > 0)
                                                        <span
                                                            class="inline-flex items-center justify-center px-1.5 py-0.5 rounded bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-400 font-bold text-xs">
                                                            +{{ $dailyScores[$student->id]['review'] }}
                                                        </span>
                                                    @else
                                                        <span class="text-zinc-300 dark:text-zinc-600 text-sm">-</span>
                                                    @endif
                                                </td>
                                                <td class="p-2 text-center bg-zinc-50/50 dark:bg-zinc-800/30">
                                                    @if(($dailyScores[$student->id]['attendance'] ?? 0) > 0)
                                                        <span
                                                            class="inline-flex items-center justify-center px-1.5 py-0.5 rounded bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-400 font-bold text-xs">
                                                            +{{ $dailyScores[$student->id]['attendance'] }}
                                                        </span>
                                                    @else
                                                        <span class="text-zinc-300 dark:text-zinc-600 text-sm">-</span>
                                                    @endif
                                                </td>
                                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </flux:card>
    @endif

    {{-- MODAL: PENDING BADGES APPROVAL --}}
    <flux:modal name="pending-badges-modal" class="md:w-[600px]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">مراجعة واعتماد الأوسمة</flux:heading>
                <flux:subheading>راجع أداء الطلاب الذين استوفوا متطلبات الأوسمة واعتمد منحها لهم.</flux:subheading>
            </div>

            <div class="space-y-3 max-h-[400px] overflow-y-auto pr-1">
                @forelse($pendingBadges as $pb)
                    <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="size-12 rounded-xl bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center overflow-hidden border">
                                @if(str_contains($pb->badge_icon, '/') || str_contains($pb->badge_icon, '.'))
                                    <img src="{{ asset('storage/' . $pb->badge_icon) }}" class="size-8 object-contain" />
                                @else
                                    <span class="text-2xl">{{ $pb->badge_icon === 'star' ? '⭐' : ($pb->badge_icon === 'fire' ? '🔥' : '🏅') }}</span>
                                @endif
                            </div>
                            <div>
                                <div class="font-bold text-sm text-zinc-900 dark:text-white">{{ $pb->student_name }}</div>
                                <div class="text-xs text-zinc-500 mt-0.5">يستحق وسام: <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $pb->badge_name }}</span></div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <flux:button wire:click="rejectBadge({{ $pb->badge_id }}, {{ $pb->student_id }})" variant="ghost" color="danger" size="sm" icon="x-mark">رفض</flux:button>
                            <flux:button wire:click="approveBadge({{ $pb->badge_id }}, {{ $pb->student_id }})" variant="filled" color="success" size="sm" icon="check">اعتماد</flux:button>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-6 text-zinc-500">لا توجد أوسمة معلقة حالياً.</div>
                @endforelse
            </div>

            <div class="flex justify-end pt-4">
                <flux:button x-on:click="$flux.modal('pending-badges-modal').hide()" variant="ghost">إغلاق</flux:button>
            </div>
        </div>
    </flux:modal>

</div>