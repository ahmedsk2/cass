@props(['organization', 'conference', 'theme', 'noindex' => false])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $conference->name }} · {{ $organization->name }}</title>
    <meta name="description" content="{{ Str::limit((string) $conference->short_description, 155) }}">
    @if ($noindex)
        <meta name="robots" content="noindex, nofollow">
    @endif
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex flex-col" style="{{ $theme->cssVariables() }}">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4">
            <div class="flex items-center gap-3">
                @if ($organization->logo_path)
                    <img src="{{ Storage::disk('branding')->url($organization->logo_path) }}"
                         alt="{{ $organization->name }}" class="h-10 w-auto">
                @endif
                <span class="text-lg font-semibold tracking-tight">{{ $organization->name }}</span>
            </div>
            {{-- contact_email is how the platform reaches the organization, so
                 it is published here only when the organizer has opted in
                 (Organization::publishesContactEmail()). Everyone else reaches
                 them through the CASS contact form in the footer. --}}
            @if ($organization->publishesContactEmail())
                <a href="mailto:{{ $organization->contact_email }}"
                   class="text-sm font-medium text-[var(--org-primary)] hover:underline">Contact the organizers</a>
            @endif
        </div>
    </header>

    <main class="flex-1">
        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl flex-col gap-2 px-4 py-6 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
            <p>{{ $organization->name }} · powered by <a href="{{ route('landing') }}" class="hover:underline">CASS</a></p>
            <p class="flex gap-4">
                <a href="{{ route('contact') }}" class="hover:underline">Contact</a>
                <a href="{{ route('privacy') }}" class="hover:underline">Privacy</a>
                <a href="{{ route('terms') }}" class="hover:underline">Terms</a>
            </p>
        </div>
    </footer>
</body>
</html>
