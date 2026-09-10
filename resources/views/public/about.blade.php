<x-layouts.public title="About">
    <section class="mx-auto grid max-w-5xl items-start gap-10 px-4 py-12 md:grid-cols-[1fr_320px]">
        <div class="prose prose-slate">
            <h1>About CASS</h1>
            <p>CASS began in 2023 as the abstract system for the Common Pediatric Diseases Symposium in Saudi Arabia. After two editions and several hundred peer reviews it was rebuilt as a platform any conference organizer can use.</p>
            <p>It is built and operated by a team of clinicians and engineers who run scientific meetings themselves. The aim is simple: fewer spreadsheets and email threads for committees, and a clear, fast submission experience for authors.</p>
            <h2>Contact</h2>
            <p>Email <a href="mailto:{{ config('cass.platform_contact_email') }}">{{ config('cass.platform_contact_email') }}</a> or use the <a href="{{ route('contact') }}">contact form</a>.</p>
        </div>
        <img src="{{ asset('images/illustrations/about-lab.png') }}" alt="" class="w-full" loading="lazy">
    </section>
</x-layouts.public>
