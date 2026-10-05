import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    celebrations,
    createSeenTimer,
    isShownOnTop,
    isTextEntry,
    LEVEL_AFTER_CLAIM_MS,
    LEVEL_AUTO_HIDE_MS,
    LEVEL_SEEN_MS,
    laterKey,
    readLater,
    takeHint,
    writeLater,
} from './celebrations.js';
import { createClaimStore } from './claims.js';

const AWARDS = ['جائزة واحدة', 'جائزتان', 'جوائز', 'جائزة', 'جائزة'];

/** A Storage stand-in. */
function memoryStorage(initial = {}) {
    const items = { ...initial };

    return {
        items,
        getItem: (key) => (key in items ? items[key] : null),
        setItem: (key, value) => { items[key] = String(value); },
    };
}

/** A Storage that refuses everything, as a private window can. */
const brokenStorage = {
    getItem: () => { throw new Error('denied'); },
    setItem: () => { throw new Error('denied'); },
};

/** A clock the test moves by hand. */
function manualClock(start = 1000) {
    let time = start;

    return { now: () => time, advance: (ms) => { time += ms; } };
}

/** Where the level card stands on a phone, as getBoundingClientRect gives it. */
const CARD_BOX = { left: 12, top: 600, width: 336, height: 180 };

/** A level card with layout: its box (changed by hand) and what lies inside it. */
function laidOutCard(level) {
    const card = {
        dataset: { level: String(level) },
        box: CARD_BOX,
        querySelector: () => null,
        getBoundingClientRect: () => card.box,
        contains: (el) => el === card || el?.parent === card,
    };

    return card;
}

/** Run the level card's clock for so long, a watch every 250 ms as the page does. */
function watchFor(component, clock, ms) {
    for (let elapsed = 0; elapsed < ms; elapsed += 250) {
        component.watchLevel();
        clock.advance(250);
    }
    component.watchLevel();
}

function awardCard(key, xp = 0, coins = 0) {
    return { dataset: { claimKey: key, xp: String(xp), coins: String(coins) }, querySelector: () => null };
}

/**
 * The cards' component bound to stand-ins: the page's root with its cards,
 * the claim store, $wire and the document.
 */
function mountCards({ cards = [], level = null, session = memoryStorage(), local = memoryStorage(), clock = manualClock(), store = createClaimStore({ now: clock.now }) } = {}) {
    const acks = [];
    const toasts = [];
    const calls = [];
    const doc = { visibilityState: 'visible', activeElement: null, addEventListener: () => {}, removeEventListener: () => {} };
    const levelCard = level === null ? null : { dataset: { level: String(level) }, querySelector: () => null };

    const component = celebrations({
        leaderboardId: 7,
        awardForms: AWARDS,
        hint: 'تجدها في «مكافآت بانتظار الاستلام»',
        session,
        local,
        now: clock.now,
        doc,
        toast: (text) => toasts.push(text),
    });

    Object.assign(component, {
        $store: { gamClaims: store },
        $root: {
            querySelectorAll: (selector) => (selector === '[data-award-card]' ? cards : []),
            querySelector: (selector) => (selector === '[data-level-card]' ? levelCard : null),
        },
        $wire: {
            acknowledgeLevelUp: (n) => acks.push(n),
            claimReward: (id) => { calls.push(['claimReward', id]); return null; },
        },
    });

    component.init();

    return { component, store, acks, toasts, calls, doc, clock, session, local };
}

/* ------------------------------------------------------------ storage */

test('the cards put off are kept per competition, and junk or refusing storage reads as none', () => {
    const session = memoryStorage();

    assert.equal(laterKey(7), 'gam-later-7');
    assert.equal(writeLater(session, 7, ['badge-1']), true);
    assert.deepEqual(readLater(session, 7), ['badge-1']);
    assert.deepEqual(readLater(session, 8), []);

    assert.deepEqual(readLater(memoryStorage({ 'gam-later-7': '{not json' }), 7), []);
    assert.deepEqual(readLater(memoryStorage({ 'gam-later-7': '{"a":1}' }), 7), []);
    assert.deepEqual(readLater(memoryStorage({ 'gam-later-7': '["badge-1", 4]' }), 7), ['badge-1']);
    assert.deepEqual(readLater(brokenStorage, 7), []);
    assert.equal(writeLater(brokenStorage, 7, ['badge-1']), false);
});

test('the hint is shown once, and never when storage refuses', () => {
    const local = memoryStorage();

    assert.equal(takeHint(local), true);
    assert.equal(takeHint(local), false);
    assert.equal(takeHint(brokenStorage), false);
    assert.equal(takeHint(null), false);
});

test('only a field the keyboard comes up for counts as typing', () => {
    assert.equal(isTextEntry({ tagName: 'INPUT', type: 'text' }), true);
    assert.equal(isTextEntry({ tagName: 'input', type: 'number' }), true);
    assert.equal(isTextEntry({ tagName: 'INPUT' }), true);
    assert.equal(isTextEntry({ tagName: 'TEXTAREA' }), true);
    assert.equal(isTextEntry({ tagName: 'DIV', isContentEditable: true }), true);
    assert.equal(isTextEntry({ tagName: 'INPUT', type: 'checkbox' }), false);
    assert.equal(isTextEntry({ tagName: 'BUTTON' }), false);
    assert.equal(isTextEntry(null), false);
});

test('the seen timer counts only while it runs', () => {
    const clock = manualClock();
    const timer = createSeenTimer({ now: clock.now });

    timer.start();
    clock.advance(1500);
    timer.stop();
    clock.advance(10000);
    assert.equal(timer.seen(), 1500);
    assert.equal(timer.seenEnough(), false);

    timer.start();
    clock.advance(LEVEL_SEEN_MS - 1500);
    assert.equal(timer.seenEnough(), true);
    assert.equal(timer.due(), false);

    clock.advance(LEVEL_AUTO_HIDE_MS - LEVEL_SEEN_MS);
    assert.equal(timer.due(), true);
});

/* ------------------------------------------------------------- awards */

test('«لاحقاً» hides a card for the visit, keeps it in session storage and says once where it waits', () => {
    const { component, store, toasts, session } = mountCards({ cards: [awardCard('badge-1', 30, 10), awardCard('badge-2', 40)] });

    component.postpone('badge-1');
    component.postpone('badge-1');
    component.postpone('badge-2');

    assert.equal(component.isHidden('badge-1'), true);
    assert.deepEqual(JSON.parse(session.items['gam-later-7']), ['badge-1', 'badge-2']);
    assert.deepEqual(toasts, ['تجدها في «مكافآت بانتظار الاستلام»']);

    // Put off is not taken: «استلام الكل» still takes it, and the count still holds it.
    assert.equal(store.isHidden('badge-1'), false);
    assert.equal(component.untaken().length, 2);
    assert.equal(component.liveLine(), 'لديك جائزتان بانتظار الاستلام');

    component.destroy();
});

test('cards put off earlier in the visit stay hidden when the page opens again', () => {
    const session = memoryStorage({ 'gam-later-7': '["badge-1"]' });
    const { component } = mountCards({ cards: [awardCard('badge-1'), awardCard('badge-2')], session });

    assert.equal(component.isHidden('badge-1'), true);
    assert.equal(component.isHidden('badge-2'), false);

    component.destroy();
});

test('a card taken anywhere hides here too, by the claim store', () => {
    const { component, store } = mountCards({ cards: [awardCard('badge-1', 30), awardCard('milestone-3-2026-06-07', 50, 100)] });

    store.claim('milestone-3-2026-06-07', { xp: 50, coins: 100 }, () => null);

    assert.equal(component.isHidden('milestone-3-2026-06-07'), true);
    assert.deepEqual(component.untaken().map((award) => award.key), ['badge-1']);
    assert.equal(component.liveLine(), 'لديك جائزة واحدة بانتظار الاستلام');

    store.restore(['milestone-3']);
    assert.equal(component.isHidden('milestone-3-2026-06-07'), false);

    component.destroy();
});

test('«استلام الكل» hides every card not yet taken as one batch, put off ones included', () => {
    const { component, store } = mountCards({ cards: [awardCard('badge-1', 30, 10), awardCard('badge-2', 40, 20), awardCard('milestone-3-2026-06-07', 50, 100)] });
    component.postpone('badge-2');
    store.claim('badge-1', { xp: 30, coins: 10 }, () => null);

    assert.equal(component.claimAll(null), 2);
    assert.equal(store.entries['badge-2'].batch, 'awards');
    assert.equal(store.entries['milestone-3-2026-06-07'].batch, 'awards');
    assert.deepEqual(store.hiddenTotals(['badge-', 'milestone-']), { count: 3, xp: 120, coins: 130 });
    assert.equal(component.liveLine(), '');

    component.destroy();
});

/* --------------------------------------------------------- level card */

test('the level card is acknowledged when the student closes it, and then hides', () => {
    const { component, acks } = mountCards({ level: 3 });

    assert.equal(component.levelVisible(), true);
    assert.equal(component.closeLevel(true), true);
    assert.deepEqual(acks, [3]);
    assert.equal(component.levelVisible(), false);

    // Closed already: a second close sends nothing.
    assert.equal(component.closeLevel(true), false);
    assert.deepEqual(acks, [3]);

    component.destroy();
});

test('the level card hides by itself after six seconds on screen and only then counts as seen', () => {
    const { component, acks, clock } = mountCards({ level: 4 });

    for (let elapsed = 0; elapsed < LEVEL_AUTO_HIDE_MS; elapsed += 250) {
        component.watchLevel();
        clock.advance(250);
    }
    component.watchLevel();

    assert.deepEqual(acks, [4]);
    assert.equal(component.levelVisible(), false);

    component.destroy();
});

test('time in a background tab or under the keyboard does not count, so the card is not taken as seen', () => {
    const { component, acks, clock, doc } = mountCards({ level: 2 });

    doc.visibilityState = 'hidden';
    for (let i = 0; i < 40; i++) {
        component.watchLevel();
        clock.advance(250);
    }
    assert.deepEqual(acks, []);
    assert.equal(component.timer.seen(), 0);

    doc.visibilityState = 'visible';
    component.inputFocused = true;
    component.watchLevel();
    clock.advance(LEVEL_AUTO_HIDE_MS);
    component.watchLevel();
    assert.deepEqual(acks, []);

    // Hidden by the page before it was seen long enough: not acknowledged.
    component.inputFocused = false;
    component.watchLevel();
    clock.advance(LEVEL_SEEN_MS - 500);
    assert.equal(component.closeLevel(false), false);
    assert.deepEqual(acks, []);

    component.destroy();
});

test('time behind the open side menu does not count: the menu takes the card off, so it is not taken as seen', () => {
    const { component, acks, clock, doc } = mountCards({ level: 3 });
    const card = laidOutCard(3);
    component.$root.querySelector = (selector) => (selector === '[data-level-card]' ? card : null);
    // The card's centre falls on its own text: inside the card counts as the card.
    doc.elementFromPoint = () => ({ parent: card });

    try {
        // The side menu hides every [data-gam-floating] with display: none.
        card.box = { left: 0, top: 0, width: 0, height: 0 };
        watchFor(component, clock, LEVEL_AUTO_HIDE_MS * 2);
        assert.deepEqual(acks, []);
        assert.equal(component.timer.seen(), 0);
        assert.equal(component.levelVisible(), true);

        // The menu closes: the card is back, and its time counts from now.
        card.box = CARD_BOX;
        watchFor(component, clock, LEVEL_AUTO_HIDE_MS);
        assert.deepEqual(acks, [3]);
    } finally {
        component.destroy();
    }
});

test('time under an open modal does not count, whether the hit test or the open dialog shows it', () => {
    const { component, acks, clock, doc } = mountCards({ level: 4 });
    const card = laidOutCard(4);
    const dialog = { tagName: 'DIALOG', contains: () => false };
    const points = [];
    component.$root.querySelector = (selector) => (selector === '[data-level-card]' ? card : null);

    try {
        // A Flux modal in the top layer: its backdrop is what shows at the card's centre.
        doc.elementFromPoint = (x, y) => {
            points.push([x, y]);

            return dialog;
        };
        watchFor(component, clock, LEVEL_AUTO_HIDE_MS * 2);
        assert.deepEqual(acks, []);
        assert.equal(component.timer.seen(), 0);
        assert.deepEqual(points[0], [CARD_BOX.left + CARD_BOX.width / 2, CARD_BOX.top + CARD_BOX.height / 2]);

        // An open modal dialog covers it even where the hit test reaches the card.
        doc.elementFromPoint = () => card;
        doc.querySelector = (selector) => (selector === 'dialog:modal' ? dialog : null);
        watchFor(component, clock, LEVEL_AUTO_HIDE_MS * 2);
        assert.deepEqual(acks, []);
        assert.equal(component.timer.seen(), 0);

        // The modal closes (in a browser without :modal, the hit test alone decides).
        doc.querySelector = () => {
            throw new SyntaxError('unknown pseudo-class');
        };
        watchFor(component, clock, LEVEL_AUTO_HIDE_MS);
        assert.deepEqual(acks, [4]);
    } finally {
        component.destroy();
    }
});

test('a card is on top only where the screen shows it; a stand-in without layout counts as shown', () => {
    const card = laidOutCard(1);

    assert.equal(isShownOnTop(card, { elementFromPoint: () => card }), true);
    assert.equal(isShownOnTop(card, { elementFromPoint: () => null }), false);
    assert.equal(isShownOnTop(card, {}), true);
    assert.equal(isShownOnTop({ dataset: {} }, { elementFromPoint: () => null }), true);
    assert.equal(isShownOnTop(null, {}), true);
});

test('a level card a claim brings waits for the claim\'s particles to land', () => {
    const clock = manualClock();
    const { component } = mountCards({ clock });

    component.holdLevel();

    // The claim's redraw brings level 5.
    component.$root.querySelector = (selector) => (selector === '[data-level-card]' ? { dataset: { level: '5' }, querySelector: () => null } : null);
    assert.equal(component.levelVisible(), false);

    clock.advance(LEVEL_AFTER_CLAIM_MS);
    assert.equal(component.levelVisible(), true);

    // A card already on show stays when another claim starts.
    component.holdLevel();
    assert.equal(component.levelVisible(), true);

    component.destroy();
});

test('a level card first drawn by a claim\'s redraw still waits for that claim\'s particles', () => {
    const clock = manualClock();
    const store = createClaimStore({ now: clock.now });

    // Nothing waited to be celebrated, so no cards were on the page to hear
    // the claim start.
    store.claim('tx-1', { xp: 5 }, null, null);
    clock.advance(300);

    // The claim's redraw draws them for the first time, with level 5.
    const { component } = mountCards({ level: 5, clock, store });
    try {
        assert.equal(component.levelVisible(), false);

        clock.advance(LEVEL_AFTER_CLAIM_MS - 301);
        assert.equal(component.levelVisible(), false);

        clock.advance(1);
        assert.equal(component.levelVisible(), true);
    } finally {
        component.destroy();
    }
});

test('the panel\'s «استلام الكل» holds a level card drawn by its redraw too, but a claim long over holds none', () => {
    const clock = manualClock();
    const store = createClaimStore({ now: clock.now });

    store.claimBatch('panel', [{ key: 'tx-1', xp: 5 }, { key: 'tx-2', coins: 10 }], null, 16);
    const brought = mountCards({ level: 6, clock, store });
    try {
        assert.equal(brought.component.levelVisible(), false);
        clock.advance(LEVEL_AFTER_CLAIM_MS);
        assert.equal(brought.component.levelVisible(), true);
    } finally {
        brought.component.destroy();
    }

    // A tap on something already hidden is no new claim.
    store.claim('tx-1', { xp: 5 }, null, null);
    const later = mountCards({ level: 6, clock, store });
    try {
        assert.equal(later.component.levelVisible(), true);
    } finally {
        later.component.destroy();
    }
});

test('the level\'s coins are taken from the card through the claim store, hiding the panel row with them', () => {
    const { component, store, calls } = mountCards({ level: 3 });
    const pending = [{ key: 'tx-9', id: 9, coins: 30 }];

    assert.equal(component.levelRewardPending(pending), true);
    component.claimLevelReward(pending, null);

    assert.deepEqual(calls, [['claimReward', 9]]);
    assert.equal(store.isHidden('tx-9'), true);
    assert.deepEqual(store.hiddenTotals('tx-'), { count: 1, xp: 0, coins: 30 });
    assert.equal(component.levelRewardPending(pending), false);

    component.destroy();
});
