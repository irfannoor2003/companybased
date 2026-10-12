{{--
    The theme tokens, in ONE place.

    Four pages render outside the normal app chrome — the app layout, the guest
    layout, the attendance QR result and the subscription-expired error page — and
    all four need the colour custom properties before first paint. They used to
    each declare their own copy, which is how the palette drifted: the error page
    kept an `--color-ink-faint` of #94a3b8 at 2.56:1 on white long after the main
    stylesheet had been corrected, and it never gained `--color-primary-ink` at all.

    The values themselves live in resources/css/app.css. This partial carries only
    what the tenant can change per request, so a brand colour set in Settings still
    re-themes every page.

    Include it wherever a page cannot rely on the compiled stylesheet alone.
--}}
<style>
    :root {
        --color-primary: {{ $appBrand['primaryRgb'] ?? '79 70 229' }};
        --color-primary-strong: {{ $appBrand['primaryStrongRgb'] ?? '59 52 203' }};
        --color-primary-ink: {{ $appBrand['primaryRgb'] ?? '79 70 229' }};
        --color-accent: {{ $appBrand['accentRgb'] ?? '14 165 233' }};
    }

    {{--
        Only the ink token changes in dark mode. `--color-primary` is deliberately
        left alone: it is a fill behind white button labels, so lightening it to
        make brand TEXT readable would drop white-on-primary from 6.29:1 to
        2.98:1 and break every button.
    --}}
    .dark {
        --color-primary-ink: {{ $appBrand['primaryInkRgb'] ?? '165 180 252' }};
    }
</style>
