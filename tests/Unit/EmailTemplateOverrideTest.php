<?php

declare(strict_types=1);

use App\Actions\Mail\ResetEmailTemplate;
use App\Actions\Mail\SaveEmailTemplate;
use App\Enums\EmailTemplateKey;
use App\Models\Conference;
use App\Models\EmailTemplate;
use App\Support\Mail\DefaultTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('updates the existing override instead of inserting a second one', function () {
    $conference = Conference::factory()->published()->create();
    $save = app(SaveEmailTemplate::class);

    $first = $save->handle($conference, EmailTemplateKey::SubmissionReceived, 'First subject', 'First body');

    // The second save is the branch the page exercises every time an organizer
    // edits wording they already customised, and the one the unique
    // (conference_id, key) index would refuse if it inserted rather than
    // updated.
    $second = $save->handle($conference, EmailTemplateKey::SubmissionReceived, 'Second subject', 'Second body');

    expect(EmailTemplate::query()->count())->toBe(1)
        ->and($second->getKey())->toBe($first->getKey())
        ->and($second->refresh()->subject)->toBe('Second subject')
        ->and($second->body)->toBe('Second body');
});

it('refuses to store a conference override for a platform-wide key', function () {
    $conference = Conference::factory()->published()->create();

    // The page hides the action for these two, so reaching here is a hand-made
    // Livewire call; the row would be written and then read by nothing.
    expect(fn () => app(SaveEmailTemplate::class)
        ->handle($conference, EmailTemplateKey::OrganizationApproved, 'Subject', 'Body'))
        ->toThrow(InvalidArgumentException::class);

    expect(EmailTemplate::query()->count())->toBe(0);
});

it('resets only this conference\'s override of this key', function () {
    $conference = Conference::factory()->published()->create();
    $elsewhere = Conference::factory()->published()->create();
    $save = app(SaveEmailTemplate::class);

    $save->handle($conference, EmailTemplateKey::SubmissionReceived, 'Mine', 'Mine');
    $save->handle($conference, EmailTemplateKey::SubmissionDraftSaved, 'Also mine', 'Also mine');
    $save->handle($elsewhere, EmailTemplateKey::SubmissionReceived, 'Theirs', 'Theirs');

    app(ResetEmailTemplate::class)->handle($conference, EmailTemplateKey::SubmissionReceived);

    expect(EmailTemplate::query()->count())->toBe(2)
        ->and($conference->emailTemplates()->pluck('key')->all())
        ->toBe([EmailTemplateKey::SubmissionDraftSaved->value]);
});

it('fails loudly when a default body is missing, not only a subject', function () {
    // Laravel answers a missing key with the key itself, so a renamed or
    // deleted `body` entry would ship an email whose whole content is the
    // string "mail.templates.submission_received.body". The subject was
    // guarded; the body, which is the entire message, was not.
    app('translator')->setLoaded(['*' => ['mail' => ['en' => [
        'templates' => [EmailTemplateKey::SubmissionReceived->value => ['subject' => 'A real subject']],
    ]]]]);

    expect(fn () => DefaultTemplates::for(EmailTemplateKey::SubmissionReceived))
        ->toThrow(RuntimeException::class, 'lang/en/mail.php');
});
