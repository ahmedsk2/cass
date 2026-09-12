@props(['title' => null])
<!DOCTYPE html>
{{-- `dir` beside the `lang` that was already here: without it an `ar` locale
     would render left-to-right and "Arabic is a copy of lang/en" would be false
     in one attribute. The direction is a language key, so no view branches on a
     locale name (spec section 10). --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ __('public.dir') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('cass.platform_name') }}</title>
    <meta name="description" content="{{ __('public.meta.description') }}">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex flex-col">
    <header class="border-b border-slate-200 bg-white">
        <nav class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
            <a href="{{ route('landing') }}" class="flex items-center">
                @include('brand.logo')
            </a>
            <div class="flex items-center gap-4 text-sm font-medium">
                <a href="{{ route('about') }}" class="text-slate-600 hover:text-brand-600">{{ __('public.nav.about') }}</a>
                <a href="{{ route('contact') }}" class="text-slate-600 hover:text-brand-600">{{ __('public.nav.contact') }}</a>
                {{-- The panel owns /org/login, so the route name is the link.
                     A panel whose ->path() changed would otherwise leave a dead
                     link on every public page and nothing would fail. --}}
                <a href="{{ route('filament.organizer.auth.login') }}" class="text-slate-600 hover:text-brand-600">{{ __('public.nav.login') }}</a>
                <a href="{{ route('register') }}" class="rounded-lg bg-brand-500 px-3 py-1.5 text-white hover:bg-brand-600">{{ __('public.nav.register') }}</a>
            </div>
        </nav>
    </header>

    <main class="flex-1">
        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-6 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
            <p>{{ __('public.footer.copyright', ['year' => date('Y'), 'platform' => config('cass.platform_name')]) }}</p>
            <p class="flex gap-4">
                <a href="{{ route('privacy') }}" class="hover:text-brand-600">{{ __('public.footer.privacy') }}</a>
                <a href="{{ route('terms') }}" class="hover:text-brand-600">{{ __('public.footer.terms') }}</a>
                <a href="mailto:{{ config('cass.platform_contact_email') }}" class="hover:text-brand-600">{{ config('cass.platform_contact_email') }}</a>
            </p>
        </div>
    </footer>
</body>
</html>
