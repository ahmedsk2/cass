<div class="mx-auto max-w-2xl px-4 py-10">
    <h1 class="text-3xl font-semibold tracking-tight">Register your organization</h1>
    <p class="mt-2 text-slate-600">Create an organizer account. The platform team reviews every new organization before its conferences go public, usually within two working days.</p>

    <form wire:submit="register" class="mt-8 space-y-6" novalidate>
        <fieldset class="space-y-4">
            <legend class="text-lg font-medium">Your account</legend>
            <div>
                <label for="name" class="block text-sm font-medium">Full name</label>
                <input id="name" type="text" wire:model="name" class="mt-1 w-full rounded-lg border-slate-300" autocomplete="name" required>
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="email" class="block text-sm font-medium">Email</label>
                <input id="email" type="email" wire:model="email" class="mt-1 w-full rounded-lg border-slate-300" autocomplete="email" required>
                @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="password" class="block text-sm font-medium">Password</label>
                    <input id="password" type="password" wire:model="password" class="mt-1 w-full rounded-lg border-slate-300" autocomplete="new-password" required>
                    <p class="mt-1 text-xs text-slate-500">At least 10 characters with letters and numbers.</p>
                    @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="password_confirmation" class="block text-sm font-medium">Confirm password</label>
                    <input id="password_confirmation" type="password" wire:model="password_confirmation" class="mt-1 w-full rounded-lg border-slate-300" autocomplete="new-password" required>
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4">
            <legend class="text-lg font-medium">Your organization</legend>
            <div>
                <label for="organization_name" class="block text-sm font-medium">Organization name</label>
                <input id="organization_name" type="text" wire:model="organization_name" class="mt-1 w-full rounded-lg border-slate-300" required>
                @error('organization_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="organization_type" class="block text-sm font-medium">Type</label>
                    <select id="organization_type" wire:model="organization_type" class="mt-1 w-full rounded-lg border-slate-300">
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}">{{ $type->getLabel() }}</option>
                        @endforeach
                    </select>
                    @error('organization_type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="country" class="block text-sm font-medium">Country</label>
                    <select id="country" wire:model="country" class="mt-1 w-full rounded-lg border-slate-300">
                        @foreach ($countries as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('country') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label for="website" class="block text-sm font-medium">Website <span class="text-slate-400">(optional)</span></label>
                <input id="website" type="url" wire:model="website" class="mt-1 w-full rounded-lg border-slate-300" placeholder="https://">
                @error('website') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="purpose" class="block text-sm font-medium">What will you use CASS for?</label>
                <textarea id="purpose" wire:model="purpose" rows="3" class="mt-1 w-full rounded-lg border-slate-300" placeholder="e.g. Abstract submission and review for our annual symposium"></textarea>
                @error('purpose') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="hidden" aria-hidden="true">
                <label for="website_confirm">Leave this field empty</label>
                <input id="website_confirm" type="text" wire:model="website_confirm" tabindex="-1" autocomplete="off">
            </div>
        </fieldset>

        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" wire:model="terms" class="mt-0.5 rounded border-slate-300">
            <span>I agree to the <a href="{{ route('terms') }}" class="text-brand-600 underline">terms of use</a> and <a href="{{ route('privacy') }}" class="text-brand-600 underline">privacy policy</a>.</span>
        </label>
        @error('terms') <p class="-mt-4 text-sm text-red-600">{{ $message }}</p> @enderror

        <button type="submit" class="w-full rounded-lg bg-brand-500 px-4 py-2.5 font-medium text-white hover:bg-brand-600 disabled:opacity-60" wire:loading.attr="disabled">
            <span wire:loading.remove>Create account</span>
            <span wire:loading>Creating…</span>
        </button>
        <p class="text-center text-sm text-slate-500">Already registered? <a href="{{ url('/org/login') }}" class="text-brand-600 underline">Sign in</a></p>
    </form>
</div>
