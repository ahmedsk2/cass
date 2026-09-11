<?php

declare(strict_types=1);

use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;

/**
 * Spec section 12's one browser test. It is not a second copy of
 * tests/Feature/Public/SubmissionFormTest.php - that file already proves every
 * rule. This proves the parts no Livewire component test can reach: that the
 * CTA on the conference page really navigates, that the built JavaScript loads
 * and Livewire boots, and that a browser ends up on a page showing a reference
 * number. File attachments are deliberately not part of it - see the note in
 * the task preamble.
 */
beforeEach(function () {
    // Pinned in phpunit.browser.xml as well; set here so the test does not
    // depend on a config file it does not own. The application runs *in this
    // process* (fact 5), so a config change here reaches the server that
    // answers the browser.
    config()->set('cass.submission_min_seconds', 0);

    $this->organization = Organization::factory()->approved()->create([
        'name' => 'Gulf Pediatric Society',
        'primary_color' => '#0F4C8A',
    ]);

    $this->conference = Conference::factory()->for($this->organization)->published()->create([
        'name' => 'Gulf Pediatric Critical Care 2026',
        'slug' => 'gpcc26',
        'reference_prefix' => 'GPCC26',
        'word_limit' => 250,
        // Zero, so the files partial - which is wrapped in
        // @if ((int) $conference->max_files > 0) - renders nothing at all and
        // there is no upload input for the browser to be asked to feed.
        'max_files' => 0,
        'allowed_file_types' => ['pdf'],
        'presentation_types' => ['oral', 'poster'],
        'terms' => 'Presenting authors must register for the conference.',
    ]);

    $this->track = Track::factory()->for($this->conference)->create(['name' => 'Neurocritical care']);
});

it('takes an author from the call for abstracts to a reference number', function () {
    $page = visit('/c/'.$this->organization->slug.'/gpcc26')
        ->assertSee('Gulf Pediatric Critical Care 2026')
        ->assertSee('Submit abstract')
        // Plan 2 shipped this as href="#"; Plan 3 Task 7 pointed it here. A
        // click is the only test that proves the link actually goes somewhere.
        ->click('Submit abstract')
        ->assertPathEndsWith('/gpcc26/submit')
        // The track the organizer configured reached the form. The plan asked
        // for assertSee('Neurocritical care') here, which cannot work: the
        // track exists only as an <option> inside a closed <select>, an
        // <option> has no layout box, and assertSee() requires Playwright's
        // isVisible() (MakesElementAssertions::assertSee, v5.0.1). Selecting it
        // proves the same thing and proves more - that the value round-trips.
        ->select('track', (string) $this->track->id)
        ->assertValue('track', (string) $this->track->id);

    $page
        ->type('title', 'Early mobilisation after paediatric cardiac surgery')
        ->type('abstract', 'Background. We studied early mobilisation. Methods. A prospective cohort of 120 children. Results. Ventilator-free days increased. Conclusion. Early mobilisation is feasible and safe.')
        ->radio('presentation_preference', 'oral')
        ->type('author-name-0', 'Dr Sara Al-Harbi')
        ->type('author-email-0', 'sara@example.org')
        ->type('author-affiliation-0', 'King Fahad Specialist Hospital')
        ->type('contact_phone', '+966500000000')
        ->check('agreed')
        ->press('Submit abstract')
        // The redirect to /s/{token} happens after the action returns, so wait
        // for the thing that can only exist on the other side of it.
        ->waitForText('GPCC26-001')
        ->assertPathBeginsWith('/s/')
        ->assertSee('Early mobilisation after paediatric cardiac surgery')
        ->assertSee('Dr Sara Al-Harbi')
        ->assertSee(SubmissionStatus::Submitted->getLabel());

    $submission = Submission::query()->firstOrFail();

    expect($submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($submission->reference)->toBe('GPCC26-001')
        // 23 whitespace-separated tokens, every one containing a letter or a
        // digit - the same count App\Support\Text\WordCounter produces from the
        // abstract alone ("Ventilator-free" is one word), and the same one the
        // live counter in resources/js/word-count.js shows.
        ->and($submission->word_count)->toBe(23)
        ->and($submission->authors)->toHaveCount(1);
})->group('browser');
