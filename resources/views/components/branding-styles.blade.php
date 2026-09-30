{{--
    The academy's colour, applied after the stylesheet so it wins.

    Tailwind compiles every `maroon` utility to `var(--color-maroon)`, so
    redefining the variable here recolours the whole interface — including the
    Flux accent, which is declared as `var(--color-maroon)` and resolves late.
--}}
<style>{!! App\Support\Branding::cssVariables() !!}</style>
