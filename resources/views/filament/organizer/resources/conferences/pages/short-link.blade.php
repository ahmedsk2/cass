@php
    $conference = $this->getConference();
    $link = $conference->shortLink;
    $daily = $this->getDailyScans();
    $peak = max(1, ...array_values($daily ?: [0]));
@endphp

<x-filament-panels::page>
    @if ($link === null)
        <x-filament::section heading="Not shared yet">
            <p style="font-size:0.875rem">
                Publishing this conference creates a short link and a QR code you can print. Publish it from the conference page when the dates and review form are ready.
            </p>
        </x-filament::section>
    @else
        <x-filament::section heading="Short link">
            <p style="font-family:ui-monospace,monospace;font-size:1.125rem">
                <x-filament::link :href="$link->url()" target="_blank" rel="noopener">{{ $link->url() }}</x-filament::link>
            </p>
            <p style="margin-top:0.5rem;font-size:0.875rem">
                Code <span style="font-family:ui-monospace,monospace;font-weight:600">{{ $link->code }}</span>. It points at
                <span style="font-family:ui-monospace,monospace">{{ $conference->publicUrl() }}</span> and never changes, so a printed poster keeps working after you close and reopen submissions.
            </p>
        </x-filament::section>

        {{--
            Inline styles, not Tailwind utilities: a Filament panel loads only
            Filament's precompiled CSS, which contains theme variables and
            fi-* component classes and no general utilities, and this panel has
            no custom theme (`->viteTheme()`). `flex`, `h-24`, `items-end` and
            `bg-primary-500` would all be no-ops here, so the spec 5.7
            sparkline would render as invisible full-width blocks.
            `--primary-500` is a real colour value Filament emits on the page.
        --}}
        <x-filament::section heading="Scans">
            <div style="display:flex;align-items:baseline;gap:0.75rem">
                <span style="font-size:1.875rem;font-weight:600">{{ number_format($link->clicks) }}</span>
                <span class="fi-text-sm">Total scans</span>
            </div>

            <h3 style="margin-top:1.5rem;font-size:0.875rem;font-weight:500">Last 30 days</h3>
            <div style="display:flex;align-items:flex-end;gap:2px;height:96px;margin-top:0.75rem">
                @foreach ($daily as $day => $count)
                    <div title="{{ $day }}: {{ $count }}"
                         style="flex:1;height:{{ $count === 0 ? 2 : (int) round($count / $peak * 96) }}px;background:var(--primary-500);border-radius:2px 2px 0 0"></div>
                @endforeach
            </div>
            <p style="margin-top:0.5rem;font-size:0.75rem;opacity:0.7">
                Only a timestamp is stored for each scan. No IP address, device or location is recorded.
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
