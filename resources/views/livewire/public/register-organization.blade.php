<div class="mx-auto max-w-2xl px-4 py-10">
    <h1 class="text-3xl font-semibold tracking-tight">{{ __('public.register.heading') }}</h1>
    <p class="mt-2 text-slate-600">{{ __('public.register.lead') }}</p>

    <form wire:submit="register" class="mt-8 space-y-6" novalidate>
        <fieldset class="space-y-4">
            <legend class="text-lg font-medium">{{ __('public.register.account') }}</legend>
            <div>
                {{-- Four labels on this form say exactly what members.invite.*
                     already says. They are those keys, not copies of them: two
                     keys with one sentence are two keys a translation can leave
                     disagreeing. --}}
                <label for="name" class="block text-sm font-medium">{{ __('members.invite.name') }}</label>
                <input id="name" type="text" wire:model="name" class="mt-1 w-full rounded-lg border-slate-300" autocomplete="name" required>
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="email" class="block text-sm font-medium">{{ __('public.register.email') }}</label>
                <input id="email" type="email" wire:model="email" class="mt-1 w-full rounded-lg border-slate-300" autocomplete="email" required>
                @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="password" class="block text-sm font-medium">{{ __('members.invite.password') }}</label>
                    <input id="password" type="password" wire:model="password" class="mt-1 w-full rounded-lg border-slate-300" autocomplete="new-password" required>
                    <p class="mt-1 text-xs text-slate-500">{{ __('members.invite.password_help') }}</p>
                    @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="password_confirmation" class="block text-sm font-medium">{{ __('members.invite.password_confirmation') }}</label>
                    <input id="password_confirmation" type="password" wire:model="password_confirmation" class="mt-1 w-full rounded-lg border-slate-300" autocomplete="new-password" required>
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4">
            <legend class="text-lg font-medium">{{ __('public.register.organization') }}</legend>
            <div>
                <label for="organization_name" class="block text-sm font-medium">{{ __('public.register.organization_name') }}</label>
                <input id="organization_name" type="text" wire:model="organization_name" class="mt-1 w-full rounded-lg border-slate-300" required>
                @error('organization_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="organization_type" class="block text-sm font-medium">{{ __('public.register.type') }}</label>
                    <select id="organization_type" wire:model="organization_type" class="mt-1 w-full rounded-lg border-slate-300">
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}">{{ $type->getLabel() }}</option>
                        @endforeach
                    </select>
                    @error('organization_type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="country" class="block text-sm font-medium">{{ __('public.register.country') }}</label>
                    <select id="country" wire:model="country" class="mt-1 w-full rounded-lg border-slate-300">
                        @foreach ($countries as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('country') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label for="website" class="block text-sm font-medium">{{ __('public.register.website') }} <span class="text-slate-400">{{ __('public.register.optional') }}</span></label>
                <input id="website" type="url" wire:model="website" class="mt-1 w-full rounded-lg border-slate-300" placeholder="{{ __('public.register.website_placeholder') }}">
                @error('website') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="purpose" class="block text-sm font-medium">{{ __('public.register.purpose') }}</label>
                <textarea id="purpose" wire:model="purpose" rows="3" class="mt-1 w-full rounded-lg border-slate-300" placeholder="{{ __('public.register.purpose_placeholder') }}"></textarea>
                @error('purpose') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="hidden" aria-hidden="true">
                <label for="website_confirm">{{ __('submission.fields.honeypot') }}</label>
                <input id="website_confirm" type="text" wire:model="website_confirm" tabindex="-1" autocomplete="off">
            </div>
        </fieldset>

        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" wire:model="terms" class="mt-0.5 rounded border-slate-300">
            {{-- One sentence across two links: one key, two placeholders, both
                 built and escaped here. --}}
            <span>{!! __('public.register.terms', [
                'terms' => '<a href="'.e(route('terms')).'" class="text-brand-600 underline">'.e(__('public.register.terms_link')).'</a>',
                'privacy' => '<a href="'.e(route('privacy')).'" class="text-brand-600 underline">'.e(__('public.register.privacy_link')).'</a>',
            ]) !!}</span>
        </label>
        @error('terms') <p class="-mt-4 text-sm text-red-600">{{ $message }}</p> @enderror

        <button type="submit" class="w-full rounded-lg bg-brand-500 px-4 py-2.5 font-medium text-white hover:bg-brand-600 disabled:opacity-60" wire:loading.attr="disabled">
            <span wire:loading.remove>{{ __('public.register.submit') }}</span>
            <span wire:loading>{{ __('public.register.submitting') }}</span>
        </button>
        <p class="text-center text-sm text-slate-500">{!! __('public.register.sign_in', [
            'link' => '<a href="'.e(route('filament.organizer.auth.login')).'" class="text-brand-600 underline">'.e(__('public.register.sign_in_link')).'</a>',
        ]) !!}</p>
    </form>
</div>
