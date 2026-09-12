<x-layouts.public :title="__('public.about.title')">
    <section class="mx-auto grid max-w-5xl items-start gap-10 px-4 py-12 md:grid-cols-[1fr_320px]">
        <div class="prose prose-slate">
            <h1>{{ __('public.about.heading') }}</h1>
            <p>{{ __('public.about.history') }}</p>
            <p>{{ __('public.about.team') }}</p>
            <h2>{{ __('public.nav.contact') }}</h2>
            {{-- One sentence split across two links. It is one key with two
                 placeholders, and both links are built and escaped here, so a
                 translation can put them in the order its language reads. --}}
            <p>{!! __('public.about.contact', [
                'email' => '<a href="mailto:'.e(config('cass.platform_contact_email')).'">'.e(config('cass.platform_contact_email')).'</a>',
                'form' => '<a href="'.e(route('contact')).'">'.e(__('public.about.contact_form')).'</a>',
            ]) !!}</p>
        </div>
        <img src="{{ asset('images/illustrations/about-lab.png') }}" alt="" class="w-full" width="1600" height="929" loading="lazy">
    </section>
</x-layouts.public>
