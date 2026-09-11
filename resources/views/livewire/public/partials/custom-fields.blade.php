@php use App\Enums\CustomFieldType; @endphp

<section class="rounded-lg border border-slate-200 bg-white p-6 space-y-4">
    <h2 class="text-lg font-semibold">{{ __('submission.sections.custom_fields') }}</h2>

    @foreach ($customFields as $field)
        @php $id = 'custom-'.$field->key; @endphp
        <div wire:key="custom-{{ $field->key }}">
            @if ($field->type === CustomFieldType::Checkbox)
                <label class="flex items-start gap-2 text-sm">
                    <input id="{{ $id }}" type="checkbox" wire:model="custom.{{ $field->key }}" class="mt-0.5 rounded border-slate-300">
                    <span>{{ $field->label }}@if ($field->required)<span class="text-red-600"> *</span>@endif</span>
                </label>
            @else
                <label for="{{ $id }}" class="block text-sm font-medium">
                    {{ $field->label }}@if ($field->required)<span class="text-red-600"> *</span>@endif
                </label>

                @switch($field->type)
                    @case(CustomFieldType::Textarea)
                        <textarea id="{{ $id }}" rows="4" wire:model.blur="custom.{{ $field->key }}"
                                  class="mt-1 w-full rounded-lg border-slate-300"></textarea>
                        @break
                    @case(CustomFieldType::Select)
                        <select id="{{ $id }}" wire:model="custom.{{ $field->key }}" class="mt-1 w-full rounded-lg border-slate-300">
                            <option value="">{{ __('submission.fields.choose') }}</option>
                            @foreach ((array) ($field->options ?? []) as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                        @break
                    @case(CustomFieldType::Number)
                        <input id="{{ $id }}" type="number" step="any" wire:model.blur="custom.{{ $field->key }}"
                               class="mt-1 w-full rounded-lg border-slate-300">
                        @break
                    @default
                        <input id="{{ $id }}" type="text" maxlength="255" wire:model.blur="custom.{{ $field->key }}"
                               class="mt-1 w-full rounded-lg border-slate-300">
                @endswitch
            @endif

            @if ($field->help_text)
                <p class="mt-1 text-sm text-slate-500">{{ $field->help_text }}</p>
            @endif
            @error('custom.'.$field->key) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
    @endforeach
</section>
