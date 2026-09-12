@php use App\Support\Purge\PurgeCounts; @endphp

@if (PurgeCounts::total($counts) === 0)
    <p>{{ __('admin.purge.nothing') }}</p>
@else
    <p style="font-weight:600;margin:0 0 0.5rem">{{ __('admin.purge.counts_heading') }}</p>
    <ul style="margin:0;padding-left:1.25rem">
        @foreach (PurgeCounts::significant($counts) as $table => $count)
            <li><strong>{{ number_format($count) }}</strong> {{ $table === 'private files' ? __('admin.purge.files') : str_replace('_', ' ', $table) }}</li>
        @endforeach
    </ul>
    <p style="margin:0.75rem 0 0;color:#475569;font-size:0.8125rem">{{ __('admin.purge.audit_note') }}</p>
@endif
