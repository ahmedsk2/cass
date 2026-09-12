{{--
    The CASS lock-up: the hummingbird mark beside the "CASS" wordmark.

    Everything is inline-styled on purpose. This view is handed to Filament as
    the panels' brand logo, and the panels compile their CSS from Filament's own
    sources - `@source '../views'` in resources/css/app.css only reaches the
    public site's stylesheet, so a Tailwind class written here would render
    unstyled inside /admin and /org.

    The one rule that cannot be inlined is the dark-mode wordmark colour:
    Filament toggles a `dark` class on <html> (see
    vendor/filament/filament/resources/views/components/layout/base.blade.php),
    and there is no inline equivalent of a descendant selector. That one style
    ELEMENT carries the CSP nonce: style-src is 'self' plus the nonce, and an
    un-nonced copy is refused silently - the page still renders, just with a
    blue wordmark on a dark panel.
--}}
<span class="cass-lockup" style="display:inline-flex;align-items:center;gap:0.5rem;line-height:1">
    {{-- width/height are the mark's own viewBox, so the browser reserves the
         right box before the SVG arrives. The inline height:2.25rem/width:auto
         still decides the rendered size; these only carry the ratio. --}}
    <img src="{{ asset('brand/cass-mark.svg') }}" alt="" width="434" height="725" style="height:2.25rem;width:auto;display:block">
    <span class="cass-wordmark" style="font-weight:600;letter-spacing:-0.01em;font-size:1.375rem;color:#0F4C8A">CASS</span>
</span>
<style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">.dark .cass-wordmark{color:#ffffff}</style>
