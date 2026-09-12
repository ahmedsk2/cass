<x-layouts.public>
    <section class="bg-white">
        <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-16 md:grid-cols-2 md:py-24">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-brand-600">{{ __('public.landing.eyebrow') }}</p>
                <h1 class="mt-3 text-4xl font-semibold tracking-tight md:text-5xl">{{ __('public.landing.title') }}</h1>
                <p class="mt-4 text-lg text-slate-600">{{ __('public.landing.lead') }}</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('register') }}" class="rounded-lg bg-brand-500 px-5 py-3 font-medium text-white hover:bg-brand-600">{{ __('public.nav.register') }}</a>
                    <a href="{{ route('filament.organizer.auth.login') }}" class="rounded-lg border border-slate-300 px-5 py-3 font-medium text-slate-700 hover:border-brand-500 hover:text-brand-600">{{ __('public.nav.login') }}</a>
                </div>
                <p class="mt-4 text-sm text-slate-500">{{ __('public.landing.reviewers') }}</p>
            </div>
            <div class="flex justify-center">
                {{-- WebP first, the committed PNG as the fallback. The hero is
                     the single biggest byte on this page; the PNG stays because
                     the poster template and mail clients that cannot take WebP
                     still resolve it. --}}
                <picture>
                    <source srcset="{{ asset('images/illustrations/hero-researcher.webp') }}" type="image/webp">
                    <img src="{{ asset('images/illustrations/hero-researcher.png') }}" alt="{{ __('public.landing.hero_alt') }}" class="w-full max-w-md" width="1600" height="1051" loading="eager">
                </picture>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-16">
        <h2 class="text-2xl font-semibold tracking-tight">{{ __('public.landing.features.heading') }}</h2>
        <div class="mt-8 grid gap-6 md:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-6">
                <img src="{{ asset('images/icons/presentation.svg') }}" alt="" class="h-10 w-10" width="40" height="40" aria-hidden="true">
                <h3 class="mt-4 font-semibold">{{ __('public.landing.features.submission_title') }}</h3>
                <p class="mt-2 text-sm text-slate-600">{{ __('public.landing.features.submission_body') }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-6">
                <img src="{{ asset('images/icons/team.svg') }}" alt="" class="h-10 w-10" width="40" height="40" aria-hidden="true">
                <h3 class="mt-4 font-semibold">{{ __('public.landing.features.review_title') }}</h3>
                <p class="mt-2 text-sm text-slate-600">{{ __('public.landing.features.review_body') }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-6">
                <img src="{{ asset('images/icons/podium.svg') }}" alt="" class="h-10 w-10" width="40" height="40" aria-hidden="true">
                <h3 class="mt-4 font-semibold">{{ __('public.landing.features.decisions_title') }}</h3>
                <p class="mt-2 text-sm text-slate-600">{{ __('public.landing.features.decisions_body') }}</p>
            </div>
        </div>
    </section>

    <section class="bg-white">
        <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-16 md:grid-cols-2">
            <div class="order-2 md:order-1 flex justify-center">
                <img src="{{ asset('images/illustrations/review-lab.png') }}" alt="" class="w-full max-w-sm" width="1600" height="1068" loading="lazy">
            </div>
            <div class="order-1 md:order-2">
                <h2 class="text-2xl font-semibold tracking-tight">{{ __('public.landing.steps.heading') }}</h2>
                <ol class="mt-6 space-y-4">
                    <li class="flex gap-4"><span class="font-mono text-sm text-brand-600">01</span><div><p class="font-medium">{{ __('public.landing.steps.register_title') }}</p><p class="text-sm text-slate-600">{{ __('public.landing.steps.register_body') }}</p></div></li>
                    <li class="flex gap-4"><span class="font-mono text-sm text-brand-600">02</span><div><p class="font-medium">{{ __('public.landing.steps.create_title') }}</p><p class="text-sm text-slate-600">{{ __('public.landing.steps.create_body') }}</p></div></li>
                    <li class="flex gap-4"><span class="font-mono text-sm text-brand-600">03</span><div><p class="font-medium">{{ __('public.landing.steps.share_title') }}</p><p class="text-sm text-slate-600">{{ __('public.landing.steps.share_body') }}</p></div></li>
                    <li class="flex gap-4"><span class="font-mono text-sm text-brand-600">04</span><div><p class="font-medium">{{ __('public.landing.steps.decide_title') }}</p><p class="text-sm text-slate-600">{{ __('public.landing.steps.decide_body') }}</p></div></li>
                </ol>
            </div>
        </div>
    </section>
</x-layouts.public>
