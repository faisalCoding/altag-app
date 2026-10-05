// The claim store behind every «استلام» on the student's gamification page
// (Alpine.store('gamClaims')).
//
// A claim hides its item at once and celebrates, then asks the server. The
// same item can show in more than one place (a reward row now; a celebration
// card and a badge sheet later), and every place asks this store whether its
// key is hidden, so one claim makes it vanish everywhere together.
//
// Keys: "tx-{id}" for a reward transaction, "badge-{id}", and
// "milestone-{id}-{date}". A key starting with "*" names a batch: "*panel" is
// every row the panel's «استلام الكل» hid, "*awards" every card the
// celebrations' «استلام الكل» hid, and "*" alone is everything still in
// flight. Any other key also names the keys it begins, up to a dash: the
// server answers a milestone claim as "milestone-{id}", which is every run of
// that milestone the page hid ("milestone-{id}-{date}").
//
// The server answers each claim with an event:
//   reward-claimed   {xp, coins, keys}   → confirm(keys)
//   gam-claim-failed {keys}              → restore(keys), with a toast
// A request that never reaches the server (no network, a server error, a
// cancelled request) rejects the promise the call returned, which restores too.
// After each redraw, prune() forgets keys whose items the page no longer
// draws, so a total read from the page is never counted down twice.

import { gamCount, gamSigned, gamUnit } from './numbers.js';

function whole(value) {
    const n = Math.trunc(Number(value) || 0);

    return n > 0 ? n : 0;
}

/** The keys of what the rewards panel lists: rewards, badges and milestones. */
export const PANEL_KEYS = ['tx-', 'badge-', 'milestone-'];

/**
 * Fly particles to what the claim fills (the level meter, or the coins when it
 * gives coins only) and float the amounts up from the stats bar.
 */
function celebrate(motion, fromEl, xp, coins, particles) {
    if (! motion) {
        return;
    }

    if (xp > 0) {
        motion.launchXp(fromEl, particles, 'gam-level-meter');
        motion.float('gam-xp-value', gamSigned(xp), 'up');
    } else if (coins > 0) {
        motion.launchXp(fromEl, particles, 'gam-coins-value');
    }

    if (coins > 0) {
        motion.float('gam-coins-value', gamSigned(coins), 'coin');
    }
}

/**
 * @param {{ motion?: object|null, dispatch?: Function, exists?: Function, now?: () => number }} deps
 *   motion:   the helpers from createMotion(), or null for none
 *   dispatch: (name, detail) => void, announces a claim as it starts
 *   exists:   (key) => boolean, whether the page still draws the key's item
 *   now:      the clock lastClaimAt is read from
 */
export function createClaimStore({ motion = null, dispatch = () => {}, exists = () => true, now = () => Date.now() } = {}) {
    return {
        /** key → { xp, coins, state: 'pending'|'claimed', batch: string|null } */
        entries: {},

        /**
         * When the last claim started (0 for none). The celebration cards may
         * not be on the page yet when it does (nothing to celebrate until the
         * claim's redraw brings a level card), so they read it as they mount
         * rather than relying on the 'gam-claiming' event alone.
         */
        lastClaimAt: 0,

        /** Bumped on every change; whatever reads the page's totals reads this too. */
        version: 0,

        isHidden(key) {
            void this.version;

            return key in this.entries;
        },

        isPending(key) {
            void this.version;

            return this.entries[key]?.state === 'pending';
        },

        /** What the hidden items with keys starting so (or with any of a list) add up to. */
        hiddenTotals(prefix = 'tx-') {
            void this.version;
            const prefixes = Array.isArray(prefix) ? prefix : [prefix];
            const totals = { count: 0, xp: 0, coins: 0 };

            for (const [key, entry] of Object.entries(this.entries)) {
                if (! prefixes.some((wanted) => key.startsWith(wanted))) {
                    continue;
                }

                totals.count++;
                totals.xp += entry.xp;
                totals.coins += entry.coins;
            }

            return totals;
        },

        /**
         * Claim one item: hide it, celebrate, then run the call (such as
         * () => $wire.claimReward(12)). A claim already hidden does nothing,
         * so a double tap claims once.
         */
        claim(key, amounts = {}, call = null, fromEl = null) {
            if (! key || this.isHidden(key)) {
                return null;
            }

            const xp = whole(amounts?.xp);
            const coins = whole(amounts?.coins);

            this.write({ [key]: { xp, coins, state: 'pending', batch: null } });
            this.lastClaimAt = now();
            dispatch('gam-claiming', { key });
            celebrate(motion, fromEl, xp, coins, 10);

            let result = null;
            try {
                result = typeof call === 'function' ? call() : null;
            } catch (error) {
                this.restore([key]);

                return null;
            }

            // The handled promise is returned, not the call's own: Alpine reports
            // a rejected promise an expression returns as an error of its own.
            if (result && typeof result.then === 'function') {
                return result.then((value) => value, () => {
                    this.restore([key]);

                    return null;
                });
            }

            return result;
        },

        /**
         * Hide a group of items at once, as «استلام الكل» does before its own
         * request goes. items: [{ key, xp, coins }]. Returns how many it hid.
         */
        claimBatch(batch, items = [], fromEl = null, particles = 16) {
            const fresh = (items ?? []).filter((item) => item?.key && ! this.isHidden(item.key));
            if (fresh.length === 0) {
                return 0;
            }

            const added = {};
            let xp = 0;
            let coins = 0;
            for (const item of fresh) {
                const entry = { xp: whole(item.xp), coins: whole(item.coins), state: 'pending', batch };
                added[item.key] = entry;
                xp += entry.xp;
                coins += entry.coins;
            }

            this.write(added);
            this.lastClaimAt = now();
            dispatch('gam-claiming', { keys: Object.keys(added), batch });
            celebrate(motion, fromEl, xp, coins, particles);

            return fresh.length;
        },

        /** The server took these: they stay hidden until the redraw drops them. */
        confirm(keys) {
            const targets = this.expand(keys);
            if (targets.length === 0) {
                return;
            }

            const entries = { ...this.entries };
            for (const key of targets) {
                entries[key] = { ...entries[key], state: 'claimed' };
            }

            this.entries = entries;
            this.version++;
        },

        /** The claim failed: show the items again. "*" restores all in flight. */
        restore(keys) {
            const targets = this.expand(keys, true);
            if (targets.length === 0) {
                return;
            }

            const entries = { ...this.entries };
            for (const key of targets) {
                delete entries[key];
            }

            this.entries = entries;
            this.version++;
        },

        /**
         * After a redraw: forget the keys whose items are gone, and the ones the
         * server confirmed (their items are gone too; one still drawn was not
         * taken after all, and shows again).
         */
        prune(stillDrawn = exists) {
            const entries = {};
            for (const [key, entry] of Object.entries(this.entries)) {
                if (entry.state === 'pending' && stillDrawn(key)) {
                    entries[key] = entry;
                }
            }

            this.entries = entries;
            this.version++;
        },

        /**
         * The keys a list names: "*" is every key ("every key in flight" when
         * restoring), "*name" every key of that batch, anything else itself
         * and the keys it begins up to a dash ("milestone-4" names
         * "milestone-4-2026-06-07", never "milestone-41-…").
         */
        expand(keys, pendingOnly = false) {
            const list = Array.isArray(keys) ? keys : [keys];
            const found = new Set();

            for (const [key, entry] of Object.entries(this.entries)) {
                if (pendingOnly && entry.state !== 'pending' && ! list.includes(key)) {
                    continue;
                }

                for (const wanted of list) {
                    if (wanted === key
                        || wanted === '*'
                        || (typeof wanted === 'string' && ! wanted.startsWith('*') && wanted !== '' && key.startsWith(wanted + '-'))
                        || (typeof wanted === 'string' && wanted.length > 1 && wanted.startsWith('*') && entry.batch === wanted.slice(1))) {
                        found.add(key);
                    }
                }
            }

            return [...found];
        },

        write(added) {
            this.entries = { ...this.entries, ...added };
            this.version++;
        },
    };
}

/** The «استلام الكل» actions, by the batch each hides before it goes. */
export const CLAIM_ALL_BATCHES = {
    claimAllRewards: '*panel',
    claimAllAwards: '*awards',
};

/**
 * Connect the page's component to the claim store; the page's own script
 * does only this. The server's answers settle the claims: reward-claimed
 * confirms its keys and pops the values that moved (in colour only, under
 * reduced motion), gam-claim-failed brings its keys back. Each «استلام الكل»
 * goes by wire:click, whose request has no promise to watch, so when it
 * never arrives, fails or is cancelled, its batch comes back. After every
 * redraw the store forgets the claims whose items are gone.
 *
 * The order matters. Livewire 4 morphs the page first and fires 'morphed'
 * about a microtask later, but holds the server's dispatches back three
 * microtasks, so the prune on 'morphed' runs before reward-claimed confirms
 * anything. That prune keeps every pending key still drawn, the claimed one
 * included; reward-claimed then confirms it and prunes again in the next
 * frame, on the page already drawn: a confirmed key whose item is gone is
 * forgotten, and one still drawn (another run of a milestone the server did
 * not take) shows again, while a claim still in flight stays hidden.
 *
 * The interceptor outlives the component unless dropped, so it goes with
 * the component (a wire:navigate away), as $wire.$hook's own do.
 *
 * The store is the page's, not the component's: a claim tapped just before a
 * wire:navigate away (or a back to a cached page) can leave an entry the old
 * component's redraws never pruned. The new component's first render fires no
 * 'morphed', so it prunes as it connects: its DOM is already drawn, and
 * whatever it no longer draws (or the server already took) is forgotten,
 * while a claim still in flight on a row it still draws stays hidden until
 * its answer settles it.
 *
 * @param {object} wire the component's $wire
 * @param {object} store the claim store
 * @param {object|null} motion the helpers from createMotion()
 * @param {Function} nextFrame runs a callback once the redraw has painted
 */
export function connectClaims(wire, store, motion = null, nextFrame = (callback) => callback()) {
    wire.$on('reward-claimed', (detail) => {
        store.confirm(detail?.keys ?? []);
        nextFrame(() => {
            // 'morphed' has already pruned, before this confirm; prune again
            // on the drawn page so a confirmed key still drawn shows again.
            store.prune();

            if (whole(detail?.xp) > 0) {
                motion?.pulseMeter();
                motion?.pulseValue('gam-xp-value', 'up');
                motion?.pulseValue('gam-level-value', 'up');
                motion?.pulseValue('gam-rank-value', 'up');
            }

            if (whole(detail?.coins) > 0) {
                motion?.pulseValue('gam-coins-value', 'coin');
            }
        });
    });

    wire.$on('gam-claim-failed', (detail) => store.restore(detail?.keys ?? ['*']));

    const stopWatching = wire.$intercept(({ action, onError, onFailure, onCancel }) => {
        const batch = CLAIM_ALL_BATCHES[action?.name];
        if (! batch) {
            return;
        }

        const restore = () => store.restore([batch]);
        onError(restore);
        onFailure(restore);
        onCancel(restore);
    });
    wire.__instance?.addCleanup?.(stopWatching);

    wire.$hook('morphed', () => store.prune());

    store.prune();
}

/**
 * The totals the page drew, less what is hidden: what is left to claim.
 */
export function remainingTotals(drawn, hidden) {
    return {
        count: Math.max(0, whole(drawn.count) - hidden.count),
        xp: Math.max(0, whole(drawn.xp) - hidden.xp),
        coins: Math.max(0, whole(drawn.coins) - hidden.coins),
    };
}

/** The totals a panel carries in its data-total-* attributes. */
export function drawnTotals(dataset) {
    return {
        count: whole(dataset?.totalCount),
        xp: whole(dataset?.totalXp),
        coins: whole(dataset?.totalCoins),
    };
}

/** The rewards' share of them, in its data-reward-* attributes. */
export function drawnRewardTotals(dataset) {
    return {
        count: whole(dataset?.rewardCount),
        xp: whole(dataset?.rewardXp),
        coins: whole(dataset?.rewardCoins),
    };
}

/**
 * The rewards panel (Alpine.data('gamRewardsPanel')). Its totals are read from
 * the attributes the server drew, which every redraw refreshes, less the
 * claims in flight. Alpine keeps a component's state across a redraw and never
 * reads x-data again, so totals written into x-data went stale as soon as a
 * claim added a row (a level's coins) or took one away.
 *
 * @param {string[]} forms the counted noun's five forms; see gamCount()
 * @param {string[]} units the points' chip unit, singular and plural; see gamUnit()
 */
export function rewardsPanel(forms = [], units = []) {
    return {
        /** Everything the panel lists still to take: rewards, badges and milestones. */
        remaining() {
            const store = this.$store.gamClaims;

            return remainingTotals(drawnTotals(this.$root.dataset), store.hiddenTotals(PANEL_KEYS));
        },

        /** The rewards alone, what the panel's «استلام الكل» takes. */
        rewardsRemaining() {
            const store = this.$store.gamClaims;

            return remainingTotals(drawnRewardTotals(this.$root.dataset), store.hiddenTotals('tx-'));
        },

        countLabel() {
            return gamCount(this.remaining().count, forms);
        },

        signed(value) {
            return gamSigned(value);
        },

        pointsUnit(count) {
            return gamUnit(count, units[0] ?? '', units[1] ?? '');
        },

        /** The rows still on show, for «استلام الكل» to hide together. */
        rows() {
            return Array.from(this.$root.querySelectorAll('[data-reward-row]'))
                .map((row) => ({
                    key: row.dataset.rewardRow,
                    xp: whole(row.dataset.xp),
                    coins: whole(row.dataset.coins),
                }))
                .filter((row) => ! this.$store.gamClaims.isHidden(row.key));
        },

        claimAll(el) {
            return this.$store.gamClaims.claimBatch('panel', this.rows(), el, 16);
        },
    };
}
