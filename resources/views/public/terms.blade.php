<x-layouts.public :title="__('public.terms.title')">
    <section class="mx-auto max-w-3xl px-4 py-12 prose prose-slate">
        <h1>{{ __('public.terms.heading') }}</h1>
        <p>{{ __('public.terms.updated') }}</p>
        <h2>{{ __('public.terms.organizers_heading') }}</h2>
        <p>{{ __('public.terms.organizers_body') }}</p>
        <h2>{{ __('public.terms.authors_heading') }}</h2>
        <p>{{ __('public.terms.authors_body') }}</p>
        <h2>{{ __('public.terms.reviewers_heading') }}</h2>
        <p>{{ __('public.terms.reviewers_body') }}</p>
        <h2>{{ __('public.terms.availability_heading') }}</h2>
        <p>{{ __('public.terms.availability_body') }}</p>
        <h2>{{ __('public.nav.contact') }}</h2>
        <p><a href="mailto:{{ config('cass.platform_contact_email') }}">{{ config('cass.platform_contact_email') }}</a></p>
    </section>
</x-layouts.public>
