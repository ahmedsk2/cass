@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('cass.platform_name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex flex-col">
    <header class="border-b border-slate-200 bg-white">
        <nav class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
            <a href="{{ route('landing') }}" class="flex items-center gap-2">
                <img src="{{ asset('brand/cass-bird.png') }}" alt="" class="h-9 w-auto">
                <span class="text-xl font-semibold tracking-tight text-brand-700">CASS</span>
            </a>
            <div class="flex items-center gap-4 text-sm font-medium">
                <a href="{{ route('about') }}" class="text-slate-600 hover:text-brand-600">About</a>
                <a href="{{ route('contact') }}" class="text-slate-600 hover:text-brand-600">Contact</a>
                <a href="{{ url('/org/login') }}" class="text-slate-600 hover:text-brand-600">Organizer login</a>
                <a href="{{ route('register') }}" class="rounded-lg bg-brand-500 px-3 py-1.5 text-white hover:bg-brand-600">Register organization</a>
            </div>
        </nav>
    </header>

    <main class="flex-1">
        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-6 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
            <p>&copy; {{ date('Y') }} CASS · Conference Abstract Submission System</p>
            <p class="flex gap-4">
                <a href="{{ route('privacy') }}" class="hover:text-brand-600">Privacy</a>
                <a href="{{ route('terms') }}" class="hover:text-brand-600">Terms</a>
                <a href="mailto:{{ config('cass.platform_contact_email') }}" class="hover:text-brand-600">{{ config('cass.platform_contact_email') }}</a>
            </p>
        </div>
    </footer>
</body>
</html>
