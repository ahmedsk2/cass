<section class="rounded-lg border border-slate-200 bg-white p-6 space-y-4">
    <h2 class="text-lg font-semibold">{{ __('submission.sections.files') }}</h2>
    <p class="text-sm text-slate-500">
        {{ __('submission.files.limits', [
            'count' => (int) $conference->max_files,
            'types' => strtoupper(implode(', ', (array) $conference->allowed_file_types)),
            'size' => (int) round(((int) config('cass.max_file_bytes')) / 1048576),
        ]) }}
    </p>

    @if ($storedFiles->isNotEmpty())
        <ul class="space-y-2">
            @foreach ($storedFiles as $file)
                <li class="flex items-center justify-between rounded border border-slate-200 px-3 py-2 text-sm" wire:key="file-{{ $file->ulid }}">
                    <span>{{ $file->original_name }} <span class="text-slate-400">({{ number_format($file->size / 1024) }} KB)</span></span>
                    <button type="button" wire:click="deleteFile('{{ $file->ulid }}')"
                            wire:confirm="{{ __('submission.files.confirm_delete') }}"
                            class="text-red-600 hover:underline">{{ __('submission.files.remove') }}</button>
                </li>
            @endforeach
        </ul>
    @endif

    <div>
        <label for="uploads" class="block text-sm font-medium">{{ __('submission.files.label') }}</label>
        {{-- wire:model (not .blur): a file input fires `change`, and the
             upload has to start when the file is chosen, not when the field
             loses focus. --}}
        <input id="uploads" type="file" multiple wire:model="uploads"
               accept="{{ collect((array) $conference->allowed_file_types)->map(fn ($t) => '.'.$t)->implode(',') }}"
               class="mt-1 w-full text-sm">
        <div wire:loading wire:target="uploads" class="mt-1 text-sm text-slate-500">{{ __('submission.files.uploading') }}</div>
        @error('uploads') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        @error('uploads.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    @if ($uploads !== [])
        <ul class="space-y-2">
            @foreach ($uploads as $index => $upload)
                <li class="flex items-center justify-between rounded border border-dashed border-slate-300 px-3 py-2 text-sm" wire:key="upload-{{ $index }}">
                    <span>{{ $upload->getClientOriginalName() }}</span>
                    <button type="button" wire:click="removeUpload({{ $index }})"
                            class="text-red-600 hover:underline">{{ __('submission.files.remove') }}</button>
                </li>
            @endforeach
        </ul>
    @endif
</section>
