// The celebration cards on the student's gamification page
// (Alpine.data('gamCelebrations')): a level reached, a badge approved, a
// streak milestone reached. One card shows at a time, above the bottom bar,
// and never blocks the page.
//
// The cards are drawn in order (the level first, then the badges, then the
// milestones) and the stylesheet shows only the first one not hidden. A card
// hides when:
//   - it was taken: the claim store hides its key (claims.js), the same key
//     its row in the rewards panel hides by, so taking it in one place takes
//     it from both;
//   - it was put off with «لاحقاً»: its key is kept in sessionStorage
//     ('gam-later-{leaderboard}') for the rest of the visit, and it waits in
//     the rewards panel meanwhile; a new visit shows it again;
//   - a text field has focus (the phone's keyboard is up), when they all do.
//
// The level card has no «لاحقاً». It is acknowledged to the server
// (acknowledgeLevelUp) when the student closes it, or when it hides by itself
// after being on screen for six seconds: only time the card was truly on
// screen counts (the page visible, the card drawn, and nothing over it: not
// the phone's side menu, which takes it off, nor a modal laid over it), so a
// card never seen is never taken as seen and returns on the next visit. Should a claim's redraw bring it, it waits until
// the claim's particles have landed: the cards may only be drawn by that very
// redraw (nothing was waiting before the claim), so as they mount they also
// read when the claim store's last claim started.

import { gamCount } from './numbers.js';

/** How long the level card must have been seen before hiding by itself counts. */
export const LEVEL_SEEN_MS = 2000;

/** How long the level card stays on screen before hiding by itself. */
export const LEVEL_AUTO_HIDE_MS = 6000;

/** How long a level card a claim brought waits: the particles' flight, then 900 ms. */
export const LEVEL_AFTER_CLAIM_MS = 2100;

const WATCH_EVERY_MS = 250;

const NOT_TYPED_INTO = ['button', 'checkbox', 'color', 'file', 'hidden', 'image', 'radio', 'range', 'reset', 'submit'];

/** The sessionStorage key for the cards put off in this competition. */
export function laterKey(leaderboardId) {
    return 'gam-later-' + leaderboardId;
}

/** The keys put off during this visit. Storage that throws or holds junk reads as none. */
export function readLater(storage, leaderboardId) {
    try {
        const raw = storage?.getItem(laterKey(leaderboardId));
        const list = raw ? JSON.parse(raw) : [];

        return Array.isArray(list) ? list.filter((key) => typeof key === 'string') : [];
    } catch (e) {
        return [];
    }
}

/** Keep the keys put off. Returns whether the storage took them. */
export function writeLater(storage, leaderboardId, keys) {
    try {
        storage?.setItem(laterKey(leaderboardId), JSON.stringify(keys));

        return true;
    } catch (e) {
        return false;
    }
}

/**
 * Whether the one-time hint («تجدها في …») is still to be shown, marking it
 * shown. Storage that throws shows it never, rather than every time.
 */
export function takeHint(storage, name = 'gam-later-hint') {
    try {
        if (! storage || storage.getItem(name)) {
            return false;
        }

        storage.setItem(name, '1');

        return true;
    } catch (e) {
        return false;
    }
}

/** Whether focus sits in a field the phone raises its keyboard for. */
export function isTextEntry(el) {
    if (! el || typeof el.tagName !== 'string') {
        return false;
    }

    const tag = el.tagName.toUpperCase();

    if (tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable === true) {
        return true;
    }

    return tag === 'INPUT' && ! NOT_TYPED_INTO.includes(String(el.type || 'text').toLowerCase());
}

/**
 * Whether an element is what the screen shows where it stands: it has a size
 * (the phone's side menu and the keyboard take the cards off with display:
 * none), no modal dialog is open over the page (Flux modals sit in the top
 * layer), and the point at its centre is the element itself or inside it, not
 * something laid over it. Stand-ins without layout count as shown.
 */
export function isShownOnTop(el, doc) {
    if (! el || typeof el.getBoundingClientRect !== 'function') {
        return true;
    }

    const box = el.getBoundingClientRect();
    if (! (box.width > 0) || ! (box.height > 0)) {
        return false;
    }

    let modal = null;
    try {
        modal = doc?.querySelector?.('dialog:modal') ?? null;
    } catch (e) {
        // A browser without :modal: the hit test below still finds the dialog's backdrop.
    }

    if (modal && ! modal.contains?.(el)) {
        return false;
    }

    if (typeof doc?.elementFromPoint !== 'function') {
        return true;
    }

    const hit = doc.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);

    return Boolean(hit) && (hit === el || el.contains?.(hit) === true);
}

/**
 * Counts the time a card has been on screen, only while it runs.
 *
 * @param {{ now?: () => number, seenMs?: number, autoHideMs?: number }} options
 */
export function createSeenTimer({ now = () => Date.now(), seenMs = LEVEL_SEEN_MS, autoHideMs = LEVEL_AUTO_HIDE_MS } = {}) {
    let total = 0;
    let since = null;

    return {
        start() {
            if (since === null) {
                since = now();
            }
        },
        stop() {
            if (since !== null) {
                total += now() - since;
                since = null;
            }
        },
        running() {
            return since !== null;
        },
        seen() {
            return total + (since === null ? 0 : now() - since);
        },
        seenEnough() {
            return this.seen() >= seenMs;
        },
        due() {
            return this.seen() >= autoHideMs;
        },
    };
}

/**
 * The cards' state, bound to a page. Tests hand in stand-ins.
 *
 * @param {{ leaderboardId: number|string, awardForms?: string[], hint?: string,
 *           session?: Storage, local?: Storage, now?: () => number,
 *           doc?: Document, toast?: (text: string) => void }} config
 */
export function celebrations(config = {}) {
    const now = config.now ?? (() => Date.now());

    return {
        later: [],
        inputFocused: false,
        hovering: false,
        tick: 0,
        holdLevelUntil: 0,
        doneLevel: null,
        timerLevel: null,
        burstLevel: null,
        timer: null,

        init() {
            const doc = this.doc();
            this.later = readLater(this.session(), config.leaderboardId);
            this.timer = createSeenTimer({ now });
            this.holdAfterLastClaim();

            this.onFocusChange = () => {
                this.inputFocused = isTextEntry(this.doc()?.activeElement);
            };
            this.onFocusOut = () => setTimeout(this.onFocusChange, 0);
            this.onVisibility = () => this.watchLevel();

            doc?.addEventListener('focusin', this.onFocusChange);
            doc?.addEventListener('focusout', this.onFocusOut);
            doc?.addEventListener('visibilitychange', this.onVisibility);
            this.onFocusChange();

            // Only a level card (or one a claim may bring) needs the clock.
            this.watcher = setInterval(() => {
                if (this.levelCard() || this.holdLevelUntil > now()) {
                    this.tick++;
                    this.watchLevel();
                }
            }, WATCH_EVERY_MS);
        },

        destroy() {
            const doc = this.doc();
            doc?.removeEventListener('focusin', this.onFocusChange);
            doc?.removeEventListener('focusout', this.onFocusOut);
            doc?.removeEventListener('visibilitychange', this.onVisibility);
            clearInterval(this.watcher);
        },

        doc() {
            return config.doc ?? globalThis.document;
        },

        session() {
            try {
                return config.session ?? globalThis.sessionStorage;
            } catch (e) {
                return null;
            }
        },

        local() {
            try {
                return config.local ?? globalThis.localStorage;
            } catch (e) {
                return null;
            }
        },

        store() {
            return this.$store.gamClaims;
        },

        /** Whether an award's card is hidden: taken, or put off this visit. */
        isHidden(key) {
            return this.later.includes(key) || this.store().isHidden(key);
        },

        /** The awards drawn as cards, as the claim store takes them. */
        awards() {
            return Array.from(this.$root.querySelectorAll('[data-award-card]')).map((card) => ({
                key: card.dataset.claimKey,
                xp: Number(card.dataset.xp) || 0,
                coins: Number(card.dataset.coins) || 0,
            }));
        },

        /** The awards not yet taken, the ones put off included: what «استلام الكل» takes. */
        untaken() {
            return this.awards().filter((award) => ! this.store().isHidden(award.key));
        },

        /** «لديك جائزتان بانتظار الاستلام», for the screen reader. */
        liveLine() {
            const count = this.untaken().length;

            return count > 0 ? 'لديك ' + gamCount(count, config.awardForms ?? []) + ' بانتظار الاستلام' : '';
        },

        /** Put a card off for the rest of the visit; say once where it waits. */
        postpone(key) {
            if (! key || this.later.includes(key)) {
                return;
            }

            this.later = [...this.later, key];
            writeLater(this.session(), config.leaderboardId, this.later);

            if (config.hint && takeHint(this.local())) {
                (config.toast ?? ((text) => globalThis.window?.Flux?.toast?.(text)))(config.hint);
            }
        },

        /** Hide every card at once, before «استلام الكل»'s own request goes. */
        claimAll(el) {
            return this.store().claimBatch('awards', this.untaken(), el, 16);
        },

        /* ------------------------------------------------------- the level card */

        levelCard() {
            return this.$root.querySelector('[data-level-card]');
        },

        /** The level the card on the page celebrates, or null. */
        levelNumber() {
            const card = this.levelCard();

            return card ? Number(card.dataset.level) : null;
        },

        /**
         * Whether the level card is on show: drawn, not closed, and not waiting
         * for a claim's particles. Read after every redraw (the store's
         * version), which drew its classes afresh.
         */
        levelVisible() {
            void this.store().version;
            void this.tick;
            const level = this.levelNumber();

            return level !== null && level !== this.doneLevel && now() >= this.holdLevelUntil;
        },

        /**
         * A claim has started. Should its redraw bring a level card, let the
         * particles land first; a card already on show stays.
         */
        holdLevel() {
            if (this.levelVisible() && this.levelOnScreen()) {
                return;
            }

            this.holdLevelUntil = now() + LEVEL_AFTER_CLAIM_MS;
        },

        /**
         * Drawn by a claim's redraw, the cards missed its 'gam-claiming': wait
         * out what is left of that claim's hold all the same.
         */
        holdAfterLastClaim() {
            const startedAt = Number(this.store()?.lastClaimAt) || 0;

            if (startedAt > 0 && now() - startedAt < LEVEL_AFTER_CLAIM_MS) {
                this.holdLevelUntil = Math.max(this.holdLevelUntil, startedAt + LEVEL_AFTER_CLAIM_MS);
            }
        },

        /**
         * Whether the level card is what the reader sees right now: on show,
         * the page visible, no keyboard up, and the card drawn with nothing
         * over it (the side menu hides it; a modal covers it).
         */
        levelOnScreen() {
            return this.levelVisible()
                && ! this.inputFocused
                && (this.doc()?.visibilityState ?? 'visible') === 'visible'
                && isShownOnTop(this.levelCard(), this.doc());
        },

        /** Count the time on screen; hide by itself once it has been long enough. */
        watchLevel() {
            const level = this.levelNumber();
            if (level === null || level === this.doneLevel) {
                this.timer?.stop();

                return;
            }

            if (this.timerLevel !== level) {
                this.timer = createSeenTimer({ now });
                this.timerLevel = level;
            }

            if (this.levelOnScreen() && ! this.hovering) {
                this.timer.start();
                this.burst(level);
            } else {
                this.timer.stop();
            }

            if (this.timer.due() && this.timer.seenEnough()) {
                this.closeLevel(false);
            }
        },

        /** Particles from the card to the level meter, once (none under reduced motion). */
        burst(level) {
            if (this.burstLevel === level) {
                return;
            }

            this.burstLevel = level;
            const from = this.levelCard()?.querySelector('[data-level-icon]');
            globalThis.window?.gamLaunchXp?.(from, 20, 'gam-level-meter');
        },

        /**
         * Close the level card and tell the server it was seen. Closed by the
         * student it was seen, as they could not have tapped it otherwise;
         * hidden by itself, only once it was on screen long enough.
         */
        closeLevel(byStudent = true) {
            const level = this.levelNumber();
            if (level === null || level === this.doneLevel) {
                return false;
            }

            this.timer?.stop();
            this.doneLevel = level;

            if (byStudent || this.timer?.seenEnough()) {
                this.$wire?.acknowledgeLevelUp(level);

                return true;
            }

            return false;
        },

        /** Whether some of the level's coins still wait to be taken. */
        levelRewardPending(items = []) {
            return items.some((item) => ! this.store().isHidden(item.key));
        },

        /** Take the level's coins from the card, as their rows in the panel would. */
        claimLevelReward(items = [], el = null) {
            for (const item of items) {
                this.store().claim(item.key, { xp: 0, coins: item.coins }, () => this.$wire.claimReward(item.id), el);
            }
        },
    };
}
