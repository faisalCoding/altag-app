<div class="space-y-6" dir="rtl">
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div class="flex items-center gap-3">
            <div class="p-2.5 rounded-xl bg-amber-500/10 text-amber-500">
                <flux:icon icon="trophy" />
            </div>
            <div>
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">{{ __('الأوائل حسب المسار') }}</flux:heading>
                <flux:subheading class="text-zinc-400">
                    <a href="{{ route('supervisor.competitions') }}" wire:navigate class="hover:text-maroon">{{ __('المسابقات') }}</a>
                    <span class="mx-1">/</span>
                    <span>{{ $leaderboard->title }}</span>
                </flux:subheading>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <div class="w-36">
                <flux:select wire:model.live="topCount" size="sm">
                    <flux:select.option value="3">{{ __('أفضل 3') }}</flux:select.option>
                    <flux:select.option value="5">{{ __('أفضل 5') }}</flux:select.option>
                    <flux:select.option value="10">{{ __('أفضل 10') }}</flux:select.option>
                </flux:select>
            </div>
            <flux:button href="{{ route('supervisor.competitions') }}" wire:navigate variant="ghost" icon="arrow-right" class="rtl:rotate-180" />
        </div>
    </div>

    <x-competition-weeks :weeks="$weeks" :selected="$week" model="week">
    @if($topByTrack->isEmpty())
        <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-16 text-center">
            <flux:icon icon="trophy" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
            <flux:heading size="lg" class="text-zinc-500 dark:text-zinc-400">{{ __('لا توجد نتائج بعد') }}</flux:heading>
            <flux:subheading class="text-zinc-400 dark:text-zinc-500">{{ __('لم يتم تسجيل أي نقاط لطلاب هذه المسابقة حتى الآن') }}</flux:subheading>
        </div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            @foreach($topByTrack as $group)
                <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden" wire:key="track-{{ $group['id'] ?? 'general' }}">
                    <div class="p-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center gap-2 bg-gradient-to-l from-maroon/5 to-transparent">
                        <flux:icon icon="flag" class="size-4 text-maroon shrink-0" />
                        <span class="font-bold text-zinc-800 dark:text-zinc-100">{{ $group['name'] }}</span>
                        @if($group['description'])
                            <span class="text-xs text-zinc-400 truncate">— {{ $group['description'] }}</span>
                        @endif
                    </div>

                    @if(empty($group['standings']))
                        <p class="text-sm text-zinc-400 text-center py-8">{{ __('لا يوجد طلاب في هذا المسار بعد') }}</p>
                    @else
                        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($group['standings'] as $standing)
                                @php
                                    $rank = $standing['track_rank'] ?? $loop->iteration;
                                    $medal = match ($rank) {
                                        1 => ['bg' => 'bg-amber-100 dark:bg-amber-900/30', 'text' => 'text-amber-600 dark:text-amber-400', 'icon' => 'trophy'],
                                        2 => ['bg' => 'bg-zinc-200 dark:bg-zinc-700', 'text' => 'text-zinc-600 dark:text-zinc-300', 'icon' => 'trophy'],
                                        3 => ['bg' => 'bg-orange-100 dark:bg-orange-900/30', 'text' => 'text-orange-600 dark:text-orange-400', 'icon' => 'trophy'],
                                        default => ['bg' => 'bg-zinc-100 dark:bg-zinc-800', 'text' => 'text-zinc-500 dark:text-zinc-400', 'icon' => null],
                                    };
                                @endphp
                                <div class="flex items-center gap-3 p-4">
                                    <span class="flex items-center justify-center size-8 rounded-full font-bold text-sm shrink-0 {{ $medal['bg'] }} {{ $medal['text'] }}">
                                        @if($medal['icon'])
                                            <flux:icon icon="{{ $medal['icon'] }}" class="size-4" />
                                        @else
                                            {{ $rank }}
                                        @endif
                                    </span>
                                    <div class="size-9 rounded-full flex items-center justify-center font-bold text-xs shrink-0" style="{{ $standing['student']->avatarStyle() }}">
                                        {{ $standing['student']->initials() }}
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="font-bold text-zinc-800 dark:text-zinc-100 truncate">{{ $standing['student']->name }}</div>
                                        <div class="text-xs text-zinc-400 truncate">{{ $standing['student']->circle?->name }}</div>
                                    </div>
                                    <flux:badge color="amber" size="sm">{{ $standing['score'] }} {{ __('نقطة') }}</flux:badge>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
    </x-competition-weeks>
</div>
