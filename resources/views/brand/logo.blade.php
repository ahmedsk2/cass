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
    and there is no inline equivalent of a descendant selector.
--}}
<span class="cass-lockup" style="display:inline-flex;align-items:center;gap:0.5rem;line-height:1">
    <img src="{{ asset('brand/cass-mark.svg') }}" alt="" style="height:2.25rem;width:auto;display:block">
    <span class="cass-wordmark" style="font-weight:600;letter-spacing:-0.01em;font-size:1.375rem;color:#0F4C8A">CASS</span>
</span>
<style>.dark .cass-wordmark{color:#ffffff}</style>
