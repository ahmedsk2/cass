<x-filament-panels::page>
    {{-- The panel loads no Tailwind utilities (backlog: the organizer theme),
         so every measurement here is an inline style, the same way
         ConferenceEmailTemplates and ConferenceShortLink do it. --}}
    @php($summary = $this->summary())

    <x-filament::section :heading="__('decisions.summary.heading')">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:1rem">
            <div>
                <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;opacity:0.7">{{ __('decisions.summary.total') }}</p>
                <p style="font-size:1.5rem;font-weight:600">{{ $summary['total'] }}</p>
            </div>
            <div>
                <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;opacity:0.7">{{ __('decisions.summary.reviewed') }}</p>
                <p style="font-size:1.5rem;font-weight:600">{{ $summary['reviewed'] }}</p>
                <p style="font-size:0.75rem;opacity:0.7">{{ __('decisions.summary.unreviewed', ['count' => $summary['unreviewed']]) }}</p>
            </div>
            <div>
                <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;opacity:0.7">{{ __('decisions.summary.mean') }}</p>
                <p style="font-size:1.5rem;font-weight:600">{{ $summary['mean_score'] !== null ? number_format($summary['mean_score'], 2) : '—' }}</p>
            </div>
            <div>
                <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;opacity:0.7">{{ __('decisions.summary.decided') }}</p>
                <p style="font-size:1.5rem;font-weight:600">{{ $summary['decided'] }}</p>
                <p style="font-size:0.75rem;opacity:0.7">{{ __('decisions.summary.undecided', ['count' => $summary['undecided']]) }}</p>
            </div>
            @foreach (\App\Enums\Decision::inReportOrder() as $decision)
                <div>
                    <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;opacity:0.7">{{ $decision->getLabel() }}</p>
                    <p style="font-size:1.5rem;font-weight:600">{{ $summary['by_decision'][$decision->value] }}</p>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <div style="display:flex;flex-direction:column;gap:1.5rem">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
