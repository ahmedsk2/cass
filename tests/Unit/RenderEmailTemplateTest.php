<?php

declare(strict_types=1);

use App\Actions\Mail\RenderEmailTemplate;
use App\Enums\EmailTemplateKey;
use App\Models\Conference;
use App\Models\EmailTemplate;
use App\Support\Mail\DefaultTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has a platform default for every key in spec 5.9', function () {
    foreach (EmailTemplateKey::cases() as $key) {
        $default = DefaultTemplates::for($key);

        expect($default->subject)->not->toBe('')
            ->and($default->body)->not->toBe('')
            // A default must not use a placeholder the key does not declare,
            // because the editor's legend and the preview are built from
            // placeholders() and an undeclared one would render literally in a
            // real email.
            ->and(placeholdersIn($default->subject.' '.$default->body))
            ->each->toBeIn($key->placeholders());
    }
});

it('keeps link placeholders out of every subject line', function () {
    foreach (EmailTemplateKey::cases() as $key) {
        // A rendered subject is stored in email_logs.subject, shown in the
        // admin panel and carried in a clear-text SMTP header, so a bearer
        // credential must never be substitutable into one.
        expect($key->subjectPlaceholders())->not->toContain('status_link')
            ->and($key->subjectPlaceholders())->not->toContain('review_link');

        // No platform default uses one either, so this narrows nothing that
        // ships - it constrains what an organizer may type in the editor.
        expect(placeholdersIn(DefaultTemplates::for($key)->subject))
            ->each->toBeIn($key->subjectPlaceholders());
    }
});

it('substitutes declared placeholders in the subject and the body', function () {
    $rendered = app(RenderEmailTemplate::class)->handle(
        EmailTemplateKey::SubmissionReceived,
        null,
        [
            'author_name' => 'Dr Sara Al-Harbi',
            'title' => 'Early mobilisation after cardiac surgery',
            'reference' => 'GPCC26-017',
            'conference' => 'Gulf Pediatric Critical Care 2026',
            'organization' => 'Gulf Pediatric Society',
            'deadline' => '3 November 2026, 23:59 (Asia/Riyadh)',
            'status_link' => 'https://cass.towardpcc.com/s/'.str_repeat('a', 64),
        ],
    );

    expect($rendered->subject)->toContain('GPCC26-017')
        ->and($rendered->body)->toContain('Dr Sara Al-Harbi')
        ->and($rendered->body)->toContain('Early mobilisation after cardiac surgery')
        ->and($rendered->body)->toContain('https://cass.towardpcc.com/s/')
        ->and($rendered->body)->not->toContain('{{');
});

it('leaves an unknown placeholder literal instead of blanking it', function () {
    $conference = Conference::factory()->create();
    EmailTemplate::factory()->for($conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Hello {{author_name}}',
        // A typo an organizer will make. Blanking it silently produces "Your
        // abstract  is received"; leaving it produces something they can see in
        // the preview and fix.
        'body' => 'Your abstract {{titel}} is reference {{reference}}.',
    ]);

    $rendered = app(RenderEmailTemplate::class)->handle(
        EmailTemplateKey::SubmissionReceived,
        $conference,
        ['author_name' => 'Sara', 'reference' => 'GPCC26-001'],
    );

    expect($rendered->subject)->toBe('Hello Sara')
        ->and($rendered->body)->toBe('Your abstract {{titel}} is reference GPCC26-001.');
});

it('escapes a value so it cannot become markdown or html', function () {
    $conference = Conference::factory()->create();
    EmailTemplate::factory()->for($conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => '{{title}}',
        'body' => 'Title: {{title}} — organizer **bold** and [a real link](https://example.org).',
    ]);

    $rendered = app(RenderEmailTemplate::class)->handle(
        EmailTemplateKey::SubmissionReceived,
        $conference,
        ['title' => '<script>alert(1)</script> [CLICK HERE](https://evil.example)'],
    );

    expect($rendered->body)->not->toContain('<script>')
        ->and($rendered->body)->toContain('&lt;script&gt;')
        // The bracket is escaped, so CommonMark renders literal brackets
        // instead of an anchor pointing at evil.example.
        ->and($rendered->body)->toContain('\[CLICK HERE]')
        // The organizer's own markdown is untouched.
        ->and($rendered->body)->toContain('**bold**')
        ->and($rendered->body)->toContain('[a real link](https://example.org)');
});

it('neutralises raw html the organizer typed while keeping their markdown', function () {
    $conference = Conference::factory()->create();
    EmailTemplate::factory()->for($conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Received',
        'body' => "<script>fetch('https://evil.example')</script>\n\n> A quote\n\n- one\n- two",
    ]);

    $rendered = app(RenderEmailTemplate::class)->handle(EmailTemplateKey::SubmissionReceived, $conference, []);

    expect($rendered->body)->not->toContain('<script>')
        // Only `<` is escaped, so a blockquote and a list still work: an email
        // template that cannot use markdown is not a markdown template.
        ->and($rendered->body)->toContain('> A quote')
        ->and($rendered->body)->toContain('- one');
});

it('strips newlines from a subject so a value cannot inject a header', function () {
    $rendered = app(RenderEmailTemplate::class)->handle(
        EmailTemplateKey::SubmissionDraftSaved,
        null,
        // The injection has to go through a placeholder this key's *subject*
        // actually uses. `submission_draft_saved`'s default subject is
        // 'Your draft abstract for {{conference}} is saved' and has no
        // {{title}}, so feeding the CRLF through `title` would never reach the
        // subject and the test would pass with or without the strip.
        ['conference' => "GPCC\r\nBcc: attacker@evil.example", 'author_name' => 'Sara'],
    );

    expect($rendered->subject)->not->toContain("\n")
        ->and($rendered->subject)->not->toContain("\r")
        // And the value really did land in the subject, so it is
        // renderSubject()'s preg_replace that makes the two assertions above
        // pass rather than an unused placeholder.
        ->and($rendered->subject)->toContain('Bcc:');
});

it('prefers a per-conference override over the platform default', function () {
    $conference = Conference::factory()->create();
    $plain = app(RenderEmailTemplate::class)->handle(EmailTemplateKey::SubmissionReceived, $conference, ['reference' => 'X-1']);

    EmailTemplate::factory()->for($conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Custom subject {{reference}}',
        'body' => 'Custom body',
    ]);

    $overridden = app(RenderEmailTemplate::class)->handle(EmailTemplateKey::SubmissionReceived, $conference, ['reference' => 'X-1']);

    expect($plain->subject)->not->toBe('Custom subject X-1')
        ->and($overridden->subject)->toBe('Custom subject X-1')
        ->and($overridden->body)->toBe('Custom body');
});

it('does not let one conference override reach another', function () {
    $mine = Conference::factory()->create();
    $theirs = Conference::factory()->create();
    EmailTemplate::factory()->for($theirs)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Their subject',
        'body' => 'Their body',
    ]);

    $rendered = app(RenderEmailTemplate::class)->handle(EmailTemplateKey::SubmissionReceived, $mine, []);

    expect($rendered->subject)->not->toBe('Their subject')
        ->and($rendered->body)->not->toBe('Their body');
});

/** @return list<string> */
function placeholdersIn(string $text): array
{
    preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $matches);

    return array_values(array_unique($matches[1]));
}
