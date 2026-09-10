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
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <p class="font-semibold">Welcome to {{ $this->getOrganization()->name }}.</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Conference management arrives in the next release. Your profile and branding are ready.</p>
        </div>
    @endif

    <x-filament-widgets::widgets :widgets="$this->getVisibleWidgets()" :columns="$this->getColumns()" />
</x-filament-panels::page>
