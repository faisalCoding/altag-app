{{--
    Celebration cards: a level reached, a badge approved, a streak milestone
    reached. They replace the full-screen boxes that covered the page until the
    student took the award: a card rises above the phone's bottom bar (at the
    bottom corner on a wide screen), one at a time, and the page stays usable
    behind it. The behaviour lives in resources/js/gamification/celebrations.js.

    The cards are drawn in order, the level first, and the stylesheet shows the
    first one not hidden. «استلام» takes an award through the claim store, so
    its row in the rewards panel hides with it; «لاحقاً» puts it off for the
    rest of the visit, and it waits in the panel; «استلام الكل» takes every
    award the cards hold (the panel's own «استلام الكل» takes its rewards).

    Everything the cards hold is in data-gam-floating, which steps aside while
    the phone's side menu is open (app.css), as the bottom bar does. x-cloak
    keeps the cards put off from flashing before the script reads which.
--}}
@props([
    'awards' => [],
    'level' => null,
    'coinIcon',
    'leaderboardId',
    'teamName' => null,
])

@php
    $awards = collect($awards);
    $chipCoin = \App\Support\GamificationEmoji::render($coinIcon, 'size-3.5 inline-block align-middle');
    $untakenCount = $awards->count();
    $cardClass = 'gam-celebration fixed inset-x-3 bottom-[calc(env(safe-area-inset-bottom,0px)+5.75rem)] lg:bottom-6 lg:start-auto lg:end-6 lg:w-96 z-[10000] rounded-3xl border border-slate-200 bg-white shadow-2xl p-4';
@endphp

@if($level || $awards->isNotEmpty())
    <style>
        /* One card at a time: the first not hidden. */
        .gam-celebration.is-hidden { display: none; }
        .gam-celebration:not(.is-hidden) ~ .gam-celebration { display: none; }

        @keyframes gam-celebration-in {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: none; }
        }
        @keyframes gam-celebration-fade {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .gam-celebration { animation: gam-celebration-in 0.35s cubic-bezier(0.2, 0.8, 0.2, 1); transition: bottom 0.2s ease-out; }

        /*
         * A toast shares the card's corner (bottom end, 1.5rem in, lifted 6rem
         * over the phone's bottom bar) and is drawn above it, so it covered the
         * card's buttons after every «استلام». While a toast shows (Flux puts
         * its box in ui-toast and takes it out when it is gone) the card rises
         * clear of a toast up to two lines tall, and settles back after.
         */
        body:has(ui-toast > [data-flux-toast-dialog]) .gam-celebration { bottom: calc(env(safe-area-inset-bottom, 0px) + 13rem); }
        @media (min-width: 64rem) {
            body:has(ui-toast > [data-flux-toast-dialog]) .gam-celebration { bottom: 7.5rem; }
        }

        /* Less motion asked for: the card fades in where it stands, and moves without sliding. */
        @media (prefers-reduced-motion: reduce) {
            .gam-celebration { animation: gam-celebration-fade 0.2s ease-out; transition: none; }
        }
    </style>

    <div data-gam-celebrations
        x-data="gamCelebrations({
            leaderboardId: @js($leaderboardId),
            awardForms: @js(\App\Support\ArabicCount::AWARDS),
            hint: @js(__('تجدها في «مكافآت بانتظار الاستلام»')),
        })"
        x-show="! inputFocused"
        x-on:gam-claiming.window="holdLevel()"
        x-cloak>
        <p class="sr-only" aria-live="polite" x-text="liveLine()">
            @if($untakenCount > 0)
                {{ __('لديك') }} {{ \App\Support\ArabicCount::of($untakenCount, \App\Support\ArabicCount::AWARDS) }} {{ __('بانتظار الاستلام') }}
            @endif
        </p>

        @if($level)
            @php
                $levelNumber = (int) $level['acknowledge'];
                $levelPending = $level['pending'] ?? [];
            @endphp
            <section wire:key="gam-celebration-level-{{ $levelNumber }}"
                data-gam-floating
                data-level-card
                data-level="{{ $levelNumber }}"
                role="dialog" aria-modal="false" aria-labelledby="gam-level-title"
                x-bind:class="{ 'is-hidden': ! levelVisible() }"
                x-on:mouseenter="hovering = true"
                x-on:mouseleave="hovering = false"
                class="{{ $cardClass }}">
                <button type="button" x-on:click="closeLevel(true)" aria-label="{{ __('إغلاق') }}"
                    class="absolute top-2 end-2 size-11 rounded-full flex items-center justify-center text-slate-400 hover:text-slate-600 hover:bg-slate-50 transition-colors cursor-pointer">
                    <flux:icon icon="x-mark" class="size-5" />
                </button>

                <div class="flex items-center gap-3 pe-10">
                    <div data-level-icon class="size-14 rounded-2xl bg-team-primary text-white flex items-center justify-center shrink-0 shadow-sm">
                        <flux:icon :icon="$level['icon'] ?: 'sparkles'" class="size-8" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-[11px] font-black text-amber-600">{{ __('مستوى جديد!') }}</div>
                        <h2 id="gam-level-title" class="font-black text-slate-900 leading-snug line-clamp-2">
                            {{ __('المستوى') }} {{ $level['level'] }}: {{ $level['name'] }}
                        </h2>
                    </div>
                </div>

                @if(! empty($level['perks']))
                    <div class="mt-3">
                        <div class="text-xs font-bold text-slate-500">{{ __('ما فُتح لك:') }}</div>
                        <ul class="mt-1.5 space-y-1.5">
                            @foreach($level['perks'] as $perk)
                                <li wire:key="gam-level-perk-{{ $perk['key'] }}" data-level-perk="{{ $perk['key'] }}" class="flex items-start gap-2 text-sm text-slate-700 leading-snug">
                                    <x-student.partials.gam-icon name="check" class="size-4 mt-0.5 text-emerald-500" />
                                    <span>
                                        @switch($perk['key'])
                                            @case('individual_multiplier')
                                                {{ __('مضاعف النقاط الفردي') }}
                                                @break
                                            @case('team_multiplier')
                                                {{ __('مضاعف النقاط لـ') }}{{ $teamName ?? __('فريقك') }}
                                                @break
                                            @case('freeze')
                                                {{ __('تجميد الحماسة') }}
                                                @break
                                            @case('freeze_days')
                                                {{ __('تجميد الأيام الفائتة، حتى') }} {{ \App\Support\ArabicCount::of($perk['days'], \App\Support\ArabicCount::DAYS_GEN) }}
                                                @break
                                            @case('donation')
                                                {{ __('حد التبرع لـ') }}{{ $teamName ?? __('فريقك') }}: {{ $perk['percent'] }}٪ {{ __('من رصيدك يومياً') }}
                                                @break
                                        @endswitch
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <p data-level-next class="mt-3 text-sm text-slate-600">
                        @if($level['next'] !== null)
                            {{ __('واصل التقدّم نحو المستوى') }} {{ $level['next'] }}
                        @else
                            {{ __('وصلت إلى أعلى مستوى، أحسنت!') }}
                        @endif
                    </p>
                @endif

                @if($level['coins'] > 0)
                    <div data-level-reward class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-2xl bg-amber-50 border border-amber-100 px-3 py-2">
                        <span class="text-sm font-bold text-amber-700 inline-flex items-center gap-1">
                            {{ __('مكافأة المستوى:') }} <x-student.partials.gam-num :value="$level['coins']" signed /> {!! $chipCoin !!}
                        </span>
                        @if(! empty($levelPending))
                            <button type="button"
                                data-level-reward-claim="{{ collect($levelPending)->pluck('key')->implode(' ') }}"
                                x-show="levelRewardPending(@js($levelPending))"
                                x-on:click="claimLevelReward(@js($levelPending), $el)"
                                class="min-h-11 px-4 rounded-xl text-sm font-bold bg-amber-500 hover:bg-amber-600 text-white transition-colors cursor-pointer">
                                {{ __('استلام المكافأة') }}
                            </button>
                            <span x-show="! levelRewardPending(@js($levelPending))" x-cloak class="text-xs font-bold text-emerald-700">{{ __('أُضيفت إلى رصيدك') }}</span>
                        @else
                            <span class="text-xs font-bold text-emerald-700">{{ __('أُضيفت إلى رصيدك') }}</span>
                        @endif
                    </div>
                @endif

                <button type="button" x-on:click="closeLevel(true)"
                    class="mt-3 w-full min-h-11 rounded-xl bg-team-primary hover:bg-team-primary-hover text-white font-bold text-sm transition-colors cursor-pointer">
                    {{ __('رائع') }}
                </button>
            </section>
        @endif

        @foreach($awards as $award)
            @php
                $isBadge = $award['kind'] === 'badge';
                $titleId = 'gam-award-title-'.$award['key'];
                $call = $isBadge ? '$wire.claimBadge('.$award['id'].')' : '$wire.claimMilestone('.$award['id'].')';
                $description = $award['description'] !== ''
                    ? $award['description']
                    : ($isBadge ? __('تقديراً لتميزك واجتهادك.') : __('حافظت على شعلة حماستك، فاستحققت هذه الجائزة.'));
            @endphp
            <section wire:key="gam-celebration-{{ $award['key'] }}"
                data-gam-floating
                data-award-card
                data-claim-key="{{ $award['key'] }}"
                data-xp="{{ $award['xp'] }}"
                data-coins="{{ $award['coins'] }}"
                role="dialog" aria-modal="false" aria-labelledby="{{ $titleId }}"
                x-bind:class="{ 'is-hidden': isHidden('{{ $award['key'] }}') }"
                class="{{ $cardClass }}">
                <div class="flex items-start gap-3">
                    <div class="size-14 rounded-2xl bg-team-10 border border-team-20 text-team-primary flex items-center justify-center shrink-0 overflow-hidden">
                        @if($isBadge)
                            <x-student.partials.gam-badge-icon :icon="$award['icon']" :alt="$award['name']" class="size-8" />
                        @else
                            <x-student.partials.gam-icon name="streak" class="size-8 text-orange-500" />
                        @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-black text-amber-600">{{ $isBadge ? __('وسام جديد') : __('جائزة الحماسة') }}</div>
                        <h2 id="{{ $titleId }}" class="font-black text-slate-900 leading-snug line-clamp-2">
                            @if($isBadge)
                                {{ $award['name'] }}
                            @else
                                {{ \App\Support\ArabicCount::of($award['days'], \App\Support\ArabicCount::DAYS) }} {{ __('من الحماسة المتتالية') }}
                            @endif
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500 leading-relaxed line-clamp-2">{{ $description }}</p>
                        @if($award['xp'] > 0 || $award['coins'] > 0)
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                @if($award['xp'] > 0)
                                    <span data-award-xp class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold bg-indigo-50 text-indigo-600">
                                        <x-student.partials.gam-num :value="$award['xp']" signed /> {{ \App\Support\ArabicCount::unit($award['xp'], 'نقطة', 'نقاط') }}
                                    </span>
                                @endif
                                @if($award['coins'] > 0)
                                    <span data-award-coins class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold bg-amber-50 text-amber-600">
                                        <x-student.partials.gam-num :value="$award['coins']" signed /> {!! $chipCoin !!}
                                    </span>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button type="button"
                        x-on:click="$store.gamClaims.claim('{{ $award['key'] }}', { xp: {{ $award['xp'] }}, coins: {{ $award['coins'] }} }, () => {{ $call }}, $el)"
                        class="flex-1 min-w-24 min-h-11 px-4 rounded-xl bg-team-primary hover:bg-team-primary-hover text-white font-bold text-sm transition-colors cursor-pointer">
                        {{ __('استلام') }}
                    </button>
                    <button type="button" data-claim-all-awards
                        wire:click="claimAllAwards"
                        x-on:click="claimAll($el)"
                        x-show="untaken().length > 1" @if($untakenCount <= 1) x-cloak @endif
                        class="min-h-11 px-4 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 font-bold text-sm transition-colors cursor-pointer data-loading:opacity-60 data-loading:pointer-events-none">
                        {{ __('استلام الكل') }} (<span x-text="untaken().length">{{ $untakenCount }}</span>)
                    </button>
                    <button type="button" x-on:click="postpone('{{ $award['key'] }}')"
                        class="min-h-11 px-4 rounded-xl text-slate-500 hover:text-slate-700 hover:bg-slate-50 font-bold text-sm transition-colors cursor-pointer">
                        {{ __('لاحقاً') }}
                    </button>
                </div>
            </section>
        @endforeach
    </div>
@endif
