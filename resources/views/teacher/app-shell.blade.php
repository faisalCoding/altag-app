<x-layouts.role-shell>
    <x-slot:title>
        {{ __('لوحة تحكم المعلم') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('teacher.sidebar-nav')
    </x-slot:sidebar>

    @php
        $teacher = Auth::guard('teacher')->user();
        $teacherCircleIds = $teacher->circles()->pluck('circles.id');

        $activeLeaderboard = null;

        if ($teacherCircleIds->isNotEmpty()) {
            $activeLeaderboard = \App\Models\Leaderboard::whereHas('circles', function($q) use ($teacherCircleIds) {
                    $q->whereIn('circles.id', $teacherCircleIds);
                })
                ->whereNotNull('supervisor_id')
                ->where('is_active_for_grading', true)
                ->first();

            if (!$activeLeaderboard) {
                $activeLeaderboard = \App\Models\Leaderboard::whereIn('circle_id', $teacherCircleIds)
                    ->whereNull('supervisor_id')
                    ->where('is_active_for_grading', true)
                    ->first();
            }
        }
    @endphp

    <div id="teacher-app-shell" x-data="{
        activeTab: '{{ $initialTab ?? 'dashboard' }}',
        showStaleWarning: false,
        lastActivity: Date.now(),

        {{-- Twenty minutes without a tap, counted from the clock rather than a
             timer: a phone pauses a page's timers while it is locked or in the
             background, and the warning has to show on return all the same. --}}
        checkStale() {
            if (! this.showStaleWarning && Date.now() - this.lastActivity >= 20 * 60 * 1000) {
                this.showStaleWarning = true;
            }
        },

        registerActivity() {
            // Every interaction hits Livewire and returns fresh data, so activity
            // means the screen is NOT stale. Once the warning is visible it stays
            // until the teacher refreshes or dismisses it explicitly.
            if (this.showStaleWarning) return;
            this.lastActivity = Date.now();
        },

        dismissStaleWarning() {
            this.showStaleWarning = false;
            this.lastActivity = Date.now();
        },

        init() {
            // Listen for popstate (browser back/forward) to update tab if needed
            window.addEventListener('popstate', (e) => {
                const path = window.location.pathname;
                const match = path.match(/\/teacher\/([a-zA-Z0-9\-]+)/);
                if (match && match[1]) {
                    this.activeTab = match[1];
                } else if (path === '/teacher/dashboard') {
                    this.activeTab = 'dashboard';
                }
            });

            // Stale data warning: after 20 minutes of *inactivity*, not 20
            // minutes after page load — checked each minute, and at once when
            // the teacher comes back to the page.
            setInterval(() => this.checkStale(), 60 * 1000);
            document.addEventListener('visibilitychange', () => { if (! document.hidden) this.checkStale(); });
            window.addEventListener('pointerdown', () => this.registerActivity(), { passive: true });
            window.addEventListener('keydown', () => this.registerActivity(), { passive: true });
        }
    }"
    x-on:switch-tab.window="
        activeTab = $event.detail.tab;
        if ($event.detail.url && window.location.pathname !== $event.detail.url) {
            history.pushState(null, '', $event.detail.url);
        }
    "
    class="relative min-h-[80vh]">

        {{--
            Stale data warning, after 20 minutes of inactivity. A card rather
            than a one-line pill: on a phone the line ran past both edges, and
            its buttons were too small to tap. It sits under the notch, wraps,
            and leaves room either side.
        --}}
        <div x-cloak x-show="showStaleWarning" role="status" aria-live="polite"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 -translate-y-3"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-x-3 z-[110] mx-auto max-w-md rounded-2xl border border-amber-200 bg-white/95 p-3.5 shadow-lg shadow-black/10 backdrop-blur dark:border-amber-500/30 dark:bg-zinc-900/95"
            style="top: max(0.75rem, env(safe-area-inset-top));">
            <div class="flex items-start gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                    <flux:icon icon="clock" class="size-5" />
                </div>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('قد تكون البيانات غير محدّثة') }}</p>
                    <p class="mt-0.5 text-xs leading-relaxed text-zinc-600 dark:text-zinc-300">
                        {{ __('مرّت عشرون دقيقة دون استخدام الصفحة، وربما عدّل غيرك شيئاً خلالها.') }}
                    </p>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <button type="button" onclick="window.location.reload()"
                            class="inline-flex h-10 items-center gap-1.5 rounded-xl bg-amber-500 px-4 text-sm font-bold text-white transition-colors hover:bg-amber-600">
                            <flux:icon icon="arrow-path" class="size-4" />
                            {{ __('تحديث الآن') }}
                        </button>
                        <button type="button" x-on:click="dismissStaleWarning()"
                            class="inline-flex h-10 items-center rounded-xl px-3 text-sm font-medium text-zinc-600 transition-colors hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800">
                            {{ __('لاحقاً') }}
                        </button>
                    </div>
                </div>

                <button type="button" x-on:click="dismissStaleWarning()" aria-label="{{ __('إغلاق') }}"
                    class="-me-1 -mt-1 flex size-9 shrink-0 items-center justify-center rounded-xl text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">
                    <flux:icon icon="x-mark" class="size-5" />
                </button>
            </div>
        </div>

        @php
            // The tab asked for renders with the page; the others load right
            // behind it (deferred), so the first shows at once and switching
            // to any other is instant by the time the teacher taps it.
            $deferred = fn (string $tab) => ($initialTab ?? 'dashboard') !== $tab;
        @endphp

        <div x-show="activeTab === 'dashboard'" x-cloak class="py-4 md:p-8 space-y-8">
            @if ($deferred('dashboard'))
                <livewire:teacher.dashboard defer />
                <livewire:shared.exceeded-limits defer />
            @else
                <livewire:teacher.dashboard />
                {{-- The violations list carries its own heading. --}}
                <livewire:shared.exceeded-limits />
            @endif
        </div>

        <div x-show="activeTab === 'students'" x-cloak>
            @if ($deferred('students'))
                <livewire:teacher.student-manager defer />
            @else
                <livewire:teacher.student-manager />
            @endif
        </div>

        <div x-show="activeTab === 'plan-creator'" x-cloak>
            @if ($deferred('plan-creator'))
                <livewire:shared.plan-creator defer />
            @else
                <livewire:shared.plan-creator />
            @endif
        </div>

        <div x-show="activeTab === 'tasmeeh'" x-cloak>
            @if ($deferred('tasmeeh'))
                <livewire:teacher.tasmeeh-manager defer />
            @else
                <livewire:teacher.tasmeeh-manager />
            @endif
        </div>

        <div x-show="activeTab === 'leaderboards'" x-cloak>
            @if ($deferred('leaderboards'))
                <livewire:teacher.leaderboards defer />
            @else
                <livewire:teacher.leaderboards />
            @endif
        </div>

        <div x-show="activeTab === 'grade-items'" x-cloak class="p-1 md:p-8">
            @if ($activeLeaderboard)
                @if ($deferred('grade-items'))
                    <livewire:teacher.leaderboard-grade :leaderboard-id="$activeLeaderboard->id" defer />
                @else
                    <livewire:teacher.leaderboard-grade :leaderboard-id="$activeLeaderboard->id" />
                @endif
            @else
                <div class="text-center py-12 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl border border-dashed border-zinc-200 dark:border-zinc-700">
                    <flux:icon icon="star" class="size-10 mx-auto text-zinc-400 mb-3" />
                    <flux:heading size="md" class="mb-2">{{ __('لا توجد مسابقة معتمدة للتسجيل') }}</flux:heading>
                    <p class="text-zinc-500 mb-4 max-w-md mx-auto">
                        {{ __('يرجى تحديد مسابقة من قائمة المسابقات لاعتمادها كالمسابقة الأساسية في شريط التنقل لتسجيل بنود التقييم.') }}
                    </p>
                    <flux:button x-on:click="$dispatch('switch-tab', { tab: 'leaderboards', url: '{{ route('teacher.leaderboards') }}' })" variant="primary" icon="trophy">
                        {{ __('الذهاب لإدارة المسابقات') }}
                    </flux:button>
                </div>
            @endif
        </div>

        <div x-show="activeTab === 'attendance'" x-cloak class="p-1 md:p-8">
            @if ($deferred('attendance'))
                <livewire:teacher.attendance defer />
            @else
                <livewire:teacher.attendance />
            @endif
        </div>
    </div>
</x-layouts.role-shell>
