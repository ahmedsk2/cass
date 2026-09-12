@php
    $conference = $this->getConference();
    $link = $conference->shortLink;
    $daily = $this->getDailyScans();
    $peak = max(1, ...array_values($daily ?: [0]));
@endphp

<x-filament-panels::page>
    @if ($link === null)
        <x-filament::section :heading="__('public.short_link.not_shared_heading')">
            <p style="font-size:0.875rem">
                {{ __('public.short_link.not_shared_body') }}
            </p>
        </x-filament::section>
    @else
        <x-filament::section :heading="__('public.short_link.heading')">
            <p style="font-family:ui-monospace,monospace;font-size:1.125rem">
                <x-filament::link :href="$link->url()" target="_blank" rel="noopener">{{ $link->url() }}</x-filament::link>
            </p>
            {{-- One sentence with two monospace spans in it: one key with two
                 placeholders, both built and escaped here, rather than three
                 fragments a translation cannot reorder. --}}
            <p style="margin-top:0.5rem;font-size:0.875rem">
                {!! __('public.short_link.code', [
                    'code' => '<span style="font-family:ui-monospace,monospace;font-weight:600">'.e($link->code).'</span>',
                    'url' => '<span style="font-family:ui-monospace,monospace">'.e($conference->publicUrl()).'</span>',
                ]) !!}
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
        <x-filament::section :heading="__('public.short_link.scans_heading')">
            <div style="display:flex;align-items:baseline;gap:0.75rem">
                <span style="font-size:1.875rem;font-weight:600">{{ number_format($link->clicks) }}</span>
                <span class="fi-text-sm">{{ __('public.short_link.total') }}</span>
            </div>

            <h3 style="margin-top:1.5rem;font-size:0.875rem;font-weight:500">{{ __('public.short_link.last_30_days') }}</h3>
            <div style="display:flex;align-items:flex-end;gap:2px;height:96px;margin-top:0.75rem">
                @foreach ($daily as $day => $count)
                    <div title="{{ __('public.short_link.day_tooltip', ['day' => $day, 'count' => $count]) }}"
                         style="flex:1;height:{{ $count === 0 ? 2 : (int) round($count / $peak * 96) }}px;background:var(--primary-500);border-radius:2px 2px 0 0"></div>
                @endforeach
            </div>
            <p style="margin-top:0.5rem;font-size:0.75rem;opacity:0.7">
                {{ __('public.short_link.privacy_note') }}
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
