<div class="mx-auto max-w-xl px-4 py-10">
    <h1 class="text-3xl font-semibold tracking-tight">Contact</h1>
    <p class="mt-2 text-slate-600">Questions about running your conference on CASS, or about a submission? Write to us.</p>

    @if ($sent)
        <div class="mt-6 rounded-lg border border-green-300 bg-green-50 p-4 text-green-900">Thank you. We will reply to your email address soon.</div>
    @else
        <form wire:submit="send" class="mt-6 space-y-4" novalidate>
            <div>
                <label for="name" class="block text-sm font-medium">Name</label>
                <input id="name" type="text" wire:model="name" class="mt-1 w-full rounded-lg border-slate-300" required>
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="email" class="block text-sm font-medium">Email</label>
                <input id="email" type="email" wire:model="email" class="mt-1 w-full rounded-lg border-slate-300" required>
                @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="message" class="block text-sm font-medium">Message</label>
                <textarea id="message" wire:model="message" rows="5" class="mt-1 w-full rounded-lg border-slate-300" required></textarea>
                @error('message') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="hidden" aria-hidden="true">
                <label for="website_confirm">Leave this field empty</label>
                <input id="website_confirm" type="text" wire:model="website_confirm" tabindex="-1" autocomplete="off">
            </div>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 font-medium text-white hover:bg-brand-600" wire:loading.attr="disabled">Send message</button>
        </form>
    @endif
</div>
