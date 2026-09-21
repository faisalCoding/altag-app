{{--
    Hold this page in the light theme whatever the reader's device prefers.

    The public pages are the academy's face, and a face should not change with
    the visitor's system settings — a parent opening the site at night should
    see what the academy chose, not a dark inversion of it.

    Three things are needed, because there are three ways the dark theme gets
    in: the class Flux's appearance script writes on <html> from a stored
    preference, the browser's own dark rendering of form controls and
    scrollbars, and the bare background a dark OS paints before the CSS lands.

    The dark: utilities inside these pages are left where they are. Nothing can
    trigger them while the class never appears, and stripping sixty of them by
    hand is a worse risk than leaving them unreachable.
--}}
<meta name="color-scheme" content="light">

<script>
    document.documentElement.classList.remove('dark');

    // The appearance script runs after this one and may put the class back.
    new MutationObserver(() => {
        document.documentElement.classList.remove('dark');
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
</script>

<style>
    :root, html, body { color-scheme: only light !important; }

    @media (prefers-color-scheme: dark) {
        html, body { background-color: #ffffff !important; color: #18181b !important; }
    }
</style>
