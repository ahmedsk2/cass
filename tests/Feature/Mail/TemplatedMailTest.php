<?php

declare(strict_types=1);

use App\Actions\Mail\SendTemplatedEmail;
use App\Enums\EmailLogStatus;
use App\Enums\EmailTemplateKey;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\Submission;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

it('queues a branded mailable and writes a queued log row', function () {
    Mail::fake();

    $organization = Organization::factory()->approved()->create(['name' => 'Gulf Pediatric Society']);
    $conference = Conference::factory()->for($organization)->published()->create(['name' => 'GPCC 2026']);
    $submission = Submission::factory()->for($conference)->submitted()->create();

    $log = app(SendTemplatedEmail::class)->handle(
        EmailTemplateKey::SubmissionReceived,
        $conference,
        'author@example.org',
        ['author_name' => 'Sara', 'reference' => 'GPCC26-017', 'title' => 'A title', 'conference' => 'GPCC 2026'],
        $submission,
    );

    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('author@example.org')
        && $mail->logUlid === $log->ulid
        && $mail->templateKey === EmailTemplateKey::SubmissionReceived->value);

    expect($log->refresh())
        ->status->toBe(EmailLogStatus::Queued)
        ->organization_id->toBe($organization->id)
        ->conference_id->toBe($conference->id)
        ->submission_id->toBe($submission->id)
        ->template_key->toBe('submission_received')
        ->to_email->toBe('author@example.org')
        ->and($log->subject)->toContain('GPCC26-017');
});

it('renders the organization logo, colour and the body into the branded layout', function () {
    // refresh(): `accent_color` and `primary_color` are DB-level defaults
    // (2026_09_10_000200_create_organizations_table), so a freshly created
    // model carries null for whichever of them the factory did not set and
    // OrganizationTheme::for() would fail on it. In production this mailable
    // always receives an organization that came back out of the database -
    // SerializesModels reloads it inside the queue worker - so reloading here
    // is what makes the fixture match the real object.
    $organization = Organization::factory()->approved()->create([
        'name' => 'Gulf Pediatric Society',
        'primary_color' => '#0F4C8A',
        'logo_path' => 'logos/gps.png',
    ])->refresh();
    $conference = Conference::factory()->for($organization)->published()->create();

    $html = (string) (new TemplatedMail(
        logUlid: (string) Str::ulid(),
        subjectLine: 'We have your abstract',
        body: 'Dear **Sara**, your reference is GPCC26-017.',
        organization: $organization,
        templateKey: EmailTemplateKey::SubmissionReceived->value,
    ))->render();

    expect($html)->toContain('Gulf Pediatric Society')
        ->and($html)->toContain('#0F4C8A')
        ->and($html)->toContain('logos/gps.png')
        // The markdown is parsed, so the body arrives as real emphasis rather
        // than as literal asterisks. Asserted as an opening tag plus the closing
        // one rather than as a bare `<strong>Sara</strong>`: the cass mail theme
        // styles `body *`, so CssToInlineStyles writes a style attribute onto
        // every element it emits, this one included.
        ->and($html)->toContain('<strong')
        ->and($html)->toContain('>Sara</strong>')
        ->and($html)->not->toContain('**Sara**');
});

it('escapes a quote in the organization name instead of injecting an attribute', function () {
    // refresh() for the same reason as the test above: the brand colours are
    // database defaults, not model defaults.
    $organization = Organization::factory()->approved()->create([
        'name' => 'Gulf " onerror="alert(1)',
        'logo_path' => 'logos/gps.png',
    ])->refresh();

    $html = (string) (new TemplatedMail(
        logUlid: (string) Str::ulid(),
        subjectLine: 'x',
        body: 'x',
        organization: $organization,
        templateKey: EmailTemplateKey::SubmissionReceived->value,
    ))->render();

    // Secured markdown encoding replaces only [ < and >, so the quote has to be
    // escaped by e() in the view or it closes alt="..." in the raw <img> block.
    expect($html)->not->toContain('onerror="alert(1)"');
});

it('marks its log row failed when the queued job fails', function () {
    Mail::fake();

    $conference = Conference::factory()->published()->create();
    $log = app(SendTemplatedEmail::class)->handle(
        EmailTemplateKey::SubmissionDraftSaved,
        $conference,
        'author@example.org',
        ['author_name' => 'Sara'],
    );

    $mail = new TemplatedMail(
        logUlid: (string) $log->ulid,
        subjectLine: 'x',
        body: 'x',
        organization: $conference->organization,
        templateKey: EmailTemplateKey::SubmissionDraftSaved->value,
    );

    // What SendQueuedMailable::failed() does (fact 8).
    $mail->failed(new RuntimeException('Connection could not be established with host "smtp.example.org"'));

    expect($log->refresh())
        ->status->toBe(EmailLogStatus::Failed)
        ->and($log->error)->toContain('smtp.example.org');
});

it('never writes a second log row for one templated send', function () {
    // MAIL_MAILER=array and QUEUE_CONNECTION=sync in phpunit.xml, so this runs
    // the real mailer: MessageSending fires, sees the X-CASS-Log header that
    // SendTemplatedEmail already minted, and must not create a duplicate.
    $conference = Conference::factory()->published()->create();

    $log = app(SendTemplatedEmail::class)->handle(
        EmailTemplateKey::SubmissionDraftSaved,
        $conference,
        'author@example.org',
        ['author_name' => 'Sara'],
    );

    expect(EmailLog::query()->count())->toBe(1)
        ->and($log->refresh()->status)->toBe(EmailLogStatus::Sent)
        ->and($log->sent_at)->not->toBeNull();
});

it('never stores an author token in a log subject, whatever the template said', function () {
    Mail::fake();

    $conference = Conference::factory()->published()->create();

    // A row written straight into the table, because the editor's own rule
    // (EmailTemplateKey::subjectPlaceholders()) is what stops an organizer
    // typing this - and email_logs is listed in the admin panel and never
    // pruned, so the write path needs its own guard too.
    $template = new EmailTemplate;
    $template->fill([
        'subject' => 'Your abstract {{status_link}}',
        'body' => 'Open {{status_link}} to edit it.',
    ]);
    $template->conference()->associate($conference);
    $template->key = EmailTemplateKey::SubmissionDraftSaved->value;
    $template->save();

    $log = app(SendTemplatedEmail::class)->handle(
        EmailTemplateKey::SubmissionDraftSaved,
        $conference,
        'author@example.org',
        ['author_name' => 'Sara', 'status_link' => 'https://cass.towardpcc.com/s/'.str_repeat('a', 64)],
    );

    expect($log->subject)->not->toMatch('#/s/[A-Za-z0-9]{64}#')
        ->and($log->subject)->toContain('/s/[redacted]');
});
