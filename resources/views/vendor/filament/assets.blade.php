@php
    // A copy of vendor/filament/support/resources/views/assets.blade.php
    // (Filament 5.10.2) with the nonce on both of its inline tags. Filament
    // nonces the <script> itself, but only when a nonce is set, and never the
    // <style>; here both carry the attribute unconditionally, in place of
    // Filament's own on the <script>, so the tags render as they always have.
    // tests/Feature/Security/PublishedFilamentViewsTest.php fails if the vendor
    // original changes, so an upgrade is a red suite and a re-publish rather
    // than a panel that silently stops rendering.
    $cspNonce = \Illuminate\Support\Facades\Vite::cspNonce();
@endphp

@php
    use Illuminate\Support\Facades\Vite;
@endphp

@if (isset($data))
    @php
        $nonce = Vite::cspNonce();
    @endphp

    <script nonce="{{ $cspNonce }}">
        window.filamentData = @js($data)
    </script>
@endif

@foreach ($assets as $asset)
    @if (! $asset->isLoadedOnRequest())
        {{ $asset->getHtml() }}
    @endif
@endforeach

<style nonce="{{ $cspNonce }}">
    :root {
        @foreach ($cssVariables ?? [] as $cssVariableName => $cssVariableValue) --{{ $cssVariableName }}:{{ $cssVariableValue }}; @endforeach
    }

    @foreach ($customColors ?? [] as $customColorName => $customColorShades) .fi-color-{{ $customColorName }} { @foreach ($customColorShades as $customColorShade) --color-{{ $customColorShade }}:var(--{{ $customColorName }}-{{ $customColorShade }}); @endforeach } @endforeach
</style>
