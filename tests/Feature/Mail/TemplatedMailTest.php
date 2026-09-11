<?php

declare(strict_types=1);

use App\Actions\Mail\RenderEmailTemplate;
use App\Actions\Mail\SendTemplatedEmail;
use App\Enums\EmailLogStatus;
use App\Enums\EmailTemplateKey;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\Submission;
use App\Support\Tokens\InvitationToken;
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

it('delivers the status link as an anchor an email client can click', function () {
    $organization = Organization::factory()->approved()->create(['name' => 'Gulf Pediatric Society'])->refresh();
    $conference = Conference::factory()->for($organization)->published()->create(['name' => 'GPCC 2026']);
    $statusLink = 'https://cass.towardpcc.com/s/'.str_repeat('a', 64);

    $rendered = app(RenderEmailTemplate::class)->handle(EmailTemplateKey::SubmissionReceived, $conference, [
        'author_name' => 'Sara',
        'title' => 'A title',
        'reference' => 'GPCC26-017',
        'conference' => 'GPCC 2026',
        'organization' => 'Gulf Pediatric Society',
        'deadline' => '3 November 2026',
        'status_link' => $statusLink,
    ]);

    $html = (string) (new TemplatedMail(
        logUlid: (string) Str::ulid(),
        subjectLine: $rendered->subject,
        body: $rendered->body,
        organization: $organization,
        templateKey: EmailTemplateKey::SubmissionReceived->value,
    ))->render();

    // `[^>]*` because CssToInlineStyles writes a style attribute onto every
    // element the cass mail theme styles, this anchor included, and it may land
    // on either side of the href.
    expect($html)->toMatch('#<a[^>]*href="'.preg_quote($statusLink, '#').'"#');
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

    // And not only in the logged copy. The subject travels as a clear-text
    // SMTP header through every relay between here and the author's provider,
    // which is the exposure the redaction exists to stop - redacting the row
    // and delivering the raw value would be redacting the wrong copy.
    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => preg_match('#/s/[A-Za-z0-9]{64}#', $mail->subjectLine) === 0
        && str_contains($mail->subjectLine, '/s/[redacted]')
        // The body still carries the real link: that is the whole email.
        && str_contains($mail->body, str_repeat('a', 64)));
});

it('redacts an invitation token from a subject as well as a status token', function () {
    Mail::fake();

    $conference = Conference::factory()->published()->create();
    $token = InvitationToken::generate();

    // Built the same way as the status-token case above rather than through
    // emailTemplates()->create([...]): EmailTemplate::$fillable is ['subject',
    // 'body'] and there is no `is_active` column on email_templates, so a mass
    // assignment of `key` or `is_active` throws under
    // Model::preventSilentlyDiscardingAttributes().
    $template = new EmailTemplate;
    $template->fill([
        'subject' => 'Review for us: '.route('invitation.accept', ['token' => $token]),
        'body' => 'Body.',
    ]);
    $template->conference()->associate($conference);
    $template->key = EmailTemplateKey::ReviewerInvitation->value;
    $template->save();

    app(SendTemplatedEmail::class)->handle(
        EmailTemplateKey::ReviewerInvitation,
        $conference,
        'reviewer@example.org',
        ['reviewer_name' => 'Dr Omar Khan', 'review_link' => 'https://example.test/invite/'.$token],
    );

    $log = EmailLog::query()->firstOrFail();

    expect($log->subject)->toContain('/invite/[redacted]')
        ->and($log->subject)->not->toContain($token);
});

it('leaves an already-sent log row alone when the job fails afterwards', function () {
    Mail::fake();

    $conference = Conference::factory()->published()->create();
    $log = app(SendTemplatedEmail::class)->handle(
        EmailTemplateKey::SubmissionDraftSaved,
        $conference,
        'author@example.org',
        ['author_name' => 'Sara'],
    );

    // MessageSent already flipped the row: the transport accepted the message.
    $log->forceFill(['status' => EmailLogStatus::Sent, 'sent_at' => now()])->save();

    $mail = new TemplatedMail(
        logUlid: (string) $log->ulid,
        subjectLine: 'x',
        body: 'x',
        organization: $conference->organization,
        templateKey: EmailTemplateKey::SubmissionDraftSaved->value,
    );

    // A job that throws *after* the transport accepted the message still ends
    // up in failed(). Rewriting the row would make the admin panel report a
    // delivered email as failed, and send support chasing a message the author
    // already has. RecordOutgoingEmail::sent() guards the same way.
    $mail->failed(new RuntimeException('the job blew up after the send'));

    expect($log->refresh())
        ->status->toBe(EmailLogStatus::Sent)
        ->and($log->error)->toBeNull();
});

it('does not put the internal organization id in a header of every author email', function () {
    $organization = Organization::factory()->approved()->create();

    $headers = (new TemplatedMail(
        logUlid: (string) Str::ulid(),
        subjectLine: 'x',
        body: 'x',
        organization: $organization,
        templateKey: EmailTemplateKey::SubmissionDraftSaved->value,
    ))->headers();

    // X-CASS-Log is the correlation id the runbook's triage uses and stays.
    // RecordOutgoingEmail only reads the context headers for a message that has
    // no X-CASS-Log, which a templated send always has - so the auto-increment
    // tenant id was read by nothing and travelled to every author anyway.
    expect($headers->text)->toHaveKey('X-CASS-Log')
        ->and($headers->text)->toHaveKey('X-CASS-Template')
        ->and($headers->text)->not->toHaveKey('X-CASS-Organization');
});

it('trims a long subject to the width of the log column instead of failing the insert', function () {
    Mail::fake();

    $conference = Conference::factory()->published()->create();

    // subjectPlaceholders() lets an organizer put {{title}} in a subject, and
    // submissions.title is itself varchar(255), so this is a subject a real
    // organizer can produce. email_logs.subject is varchar(255) and MySQL runs
    // in strict mode (config/database.php), so an untrimmed write throws on the
    // insert and takes the send - and whatever transaction wraps it - with it.
    // SQLite does not enforce the width, which is why this asserts the length
    // rather than merely that the insert succeeded.
    $template = new EmailTemplate;
    $template->fill(['subject' => 'Abstract received: {{title}}', 'body' => 'Thank you.']);
    $template->conference()->associate($conference);
    $template->key = EmailTemplateKey::SubmissionReceived->value;
    $template->save();

    $log = app(SendTemplatedEmail::class)->handle(
        EmailTemplateKey::SubmissionReceived,
        $conference,
        'author@example.org',
        ['title' => str_repeat('a', 255)],
    );

    expect(mb_strlen($log->subject))->toBeLessThanOrEqual(255)
        ->and($log->subject)->toStartWith('Abstract received: aaa')
        ->and(mb_strlen($log->refresh()->subject))->toBeLessThanOrEqual(255);

    // The trim is a property of the log row, not of the message: the recipient
    // still gets the subject the organizer wrote.
    Mail::assertQueued(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->subjectLine === 'Abstract received: '.str_repeat('a', 255),
    );
});
