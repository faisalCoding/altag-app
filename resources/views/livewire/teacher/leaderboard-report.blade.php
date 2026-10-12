<div>
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <flux:button href="{{ route('teacher.leaderboards') }}" wire:navigate variant="ghost" size="sm" icon="arrow-right" class="rtl:rotate-180" />
                <flux:heading size="xl">{{ __('التقرير الشامل للمنافسة: ') }} <span class="text-indigo-600 dark:text-indigo-400">{{ $leaderboard->title }}</span></flux:heading>
            </div>
            <flux:subheading class="ms-10">
                {{ $chosenWeek
                    ? __('الترتيب ونقاط :week وحده.', ['week' => $chosenWeek['label']])
                    : __('يُظهر هذا التقرير الترتيب العام وإجمالي النقاط التلقائية واليدوية منذ انطلاق المسابقة وحتى اليوم.') }}
            </flux:subheading>
            <div class="ms-10 mt-3">
                <x-competition-week-select :weeks="$weeks" wire:model.live="week" />
            </div>
        </div>
        <div class="bg-white dark:bg-zinc-800 px-4 py-2 rounded-xl border border-zinc-200 dark:border-zinc-700 text-sm font-semibold flex items-center gap-2">
            <flux:icon icon="calendar" class="size-4 text-zinc-500" />
            <span><x-hijri-date :date="$leaderboard->start_date" /></span>
            <span class="text-zinc-400">-</span>
            <span>{{ $leaderboard->end_date ? $leaderboard->end_date->format('Y-m-d') : __('الآن') }}</span>
        </div>
    </div>

    <flux:card class="p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right">
                <thead class="bg-zinc-50 dark:bg-zinc-800/80 border-b border-zinc-200 dark:border-zinc-700">
                    <tr>
                        <th class="p-4 font-semibold text-zinc-800 dark:text-zinc-200">{{ __('الترتيب والطالب') }}</th>
                        <th class="p-4 font-semibold text-zinc-800 dark:text-zinc-200 text-center">{{ __('الإجمالي') }}</th>
                        @if($leaderboard->settings['manual_claim_enabled'] ?? false)
                            <th class="p-4 font-semibold text-amber-700 dark:text-amber-400 text-center bg-amber-50/50 dark:bg-amber-900/10 border-r border-l border-zinc-100 dark:border-zinc-700">{{ __('بانتظار الاستلام') }}</th>
                        @endif

                        <!-- Automated columns -->
                        <th class="p-4 font-semibold text-indigo-700 dark:text-indigo-400 text-center bg-indigo-50/50 dark:bg-indigo-900/10 border-r border-l border-zinc-100 dark:border-zinc-700">{{ __('تلقائي (حفظ)') }}</th>
                        <th class="p-4 font-semibold text-indigo-700 dark:text-indigo-400 text-center bg-indigo-50/50 dark:bg-indigo-900/10 border-r border-l border-zinc-100 dark:border-zinc-700">{{ __('تلقائي (مراجعة)') }}</th>
                        <th class="p-4 font-semibold text-indigo-700 dark:text-indigo-400 text-center bg-indigo-50/50 dark:bg-indigo-900/10 border-r border-l border-zinc-100 dark:border-zinc-700">{{ __('تلقائي (حضور)') }}</th>

                        <!-- Manual criteria columns -->
                        @foreach($leaderboard->criteria as $criterion)
                            <th class="p-4 font-semibold text-emerald-700 dark:text-emerald-400 text-center bg-emerald-50/50 dark:bg-emerald-900/10 border-l border-zinc-100 dark:border-zinc-700">
                                <div class="flex flex-col items-center">
                                    <span>{{ $criterion->name }}</span>
                                    <span class="text-[10px] font-normal opacity-70 mt-0.5">{{ $criterion->points }} {{ __('نقاط') }}</span>
                                </div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @if($standingsByTrack->isNotEmpty())
                        @foreach($standingsByTrack as $group)
                            <tr class="bg-purple-50/60 dark:bg-purple-900/10 border-y border-purple-100 dark:border-purple-900/30">
                                <td colspan="100%" class="px-4 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <flux:icon icon="flag" class="size-4 text-purple-500 shrink-0" />
                                        <span class="font-bold text-purple-700 dark:text-purple-300">{{ $group['name'] }}</span>
                                        <span class="text-xs text-zinc-400">({{ count($group['standings']) }})</span>
                                        @if($group['description'])
                                            <span class="text-xs text-zinc-400 truncate">— {{ $group['description'] }}</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @foreach($group['standings'] as $standing)
                                @include('livewire.teacher.partials.report-row', ['standing' => $standing, 'rank' => $standing['track_rank'], 'leaderboard' => $leaderboard])
                            @endforeach
                        @endforeach
                    @else
                        @foreach($standings as $index => $standing)
                            @include('livewire.teacher.partials.report-row', ['standing' => $standing, 'rank' => $index + 1, 'leaderboard' => $leaderboard])
                        @endforeach
                    @endif

                    @if($standings->isEmpty())
                        <tr>
                            <td colspan="100%" class="p-8 text-center text-zinc-500">
                                {{ __('لا يوجد طلاب في هذه الحلقة بعد.') }}
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
