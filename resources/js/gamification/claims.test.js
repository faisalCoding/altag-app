import assert from 'node:assert/strict';
import { test } from 'node:test';

import { connectClaims, createClaimStore, drawnTotals, PANEL_KEYS, remainingTotals, rewardsPanel } from './claims.js';

/** Motion helpers that record what they were asked to do. */
function recordingMotion() {
    const calls = [];

    return {
        calls,
        launchXp: (fromEl, count, target) => { calls.push(['launch', count, target]); return count; },
        float: (id, text, tone) => { calls.push(['float', id, text, tone]); return null; },
    };
}

/** A promise with its settlers exposed, as a $wire call returns. */
function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });

    return { promise, resolve, reject };
}

const tick = () => new Promise((resolve) => setImmediate(resolve));

test('a claim hides its key at once, celebrates and calls the server', () => {
    const motion = recordingMotion();
    const events = [];
    const store = createClaimStore({ motion, dispatch: (name, detail) => events.push([name, detail]) });
    let called = 0;

    store.claim('tx-12', { xp: 20, coins: 5 }, () => { called++; return null; });

    assert.equal(store.isHidden('tx-12'), true);
    assert.equal(store.isPending('tx-12'), true);
    assert.equal(called, 1);
    assert.deepEqual(events, [['gam-claiming', { key: 'tx-12' }]]);
    assert.deepEqual(motion.calls, [
        ['launch', 10, 'gam-level-meter'],
        ['float', 'gam-xp-value', '+20', 'up'],
        ['float', 'gam-coins-value', '+5', 'coin'],
    ]);
});

test('a coins-only claim flies to the coins and floats no points', () => {
    const motion = recordingMotion();
    const store = createClaimStore({ motion });

    store.claim('tx-3', { xp: 0, coins: 50 }, () => null);

    assert.deepEqual(motion.calls, [
        ['launch', 10, 'gam-coins-value'],
        ['float', 'gam-coins-value', '+50', 'coin'],
    ]);
});

test('a double tap claims once', () => {
    const store = createClaimStore();
    let called = 0;

    store.claim('tx-1', { xp: 5 }, () => { called++; });
    store.claim('tx-1', { xp: 5 }, () => { called++; });

    assert.equal(called, 1);
});

test('a failure event from the server restores the key', () => {
    const store = createClaimStore();
    store.claim('tx-7', { xp: 5 }, () => null);

    store.restore(['tx-7']);

    assert.equal(store.isHidden('tx-7'), false);
});

test('a request that fails (no network, server error, cancelled) restores the key', async () => {
    const store = createClaimStore();
    const call = deferred();

    const returned = store.claim('tx-9', { xp: 5 }, () => call.promise);
    assert.equal(store.isHidden('tx-9'), true);

    call.reject(new Error('offline'));
    await tick();

    assert.equal(store.isHidden('tx-9'), false);
    // What the click handler gets back settles quietly, so Alpine logs no error.
    assert.equal(await returned, null);
});

test('a call that throws restores at once', () => {
    const store = createClaimStore();

    store.claim('tx-2', { xp: 1 }, () => { throw new Error('no $wire'); });

    assert.equal(store.isHidden('tx-2'), false);
});

test('a confirmed key stays hidden until the redraw, then is forgotten', async () => {
    const store = createClaimStore();
    const call = deferred();
    store.claim('tx-4', { xp: 5 }, () => call.promise);

    store.confirm(['tx-4']);
    call.resolve(null);
    await tick();
    assert.equal(store.isHidden('tx-4'), true);

    store.prune(() => false);
    assert.equal(store.isHidden('tx-4'), false);
    assert.deepEqual(store.entries, {});
});

test('a redraw keeps a claim still in flight whose row is still drawn', () => {
    const store = createClaimStore();
    store.claim('tx-5', { xp: 5 }, () => null);
    const before = store.version;

    store.prune((key) => key === 'tx-5');

    assert.equal(store.isHidden('tx-5'), true);
    assert.ok(store.version > before);
});

test('a redraw drops a claim in flight whose row is gone, so the totals are not counted down twice', () => {
    const store = createClaimStore();
    store.claim('tx-5', { xp: 5, coins: 2 }, () => null);

    store.prune(() => false);

    assert.deepEqual(store.hiddenTotals('tx-'), { count: 0, xp: 0, coins: 0 });
});

test('a confirmed key still drawn after the redraw shows again', () => {
    const store = createClaimStore();
    store.claim('tx-6', { xp: 5 }, () => null);
    store.confirm(['tx-6']);

    store.prune(() => true);

    assert.equal(store.isHidden('tx-6'), false);
});

test('«استلام الكل» hides every row as one batch and confirms it by "*panel"', () => {
    const motion = recordingMotion();
    const store = createClaimStore({ motion });

    const hidden = store.claimBatch('panel', [
        { key: 'tx-1', xp: 10, coins: 0 },
        { key: 'tx-2', xp: 0, coins: 50 },
    ], null, 16);

    assert.equal(hidden, 2);
    assert.deepEqual(store.hiddenTotals('tx-'), { count: 2, xp: 10, coins: 50 });
    assert.deepEqual(motion.calls[0], ['launch', 16, 'gam-level-meter']);

    store.confirm(['*panel']);
    assert.equal(store.entries['tx-1'].state, 'claimed');
    assert.equal(store.entries['tx-2'].state, 'claimed');
});

test('a row the claim itself adds (a level\'s coins) is not hidden by the batch', () => {
    const store = createClaimStore();
    store.claimBatch('panel', [{ key: 'tx-1', xp: 120 }]);
    store.confirm(['*panel']);

    // The redraw takes tx-1 away and draws the new level reward, tx-99.
    store.prune((key) => key === 'tx-99');

    assert.equal(store.isHidden('tx-99'), false);
    assert.equal(store.isHidden('tx-1'), false);
});

test('nothing claimed by «استلام الكل» brings back the panel\'s rows alone with "*panel"', () => {
    const store = createClaimStore();
    store.claimBatch('panel', [{ key: 'tx-1', xp: 1 }, { key: 'tx-2', xp: 2 }]);
    store.claim('tx-3', { xp: 3 }, () => null);
    store.confirm(['tx-3']);
    // Still waiting on answers of their own: a badge claimed by itself, and a
    // card the celebrations' «استلام الكل» hid.
    store.claim('badge-3', { xp: 30, coins: 10 }, () => null);
    store.claimBatch('awards', [{ key: 'milestone-5-2026-06-07', xp: 50, coins: 100 }]);

    store.restore(['*panel']);

    assert.equal(store.isHidden('tx-1'), false);
    assert.equal(store.isHidden('tx-2'), false);
    // A claim the server already took is not brought back.
    assert.equal(store.isHidden('tx-3'), true);
    assert.equal(store.isHidden('badge-3'), true);
    assert.equal(store.isPending('badge-3'), true);
    assert.equal(store.isHidden('milestone-5-2026-06-07'), true);
    assert.equal(store.entries['milestone-5-2026-06-07'].batch, 'awards');
});

test('a failed «استلام الكل» request restores its batch only', () => {
    const store = createClaimStore();
    store.claim('tx-1', { xp: 1 }, () => null);
    store.claimBatch('panel', [{ key: 'tx-2', xp: 2 }]);

    store.restore(['*panel']);

    assert.equal(store.isHidden('tx-1'), true);
    assert.equal(store.isHidden('tx-2'), false);
});

test('a batch skips rows already being claimed one by one', () => {
    const store = createClaimStore();
    store.claim('tx-1', { xp: 1 }, () => null);

    assert.equal(store.claimBatch('panel', [{ key: 'tx-1', xp: 1 }, { key: 'tx-2', xp: 2 }]), 1);
    assert.equal(store.entries['tx-1'].batch, null);
    assert.equal(store.claimBatch('panel', [{ key: 'tx-2', xp: 2 }]), 0);
});

test('totals left to claim are the drawn totals less the claims in flight', () => {
    const store = createClaimStore();
    store.claim('tx-1', { xp: 20, coins: 5 }, () => null);
    store.claim('badge-4', { xp: 100, coins: 0 }, () => null);

    const left = remainingTotals(drawnTotals({ totalCount: '3', totalXp: '45', totalCoins: '15' }), store.hiddenTotals('tx-'));

    assert.deepEqual(left, { count: 2, xp: 25, coins: 10 });
    assert.deepEqual(remainingTotals({ count: 1, xp: 0, coins: 0 }, { count: 3, xp: 9, coins: 9 }), { count: 0, xp: 0, coins: 0 });
});

test('the panel reads its totals from the attributes each redraw refreshes', () => {
    const store = createClaimStore();
    const dataset = { totalCount: '2', totalXp: '30', totalCoins: '0' };
    const panel = rewardsPanel(['مكافأة واحدة', 'مكافأتين', 'مكافآت', 'مكافأة', 'مكافأة'], ['نقطة', 'نقاط']);
    Object.assign(panel, { $store: { gamClaims: store }, $root: { dataset, querySelectorAll: () => [] } });

    assert.equal(panel.countLabel(), 'مكافأتين');
    assert.equal(panel.pointsUnit(5), 'نقاط');
    assert.equal(panel.pointsUnit(30), 'نقطة');
    assert.equal(panel.signed(30), '+30');

    store.claim('tx-1', { xp: 10 }, () => null);
    assert.deepEqual(panel.remaining(), { count: 1, xp: 20, coins: 0 });
    assert.equal(panel.countLabel(), 'مكافأة واحدة');

    // The redraw: the row is gone, the attributes say so, the store forgets it.
    dataset.totalCount = '1';
    dataset.totalXp = '20';
    store.prune(() => false);
    assert.deepEqual(panel.remaining(), { count: 1, xp: 20, coins: 0 });
});

test('the panel hands «استلام الكل» the rows still on show', () => {
    const store = createClaimStore();
    const row = (key, xp, coins) => ({ dataset: { rewardRow: key, xp: String(xp), coins: String(coins) } });
    const panel = rewardsPanel([]);
    Object.assign(panel, {
        $store: { gamClaims: store },
        $root: { dataset: {}, querySelectorAll: () => [row('tx-1', 10, 0), row('tx-2', 0, 5)] },
    });
    store.claim('tx-1', { xp: 10 }, () => null);

    assert.deepEqual(panel.rows(), [{ key: 'tx-2', xp: 0, coins: 5 }]);
    assert.equal(panel.claimAll(null), 1);
    assert.equal(store.entries['tx-2'].batch, 'panel');
});

/* ------------------------------------------------ awards (badges, milestones) */

test('a milestone answered by its id names every run of it the page hid, and no other milestone', () => {
    const store = createClaimStore();
    store.claim('milestone-4-2026-06-04', { xp: 50 }, () => null);
    store.claim('milestone-4-2026-06-07', { xp: 50 }, () => null);
    store.claim('milestone-41-2026-06-07', { xp: 10 }, () => null);

    store.restore(['milestone-4']);

    assert.equal(store.isHidden('milestone-4-2026-06-04'), false);
    assert.equal(store.isHidden('milestone-4-2026-06-07'), false);
    assert.equal(store.isHidden('milestone-41-2026-06-07'), true);

    store.claim('milestone-4-2026-06-04', { xp: 50 }, () => null);
    store.confirm(['milestone-4']);
    assert.equal(store.entries['milestone-4-2026-06-04'].state, 'claimed');
    assert.equal(store.entries['milestone-41-2026-06-07'].state, 'pending');
});

test('a run of a milestone still drawn after its claim was another run, and shows again', () => {
    const store = createClaimStore();
    store.claim('milestone-4-2026-06-07', { xp: 50 }, () => null);
    store.confirm(['milestone-4']);

    // The server took the earliest run (06-04); the redraw still draws 06-07.
    store.prune((key) => key === 'milestone-4-2026-06-07');

    assert.equal(store.isHidden('milestone-4-2026-06-07'), false);
});

test('the cards\' «استلام الكل» is a batch of its own, apart from the panel\'s', () => {
    const store = createClaimStore();
    store.claimBatch('panel', [{ key: 'tx-1', xp: 10 }]);
    store.claimBatch('awards', [{ key: 'badge-2', xp: 30, coins: 10 }, { key: 'milestone-3-2026-06-07', xp: 50, coins: 100 }]);

    store.restore(['*awards']);
    assert.equal(store.isHidden('badge-2'), false);
    assert.equal(store.isHidden('milestone-3-2026-06-07'), false);
    assert.equal(store.isHidden('tx-1'), true);

    store.claimBatch('awards', [{ key: 'badge-2', xp: 30, coins: 10 }]);
    store.confirm(['*awards']);
    assert.equal(store.entries['badge-2'].state, 'claimed');
    assert.equal(store.entries['tx-1'].state, 'pending');
});

test('the panel counts its awards with its rewards, but its «استلام الكل» totals the rewards alone', () => {
    const store = createClaimStore();
    const dataset = {
        totalCount: '3', totalXp: '100', totalCoins: '115',
        rewardCount: '1', rewardXp: '20', rewardCoins: '5',
    };
    const panel = rewardsPanel(['مكافأة واحدة', 'مكافأتين', 'مكافآت', 'مكافأة', 'مكافأة']);
    Object.assign(panel, { $store: { gamClaims: store }, $root: { dataset, querySelectorAll: () => [] } });

    assert.equal(panel.countLabel(), '3 مكافآت');

    // A badge taken from its card leaves the panel too; the rewards' total stays.
    store.claim('badge-2', { xp: 30, coins: 10 }, () => null);
    assert.deepEqual(store.hiddenTotals(PANEL_KEYS), { count: 1, xp: 30, coins: 10 });
    assert.deepEqual(panel.remaining(), { count: 2, xp: 70, coins: 105 });
    assert.deepEqual(panel.rewardsRemaining(), { count: 1, xp: 20, coins: 5 });

    store.claim('tx-1', { xp: 20, coins: 5 }, () => null);
    assert.deepEqual(panel.rewardsRemaining(), { count: 0, xp: 0, coins: 0 });
    assert.equal(panel.countLabel(), 'مكافأة واحدة');
});

/* ------------------------------------------------- the component's answers */

/**
 * A stand-in for a component's $wire: it records the listeners, the action
 * interceptor and the hooks connectClaims() sets, so a test can play the
 * server's answers and a request's fate back to them.
 */
function fakeWire() {
    const listeners = {};
    const hooks = {};
    const cleanups = [];
    const interceptors = [];

    return {
        listeners,
        hooks,
        cleanups,
        interceptors,
        $on(name, callback) { (listeners[name] ??= []).push(callback); },
        $hook(name, callback) { (hooks[name] ??= []).push(callback); },
        $intercept(callback) {
            interceptors.push(callback);

            return () => interceptors.splice(interceptors.indexOf(callback), 1);
        },
        __instance: { addCleanup: (callback) => cleanups.push(callback) },

        /** The server dispatched an event to the page. */
        answer(name, detail) { (listeners[name] ?? []).forEach((callback) => callback(detail)); },

        /** An action starts; returns its lifecycle, to fail, error or cancel it. */
        act(name) {
            const fate = { error: [], failure: [], cancel: [] };
            interceptors.forEach((callback) => callback({
                action: { name },
                onError: (cb) => fate.error.push(cb),
                onFailure: (cb) => fate.failure.push(cb),
                onCancel: (cb) => fate.cancel.push(cb),
            }));

            return {
                error: () => fate.error.forEach((cb) => cb({ response: { status: 500 } })),
                fail: () => fate.failure.forEach((cb) => cb({ error: new Error('offline') })),
                cancel: () => fate.cancel.forEach((cb) => cb()),
            };
        },

        /** The page was drawn again. */
        morph() { (hooks.morphed ?? []).forEach((callback) => callback({ el: null, component: null })); },
    };
}

/** Motion helpers that record the values they pop. */
function poppingMotion() {
    const calls = [];

    return {
        calls,
        pulseMeter: () => calls.push(['meter']),
        pulseValue: (id, tone) => calls.push(['pop', id, tone]),
    };
}

test('the server\'s answer confirms the claim and pops the values that moved', () => {
    const wire = fakeWire();
    const motion = poppingMotion();
    const frames = [];
    // The redraw that carried the answer took the row away.
    const store = createClaimStore({ exists: () => false });
    connectClaims(wire, store, motion, (callback) => frames.push(callback));

    store.claim('tx-1', { xp: 20, coins: 5 }, () => null);
    wire.answer('reward-claimed', { xp: 20, coins: 5, keys: ['tx-1'] });

    assert.equal(store.entries['tx-1'].state, 'claimed');

    frames.forEach((callback) => callback());
    assert.deepEqual(store.entries, {});
    assert.deepEqual(motion.calls, [
        ['meter'],
        ['pop', 'gam-xp-value', 'up'],
        ['pop', 'gam-level-value', 'up'],
        ['pop', 'gam-rank-value', 'up'],
        ['pop', 'gam-coins-value', 'coin'],
    ]);
});

test('a coins-only answer pops the coins alone', () => {
    const wire = fakeWire();
    const motion = poppingMotion();
    connectClaims(wire, createClaimStore(), motion);

    wire.answer('reward-claimed', { xp: 0, coins: 40, keys: ['tx-9'] });

    assert.deepEqual(motion.calls, [['pop', 'gam-coins-value', 'coin']]);
});

test('the pops wait for the frame the redraw paints in', () => {
    const wire = fakeWire();
    const motion = poppingMotion();
    const frames = [];
    connectClaims(wire, createClaimStore(), motion, (callback) => frames.push(callback));

    wire.answer('reward-claimed', { xp: 10, coins: 0, keys: ['tx-1'] });
    assert.deepEqual(motion.calls, []);

    frames.forEach((callback) => callback());
    assert.equal(motion.calls[0][0], 'meter');
});

test('the server\'s refusal brings the claim back, and a refusal with no keys brings back all in flight', () => {
    const wire = fakeWire();
    const store = createClaimStore();
    connectClaims(wire, store);

    store.claim('tx-1', { xp: 1 }, () => null);
    store.claim('tx-2', { xp: 2 }, () => null);

    wire.answer('gam-claim-failed', { keys: ['tx-1'] });
    assert.equal(store.isHidden('tx-1'), false);
    assert.equal(store.isHidden('tx-2'), true);

    wire.answer('gam-claim-failed', {});
    assert.equal(store.isHidden('tx-2'), false);
});

test('a «استلام الكل» request that fails, errors or is cancelled brings its own batch back', () => {
    for (const [action, batch, fate] of [
        ['claimAllRewards', 'panel', 'fail'],
        ['claimAllRewards', 'panel', 'error'],
        ['claimAllAwards', 'awards', 'cancel'],
    ]) {
        const wire = fakeWire();
        const store = createClaimStore();
        connectClaims(wire, store);

        store.claim('tx-7', { xp: 7 }, () => null);
        store.claimBatch(batch, [{ key: 'badge-1', xp: 10 }, { key: 'tx-2', xp: 2 }]);
        wire.act(action)[fate]();

        assert.equal(store.isHidden('badge-1'), false, action + ' ' + fate);
        assert.equal(store.isHidden('tx-2'), false, action + ' ' + fate);
        assert.equal(store.isHidden('tx-7'), true, action + ' ' + fate);
    }
});

test('other actions failing leave the claims alone', () => {
    const wire = fakeWire();
    const store = createClaimStore();
    connectClaims(wire, store);

    store.claimBatch('panel', [{ key: 'tx-2', xp: 2 }]);
    wire.act('setNewsDate').fail();
    wire.act('claimReward').error();

    assert.equal(store.isHidden('tx-2'), true);
});

test('every redraw forgets the claims whose items are gone', () => {
    const wire = fakeWire();
    const drawn = new Set(['tx-1', 'tx-2']);
    const store = createClaimStore({ exists: (key) => drawn.has(key) });
    connectClaims(wire, store);

    store.claim('tx-1', { xp: 1 }, () => null);
    store.claim('tx-2', { xp: 2 }, () => null);
    drawn.delete('tx-1');
    wire.morph();

    assert.equal(store.isHidden('tx-1'), false);
    assert.equal(store.isHidden('tx-2'), true);
});

test('in Livewire 4\'s order (morphed, then the answer) a confirmed key still drawn shows again', () => {
    // One request carried two claims: a reward whose row the redraw took
    // away, and the 06-07 card of milestone 4, though the server took its
    // earlier run (06-04) that the student had put off. A reward tapped after
    // that request left is still on its way.
    const wire = fakeWire();
    const frames = [];
    const drawn = new Set(['tx-1', 'milestone-4-2026-06-07', 'tx-3']);
    const store = createClaimStore({ exists: (key) => drawn.has(key) });
    connectClaims(wire, store, null, (callback) => frames.push(callback));

    store.claim('tx-1', { xp: 10, coins: 0 }, () => null);
    store.claim('milestone-4-2026-06-07', { xp: 50, coins: 100 }, () => null);
    store.claim('tx-3', { xp: 3, coins: 1 }, () => null);

    // Livewire morphs and fires 'morphed' first; the dispatches come after.
    drawn.delete('tx-1');
    wire.morph();
    wire.answer('reward-claimed', { xp: 10, coins: 0, keys: ['tx-1'] });
    wire.answer('reward-claimed', { xp: 50, coins: 100, keys: ['milestone-4'] });

    assert.equal(store.isHidden('milestone-4-2026-06-07'), true);

    frames.forEach((callback) => callback());

    assert.equal(store.isHidden('milestone-4-2026-06-07'), false);
    assert.equal(store.isHidden('tx-1'), false);
    assert.equal('tx-1' in store.entries, false);
    assert.equal(store.isHidden('tx-3'), true);
    assert.equal(store.isPending('tx-3'), true);
    assert.deepEqual(store.hiddenTotals(PANEL_KEYS), { count: 1, xp: 3, coins: 1 });
});

test('a new component (a wire:navigate back) forgets the claims its first render no longer needs', () => {
    // Left over from the component before it: a claim the server took (its
    // row is gone), a claim in flight whose row is gone, and one in flight
    // whose row the new render still draws.
    const drawn = new Set(['tx-6']);
    const store = createClaimStore({ exists: (key) => drawn.has(key) });
    store.claim('tx-5', { xp: 20, coins: 5 }, () => null);
    store.confirm(['tx-5']);
    store.claim('tx-6', { xp: 3, coins: 1 }, () => null);
    store.claim('tx-7', { xp: 9, coins: 9 }, () => null);

    // Its first render fires no 'morphed'.
    connectClaims(fakeWire(), store);

    assert.equal(store.isHidden('tx-5'), false);
    assert.equal(store.isHidden('tx-7'), false);
    assert.equal(store.isHidden('tx-6'), true);
    assert.equal(store.isPending('tx-6'), true);
    assert.deepEqual(store.hiddenTotals('tx-'), { count: 1, xp: 3, coins: 1 });
});

test('the interceptor is dropped with the component, so a page left behind stops listening', () => {
    const wire = fakeWire();
    connectClaims(wire, createClaimStore());

    assert.equal(wire.interceptors.length, 1);
    wire.cleanups.forEach((cleanup) => cleanup());
    assert.equal(wire.interceptors.length, 0);
});
