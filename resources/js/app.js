import './gamification/index.js';

// A teacher leaves the roll open on their phone and comes back after the
// session has lapsed: Livewire would ask, in English, whether to refresh.
// Ask the same thing in the language the rest of the page speaks.
let sessionExpiredAsked = false;

document.addEventListener('livewire:init', () => {
    window.Livewire.interceptRequest(({ onError }) => {
        onError(({ response, preventDefault }) => {
            if (response?.status !== 419) {
                return;
            }

            preventDefault();

            // Several components can fail together; ask once, as Livewire does.
            if (sessionExpiredAsked) {
                return;
            }

            sessionExpiredAsked = true;

            if (window.confirm('انتهت صلاحية الصفحة لأنها بقيت مفتوحة مدة طويلة.\nهل تريد تحديثها الآن؟')) {
                window.location.reload();
            }
        });
    });
});
