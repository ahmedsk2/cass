<x-filament-panels::page>
    @if ($this->isPending())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
            <p class="font-semibold">{{ $this->getOrganization()->name }} is awaiting approval.</p>
            <p class="mt-1 text-sm">You can complete your organization profile and branding now. Publishing a conference becomes available once the platform team approves your organization. We usually respond within two working days.</p>
        </div>
    @elseif ($this->isSuspended())
        <div class="rounded-xl border border-red-300 bg-red-50 p-4 text-red-900 dark:border-red-700 dark:bg-red-950 dark:text-red-100">
            <p class="font-semibold">This organization is suspended.</p>
            @if ($this->getOrganization()->status_reason)
                <p class="mt-1 text-sm">Reason: {{ $this->getOrganization()->status_reason }}</p>
            @endif
            <p class="mt-1 text-sm">Contact {{ config('cass.platform_contact_email') }} if you believe this is a mistake.</p>
        </div>
    @else
        {{-- <x-filament::section>, not Tailwind utilities: a panel loads only
             Filament's precompiled CSS, which has no general utilities, so
             `rounded-xl border bg-white p-4` renders as nothing (the existing
             pending and suspended banners above have the same problem - see
             the backlog item about an organizer panel theme). --}}
        <x-filament::section>
            <p style="font-weight:600">Welcome to {{ $this->getOrganization()->name }}.</p>
            <p style="margin-top:0.25rem;font-size:0.875rem">
                Create a conference, set its dates and review form, then publish it to get a public page, a short link and a printable QR poster.
            </p>
        </x-filament::section>
    @endif

    <x-filament-widgets::widgets :widgets="$this->getVisibleWidgets()" :columns="$this->getColumns()" />
</x-filament-panels::page>
