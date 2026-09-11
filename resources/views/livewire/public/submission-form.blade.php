@php use App\Enums\SubmissionWindow; @endphp

<div class="mx-auto max-w-3xl px-4 py-10">
    @if ($isPreview)
        <div class="mb-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <span class="font-semibold">{{ __('submission.preview.badge') }}</span>
            {{ __('submission.preview.body', ['organization' => $organization->name]) }}
        </div>
    @endif

    <p class="text-sm font-semibold uppercase tracking-wide text-[var(--org-accent)]">{{ __('submission.page.eyebrow') }}</p>
    <h1 class="mt-2 text-3xl font-semibold tracking-tight">{{ $conference->name }}</h1>
    <p class="mt-2 text-slate-600">{{ $organization->name }}</p>

    {{--
        The notice and the form are two decisions, not one. $showForm is false
        only for a visitor who has nothing to type into - the window is shut and
        they did not open the page while it was open - so the notice appears
        above a form that is still there for a member previewing an unpublished
        conference and for an author whose deadline passed mid-session. Neither
        can write: isWritable() and windowIsOpen() refuse with a message.
    --}}
    @if ($window !== SubmissionWindow::Open)
        <div class="mt-8 rounded-lg border border-slate-300 bg-white p-6">
            <p class="text-lg font-semibold text-slate-700">
                @switch($window)
                    @case(SubmissionWindow::Upcoming)
                        {{ __('submission.window.upcoming', [
                            'date' => $conference->opensAtInConferenceTimezone()?->format('j F Y, H:i'),
                            'timezone' => $conference->timezone,
                        ]) }}
                        @break
                    @case(SubmissionWindow::Closed)
                        {{ __('submission.window.closed') }}
                        @break
                    @default
                        {{ __('submission.window.not_configured') }}
                @endswitch
            </p>
            @unless ($showForm)
                {{-- Offered only as a dead end. Above a form the author is still
                     typing into, a link away from the page is a trap. --}}
                <a href="{{ route('conference.show', [$organization, $conference]) }}"
                   class="mt-4 inline-block text-sm font-medium text-[var(--org-primary)] hover:underline">
                    {{ __('submission.window.back') }}
                </a>
            @endunless
        </div>
    @endif

    @if ($showForm)
        <form wire:submit="submit" class="mt-8 space-y-8" novalidate>
            {{-- Honeypot. Hidden from sight and from screen readers, out of the
                 tab order, and with autocomplete off so a password manager does
                 not fill it in and lock a real author out. --}}
            <div class="hidden" aria-hidden="true">
                <label for="website_confirm">{{ __('submission.fields.honeypot') }}</label>
                <input id="website_confirm" type="text" wire:model="website_confirm" tabindex="-1" autocomplete="off">
            </div>

            @if ($turnstileSiteKey !== null)
                {{-- wire:ignore: the widget is rendered by Cloudflare's script
                     into this div, and a Livewire morph would replace it with an
                     empty one on the next update. The callback writes the token
                     straight into the component with $wire.set. --}}
                <div wire:ignore>
                    <div class="cf-turnstile"
                         data-sitekey="{{ $turnstileSiteKey }}"
                         data-callback="cassTurnstileCallback"></div>
                    <script>
                        window.cassTurnstileCallback = (token) => window.Livewire.find('{{ $this->getId() }}').set('turnstileToken', token, false);
                        // $this->dispatch('turnstile-reset') reaches
                        // dispatchGlobal(), which is a window CustomEvent. A
                        // refused verification burns the token, and the widget
                        // is inside wire:ignore, so nothing else would mint a
                        // replacement before its own refresh-expired timer.
                        window.addEventListener('turnstile-reset', () => window.turnstile && window.turnstile.reset());
                    </script>
                    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                </div>
                @error('turnstileToken') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            @endif

            <section class="rounded-lg border border-slate-200 bg-white p-6 space-y-4">
                <h2 class="text-lg font-semibold">{{ __('submission.sections.abstract') }}</h2>

                <div>
                    <label for="title" class="block text-sm font-medium">{{ __('submission.fields.title') }}</label>
                    <input id="title" type="text" wire:model.blur="title" maxlength="255"
                           class="mt-1 w-full rounded-lg border-slate-300" required>
                    @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                {{--
                    The live counter is Alpine (Livewire 4 ships and starts it),
                    calling the same function the server uses - resources/js/word-count.js
                    exports window.cassCountWords and App\Support\Text\WordCounter
                    applies the identical two rules. The initial value comes from
                    the server so the number is right before a single key is
                    pressed, and wire:model.blur means typing never round-trips.
                --}}
                <div x-data="{ words: {{ $wordCount }}, limit: {{ (int) $conference->word_limit }} }">
                    <div class="flex items-baseline justify-between">
                        <label for="abstract" class="block text-sm font-medium">{{ __('submission.fields.abstract') }}</label>
                        <span class="text-sm" :class="words > limit ? 'font-semibold text-red-600' : 'text-slate-500'">
                            <span x-text="words"></span> / <span x-text="limit"></span> {{ __('submission.fields.words') }}
                        </span>
                    </div>
                    <textarea id="abstract" rows="14" wire:model.blur="abstract"
                              x-on:input="words = window.cassCountWords($event.target.value)"
                              class="mt-1 w-full rounded-lg border-slate-300 font-sans" required></textarea>
                    @error('abstract') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                @if ($tracks->isNotEmpty())
                    <div>
                        <label for="track" class="block text-sm font-medium">{{ __('submission.fields.track') }}</label>
                        <select id="track" wire:model="track_id" class="mt-1 w-full rounded-lg border-slate-300">
                            <option value="">{{ __('submission.fields.track_none') }}</option>
                            @foreach ($tracks as $track)
                                <option value="{{ $track->id }}">{{ $track->name }}</option>
                            @endforeach
                        </select>
                        @error('track_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endif

                <fieldset>
                    <legend class="block text-sm font-medium">{{ __('submission.fields.presentation_preference') }}</legend>
                    <div class="mt-2 space-y-2">
                        @foreach ($presentationOptions as $value => $label)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" name="presentation_preference" value="{{ $value }}"
                                       wire:model="presentation_preference" class="border-slate-300">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @error('presentation_preference') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </fieldset>
            </section>

            @include('livewire.public.partials.authors')

            <section class="rounded-lg border border-slate-200 bg-white p-6 space-y-4">
                <h2 class="text-lg font-semibold">{{ __('submission.sections.contact') }}</h2>
                <div>
                    <label for="contact_phone" class="block text-sm font-medium">{{ __('submission.fields.contact_phone') }}</label>
                    <input id="contact_phone" type="tel" wire:model.blur="contact_phone" maxlength="40"
                           class="mt-1 w-full rounded-lg border-slate-300">
                    <p class="mt-1 text-sm text-slate-500">{{ __('submission.fields.contact_phone_help') }}</p>
                    @error('contact_phone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </section>

            @if ($customFields->isNotEmpty())
                @include('livewire.public.partials.custom-fields')
            @endif

            @if ((int) $conference->max_files > 0)
                @include('livewire.public.partials.files')
            @endif

            <section class="rounded-lg border border-slate-200 bg-white p-6 space-y-4">
                <h2 class="text-lg font-semibold">{{ __('submission.sections.agreement') }}</h2>
                @if ($conference->terms)
                    <div class="max-h-48 overflow-y-auto rounded border border-slate-200 bg-slate-50 p-3 text-sm whitespace-pre-line text-slate-700">{{ $conference->terms }}</div>
                @endif
                <label class="flex items-start gap-2 text-sm">
                    {{-- `name` as well as `id`: the browser test's check('agreed')
                         resolves a field by id, name, label or placeholder, and a
                         checkbox with neither is addressable only by CSS selector. --}}
                    <input id="agreed" name="agreed" type="checkbox" wire:model="agreed" class="mt-0.5 rounded border-slate-300">
                    <span>{{ __('submission.fields.agreed') }}</span>
                </label>
                @error('agreed') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </section>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" wire:loading.attr="disabled"
                        class="rounded-lg bg-[var(--org-primary)] px-6 py-3 font-semibold text-[var(--org-on-primary)] hover:opacity-90">
                    {{ __('submission.buttons.submit') }}
                </button>
                <button type="button" wire:click="saveDraft" wire:loading.attr="disabled"
                        class="rounded-lg border border-slate-300 px-6 py-3 font-medium text-slate-700 hover:bg-slate-50">
                    {{ __('submission.buttons.save_draft') }}
                </button>
                <span wire:loading class="text-sm text-slate-500">{{ __('submission.buttons.working') }}</span>
            </div>

            <p class="text-sm text-slate-500">{{ __('submission.buttons.draft_help') }}</p>
        </form>
    @endif
</div>
