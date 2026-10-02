<x-filament-panels::page>
    @if ($this->isPending())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
            <p class="font-semibold">{{ __('public.dashboard.pending_title', ['organization' => $this->getOrganization()->name]) }}</p>
            <p class="mt-1 text-sm">{{ __('public.dashboard.pending_body') }}</p>
        </div>
    @elseif ($this->isSuspended())
        <div class="rounded-xl border border-red-300 bg-red-50 p-4 text-red-900 dark:border-red-700 dark:bg-red-950 dark:text-red-100">
            <p class="font-semibold">{{ __('public.dashboard.suspended_title') }}</p>
            @if ($this->getOrganization()->status_reason)
                <p class="mt-1 text-sm">{{ __('public.dashboard.suspended_reason', ['reason' => $this->getOrganization()->status_reason]) }}</p>
            @endif
            <p class="mt-1 text-sm">{{ __('public.dashboard.suspended_contact', ['email' => config('cass.platform_contact_email')]) }}</p>
        </div>
    @else
        {{-- Utilities work in the panels since Plan 7: every panel loads
             resources/css/filament/theme.css, whose @source reaches this
             directory. The two banners above were written that way in Plan 1
             and rendered unstyled until then. --}}
        <x-filament::section>
            <p class="font-semibold">{{ __('public.dashboard.welcome_title', ['organization' => $this->getOrganization()->name]) }}</p>
            <p class="mt-1 text-sm">
                {{ __('public.dashboard.welcome_body') }}
            </p>
        </x-filament::section>
    @endif

    <x-filament-widgets::widgets :widgets="$this->getVisibleWidgets()" :columns="$this->getColumns()" />
</x-filament-panels::page>
