// Motion on the student's gamification page: particles that fly to the level
// meter, a "+12" that floats up from a value, the pulse of a value that
// changed, and the collapse of a claimed row. Every one of them asks
// gamReducedMotion() first: with reduced motion on, nothing flies, floats or
// slides, and a changed value only flashes its colour (the page's stylesheet
// turns .gam-pop into a colour change).

import { gamIsolate } from './numbers.js';

/** The colours a value flashes in: gained, coins, lost. */
export const TONES = {
    up: '#059669',
    coin: '#d97706',
    down: '#e11d48',
};

const PARTICLE_STAGGER_MS = 45;
const PARTICLE_FLIGHT_MS = 780;

/**
 * The page's motion helpers, bound to a window. Tests hand in a stand-in.
 *
 * @param {Window & typeof globalThis} win
 */
export function createMotion(win = globalThis.window) {
    const doc = () => win?.document;
    const later = (callback, ms) => (win?.setTimeout ?? setTimeout)(callback, ms);

    function reducedMotion() {
        try {
            return Boolean(win?.matchMedia && win.matchMedia('(prefers-reduced-motion: reduce)').matches);
        } catch (e) {
            return false;
        }
    }

    /** Flash the level meter's fill, as XP lands in it. */
    function pulseMeter() {
        if (reducedMotion()) {
            return false;
        }

        const fill = doc()?.getElementById('gam-level-fill');
        if (! fill) {
            return false;
        }

        fill.classList.remove('gam-flash');
        void fill.offsetWidth;
        fill.classList.add('gam-flash');
        later(() => fill.classList.remove('gam-flash'), 650);

        return true;
    }

    /**
     * Pop a value that changed, in the tone's colour. Under reduced motion the
     * stylesheet keeps only the colour, so this still runs.
     */
    function pulseValue(id, tone = 'up') {
        const el = doc()?.getElementById(id);
        if (! el) {
            return false;
        }

        el.style.setProperty('--gam-pop-color', TONES[tone] ?? TONES.up);
        el.classList.remove('gam-pop');
        void el.offsetWidth;
        el.classList.add('gam-pop');
        later(() => el.classList.remove('gam-pop'), 550);

        return true;
    }

    /**
     * Fly particles from an element to a target (the level meter by default),
     * then flash the meter as they land. Returns how many were launched.
     */
    function launchXp(fromEl, count = 10, targetId = 'gam-level-meter') {
        if (reducedMotion()) {
            return 0;
        }

        const target = doc()?.getElementById(targetId);
        if (! fromEl || ! target || typeof fromEl.getBoundingClientRect !== 'function') {
            return 0;
        }

        const from = fromEl.getBoundingClientRect();
        const to = target.getBoundingClientRect();
        const sx = from.left + from.width / 2;
        const sy = from.top + from.height / 2;
        const dx = (to.left + to.width / 2) - sx;
        const dy = (to.top + to.height / 2) - sy;

        for (let i = 0; i < count; i++) {
            const particle = doc().createElement('div');
            particle.className = 'gam-xp-particle';
            particle.style.left = sx + 'px';
            particle.style.top = sy + 'px';
            doc().body.appendChild(particle);

            const mx = dx * 0.5 + (Math.random() - 0.5) * 140;
            const my = dy * 0.5 - (70 + Math.random() * 90);

            if (typeof particle.animate !== 'function') {
                particle.remove();
                continue;
            }

            const flight = particle.animate([
                { transform: 'translate(0,0) scale(' + (0.5 + Math.random() * 0.7) + ')', opacity: 1, offset: 0 },
                { transform: 'translate(' + mx + 'px,' + my + 'px) scale(1)', opacity: 1, offset: 0.6 },
                { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(0.25)', opacity: 0.15, offset: 1 },
            ], { duration: 750 + Math.random() * 250, delay: i * PARTICLE_STAGGER_MS, easing: 'cubic-bezier(.4,0,.2,1)', fill: 'forwards' });
            flight.onfinish = () => particle.remove();
        }

        if (targetId === 'gam-level-meter') {
            later(pulseMeter, count * PARTICLE_STAGGER_MS + PARTICLE_FLIGHT_MS);
        } else {
            later(() => pulseValue(targetId, 'coin'), count * PARTICLE_STAGGER_MS + PARTICLE_FLIGHT_MS);
        }

        return count;
    }

    /**
     * Float a short text ("+12") up from a value and fade it out. Nothing under
     * reduced motion: the value's own colour flash says it instead.
     */
    function float(id, text, tone = 'up') {
        if (reducedMotion()) {
            return null;
        }

        const anchor = doc()?.getElementById(id);
        if (! anchor) {
            return null;
        }

        const box = anchor.getBoundingClientRect();
        const label = doc().createElement('span');
        label.className = 'gam-float';
        label.setAttribute('aria-hidden', 'true');
        label.textContent = gamIsolate(text);
        label.style.left = (box.left + box.width / 2) + 'px';
        label.style.top = box.top + 'px';
        label.style.color = TONES[tone] ?? TONES.up;
        doc().body.appendChild(label);

        if (typeof label.animate !== 'function') {
            later(() => label.remove(), 1100);

            return label;
        }

        const rise = label.animate([
            { transform: 'translate(-50%, 0)', opacity: 0 },
            { transform: 'translate(-50%, -6px)', opacity: 1, offset: 0.2 },
            { transform: 'translate(-50%, -30px)', opacity: 0 },
        ], { duration: 1100, easing: 'ease-out', fill: 'forwards' });
        rise.onfinish = () => label.remove();

        return label;
    }

    /**
     * Scroll behaviour that respects reduced motion: 'auto' (a jump) when the
     * reader asked for less motion, the asked-for behaviour otherwise.
     */
    function scrollBehavior(wanted = 'smooth') {
        return reducedMotion() ? 'auto' : wanted;
    }

    return { reducedMotion, pulseMeter, pulseValue, launchXp, float, scrollBehavior };
}

function showAtOnce(el) {
    el.hidden = false;
    el.style.removeProperty('display');
    el.style.height = 'auto';
    el.style.removeProperty('overflow');
    el._x_isShown = true;
}

function hideAtOnce(el) {
    el.style.height = '0px';
    el.style.overflow = 'hidden';
    el.style.display = 'none';
    el.hidden = true;
    el._x_isShown = false;
}

/**
 * Guard an element's x-collapse (Alpine.directive('gam-collapse'), written
 * after it: x-show="…" x-collapse x-gam-collapse).
 *
 * Alpine's collapse loses a show that comes in the first frames of a hide:
 * the show cancels the hide, the cancelled hide ends by setting display:none,
 * and the show never takes it off. A claim that fails at once (the phone is
 * offline) brings its row back that soon, and the row stayed hidden while the
 * totals counted it again. A hide that has not set its end height yet has not
 * moved, so it is cancelled and the element shown at once; a hide already
 * sliding is turned back by the collapse itself.
 *
 * Under reduced motion nothing slides: the element shows or hides at once.
 *
 * @param {HTMLElement} el an element with x-show and x-collapse (no .min)
 * @param {Function} reducedMotion whether the reader asked for less motion
 * @returns {boolean} whether the element's collapse is guarded
 */
export function guardCollapse(el, reducedMotion = () => false) {
    const stock = el?._x_transition;
    if (! stock || typeof stock.in !== 'function' || typeof stock.out !== 'function') {
        return false;
    }

    if (stock.gamGuarded) {
        return true;
    }

    const stopRunning = () => el._x_transitioning?.cancel?.();

    el._x_transition = {
        gamGuarded: true,

        in(before = () => {}, after = () => {}) {
            const hideNotMoving = Boolean(el._x_transitioning) && parseFloat(el.style.height) > 0;

            if (reducedMotion() || hideNotMoving) {
                stopRunning();
                showAtOnce(el);

                return;
            }

            stock.in(before, after);
        },

        out(before = () => {}, after = () => {}) {
            if (reducedMotion()) {
                stopRunning();
                hideAtOnce(el);

                return;
            }

            stock.out(before, after);
        },
    };

    return true;
}
