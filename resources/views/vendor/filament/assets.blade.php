@php
    // Filament 5.8.1 has no nonce support (Plan 6 fact 5). This file is a
    // verbatim copy of vendor/filament/support/resources/views/assets.blade.php
    // with two nonce attributes added. tests/Feature/Security/PublishedFilamentViewsTest.php
    // fails if the vendor original changes, so an upgrade is a red suite and a
    // re-publish rather than a panel that silently stops rendering.
    $cspNonce = \Illuminate\Support\Facades\Vite::cspNonce();
@endphp

@if (isset($data))
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
