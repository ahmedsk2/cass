<div class="mx-auto max-w-3xl px-4 py-10">
    @if (session('status'))
        <div class="mb-6 rounded-lg border border-green-300 bg-green-50 p-4 text-green-900">{{ session('status') }}</div>
    @endif

    <p class="text-sm font-semibold uppercase tracking-wide text-[var(--org-accent)]">{{ $conference->name }}</p>
    <h1 class="mt-2 text-3xl font-semibold tracking-tight">{{ $submission->title }}</h1>

    <dl class="mt-6 grid gap-4 sm:grid-cols-3">
        <div>
            <dt class="text-sm font-medium text-slate-500">{{ __('submission.status.reference') }}</dt>
            <dd class="mt-1 font-mono text-lg font-semibold">{{ $submission->reference ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-slate-500">{{ __('submission.status.state') }}</dt>
            <dd class="mt-1">
                <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-800">
                    {{ $submission->status->getLabel() }}
                </span>
            </dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-slate-500">{{ __('submission.status.deadline') }}</dt>
            <dd class="mt-1 font-medium">
                {{ $conference->deadlineInConferenceTimezone()?->format('j F Y, H:i') }} ({{ $conference->timezone }})
            </dd>
        </div>
    </dl>

    @if ($submission->status === App\Enums\SubmissionStatus::Draft)
        <div class="mt-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-900">
            {{ __('submission.status.draft_warning') }}
        </div>
    @elseif ($submission->status === App\Enums\SubmissionStatus::Withdrawn)
        <div class="mt-6 rounded-lg border border-red-300 bg-red-50 p-4 text-red-900">
            {{-- `->copy()` before `->setTimezone()`, exactly as
                 Conference::deadlineInConferenceTimezone() does it:
                 Illuminate\Support\Carbon is mutable, so setting the zone on
                 the instance a cast handed out is a write, not a read. --}}
            {{ __('submission.status.withdrawn_notice', [
                'date' => $submission->withdrawn_at?->copy()->setTimezone($conference->timezone)->format('j F Y, H:i'),
            ]) }}
        </div>
    @elseif ($letter)
        {{-- The letter as it was SENT, not a fresh render: an organizer who
             edits the template next March must not rewrite what this author
             was told in September.

             Rendered rather than escaped, and that is safe because of a chain
             that already exists: RenderEmailTemplate escapes every substituted
             value and then every `<` in the finished body before the letter is
             stored, so no tag can be in one. This is the same
             Markdown::parse() call ConferenceEmailTemplates::preview() makes on
             the same text.

             $letterBody is the stored markdown with the withheld status link
             filled in from this reader's own token; the substitution is in
             SubmissionStatus::render() because Blade cannot carry the literal
             placeholder. --}}
        <section class="mt-6 rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-lg font-semibold">{{ __('submission.status.decision.heading') }}</h2>
            <p class="mt-1 text-sm text-slate-500">
                {{ __('submission.status.decision.sent_on', [
                    'date' => $letter->notified_at?->copy()->setTimezone($conference->timezone)->format('j F Y'),
                ]) }}
            </p>
            <div class="mt-4 text-slate-700 [&_p]:mt-3 [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:mt-3 [&_ol]:list-decimal [&_ol]:pl-5 [&_a]:text-[var(--org-primary)] [&_a]:underline [&_strong]:font-semibold">
                {!! Illuminate\Mail\Markdown::parse((string) $letterBody) !!}
            </div>
        </section>
    @elseif ($submission->status->isWithOrganizers())
        <div class="mt-6 rounded-lg border border-slate-300 bg-slate-50 p-4 text-slate-700">
            {{ __('submission.status.decision_pending') }}
        </div>
    @endif

    @error('withdraw') <p class="mt-4 text-sm text-red-600">{{ $message }}</p> @enderror

    @if ($editing)
        <div class="mt-8">
            <button type="button" wire:click="cancelEditing" class="text-sm font-medium text-slate-600 hover:underline">
                {{ __('submission.status.cancel_edit') }}
            </button>
            {{-- A nested Livewire component: same form, same validation, same
                 actions. wire:key is the submission's ULID so the child is not
                 re-mounted on every parent render. --}}
            @livewire('public.submission-form', [
                'organization' => $organization,
                'conference' => $conference,
                'submission' => $submission,
                'token' => $token,
            ], key('form-'.$submission->ulid))
        </div>
    @else
        <section class="mt-8 rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-lg font-semibold">{{ __('submission.sections.authors') }}</h2>
            <ol class="mt-3 space-y-2 text-sm">
                @foreach ($authors as $author)
                    <li class="flex flex-wrap items-baseline gap-2">
                        <span class="font-medium">{{ $author->name }}</span>
                        <span class="text-slate-500">{{ $author->email }}</span>
                        @if ($author->affiliation)<span class="text-slate-500">· {{ $author->affiliation }}</span>@endif
                        @if ($author->is_presenter)<span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ __('submission.authors.is_presenter') }}</span>@endif
                        @if ($author->is_corresponding)<span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ __('submission.authors.is_corresponding') }}</span>@endif
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="mt-6 rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-lg font-semibold">{{ __('submission.fields.abstract') }}</h2>
            <p class="mt-3 whitespace-pre-line text-slate-700">{{ $submission->abstract }}</p>
            <p class="mt-3 text-sm text-slate-500">
                {{ $submission->word_count }} {{ __('submission.fields.words') }}
                @if ($submission->track) · {{ $submission->track->name }} @endif
                @if ($submission->presentation_preference) · {{ $submission->presentation_preference->getLabel() }} @endif
            </p>
        </section>

        @if ($files->isNotEmpty())
            <section class="mt-6 rounded-lg border border-slate-200 bg-white p-6">
                <h2 class="text-lg font-semibold">{{ __('submission.sections.files') }}</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($files as $file)
                        {{-- A fresh signed URL on every render, valid for
                             cass.file_url_minutes. Nothing about it is stored. --}}
                        <li>
                            <a href="{{ $file->temporaryUrl() }}" class="text-[var(--org-primary)] hover:underline">{{ $file->original_name }}</a>
                            <span class="text-slate-400">({{ number_format($file->size / 1024) }} KB)</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($canChange)
            <div class="mt-8 flex flex-wrap items-center gap-3">
                <button type="button" wire:click="startEditing"
                        class="rounded-lg bg-[var(--org-primary)] px-6 py-3 font-semibold text-[var(--org-on-primary)] hover:opacity-90">
                    {{ __('submission.status.edit') }}
                </button>
                <button type="button" wire:click="withdraw"
                        wire:confirm="{{ __('submission.status.confirm_withdraw') }}"
                        class="rounded-lg border border-red-300 px-6 py-3 font-medium text-red-700 hover:bg-red-50">
                    {{ __('submission.status.withdraw') }}
                </button>
            </div>
            <p class="mt-3 text-sm text-slate-500">{{ __('submission.status.until_deadline') }}</p>
        @endif
    @endif

    <p class="mt-10 text-sm text-slate-500">{{ __('submission.status.keep_link') }}</p>
</div>
