import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createMotion, guardCollapse, TONES } from './motion.js';

/** A stand-in element: enough of the DOM for the helpers to run. */
function fakeElement(id = null, box = { left: 0, top: 0, width: 10, height: 10 }) {
    const classes = new Set();
    const properties = {};

    return {
        id,
        animations: [],
        removed: false,
        attributes: {},
        textContent: '',
        offsetWidth: 10,
        style: { setProperty: (name, value) => { properties[name] = value; }, properties },
        classList: {
            add: (name) => classes.add(name),
            remove: (name) => classes.delete(name),
            contains: (name) => classes.has(name),
        },
        setAttribute(name, value) { this.attributes[name] = value; },
        getBoundingClientRect: () => box,
        animate(frames, options) {
            const animation = { frames, options, onfinish: null };
            this.animations.push(animation);

            return animation;
        },
        remove() { this.removed = true; },
    };
}

/** A stand-in window whose reader may or may not ask for reduced motion. */
function fakeWindow({ reduced = false, ids = ['gam-level-meter', 'gam-level-fill', 'gam-xp-value', 'gam-coins-value'] } = {}) {
    const byId = Object.fromEntries(ids.map((id) => [id, fakeElement(id, { left: 100, top: 10, width: 20, height: 8 })]));
    const appended = [];
    const timers = [];

    return {
        byId,
        appended,
        timers,
        matchMedia: (query) => ({ matches: reduced && query.includes('reduce') }),
        setTimeout: (callback, ms) => { timers.push({ callback, ms }); },
        document: {
            getElementById: (id) => byId[id] ?? null,
            createElement: () => fakeElement(),
            body: { appendChild: (el) => appended.push(el) },
        },
    };
}

test('it reads the reader\'s reduced-motion setting', () => {
    assert.equal(createMotion(fakeWindow({ reduced: true })).reducedMotion(), true);
    assert.equal(createMotion(fakeWindow()).reducedMotion(), false);
});

test('a broken matchMedia counts as motion allowed', () => {
    const win = fakeWindow();
    win.matchMedia = () => { throw new Error('nope'); };

    assert.equal(createMotion(win).reducedMotion(), false);
});

test('particles fly to the level meter and the meter flashes as they land', () => {
    const win = fakeWindow();
    const motion = createMotion(win);

    assert.equal(motion.launchXp(fakeElement(), 10), 10);
    assert.equal(win.appended.length, 10);
    assert.ok(win.appended.every((el) => el.className === 'gam-xp-particle' && el.animations.length === 1));

    const landing = win.timers.at(-1);
    assert.equal(landing.ms, 10 * 45 + 780);
    landing.callback();
    assert.ok(win.byId['gam-level-fill'].classList.contains('gam-flash'));
});

test('coins-only particles fly to the coins and pop them in amber', () => {
    const win = fakeWindow();
    const motion = createMotion(win);

    motion.launchXp(fakeElement(), 4, 'gam-coins-value');
    win.timers.at(-1).callback();

    assert.ok(win.byId['gam-coins-value'].classList.contains('gam-pop'));
    assert.equal(win.byId['gam-coins-value'].style.properties['--gam-pop-color'], TONES.coin);
});

test('reduced motion launches nothing, floats nothing and does not flash the meter', () => {
    const win = fakeWindow({ reduced: true });
    const motion = createMotion(win);

    assert.equal(motion.launchXp(fakeElement(), 16), 0);
    assert.equal(motion.float('gam-xp-value', '+12'), null);
    assert.equal(motion.pulseMeter(), false);
    assert.equal(win.appended.length, 0);
    assert.equal(motion.scrollBehavior('smooth'), 'auto');
});

test('reduced motion still pops a changed value, which the stylesheet keeps to a colour flash', () => {
    const win = fakeWindow({ reduced: true });
    const motion = createMotion(win);

    assert.equal(motion.pulseValue('gam-xp-value'), true);
    assert.ok(win.byId['gam-xp-value'].classList.contains('gam-pop'));
    assert.equal(win.byId['gam-xp-value'].style.properties['--gam-pop-color'], TONES.up);
});

test('a float is isolated so "+12" reads as written in Arabic', () => {
    const win = fakeWindow();
    const label = createMotion(win).float('gam-xp-value', '+12', 'up');

    assert.equal(label.textContent, '\u2066+12\u2069');
    assert.equal(label.attributes['aria-hidden'], 'true');
    assert.equal(label.style.color, TONES.up);
    assert.equal(createMotion(win).scrollBehavior('smooth'), 'smooth');
});

test('missing targets are ignored rather than thrown on', () => {
    const motion = createMotion(fakeWindow({ ids: [] }));

    assert.equal(motion.launchXp(fakeElement(), 5), 0);
    assert.equal(motion.launchXp(null, 5), 0);
    assert.equal(motion.float('gam-xp-value', '+1'), null);
    assert.equal(motion.pulseValue('gam-xp-value'), false);
    assert.equal(motion.pulseMeter(), false);
});

/**
 * A stand-in for an element under x-show and Alpine's x-collapse, with the
 * collapse's transitions as Alpine runs them. A hide sets its start height
 * at once and its end height (0px) two frames later (slide()); cancelling it
 * applies the end if it was not set yet, then its finish, which sets
 * display:none when the height is 0px. A show undoes display and hidden,
 * sets the height to auto, then cancels whatever runs before growing.
 */
function collapsible(height = 72) {
    const style = {
        height: '',
        overflow: '',
        display: '',
        removeProperty(name) { this[name] = ''; },
    };
    const el = { hidden: false, style, _x_isShown: true, calls: [] };

    el._x_transition = {
        in() {
            el.calls.push('in');
            el.hidden = false;
            el.style.display = '';
            el.style.height = 'auto';
            el._x_transitioning?.cancel();
            el._x_isShown = true;
        },
        out() {
            el.calls.push('out');
            el.style.height = height + 'px';
            el.style.overflow = 'hidden';
            let ended = false;
            el.slide = () => { el.style.height = '0px'; ended = true; };
            el._x_transitioning = {
                cancel() {
                    if (! ended) {
                        el.style.height = '0px';
                    }
                    el._x_isShown = false;
                    if (el.style.height === '0px') {
                        el.style.display = 'none';
                        el.hidden = true;
                    }
                    delete el._x_transitioning;
                },
            };
        },
    };

    return el;
}

const shown = (el) => el.style.display !== 'none' && ! el.hidden;

test('Alpine\'s collapse alone loses a show that comes in the first frames of a hide', () => {
    const el = collapsible();

    el._x_transition.out();
    el._x_transition.in();

    assert.equal(shown(el), false);
});

test('a guarded collapse shows at once a row whose hide has not moved yet, as a claim failing at once does', () => {
    const el = collapsible();
    assert.equal(guardCollapse(el), true);

    el._x_transition.out();
    el._x_transition.in();

    assert.equal(shown(el), true);
    assert.equal(el.style.height, 'auto');
    assert.equal(el.style.overflow, '');
    assert.equal(el._x_isShown, true);
    assert.equal(el._x_transitioning, undefined);
    assert.deepEqual(el.calls, ['out']);
});

test('a guarded collapse lets the collapse turn back a hide already sliding', () => {
    const el = collapsible();
    guardCollapse(el);

    el._x_transition.out();
    el.slide();
    el._x_transition.in();

    assert.equal(shown(el), true);
    assert.deepEqual(el.calls, ['out', 'in']);
});

test('under reduced motion a guarded row hides and shows at once, without sliding', () => {
    const el = collapsible();
    guardCollapse(el, () => true);

    el._x_transition.out();
    assert.equal(shown(el), false);
    assert.equal(el.style.height, '0px');
    assert.equal(el._x_isShown, false);

    el._x_transition.in();
    assert.equal(shown(el), true);
    assert.equal(el.style.height, 'auto');
    assert.deepEqual(el.calls, []);
});

test('an element without a collapse is left alone, and a collapse is guarded once', () => {
    assert.equal(guardCollapse({ style: {} }), false);
    assert.equal(guardCollapse(null), false);

    const el = collapsible();
    guardCollapse(el);
    const guarded = el._x_transition;

    assert.equal(guardCollapse(el), true);
    assert.equal(el._x_transition, guarded);
});
