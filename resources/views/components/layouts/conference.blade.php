@props(['organization', 'conference', 'theme', 'noindex' => false])
<!DOCTYPE html>
{{-- `dir` beside the `lang` that was already here, from a language key rather
     than a locale check, so lang/ar/public.php is a copy with one word changed
     (spec section 10). --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ __('public.dir') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $conference->name }} · {{ $organization->name }}</title>
    <meta name="description" content="{{ Str::limit((string) $conference->short_description, 155) }}">
    {{-- The canonical URL is the organization's own domain when they have one
         (spec 5.8), so a conference that is reachable on two hosts tells a
         search engine which one is the page. --}}
    <link rel="canonical" href="{{ $conference->publicUrl() }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $conference->publicUrl() }}">
    <meta property="og:title" content="{{ $conference->name }}">
    <meta property="og:description" content="{{ Str::limit((string) $conference->short_description, 155) }}">
    <meta property="og:site_name" content="{{ $organization->name }}">
    @if ($organization->logo_path)
        {{-- The organization's logo, because it is the one image on this page
             that is theirs. The QR poster would be a better share card and is
             a render behind an authenticated route; the backlog keeps it. --}}
        <meta property="og:image" content="{{ Storage::disk('branding')->url($organization->logo_path) }}">
    @endif
    <meta name="twitter:card" content="summary">
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
                   class="text-sm font-medium text-[var(--org-primary)] hover:underline">{{ __('conference.contact_organizers') }}</a>
            @endif
        </div>
    </header>

    <main class="flex-1">
        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl flex-col gap-2 px-4 py-6 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
            {{-- One sentence with a link in the middle, so it is one key with
                 two placeholders rather than three fragments a translation
                 cannot reorder. Both values are escaped here. --}}
            <p>{!! __('public.footer.powered_by', [
                'organization' => e($organization->name),
                'platform' => '<a href="'.e(route('landing')).'" class="hover:underline">'.e(config('cass.platform_name')).'</a>',
            ]) !!}</p>
            <p class="flex gap-4">
                <a href="{{ route('contact') }}" class="hover:underline">{{ __('public.nav.contact') }}</a>
                <a href="{{ route('privacy') }}" class="hover:underline">{{ __('public.footer.privacy') }}</a>
                <a href="{{ route('terms') }}" class="hover:underline">{{ __('public.footer.terms') }}</a>
            </p>
        </div>
    </footer>
</body>
</html>
