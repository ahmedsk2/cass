<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body {
            margin: 0;
            font-family: 'IBM Plex Sans', 'DejaVu Sans', sans-serif;
            color: #111827;
            text-align: center;
        }
        .sheet { padding: 48px 40px; }
        .rule { height: 10px; background: {{ $theme->primary }}; }
        .logo { max-height: {{ $size === App\Enums\PosterSize::A3 ? 110 : 78 }}px; margin-bottom: 18px; }
        .organization { font-size: {{ $scale['body'] }}pt; letter-spacing: 1px; text-transform: uppercase; color: #6b7280; }
        .title { font-size: {{ $scale['title'] }}pt; font-weight: bold; line-height: 1.15; margin: 14px 0 8px; }
        .lead { font-size: {{ $scale['lead'] }}pt; color: #374151; margin: 0 0 22px; }
        .cta {
            display: inline-block;
            background: {{ $theme->primary }};
            color: {{ $theme->onPrimary }};
            font-size: {{ $scale['lead'] }}pt;
            font-weight: bold;
            padding: 12px 28px;
            border-radius: 8px;
        }
        .qr { width: {{ $scale['qr'] }}px; height: {{ $scale['qr'] }}px; margin: 26px auto 10px; }
        /* Spec 13 sets codes in IBM Plex Mono, and this is the one code a
           reader types by hand. PosterFonts registers the Mono face as `bold`
           to match the weight below: dompdf does not fall back to another
           weight inside a named family, it falls through to the next family. */
        .short-url {
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-size: {{ $scale['lead'] }}pt;
            font-weight: bold;
            letter-spacing: 1px;
        }
        .deadline { font-size: {{ $scale['body'] }}pt; color: #374151; margin-top: 18px; }
        .deadline strong { color: {{ $theme->accent }}; }
        .meta { font-size: {{ $scale['body'] }}pt; color: #6b7280; margin-top: 8px; }
        .footer { font-size: {{ max(8, $scale['body'] - 2) }}pt; color: #9ca3af; margin-top: 26px; }
    </style>
</head>
<body>
    <div class="rule"></div>
    <div class="sheet">
        @if ($logoDataUri)
            <img class="logo" src="{{ $logoDataUri }}" alt="">
        @endif

        <div class="organization">{{ $organization->name }}</div>
        <h1 class="title">{{ $conference->name }}</h1>

        @if ($conference->short_description)
            <p class="lead">{{ $conference->short_description }}</p>
        @endif

        <p><span class="cta">Submit your abstract</span></p>

        <img class="qr" src="{{ $qrDataUri }}" alt="">
        <div class="short-url">{{ $shortUrl }}</div>

        @if ($conference->submission_deadline)
            <p class="deadline">
                Deadline
                <strong>{{ $conference->deadlineInConferenceTimezone()?->format('j F Y, H:i') }}</strong>
                ({{ $conference->timezone }})
            </p>
        @endif

        @if ($conference->starts_at || $conference->venue || $conference->city)
            <p class="meta">
                {{ collect([
                    $conference->starts_at?->format('j M Y'),
                    $conference->venue,
                    $conference->city,
                ])->filter()->implode(' · ') }}
            </p>
        @endif

        <p class="footer">Scan the code or type the address above.</p>
    </div>
</body>
</html>
