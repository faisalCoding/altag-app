<?php

use App\Models\GamificationNews;
use Livewire\Component;

new class extends Component
{
    public int $leaderboardId;

    /**
     * Latest news ids (newest first) for the competition. The unread count is
     * computed client-side by comparing these against the last-seen id kept in
     * the browser's localStorage, so opening the news tab clears the badge
     * per-device without any server-side read tracking.
     *
     * @var array<int, int>
     */
    public array $newsIds = [];

    public function mount(int $leaderboardId): void
    {
        $this->leaderboardId = $leaderboardId;
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->newsIds = GamificationNews::where('leaderboard_id', $this->leaderboardId)
            ->orderByDesc('id')
            ->limit(100)
            ->pluck('id')
            ->all();
    }
}; ?>

{{-- Asks for new ids once a minute, and only while the badge is on screen:
     the bottom bar is hidden on a wide screen, and a tab in the background is
     slowed further by Livewire itself. It asked every 3 seconds from every open
     page, each ask a request and a session write, for a count that moves a few
     times a day. The browser's storage may refuse (a private window); the badge
     then counts everything as unread rather than breaking.

     Opening the news marks as seen the newest of the ids it last asked for and
     the newest the news tab itself drew (data-news-max-id, on the dashboard).
     The tab is drawn again on every tap on the page, so it can hold news that
     came after the badge last asked; marked by its own ids alone, that news
     came back as unread at the next ask, though the student had just read it. --}}
<div wire:poll.60s.visible="refresh"
    x-data="{
        seen: 0,
        init() {
            try { this.seen = Number(localStorage.getItem('gam-news-seen-{{ $leaderboardId }}') || 0); } catch (e) {}
            let openTab = null;
            try { openTab = localStorage.getItem('student-gam-tab'); } catch (e) {}
            if (openTab === 'news') { this.markSeen(); }
        },
        get unread() {
            return (this.$wire.newsIds || []).filter(id => id > this.seen).length;
        },
        markSeen() {
            let ids = this.$wire.newsIds || [];
            let max = ids.length ? Math.max(...ids) : 0;
            let drawn = Number(document.querySelector('[data-news-max-id]')?.dataset.newsMaxId || 0);
            this.seen = Math.max(this.seen, max, drawn);
            try { localStorage.setItem('gam-news-seen-{{ $leaderboardId }}', this.seen); } catch (e) {}
        }
    }"
    x-on:news-opened.window="markSeen()"
    class="absolute -top-0.5 -end-0.5 pointer-events-none">
    <span x-show="unread > 0" x-cloak
        x-text="unread > 9 ? '9+' : unread"
        class="flex items-center justify-center min-w-5 h-5 px-1 rounded-full bg-rose-500 text-white text-[10px] font-black leading-none ring-2 ring-white shadow"></span>
</div>
