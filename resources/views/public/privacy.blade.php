<x-layouts.public :title="__('public.privacy.title')">
    <section class="mx-auto max-w-3xl px-4 py-12 prose prose-slate">
        <h1>{{ __('public.privacy.heading') }}</h1>
        <p>{{ __('public.privacy.updated') }}</p>
        <h2>{{ __('public.privacy.collect_heading') }}</h2>
        <p>{{ __('public.privacy.collect_body') }}</p>
        <h2>{{ __('public.privacy.use_heading') }}</h2>
        <p>{{ __('public.privacy.use_body') }}</p>
        <h2>{{ __('public.privacy.access_heading') }}</h2>
        <p>{{ __('public.privacy.access_body') }}</p>
        <h2>{{ __('public.privacy.retention_heading') }}</h2>
        {{-- The address is a link inside the sentence, so it is a placeholder
             built and escaped here rather than three fragments. --}}
        <p>{!! __('public.privacy.retention_body', [
            'email' => '<a href="mailto:'.e(config('cass.platform_contact_email')).'">'.e(config('cass.platform_contact_email')).'</a>',
        ]) !!}</p>
        <h2>{{ __('public.privacy.hosting_heading') }}</h2>
        <p>{{ __('public.privacy.hosting_body') }}</p>
    </section>
</x-layouts.public>
