@php use App\Support\Html\RichText; @endphp

<x-layouts.conference :organization="$organization" :conference="$conference" :theme="$theme">
    @if ($isPreview)
        <div class="bg-amber-50 text-amber-900">
            <div class="mx-auto max-w-5xl px-4 py-3 text-sm">
                <span class="font-semibold">{{ __('conference.preview.badge') }}</span>
                {{ __('conference.preview.body', [
                    'status' => $conference->status->getLabel(),
                    'organization' => $organization->name,
                ]) }}
            </div>
        </div>
    @endif

    <section class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-5xl px-4 py-12">
            <p class="text-sm font-semibold uppercase tracking-wide text-[var(--org-accent)]">{{ __('conference.call') }}</p>
            <h1 class="mt-2 text-4xl font-semibold tracking-tight">{{ $conference->name }}</h1>

            @if ($conference->short_description)
                <p class="mt-4 max-w-3xl text-lg text-slate-600">{{ $conference->short_description }}</p>
            @endif

            <dl class="mt-8 grid gap-6 sm:grid-cols-3">
                @if ($conference->starts_at)
                    <div>
                        <dt class="text-sm font-medium text-slate-500">{{ __('conference.dates') }}</dt>
                        <dd class="mt-1 font-medium">
                            {{ $conference->starts_at->format('j M Y') }}@if ($conference->ends_at && ! $conference->ends_at->isSameDay($conference->starts_at)) – {{ $conference->ends_at->format('j M Y') }}@endif
                        </dd>
                    </div>
                @endif
                @if ($conference->venue || $conference->city)
                    <div>
                        <dt class="text-sm font-medium text-slate-500">{{ __('conference.venue') }}</dt>
                        <dd class="mt-1 font-medium">{{ collect([$conference->venue, $conference->city])->filter()->implode(', ') }}</dd>
                    </div>
                @endif
                @if ($conference->submission_deadline)
                    <div>
                        <dt class="text-sm font-medium text-slate-500">{{ __('conference.deadline') }}</dt>
                        {{-- The parentheses are in the key, not in the view: a
                             translation has to be free to move or drop them. --}}
                        <dd class="mt-1 font-medium">{{ __('conference.deadline_value', [
                            'date' => $conference->deadlineInConferenceTimezone()?->format('j M Y, H:i'),
                            'timezone' => $conference->timezone,
                        ]) }}</dd>
                    </div>
                @endif
            </dl>

            @include('public.partials.submit-cta', ['conference' => $conference])
        </div>
    </section>

    <div class="mx-auto grid max-w-5xl gap-10 px-4 py-12 md:grid-cols-3">
        <div class="md:col-span-2">
            @if ($conference->description)
                <div class="prose prose-slate max-w-none">
                    {!! RichText::sanitize($conference->description) !!}
                </div>
            @endif

            @if ($conference->terms)
                <section class="mt-10">
                    <h2 class="text-xl font-semibold tracking-tight">{{ __('conference.terms') }}</h2>
                    <p class="mt-3 whitespace-pre-line text-slate-600">{{ $conference->terms }}</p>
                </section>
            @endif
        </div>

        <aside class="space-y-8">
            @if ($conference->tracks->isNotEmpty())
                <section>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('conference.tracks') }}</h2>
                    <ul class="mt-3 space-y-2">
                        @foreach ($conference->tracks as $track)
                            <li class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                                <p class="font-medium">{{ $track->name }}</p>
                                @if ($track->description)
                                    <p class="mt-1 text-sm text-slate-600">{{ $track->description }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('conference.prepare.heading') }}</h2>
                {{-- One key per sentence, with the numbers as placeholders. The
                     emphasis <span>s that used to sit inside these sentences are
                     gone: a sentence broken into three fragments is a sentence a
                     translation cannot reorder. --}}
                <ul class="mt-3 space-y-2 text-sm text-slate-600">
                    <li>{{ __('conference.prepare.words', ['count' => $conference->word_limit]) }}</li>
                    <li>{{ __('conference.prepare.files', [
                        'max' => $conference->max_files,
                        'types' => Str::upper(implode(', ', $conference->allowed_file_types)),
                    ]) }}</li>
                    <li>{{ __('conference.prepare.presentation', ['types' => implode(', ', $conference->presentation_types)]) }}</li>
                </ul>
            </section>
        </aside>
    </div>
</x-layouts.conference>
