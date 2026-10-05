@if(auth('student')->check())
    @php
        $student = auth('student')->user();
        $activeGamification = \App\Models\Leaderboard::whereHas('circles', fn($q) => $q->where('circles.id', $student->circle_id))
            ->whereNotNull('supervisor_id')
            ->where('is_active', true)
            ->latest()
            ->first();

        if (!$activeGamification) {
            $activeGamification = \App\Models\Leaderboard::where('circle_id', $student->circle_id)
                ->whereNull('supervisor_id')
                ->where('is_active', true)
                ->latest()
                ->first();
        }

        if ($activeGamification && $activeGamification->competition_type !== 'gamification') {
            $activeGamification = null;
        }
    @endphp

    @if($activeGamification)
        @php
            $studentTeam = \App\Models\GamificationTeam::whereHas('students', fn($q) => $q->where('users.id', $student->id))
                ->where('leaderboard_id', $activeGamification->id)
                ->first();
            $theme = \App\Services\GamificationThemeService::getTheme($activeGamification);
            $navColor = $studentTeam ? ($studentTeam->color ?? ($theme['color'] ?? '#4f46e5')) : ($theme['color'] ?? '#4f46e5');

            // The same tabs as the dashboard's own strip on a wide screen.
            $navItems = \App\Support\StudentGamificationTabs::for($studentTeam, $theme);
        @endphp

        {{-- data-bottom-nav: steps aside while the side menu is open, as the
             everyday bars do (app.css), instead of covering the menu's foot.

             The dashboard decides which tab opens: it reads the sidebar's
             fragment and checks the remembered tab against the ones it draws,
             then announces its choice as gamnav-changed. This bar follows for
             its highlight. Its own first guess is checked the same way, so a
             remembered «فريقي» the student no longer has lights the home tab
             rather than nothing. --}}
        <div data-bottom-nav
            x-data="{
                tabs: @js(array_column($navItems, 'tab')),
                activeTab: 'leaderboard',
                init() {
                    let remembered = null;
                    try { remembered = localStorage.getItem('student-gam-tab'); } catch (e) {}
                    this.activeTab = this.tabs.includes(remembered) ? remembered : 'leaderboard';
                },
            }"
            x-on:gamnav-changed.window="if (tabs.includes($event.detail.tab)) { activeTab = $event.detail.tab; try { localStorage.setItem('student-gam-tab', activeTab); } catch (e) {} }"
            class="fixed bottom-0 w-full start-0 z-[9999] lg:hidden border-t border-white/10 shadow-none"
            style="background-color: {{ $navColor }}; padding-bottom: env(safe-area-inset-bottom, 12px);">
            <div class="flex items-center justify-around px-2 min-h-18 max-w-lg mx-auto">

                @foreach($navItems as $item)
                    <button
                        x-on:click="window.dispatchEvent(new CustomEvent('gamnav-changed', { detail: { tab: '{{ $item['tab'] }}' } })); @if($item['tab'] === 'news') window.dispatchEvent(new CustomEvent('news-opened')); @endif window.scrollTo({ top: 0, behavior: 'instant' });"
                        :class="activeTab === '{{ $item['tab'] }}' ? 'text-white' : 'text-white/60 hover:text-white'"
                        class="relative flex flex-col items-center justify-center duration-300 ease-out h-full flex-1 py-2 cursor-pointer">

                        <div :class="activeTab === '{{ $item['tab'] }}' ? 'bg-white/15 px-6 py-2' : 'p-2'"
                            class="relative flex items-center justify-center min-h-15 rounded-full duration-300">
                            {{-- Both shapes are drawn here and Alpine shows one. Binding
                                 variant on a single icon only set an attribute on an svg
                                 already drawn as an outline, so the open tab never filled. --}}
                            <flux:icon icon="{{ $item['icon'] }}" variant="solid" class="size-7 shrink-0"
                                x-show="activeTab === '{{ $item['tab'] }}'" x-cloak />
                            <flux:icon icon="{{ $item['icon'] }}" variant="outline" class="size-7 shrink-0"
                                x-show="activeTab !== '{{ $item['tab'] }}'" />
                            <span x-show="activeTab === '{{ $item['tab'] }}'" x-cloak class="ms-2 font-bold text-sm truncate block">{{ $item['name'] }}</span>

                            @if($item['tab'] === 'news')
                                <livewire:student.gamification-news-badge :leaderboard-id="$activeGamification->id" wire:key="gam-news-badge-{{ $activeGamification->id }}" />
                            @endif
                        </div>
                    </button>
                @endforeach

            </div>
        </div>
    @endif
@endif