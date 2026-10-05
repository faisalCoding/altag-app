// The student's gamification page: its claim store, its rewards panel, its
// celebration cards and its motion helpers, registered with the Alpine that
// Livewire brings. The page's own script only connects the component's events
// to these.

import { celebrations } from './celebrations.js';
import { connectClaims, createClaimStore, rewardsPanel } from './claims.js';
import { createMotion, guardCollapse } from './motion.js';
import { gamCount, gamIsolate, gamSigned, gamUnit } from './numbers.js';

const motion = createMotion(window);

window.gamReducedMotion = motion.reducedMotion;
window.gamScrollBehavior = motion.scrollBehavior;
window.gamLaunchXp = motion.launchXp;
window.gamFloat = motion.float;
window.gamPulseMeter = motion.pulseMeter;
window.gamPulseValue = motion.pulseValue;
window.gamCount = gamCount;
window.gamUnit = gamUnit;
window.gamIsolate = gamIsolate;
window.gamSigned = gamSigned;

/** Whether the page still draws an item for this claim key. */
function claimKeyDrawn(key) {
    const value = window.CSS?.escape ? window.CSS.escape(key) : String(key).replace(/"/g, '\\"');

    return document.querySelector('[data-claim-key="' + value + '"]') !== null;
}

function register(Alpine) {
    Alpine.store('gamClaims', createClaimStore({
        motion,
        dispatch: (name, detail) => window.dispatchEvent(new CustomEvent(name, { detail })),
        exists: claimKeyDrawn,
    }));

    Alpine.data('gamRewardsPanel', rewardsPanel);
    Alpine.data('gamCelebrations', celebrations);

    // x-gam-collapse, after x-collapse; see guardCollapse(). Should it run
    // first, the collapse is guarded once every directive is in.
    Alpine.directive('gam-collapse', (el) => {
        if (! guardCollapse(el, motion.reducedMotion)) {
            queueMicrotask(() => guardCollapse(el, motion.reducedMotion));
        }
    });

    // The page's @script hands its $wire here; see connectClaims().
    window.gamConnectClaims = (wire) => connectClaims(
        wire,
        Alpine.store('gamClaims'),
        motion,
        (callback) => window.requestAnimationFrame(callback),
    );
}

// Livewire loads Alpine before this module runs and starts it once the page
// has loaded; register now if it is here, or as it starts.
if (window.Alpine) {
    register(window.Alpine);
} else {
    document.addEventListener('alpine:init', () => register(window.Alpine), { once: true });
}
