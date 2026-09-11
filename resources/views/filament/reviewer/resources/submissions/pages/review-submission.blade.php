@php
    $submission = $this->getSubmission();
    $conference = $this->getConference();
    $files = $this->getFiles();
    $customFields = $this->getCustomFieldLines();
@endphp

<x-filament-panels::page>
    <x-filament::section :heading="__('reviewer.review.abstract')">
        <p style="font-family:ui-monospace,monospace;font-size:0.875rem">{{ $submission->reference }}</p>
        <h2 style="margin-top:0.5rem;font-size:1.125rem;font-weight:600">{{ $submission->title }}</h2>

        <div style="margin-top:0.75rem;display:flex;gap:1.5rem;flex-wrap:wrap;font-size:0.875rem">
            <span>{{ __('reviewer.review.track') }}: {{ $submission->track?->name ?? '-' }}</span>
            <span>{{ __('reviewer.review.preference') }}: {{ $submission->presentation_preference?->getLabel() ?? '-' }}</span>
            <span>{{ __('reviewer.review.words', ['count' => $submission->word_count]) }}</span>
        </div>

        {{-- Plain text, never HTML: the column is plain text and the public
             form is a textarea, so this is an escaped echo by construction. --}}
        <p style="margin-top:1rem;white-space:pre-wrap">{{ $submission->abstract }}</p>
    </x-filament::section>

    @if ($this->isBlind())
        <x-filament::section :heading="__('reviewer.review.authors')">
            <p style="font-size:0.875rem">{{ __('reviewer.review.blind_notice') }}</p>
        </x-filament::section>
    @else
        <x-filament::section :heading="__('reviewer.review.authors')">
            <ul style="font-size:0.875rem;display:flex;flex-direction:column;gap:0.25rem">
                @foreach ($submission->authors as $author)
                    <li>
                        {{ $author->name }}
                        @if ($author->affiliation) &mdash; {{ $author->affiliation }} @endif
                        @if ($author->is_corresponding) ({{ $author->email }}) @endif
                    </li>
                @endforeach
            </ul>
            @if ($submission->contact_phone)
                <p style="margin-top:0.5rem;font-size:0.875rem">{{ __('reviewer.review.phone') }}: {{ $submission->contact_phone }}</p>
            @endif
        </x-filament::section>
    @endif

    @if ($customFields !== [])
        <x-filament::section :heading="__('reviewer.review.extra')">
            <ul style="font-size:0.875rem;display:flex;flex-direction:column;gap:0.25rem">
                @foreach ($customFields as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    @if ($files->isNotEmpty())
        <x-filament::section :heading="__('reviewer.review.files')">
            <ul style="font-size:0.875rem;display:flex;flex-direction:column;gap:0.5rem">
                @foreach ($files as $file)
                    <li>
                        @php($url = $this->fileUrl($file))
                        @if ($url)
                            <x-filament::link :href="$url" target="_blank" rel="noopener">{{ $this->fileName($file) }}</x-filament::link>
                        @else
                            {{ $this->fileName($file) }}
                        @endif
                        <span style="opacity:0.7">({{ number_format($file->size / 1024) }} KB)</span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
