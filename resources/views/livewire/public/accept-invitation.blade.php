@php
    use App\Enums\InvitationStatus;
@endphp

<div class="mx-auto max-w-xl px-4 py-12">
    <h1 class="text-3xl font-semibold tracking-tight">{{ __('members.invite.title') }}</h1>
    <p class="mt-3 text-slate-700">{{ $invitation->invitationHeadline() }}</p>
    <p class="mt-1 text-sm text-slate-500">
        {{ __('members.invite.addressed_to', ['email' => $invitation->invitedEmail()]) }}
    </p>

    @error('token')
        <p class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $message }}</p>
    @enderror

    @if ($status === InvitationStatus::Expired)
        <p class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ __('members.invite.expired') }}</p>
    @elseif ($status === InvitationStatus::Revoked)
        <p class="mt-6 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">{{ __('members.invite.revoked') }}</p>
    @elseif ($status === InvitationStatus::Accepted)
        <p class="mt-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-900">{{ __('members.invite.already_accepted') }}</p>
    @elseif ($signedInUser && ! $matchesSignedIn)
        <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p>{{ __('members.invite.wrong_account', ['email' => $signedInUser->email]) }}</p>
            <button type="button" wire:click="signOut" class="mt-3 rounded-lg bg-brand-500 px-3 py-1.5 font-medium text-white hover:bg-brand-600">
                {{ __('members.invite.sign_out') }}
            </button>
        </div>
    @elseif ($signedInUser)
        <form wire:submit="accept" class="mt-8">
            <p class="text-sm text-slate-600">{{ __('members.invite.signed_in_as', ['email' => $signedInUser->email]) }}</p>
            <button type="submit" class="mt-4 rounded-lg bg-brand-500 px-4 py-2 font-medium text-white hover:bg-brand-600">
                {{ $isReviewer ? __('reviewer.invite.accept') : __('members.invite.accept') }}
            </button>
        </form>
    @elseif ($accountExists)
        <form wire:submit="signIn" class="mt-8 space-y-4" novalidate>
            <h2 class="text-lg font-medium">{{ __('members.invite.sign_in') }}</h2>
            <p class="text-sm text-slate-600">{{ __('members.invite.sign_in_help') }}</p>
            <div>
                <label for="password" class="block text-sm font-medium">{{ __('members.invite.password') }}</label>
                <input id="password" type="password" wire:model="password" autocomplete="current-password"
                       class="mt-1 w-full rounded-lg border-slate-300" required>
                @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2 font-medium text-white hover:bg-brand-600">
                {{ __('members.invite.sign_in_and_accept') }}
            </button>
        </form>
    @else
        <form wire:submit="createAccount" class="mt-8 space-y-4" novalidate>
            <h2 class="text-lg font-medium">{{ __('members.invite.create_account') }}</h2>
            <p class="text-sm text-slate-600">{{ __('members.invite.create_account_help') }}</p>
            <div>
                <label for="name" class="block text-sm font-medium">{{ __('members.invite.name') }}</label>
                <input id="name" type="text" wire:model="name" autocomplete="name"
                       class="mt-1 w-full rounded-lg border-slate-300" required>
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="password" class="block text-sm font-medium">{{ __('members.invite.password') }}</label>
                    <input id="password" type="password" wire:model="password" autocomplete="new-password"
                           class="mt-1 w-full rounded-lg border-slate-300" required>
                    <p class="mt-1 text-xs text-slate-500">{{ __('members.invite.password_help') }}</p>
                    @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="password_confirmation" class="block text-sm font-medium">{{ __('members.invite.password_confirmation') }}</label>
                    <input id="password_confirmation" type="password" wire:model="password_confirmation"
                           autocomplete="new-password" class="mt-1 w-full rounded-lg border-slate-300" required>
                </div>
            </div>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2 font-medium text-white hover:bg-brand-600">
                {{ __('members.invite.create_account_and_accept') }}
            </button>
        </form>
    @endif

    @if ($invitation->invitationExpiresAt() && $status === InvitationStatus::Pending)
        <p class="mt-6 text-xs text-slate-500">
            {{ __('members.invite.expires_on', ['date' => $invitation->invitationExpiresAt()->format('j F Y')]) }}
        </p>
    @endif
</div>
