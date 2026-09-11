<section class="rounded-lg border border-slate-200 bg-white p-6">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold">{{ __('submission.sections.authors') }}</h2>
        <button type="button" wire:click="addAuthor"
                class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium hover:bg-slate-50">
            {{ __('submission.authors.add') }}
        </button>
    </div>
    <p class="mt-1 text-sm text-slate-500">{{ __('submission.authors.help') }}</p>

    <ol class="mt-4 space-y-4">
        {{--
            Keyed by index on purpose: removing a row renumbers the array, and a
            key derived from the row's own data would let Livewire's morph reuse
            the wrong input when two authors share an empty email.
        --}}
        @foreach ($authors as $index => $author)
            <li class="rounded-lg border border-slate-200 p-4" wire:key="author-{{ $index }}">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="author-name-{{ $index }}" class="block text-sm font-medium">{{ __('submission.authors.name') }}</label>
                        <input id="author-name-{{ $index }}" type="text" wire:model.blur="authors.{{ $index }}.name"
                               class="mt-1 w-full rounded-lg border-slate-300">
                        @error("authors.{$index}.name") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="author-email-{{ $index }}" class="block text-sm font-medium">{{ __('submission.authors.email') }}</label>
                        <input id="author-email-{{ $index }}" type="email" wire:model.blur="authors.{{ $index }}.email"
                               class="mt-1 w-full rounded-lg border-slate-300">
                        @error("authors.{$index}.email") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="author-affiliation-{{ $index }}" class="block text-sm font-medium">{{ __('submission.authors.affiliation') }}</label>
                        <input id="author-affiliation-{{ $index }}" type="text" wire:model.blur="authors.{{ $index }}.affiliation"
                               class="mt-1 w-full rounded-lg border-slate-300">
                        @error("authors.{$index}.affiliation") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-4 text-sm">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model="authors.{{ $index }}.is_presenter" class="rounded border-slate-300">
                        {{ __('submission.authors.is_presenter') }}
                    </label>
                    <label class="flex items-center gap-2">
                        {{-- A radio, not a checkbox: exactly one corresponding author. --}}
                        {{-- `?? false`: `authors` is public and unlocked, so a
                             crafted payload can send a row without this key,
                             and an undefined index here is an ErrorException -
                             a 500 on a public page. --}}
                        <input type="radio" name="corresponding" @checked($author['is_corresponding'] ?? false)
                               wire:click="makeCorresponding({{ $index }})" class="border-slate-300">
                        {{ __('submission.authors.is_corresponding') }}
                    </label>
                    @if (count($authors) > 1)
                        <button type="button" wire:click="removeAuthor({{ $index }})"
                                class="ml-auto text-red-600 hover:underline">{{ __('submission.authors.remove') }}</button>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
</section>
