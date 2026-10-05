{{--
    Rewards waiting to be claimed (the competition has manual claim on).

    A tap on «استلام» hides the row at once, flies its points to the level
    meter (or its coins to the coins, when it gives coins only), and then asks
    the server; the totals and the count drop with it. If the server says no,
    or the request never arrives, the row comes back. The claim store
    ($store.gamClaims, resources/js/gamification/claims.js) keeps what is in
    flight, so any other place showing the same reward hides with it.

    The totals are read from the data-total-* attributes, which every redraw
    refreshes, less the claims in flight: a claim can add a row (a level's
    coins), and Alpine never reads x-data again after a redraw.

    Every row shows its chips at every width, and only what it gives: no «+0».

    Each collapse carries x-gam-collapse after x-collapse (see guardCollapse()
    in resources/js/gamification/motion.js): a claim that fails at once brings
    its row back, and under reduced motion a row hides without sliding.

    Badges and streak milestones waiting to be taken are listed too, under the
    rewards: the ones the student put off from their celebration card wait
    here. They are taken one by one; the panel's «استلام الكل» takes the
    rewards only, and its total beside it says what it takes.
--}}
@props([
    'rewards',
    'coinIcon',
    'awards' => [],
])

@php
    $rewardCount = $rewards->count();
    $rewardXp = (int) $rewards->sum('xp_amount');
    $rewardCoins = (int) $rewards->sum('amount');
    $awardList = collect($awards);
    $totalCount = $rewardCount + $awardList->count();
    $totalXp = $rewardXp + (int) $awardList->sum('xp');
    $totalCoins = $rewardCoins + (int) $awardList->sum('coins');
    $chipCoin = \App\Support\GamificationEmoji::render($coinIcon, 'size-3.5 inline-block align-middle');
    $rewardIcons = [
        'App\\Models\\StudentPlanDay' => 'book-open',
        'App\\Models\\FreeRecitation' => 'book-open',
        'App\\Models\\StudentOdeAchievement' => 'musical-note',
        'App\\Models\\StudentHadithAchievement' => 'document-text',
        'App\\Models\\Attendance' => 'user-group',
        'App\\Models\\LeaderboardScore' => 'star',
        'App\\Models\\GamificationActivityWinner' => 'trophy',
        'App\\Models\\GamificationLevel' => 'academic-cap',
        'leaderboard_extra_points' => 'plus-circle',
    ];
@endphp

<div x-data="gamRewardsPanel(@js(\App\Support\ArabicCount::REWARDS_GEN), @js(['نقطة', 'نقاط']))"
    x-show="remaining().count > 0" x-collapse x-gam-collapse
    data-rewards-panel
    data-total-count="{{ $totalCount }}"
    data-total-xp="{{ $totalXp }}"
    data-total-coins="{{ $totalCoins }}"
    data-reward-count="{{ $rewardCount }}"
    data-reward-xp="{{ $rewardXp }}"
    data-reward-coins="{{ $rewardCoins }}"
    class="relative z-10 mb-6 rounded-3xl border border-amber-200 bg-white overflow-hidden shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 p-4 border-b border-slate-100">
        <div class="flex items-center gap-2.5 min-w-0">
            <div class="size-9 rounded-xl bg-amber-50 text-amber-500 flex items-center justify-center shrink-0"><flux:icon icon="gift" class="size-5" /></div>
            <div class="min-w-0">
                <div class="font-bold text-slate-900">{{ __('مكافآت بانتظار الاستلام') }}</div>
                <div data-rewards-count class="text-xs text-slate-400">
                    {{ __('اضغط «استلام» لتحصل على') }} <span x-text="countLabel()">{{ \App\Support\ArabicCount::of($totalCount, \App\Support\ArabicCount::REWARDS_GEN) }}</span>
                </div>
            </div>
        </div>
        <div class="flex gap-1.5 text-xs font-bold">
            <span data-rewards-total-xp x-show="remaining().xp > 0" @if($totalXp <= 0) x-cloak @endif
                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-600">
                <x-student.partials.gam-icon name="xp" class="size-3.5" />
                <x-student.partials.gam-num :value="$totalXp" signed x-text="signed(remaining().xp)" />
            </span>
            <span data-rewards-total-coins x-show="remaining().coins > 0" @if($totalCoins <= 0) x-cloak @endif
                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-50 text-amber-600">
                {!! $chipCoin !!}
                <x-student.partials.gam-num :value="$totalCoins" signed x-text="signed(remaining().coins)" />
            </span>
        </div>
    </div>

    <div class="divide-y divide-slate-100 max-h-80 overflow-y-auto scrollbar-thin">
        @foreach($rewards as $reward)
            @php
                $rewardKey = 'tx-'.$reward->id;
                $rowXp = max(0, (int) $reward->xp_amount);
                $rowCoins = max(0, (int) $reward->amount);
            @endphp
            <div wire:key="reward-tx-{{ $reward->id }}"
                data-reward-row="{{ $rewardKey }}"
                data-claim-key="{{ $rewardKey }}"
                data-xp="{{ $rowXp }}"
                data-coins="{{ $rowCoins }}"
                x-show="! $store.gamClaims.isHidden('{{ $rewardKey }}')" x-collapse x-gam-collapse>
                <div class="flex items-center gap-3 p-3.5">
                    <div class="size-9 rounded-lg bg-slate-50 text-slate-500 flex items-center justify-center shrink-0">
                        <flux:icon :icon="$rewardIcons[$reward->reference_type] ?? 'gift'" class="size-5" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm text-slate-800 leading-snug line-clamp-2">{{ $reward->description }}</div>
                        @if($rowXp > 0 || $rowCoins > 0)
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                @if($rowXp > 0)
                                    <span data-reward-xp class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold bg-indigo-50 text-indigo-600">
                                        <x-student.partials.gam-num :value="$rowXp" signed /> {{ \App\Support\ArabicCount::unit($rowXp, 'نقطة', 'نقاط') }}
                                    </span>
                                @endif
                                @if($rowCoins > 0)
                                    <span data-reward-coins class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold bg-amber-50 text-amber-600">
                                        <x-student.partials.gam-num :value="$rowCoins" signed /> {!! $chipCoin !!}
                                    </span>
                                @endif
                            </div>
                        @endif
                    </div>
                    <button type="button"
                        aria-label="{{ __('استلام مكافأة:') }} {{ $reward->description }}"
                        x-on:click="$store.gamClaims.claim('{{ $rewardKey }}', { xp: {{ $rowXp }}, coins: {{ $rowCoins }} }, () => $wire.claimReward({{ $reward->id }}), $el)"
                        class="shrink-0 min-h-11 px-4 rounded-xl text-sm font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 active:bg-emerald-200 transition-colors cursor-pointer">
                        {{ __('استلام') }}
                    </button>
                </div>
            </div>
        @endforeach

        @foreach($awardList as $award)
            @php
                $awardLabel = $award['kind'] === 'badge'
                    ? __('وسام:').' '.$award['name']
                    : __('جائزة حماسة:').' '.\App\Support\ArabicCount::of($award['days'], \App\Support\ArabicCount::DAYS);
                $awardCall = $award['kind'] === 'badge'
                    ? '$wire.claimBadge('.$award['id'].')'
                    : '$wire.claimMilestone('.$award['id'].')';
            @endphp
            <div wire:key="reward-{{ $award['key'] }}"
                data-award-row="{{ $award['key'] }}"
                data-claim-key="{{ $award['key'] }}"
                x-show="! $store.gamClaims.isHidden('{{ $award['key'] }}')" x-collapse x-gam-collapse>
                <div class="flex items-center gap-3 p-3.5">
                    <div class="size-9 rounded-lg bg-amber-50 text-amber-500 flex items-center justify-center shrink-0 overflow-hidden">
                        @if($award['kind'] === 'badge')
                            <x-student.partials.gam-badge-icon :icon="$award['icon']" :alt="$award['name']" class="size-5" />
                        @else
                            <x-student.partials.gam-icon name="streak" class="size-5" />
                        @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-bold text-slate-800 leading-snug line-clamp-2">{{ $awardLabel }}</div>
                        @if($award['xp'] > 0 || $award['coins'] > 0)
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                @if($award['xp'] > 0)
                                    <span data-reward-xp class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold bg-indigo-50 text-indigo-600">
                                        <x-student.partials.gam-num :value="$award['xp']" signed /> {{ \App\Support\ArabicCount::unit($award['xp'], 'نقطة', 'نقاط') }}
                                    </span>
                                @endif
                                @if($award['coins'] > 0)
                                    <span data-reward-coins class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold bg-amber-50 text-amber-600">
                                        <x-student.partials.gam-num :value="$award['coins']" signed /> {!! $chipCoin !!}
                                    </span>
                                @endif
                            </div>
                        @endif
                    </div>
                    <button type="button"
                        aria-label="{{ __('استلام الجائزة:') }} {{ $awardLabel }}"
                        x-on:click="$store.gamClaims.claim('{{ $award['key'] }}', { xp: {{ $award['xp'] }}, coins: {{ $award['coins'] }} }, () => {{ $awardCall }}, $el)"
                        class="shrink-0 min-h-11 px-4 rounded-xl text-sm font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 active:bg-emerald-200 transition-colors cursor-pointer">
                        {{ __('استلام') }}
                    </button>
                </div>
            </div>
        @endforeach
    </div>

    <div x-show="rewardsRemaining().count > 0" @if($rewardCount === 0) x-cloak @endif
        class="flex flex-wrap items-center justify-between gap-3 p-4 bg-slate-50/70 border-t border-slate-100">
        <span class="text-xs text-slate-500 flex flex-wrap items-center gap-x-2 gap-y-1">
            <span>{{ __('الإجمالي') }}:</span>
            <span x-show="rewardsRemaining().xp > 0" @if($rewardXp <= 0) x-cloak @endif class="inline-flex items-center gap-1">
                <x-student.partials.gam-num :value="$rewardXp" signed x-text="signed(rewardsRemaining().xp)" />
                <span x-text="pointsUnit(rewardsRemaining().xp)">{{ \App\Support\ArabicCount::unit($rewardXp, 'نقطة', 'نقاط') }}</span>
            </span>
            <span x-show="rewardsRemaining().coins > 0" @if($rewardCoins <= 0) x-cloak @endif class="inline-flex items-center gap-1">
                <x-student.partials.gam-num :value="$rewardCoins" signed x-text="signed(rewardsRemaining().coins)" />
                {!! $chipCoin !!}
            </span>
        </span>
        <button type="button" data-claim-all
            wire:click="claimAllRewards"
            x-on:click="claimAll($el)"
            class="inline-flex items-center justify-center gap-2 min-h-11 px-5 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-sm transition-colors cursor-pointer data-loading:opacity-60 data-loading:pointer-events-none">
            <x-student.partials.gam-icon name="check" class="size-4" /> {{ __('استلام الكل') }}
        </button>
    </div>
</div>
