@php($coverage = $this->getCoverage())

<x-filament-panels::page>
    <div style="display:flex;flex-direction:column;gap:1.5rem">
        <x-filament::section :heading="__('reviewer.assign.coverage')">
            <div style="display:flex;gap:2rem;flex-wrap:wrap">
                <p style="font-size:0.875rem">{{ __('reviewer.assign.coverage_target', ['count' => $coverage['target']]) }}</p>
                <p style="font-size:0.875rem">{{ __('reviewer.assign.coverage_covered', ['count' => $coverage['covered'], 'total' => $coverage['submissions']]) }}</p>
                <p style="font-size:0.875rem">{{ __('reviewer.assign.coverage_under', ['count' => $coverage['under']]) }}</p>
                <p style="font-size:0.875rem">{{ __('reviewer.assign.coverage_unassigned', ['count' => $coverage['unassigned']]) }}</p>
            </div>
        </x-filament::section>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
