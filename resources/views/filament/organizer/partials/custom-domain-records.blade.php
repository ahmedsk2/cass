{{-- The panel loads no Tailwind utilities (backlog: the organizer theme), so
     this is inline-styled like every other organizer partial in this app. --}}
@php
    $txtName = $organization?->customDomainTxtName();
    $token = (string) ($organization?->custom_domain_token ?? '');
    $cname = \App\Support\Domains\DomainName::cnameTarget();
    $domain = (string) ($organization?->custom_domain ?? '');
@endphp

<div style="border:1px solid #e2e8f0;border-radius:0.5rem;padding:1rem;background:#f8fafc">
    <p style="font-weight:600;margin:0 0 0.25rem">{{ __('domain.records.heading') }}</p>
    <p style="margin:0 0 0.75rem;color:#475569;font-size:0.875rem">
        {{ __('domain.records.intro', ['domain' => $domain]) }}
    </p>

    <dl style="display:grid;grid-template-columns:minmax(0,14rem) minmax(0,1fr);gap:0.5rem 1rem;margin:0;font-size:0.875rem">
        <dt style="color:#475569">{{ __('domain.records.txt_name') }}</dt>
        <dd style="margin:0;font-family:ui-monospace,monospace;overflow-wrap:anywhere">{{ $txtName }}</dd>

        <dt style="color:#475569">{{ __('domain.records.txt_value') }}</dt>
        <dd style="margin:0;font-family:ui-monospace,monospace;overflow-wrap:anywhere">{{ $token }}</dd>

        <dt style="color:#475569">{{ __('domain.records.cname_name') }}</dt>
        <dd style="margin:0;font-family:ui-monospace,monospace;overflow-wrap:anywhere">{{ $domain }}</dd>

        <dt style="color:#475569">{{ __('domain.records.cname_value') }}</dt>
        <dd style="margin:0;font-family:ui-monospace,monospace;overflow-wrap:anywhere">{{ $cname }}</dd>
    </dl>

    <p style="margin:0.75rem 0 0;color:#475569;font-size:0.8125rem">{{ __('domain.records.cname_note') }}</p>
    @if ($organization?->hasVerifiedCustomDomain())
        <p style="margin:0.25rem 0 0;color:#475569;font-size:0.8125rem">{{ __('domain.records.after') }}</p>
    @endif
</div>
