<x-layouts.public>
    <section class="bg-white">
        <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-16 md:grid-cols-2 md:py-24">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-brand-600">For conference organizers</p>
                <h1 class="mt-3 text-4xl font-semibold tracking-tight md:text-5xl">Conference Abstract Submission System</h1>
                <p class="mt-4 text-lg text-slate-600">Collect abstracts, run peer review, and announce decisions from one place. Every conference gets its own submission page, short link and QR code you can print on a poster.</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('register') }}" class="rounded-lg bg-brand-500 px-5 py-3 font-medium text-white hover:bg-brand-600">Register organization</a>
                    <a href="{{ url('/org/login') }}" class="rounded-lg border border-slate-300 px-5 py-3 font-medium text-slate-700 hover:border-brand-500 hover:text-brand-600">Organizer login</a>
                </div>
                <p class="mt-4 text-sm text-slate-500">Reviewers sign in from the invitation link they received by email.</p>
            </div>
            <div class="flex justify-center">
                <img src="{{ asset('images/illustrations/hero-researcher.png') }}" alt="Researcher reviewing an abstract on screen" class="w-full max-w-md" width="1600" height="1051" loading="eager">
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-16">
        <h2 class="text-2xl font-semibold tracking-tight">Everything a scientific committee needs</h2>
        <div class="mt-8 grid gap-6 md:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-6">
                <img src="{{ asset('images/icons/presentation.svg') }}" alt="" class="h-10 w-10" aria-hidden="true">
                <h3 class="mt-4 font-semibold">Submission page with QR code</h3>
                <p class="mt-2 text-sm text-slate-600">Publish a call for abstracts with a word limit, file upload and your own fields. Download the QR code and short link for posters and emails.</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-6">
                <img src="{{ asset('images/icons/team.svg') }}" alt="" class="h-10 w-10" aria-hidden="true">
                <h3 class="mt-4 font-semibold">Peer review that fits your committee</h3>
                <p class="mt-2 text-sm text-slate-600">Invite reviewers by email. Let every reviewer score every abstract, or assign a balanced set to each. Blind review and automatic deadline reminders included.</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-6">
                <img src="{{ asset('images/icons/podium.svg') }}" alt="" class="h-10 w-10" aria-hidden="true">
                <h3 class="mt-4 font-semibold">Scores, ranking, decisions</h3>
                <p class="mt-2 text-sm text-slate-600">Weighted scoring, a ranked table with export, and accept, poster, waitlist or reject decisions sent with templated emails.</p>
            </div>
        </div>
    </section>

    <section class="bg-white">
        <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-16 md:grid-cols-2">
            <div class="order-2 md:order-1 flex justify-center">
                <img src="{{ asset('images/illustrations/review-lab.png') }}" alt="" class="w-full max-w-sm" loading="lazy">
            </div>
            <div class="order-1 md:order-2">
                <h2 class="text-2xl font-semibold tracking-tight">How it works</h2>
                <ol class="mt-6 space-y-4">
                    <li class="flex gap-4"><span class="font-mono text-sm text-brand-600">01</span><div><p class="font-medium">Register your organization</p><p class="text-sm text-slate-600">We approve new organizations within two working days.</p></div></li>
                    <li class="flex gap-4"><span class="font-mono text-sm text-brand-600">02</span><div><p class="font-medium">Create a conference</p><p class="text-sm text-slate-600">Set deadlines, the review form and your branding.</p></div></li>
                    <li class="flex gap-4"><span class="font-mono text-sm text-brand-600">03</span><div><p class="font-medium">Share the QR code</p><p class="text-sm text-slate-600">Authors submit from any device. You see submissions arrive.</p></div></li>
                    <li class="flex gap-4"><span class="font-mono text-sm text-brand-600">04</span><div><p class="font-medium">Review and decide</p><p class="text-sm text-slate-600">Reviewers score, you rank and notify authors in bulk.</p></div></li>
                </ol>
            </div>
        </div>
    </section>
</x-layouts.public>
