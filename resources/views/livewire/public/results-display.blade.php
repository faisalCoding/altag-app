{{--
    The deck is drawn once by the server; everything that only moves through
    it — the slide on screen, hidden lists, the settings panel, auto-advance —
    lives in Alpine, so none of it waits on a request. Only a change of dates
    (and a quiet refresh every five minutes) asks the server again.

    The root's x-data carries no server values on purpose: Alpine now resets
    an x-data object to its expression whenever a redraw changes it, which
    would drop the slide on screen and the running timer. The slides are read
    from the DOM instead, after each redraw.
--}}
<div class="h-dvh w-full flex flex-col overflow-hidden"
    wire:poll.300s.visible
    x-data="{
        current: 0,
        keys: [],
        titles: {},
        hidden: [],
        auto: false,
        seconds: 15,
        timer: null,
        settings: false,
        fullscreen: false,
        idle: false,
        idleTimer: null,
        wakeLock: null,
        touchX: null,
        storeKey: 'resultsDisplay:{{ $leaderboard->id }}',
        init() {
            try {
                const saved = JSON.parse(localStorage.getItem(this.storeKey) || '{}');
                this.hidden = Array.isArray(saved.hidden) ? saved.hidden : [];
                this.seconds = this.clampSeconds(saved.seconds);
            } catch (e) {}
            this.readSlides();
            Livewire.hook('morphed', ({ el }) => { if (el === this.$root) this.readSlides(); });
            this.wake();
        },
        readSlides() {
            const slides = [...this.$root.querySelectorAll('[data-slide]')];
            this.keys = slides.map((el) => el.dataset.slide);
            this.titles = Object.fromEntries(slides.map((el) => [el.dataset.slide, el.dataset.title]));
            if (this.current >= this.visible.length) this.current = 0;
        },
        get visible() { return this.keys.filter((key) => ! this.hidden.includes(key)); },
        get currentKey() { return this.visible[this.current] ?? null; },
        go(index) {
            const count = this.visible.length;
            this.current = count ? (index + count) % count : 0;
            this.restartTimer();
        },
        next() { this.go(this.current + 1); },
        prev() { this.go(this.current - 1); },
        toggleList(key) {
            const shown = this.currentKey;
            this.hidden = this.hidden.includes(key) ? this.hidden.filter((k) => k !== key) : [...this.hidden, key];
            const index = this.visible.indexOf(shown);
            this.current = index === -1 ? Math.min(this.current, Math.max(0, this.visible.length - 1)) : index;
            this.save();
        },
        clampSeconds(value) { return Math.min(120, Math.max(5, parseInt(value) || 15)); },
        save() {
            try { localStorage.setItem(this.storeKey, JSON.stringify({ hidden: this.hidden, seconds: this.seconds })); } catch (e) {}
        },
        toggleAuto() {
            this.auto = ! this.auto;
            this.restartTimer();
            this.holdScreen();
        },
        restartTimer() {
            clearTimeout(this.timer);
            this.$refs.progress?.getAnimations().forEach((animation) => animation.cancel());
            if (! this.auto || this.visible.length < 2) return;
            this.timer = setTimeout(() => this.next(), this.seconds * 1000);
            this.$refs.progress?.animate([{ transform: 'scaleX(0)' }, { transform: 'scaleX(1)' }], { duration: this.seconds * 1000, easing: 'linear', fill: 'forwards' });
        },
        async holdScreen() {
            // A projector left on auto must not dim or sleep between slides.
            try {
                if (this.auto && ! this.wakeLock && 'wakeLock' in navigator && document.visibilityState === 'visible') {
                    this.wakeLock = await navigator.wakeLock.request('screen');
                    this.wakeLock.addEventListener('release', () => this.wakeLock = null);
                } else if (! this.auto && this.wakeLock) {
                    await this.wakeLock.release();
                    this.wakeLock = null;
                }
            } catch (e) {}
        },
        toggleTheme() {
            const root = document.documentElement;
            root.classList.toggle('dark');
            try { localStorage.setItem('resultsDisplayTheme', root.classList.contains('dark') ? 'dark' : 'light'); } catch (e) {}
        },
        toggleFullscreen() {
            document.fullscreenElement ? document.exitFullscreen?.() : document.documentElement.requestFullscreen?.();
        },
        wake() {
            this.idle = false;
            clearTimeout(this.idleTimer);
            this.idleTimer = setTimeout(() => { if (this.fullscreen && ! this.settings) this.idle = true; }, 3000);
        },
        onKey(event) {
            const target = event.target instanceof Element ? event.target : null;
            if (target?.closest('input, textarea, select, [contenteditable]')) return;
            if (event.key === ' ' && target?.closest('button')) return;
            // By the key's place, not its letter: on an Arabic layout F types ب.
            if (event.code === 'KeyF') { event.preventDefault(); this.toggleFullscreen(); return; }
            if (event.key === 'Escape') { this.settings = false; return; }
            const moves = { ArrowLeft: 1, PageDown: 1, ' ': 1, ArrowRight: -1, PageUp: -1 };
            if (event.key in moves) { event.preventDefault(); this.go(this.current + moves[event.key]); this.wake(); }
            if (event.key === 'Home') { event.preventDefault(); this.go(0); }
            if (event.key === 'End') { event.preventDefault(); this.go(this.visible.length - 1); }
        },
        swipe(event) {
            if (this.touchX === null) return;
            const distance = event.changedTouches[0].clientX - this.touchX;
            this.touchX = null;
            if (Math.abs(distance) > 60) distance < 0 ? this.next() : this.prev();
        },
    }"
    x-on:keydown.window="onKey($event)"
    x-on:mousemove.window="wake()"
    x-on:fullscreenchange.document="fullscreen = !! document.fullscreenElement; wake()"
    x-on:visibilitychange.document="holdScreen()"
    x-on:touchstart="touchX = $event.touches[0].clientX"
    x-on:touchend="swipe($event)"
    x-bind:class="idle && 'cursor-none'">

    {{-- Top bar --}}
    <header class="flex items-center justify-between gap-4 px-[3vw] py-[1.6vh] shrink-0">
        <div class="flex items-center gap-3 min-w-0">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-[clamp(1.75rem,4.5vh,2.75rem)] shrink-0 text-amber-500 dark:text-amber-400" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 013 3h-15a3 3 0 013-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 01-.982-3.172M9.497 14.25a7.454 7.454 0 00.981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 007.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 002.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 012.916.52 6.003 6.003 0 01-5.395 4.972m0 0a6.726 6.726 0 01-2.749 1.35m0 0a6.772 6.772 0 01-3.044 0" />
            </svg>
            <div class="min-w-0">
                <div class="text-[clamp(1rem,2.6vh,1.5rem)] font-bold truncate">{{ $leaderboard->title }}</div>
                <div class="text-[clamp(0.7rem,1.5vh,0.875rem)] text-zinc-500 dark:text-zinc-400">شاشة النتائج</div>
            </div>
        </div>

        <div class="flex items-center gap-1 shrink-0 transition-opacity duration-300" x-bind:class="idle && 'opacity-0 pointer-events-none'">
            <span class="hidden sm:inline text-sm text-zinc-500 tabular-nums px-2" x-show="visible.length" x-text="`${current + 1} من ${visible.length}`"></span>
            <button type="button" class="p-2 rounded-lg hover:bg-zinc-900/10 dark:hover:bg-white/10 transition-colors"
                x-on:click="toggleTheme()" title="تبديل المظهر" aria-label="تبديل المظهر">
                <flux:icon icon="sun" class="size-6 hidden dark:block" />
                <flux:icon icon="moon" class="size-6 dark:hidden" />
            </button>
            <button type="button" class="p-2 rounded-lg hover:bg-zinc-900/10 dark:hover:bg-white/10 transition-colors"
                x-bind:class="auto && 'text-emerald-600 dark:text-emerald-400'"
                x-bind:aria-pressed="auto"
                x-on:click="toggleAuto()" title="تقليب تلقائي" aria-label="تقليب تلقائي">
                <flux:icon icon="play-circle" class="size-6" x-show="! auto" />
                <flux:icon icon="pause-circle" class="size-6" x-show="auto" x-cloak />
            </button>
            <button type="button" class="p-2 rounded-lg hover:bg-zinc-900/10 dark:hover:bg-white/10 transition-colors"
                x-on:click="toggleFullscreen()" title="ملء الشاشة (F)" aria-label="ملء الشاشة">
                <flux:icon icon="arrows-pointing-out" class="size-6" x-show="! fullscreen" />
                <flux:icon icon="arrows-pointing-in" class="size-6" x-show="fullscreen" x-cloak />
            </button>
            <button type="button" class="p-2 rounded-lg hover:bg-zinc-900/10 dark:hover:bg-white/10 transition-colors"
                x-bind:class="settings && 'bg-zinc-900/10 dark:bg-white/10'"
                x-bind:aria-expanded="settings"
                x-on:click="settings = ! settings" title="الإعدادات" aria-label="الإعدادات">
                <flux:icon icon="cog-6-tooth" class="size-6" />
            </button>
        </div>
    </header>

    {{-- Settings panel --}}
    <div x-show="settings" x-cloak
        x-on:click.outside="settings = false"
        x-transition.opacity.duration.150ms
        class="absolute top-[calc(1.6vh+3.5rem)] end-[3vw] z-30 w-[min(24rem,calc(100vw-2rem))] bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-2xl p-5 shadow-2xl space-y-5">
        <div class="flex items-center justify-between">
            <flux:heading size="sm">إعدادات العرض</flux:heading>
            <button type="button" class="p-1 rounded-md text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" x-on:click="settings = false" aria-label="إغلاق">
                <flux:icon icon="x-mark" variant="micro" class="size-4" />
            </button>
        </div>

        <div class="space-y-3">
            <livewire:shared.hijri-datepicker wire:model.live="endDate" label="آخر يوم محسوب (هجري)" :min-date="$firstDay" :max-date="$lastDay" />
            <livewire:shared.hijri-datepicker wire:model.live="attendanceStart" label="بداية فترة الحضور (هجري)" :max-date="$lastDay" />
        </div>

        <div>
            <div class="text-sm font-medium mb-2">القوائم المعروضة</div>
            <div class="space-y-1.5 max-h-[32vh] overflow-y-auto pe-1">
                @foreach($slides as $slide)
                    <label class="flex items-center gap-2 text-sm cursor-pointer" wire:key="toggle-{{ $slide['key'] }}">
                        <input type="checkbox" class="rounded border-zinc-400 dark:border-zinc-600 bg-white dark:bg-zinc-800"
                            x-bind:checked="! hidden.includes(@js($slide['key']))"
                            x-on:change="toggleList(@js($slide['key']))">
                        <span class="truncate">{{ $slide['title'] }}</span>
                        @if(empty($slide['rows']))
                            <span class="text-xs text-zinc-400 shrink-0">(فارغة)</span>
                        @endif
                    </label>
                @endforeach
            </div>
        </div>

        <div class="flex items-end justify-between gap-4">
            <div>
                <label for="results-seconds" class="text-sm font-medium block mb-1">مدة التقليب التلقائي (ثانية)</label>
                <input id="results-seconds" type="number" min="5" max="120" x-model.number="seconds"
                    x-on:change="seconds = clampSeconds(seconds); save(); restartTimer()"
                    class="w-24 rounded-lg bg-white dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-600 px-3 py-1.5 text-sm">
            </div>
            <flux:button size="sm" icon="arrow-path" wire:click="$refresh">تحديث النتائج</flux:button>
        </div>
    </div>

    {{-- Updating after a change of dates --}}
    <div wire:loading.delay wire:target="endDate, attendanceStart"
        class="absolute top-[1.6vh] inset-x-0 z-40 pointer-events-none">
        <div class="flex justify-center">
            <span class="rounded-full bg-zinc-900/80 dark:bg-white/90 text-white dark:text-zinc-900 text-sm px-4 py-1.5 shadow-lg">جارٍ تحديث النتائج…</span>
        </div>
    </div>

    {{-- Slides --}}
    <main class="grow relative min-h-0">
        @foreach($slides as $slide)
            @php
                $perPage = 10;
                $pages = max(1, (int) ceil(count($slide['rows']) / $perPage));
            @endphp
            <section wire:key="slide-{{ $slide['key'] }}"
                data-slide="{{ $slide['key'] }}" data-title="{{ $slide['title'] }}"
                {{-- A crossfade by class rather than x-show's transitions, which
                     stall when the operator presses through slides quickly. --}}
                x-bind:class="currentKey === @js($slide['key']) ? 'opacity-100 visible' : 'opacity-0 invisible pointer-events-none'"
                x-bind:aria-hidden="currentKey !== @js($slide['key'])"
                x-cloak
                x-data="{ chunk: 0, shown: false, pages: {{ $pages }} }"
                {{-- Each time a long list comes back on screen it shows its next page. --}}
                x-init="shown = currentKey === @js($slide['key']);
                    $watch('currentKey', (key) => {
                        if (key !== @js($slide['key'])) return;
                        if (shown && pages > 1) chunk = (chunk + 1) % pages;
                        shown = true;
                    })"
                class="absolute inset-0 flex flex-col px-[4vw] sm:px-[max(4vw,4.5rem)] pb-[2vh] transition-[opacity,visibility] duration-500 motion-reduce:transition-none"
                aria-roledescription="شريحة">

                <div class="text-center shrink-0 mb-[2.2vh]">
                    <h1 class="text-[clamp(1.5rem,min(5.2vh,6vw),3.75rem)] font-extrabold leading-tight">{{ $slide['title'] }}</h1>
                    <p class="text-zinc-500 dark:text-zinc-400 mt-[0.8vh] text-[clamp(0.8rem,min(2.1vh,3.4vw),1.35rem)]">{{ $slide['subtitle'] }}</p>
                    @if($pages > 1)
                        <p class="text-zinc-400 dark:text-zinc-500 mt-[0.4vh] text-[clamp(0.7rem,1.6vh,1rem)]" x-text="`الصفحة ${chunk + 1} من ${pages}`"></p>
                    @endif
                </div>

                @if(empty($slide['rows']))
                    <div class="grow flex items-center justify-center text-[clamp(1.1rem,3vh,2rem)] text-zinc-400 dark:text-zinc-600">
                        لا توجد نتائج في هذه الفترة
                    </div>
                @else
                    {{-- Ten rows to a screen whatever its height: every row a tenth of what is left. --}}
                    <ol class="grow min-h-0 w-full max-w-6xl mx-auto grid grid-rows-10 gap-[1vh]">
                        @foreach($slide['rows'] as $rank => $row)
                            @php
                                $medal = [
                                    ['disc' => '#f59e0b', 'edge' => '#b45309', 'text' => '#78350f'],
                                    ['disc' => '#d4d4d8', 'edge' => '#71717a', 'text' => '#3f3f46'],
                                    ['disc' => '#d97706', 'edge' => '#92400e', 'text' => '#451a03'],
                                ][$rank] ?? null;
                                $rowClass = match ($rank) {
                                    0 => 'bg-amber-100 border-amber-300 dark:bg-amber-500/15 dark:border-amber-400/40',
                                    1 => 'bg-zinc-200 border-zinc-300 dark:bg-zinc-400/15 dark:border-zinc-300/40',
                                    2 => 'bg-orange-100 border-orange-300 dark:bg-orange-700/15 dark:border-orange-500/40',
                                    default => 'bg-white border-zinc-200 dark:bg-white/5 dark:border-white/10',
                                };
                            @endphp
                            <li wire:key="row-{{ $slide['key'] }}-{{ $rank }}"
                                @if($pages > 1) x-show="Math.floor({{ $rank }} / {{ $perPage }}) === chunk" @endif
                                class="min-h-0 flex items-center gap-[min(2vw,1.5rem)] px-[min(2.4vw,1.75rem)] rounded-[clamp(0.6rem,1.6vh,1.25rem)] border {{ $rowClass }}">
                                <div class="h-full w-[clamp(2rem,5vh,3.5rem)] flex items-center justify-center shrink-0">
                                    @if($medal)
                                        <svg viewBox="0 0 24 30" class="h-[82%] max-h-16 aspect-[24/30]" role="img" aria-label="المركز {{ $rank + 1 }}">
                                            <path d="M7 0h4l1 4.5L13 0h4l-3.2 11h-4.6z" fill="#dc2626" />
                                            <path d="M7 0h2.6l2.2 7.5L9.6 11H8.2z" fill="#b91c1c" />
                                            <circle cx="12" cy="20" r="8.2" fill="{{ $medal['disc'] }}" stroke="{{ $medal['edge'] }}" stroke-width="1.6" />
                                            <text x="12" y="24" text-anchor="middle" font-size="10.5" font-weight="800" fill="{{ $medal['text'] }}">{{ $rank + 1 }}</text>
                                        </svg>
                                    @else
                                        <span class="text-[clamp(1rem,min(3.4vh,5vw),2.4rem)] font-black tabular-nums text-zinc-400 dark:text-zinc-500">{{ $rank + 1 }}</span>
                                    @endif
                                </div>
                                <div class="grow min-w-0">
                                    <div class="text-[clamp(0.95rem,min(3.3vh,4.6vw),2.5rem)] font-bold leading-tight truncate">{{ $row['name'] }}</div>
                                    @if($row['circle'] ?? null)
                                        <div class="text-[clamp(0.65rem,min(1.7vh,2.8vw),1.1rem)] leading-tight text-zinc-500 dark:text-zinc-400 truncate">{{ $row['circle'] }}</div>
                                    @endif
                                </div>
                                @if($slide['type'] === 'attendance')
                                    <dl class="flex items-center gap-[min(2.5vw,1.75rem)] shrink-0 text-center">
                                        <div>
                                            <dd class="text-[clamp(1rem,min(3.4vh,4.6vw),2.25rem)] font-extrabold leading-none text-red-600 dark:text-red-400 tabular-nums">{{ $row['absences'] }}</dd>
                                            <dt class="text-[clamp(0.6rem,1.4vh,0.8rem)] text-zinc-500 mt-1">غياب</dt>
                                        </div>
                                        <div>
                                            <dd class="text-[clamp(1rem,min(3.4vh,4.6vw),2.25rem)] font-extrabold leading-none text-emerald-600 dark:text-emerald-400 tabular-nums">{{ $row['presents'] }}</dd>
                                            <dt class="text-[clamp(0.6rem,1.4vh,0.8rem)] text-zinc-500 mt-1">حضور</dt>
                                        </div>
                                        <div>
                                            <dd class="text-[clamp(1rem,min(3.4vh,4.6vw),2.25rem)] font-extrabold leading-none tabular-nums">{{ $row['percentage'] }}%</dd>
                                            <dt class="text-[clamp(0.6rem,1.4vh,0.8rem)] text-zinc-500 mt-1">انضباط</dt>
                                        </div>
                                    </dl>
                                @else
                                    <div class="shrink-0 flex items-baseline gap-1.5">
                                        <span class="text-[clamp(1.15rem,min(4vh,5.5vw),3rem)] font-extrabold leading-none tabular-nums text-amber-600 dark:text-amber-300">{{ number_format($row['value']) }}</span>
                                        <span class="text-[clamp(0.65rem,min(1.7vh,2.8vw),1.1rem)] text-zinc-500 dark:text-zinc-400">{{ $slide['unit'] }}</span>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        @endforeach

        <div x-show="! visible.length" x-cloak class="h-full flex items-center justify-center px-6 text-center text-[clamp(1.1rem,3vh,2rem)] text-zinc-400 dark:text-zinc-600">
            كل القوائم مخفية — فعّل قائمة واحدة على الأقل من الإعدادات
        </div>

        {{-- Arrows: the next slide is to the left, as a page turns in Arabic. A
             phone swipes instead, the arrows would sit over the rows. --}}
        <div x-show="visible.length > 1" x-cloak class="max-sm:hidden transition-opacity duration-300" x-bind:class="idle && 'opacity-0 pointer-events-none'">
            <button type="button" x-on:click="next()" aria-label="الشريحة التالية"
                class="absolute left-[1vw] top-1/2 -translate-y-1/2 z-20 p-[clamp(0.4rem,1.2vh,0.75rem)] rounded-full bg-zinc-900/10 hover:bg-zinc-900/20 dark:bg-white/10 dark:hover:bg-white/20 transition-colors">
                <flux:icon icon="chevron-left" class="size-[clamp(1.25rem,3.5vh,2rem)]" />
            </button>
            <button type="button" x-on:click="prev()" aria-label="الشريحة السابقة"
                class="absolute right-[1vw] top-1/2 -translate-y-1/2 z-20 p-[clamp(0.4rem,1.2vh,0.75rem)] rounded-full bg-zinc-900/10 hover:bg-zinc-900/20 dark:bg-white/10 dark:hover:bg-white/20 transition-colors">
                <flux:icon icon="chevron-right" class="size-[clamp(1.25rem,3.5vh,2rem)]" />
            </button>
        </div>
    </main>

    {{-- Where the deck is, and how long until it moves on --}}
    <footer class="shrink-0 pb-[1.6vh] pt-[0.6vh] flex flex-col items-center gap-[1vh]" x-show="visible.length > 1" x-cloak>
        <div class="h-1 w-[min(20rem,60vw)] rounded-full bg-zinc-900/10 dark:bg-white/10 overflow-hidden" x-show="auto">
            <div x-ref="progress" class="h-full w-full bg-amber-500 origin-right" style="transform: scaleX(0)"></div>
        </div>
        <nav class="flex items-center gap-1.5 transition-opacity duration-300" x-bind:class="idle && 'opacity-40'" aria-label="الشرائح">
            <template x-for="(key, index) in visible" x-bind:key="key">
                <button type="button" x-on:click="go(index)"
                    x-bind:title="titles[key]" x-bind:aria-label="titles[key]" x-bind:aria-current="index === current"
                    x-bind:class="index === current ? 'w-7 bg-amber-500' : 'w-2.5 bg-zinc-400/50 hover:bg-zinc-400'"
                    class="h-2.5 rounded-full transition-all duration-300"></button>
            </template>
        </nav>
    </footer>
</div>
