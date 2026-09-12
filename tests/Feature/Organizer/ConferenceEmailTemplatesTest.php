<?php

declare(strict_types=1);

use App\Enums\EmailTemplateKey;
use App\Enums\OrganizationRole;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceEmailTemplates;
use App\Filament\Organizer\Resources\Conferences\Pages\ViewConference;
use App\Models\Conference;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $this->conference = Conference::factory()->for($this->organization)->create(['name' => 'Alpha Annual Meeting']);
});

function templatesPage(Conference $conference): Testable
{
    return livewire(ConferenceEmailTemplates::class, ['record' => $conference->getRouteKey()]);
}

it('lists all twelve template keys of spec 5.9', function () {
    $page = templatesPage($this->conference)->assertOk();

    // Twelve, not the spec's eleven: Plan 6 split the rejection letter in two,
    // because "we could not approve you" is the wrong sentence to send an
    // organization that WAS approved and is now suspended.
    expect(EmailTemplateKey::cases())->toHaveCount(12);

    foreach (EmailTemplateKey::cases() as $key) {
        $page->assertSee($key->getLabel());
    }

    // "Sent when" has to stay true of the branch it ships on. The three
    // reviewer keys carried "Not sent yet" from Plan 3, when they were not -
    // but InviteReviewer and SendReviewerReminders mail all three now, and an
    // organizer who believes the panel leaves that text uncustomised and lets
    // it go to real reviewers.
    $page->assertDontSee('Not sent yet');
});

it('marks each key as a platform default until it is overridden', function () {
    templatesPage($this->conference)
        ->assertSee('Platform default')
        ->assertDontSee('Customised');

    EmailTemplate::factory()->for($this->conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Our own subject',
        'body' => 'Our own body',
    ]);

    templatesPage($this->conference)
        ->assertSee('Customised')
        ->assertSee('Platform default');
});

it('shows the platform default subject in the row', function () {
    templatesPage($this->conference)
        ->assertSee('Abstract {{reference}} received');
});

it('edits a template and stores it against this conference only', function () {
    // callTableAction($name, $record, $data): the second argument is the record
    // *key*, which for an array-backed table is the key the collection is keyed
    // by (fact 10) - here the template key itself.
    templatesPage($this->conference)
        ->callTableAction('edit', EmailTemplateKey::SubmissionReceived->value, [
            'subject' => 'We have your abstract, {{author_name}}',
            'body' => 'Dear {{author_name}}, your reference is {{reference}}.',
        ])
        ->assertHasNoActionErrors();

    $template = EmailTemplate::query()->firstOrFail();

    expect($template->conference_id)->toBe($this->conference->id)
        ->and($template->key)->toBe('submission_received')
        ->and($template->subject)->toBe('We have your abstract, {{author_name}}');
});

it('refuses a subject or body that is empty', function () {
    templatesPage($this->conference)
        ->callTableAction('edit', EmailTemplateKey::SubmissionReceived->value, ['subject' => '', 'body' => ''])
        ->assertHasActionErrors(['subject', 'body']);

    expect(EmailTemplate::query()->count())->toBe(0);
});

it('warns about a placeholder the key does not declare', function () {
    templatesPage($this->conference)
        ->callTableAction('edit', EmailTemplateKey::SubmissionReceived->value, [
            'subject' => 'Hello {{reviewer_name}}',
            'body' => 'Body',
        ])
        ->assertHasActionErrors(['subject']);
});

it('resets a template back to the platform default by deleting the override', function () {
    EmailTemplate::factory()->for($this->conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Our own subject',
        'body' => 'Our own body',
    ]);

    templatesPage($this->conference)
        ->callTableAction('reset', EmailTemplateKey::SubmissionReceived->value)
        ->assertHasNoActionErrors();

    expect(EmailTemplate::query()->count())->toBe(0);

    templatesPage($this->conference)->assertDontSee('Our own subject');
});

it('offers neither edit nor reset for the three platform-wide keys', function () {
    $platformWide = [
        EmailTemplateKey::OrganizationApproved,
        EmailTemplateKey::OrganizationRejected,
        EmailTemplateKey::OrganizationSuspended,
    ];

    foreach ($platformWide as $key) {
        templatesPage($this->conference)
            ->assertTableActionHidden('edit', $key->value)
            ->assertTableActionHidden('reset', $key->value);
    }

    templatesPage($this->conference)
        ->assertTableActionVisible('edit', EmailTemplateKey::SubmissionReceived->value)
        ->assertSee('Platform-wide');
});

it('previews the finished email with sample values', function () {
    EmailTemplate::factory()->for($this->conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Abstract {{reference}}',
        'body' => 'Dear {{author_name}}, **{{title}}** is received.',
    ]);

    // assertMountedActionModalSee(), not assertSee(): Livewire 4 re-renders the
    // modal as the `action-modals` *partial*, and SubsequentRender forwards the
    // previous html when a response carries no `effects.html`, so the component
    // html a plain assertSee() reads is the one from before the action mounted.
    // Filament's own helper reads effects.partials, which is where the modal is.
    templatesPage($this->conference)
        ->mountTableAction('edit', EmailTemplateKey::SubmissionReceived->value)
        // The sample values of EmailTemplateKey::sampleValues(), so the
        // organizer reads a finished sentence instead of braces.
        ->assertMountedActionModalSee('GPCC26-017')
        ->assertMountedActionModalSee('Dr Sara Al-Harbi')
        // The legend of what they may use here.
        ->assertMountedActionModalSee('{{status_link}}');
});

it('keeps raw HTML out of the preview', function () {
    // The preview is injected into the panel as an HtmlString, so the only thing
    // between an organizer's typing and stored XSS in another organizer's
    // browser is RenderEmailTemplate::renderBody()'s `<` escaping. Pin it: the
    // exact bytes must not survive into the modal.
    EmailTemplate::factory()->for($this->conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Abstract {{reference}}',
        'body' => "Dear {{author_name}},\n\n<script>alert(1)</script>\n\n**{{title}}** is received.",
    ]);

    templatesPage($this->conference)
        ->mountTableAction('edit', EmailTemplateKey::SubmissionReceived->value)
        ->assertMountedActionModalDontSeeHtml('<script>alert(1)</script>')
        ->assertMountedActionModalSee('alert(1)');
});

it('previews the text on screen rather than the text that is stored', function () {
    // handle() renders what is *stored*; the preview has to render what the
    // organizer is typing, or it lies about what pressing Save will send.
    EmailTemplate::factory()->for($this->conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Abstract {{reference}}',
        'body' => 'The stored body nobody is looking at.',
    ]);

    templatesPage($this->conference)
        ->mountTableAction('edit', EmailTemplateKey::SubmissionReceived->value)
        ->setTableActionData(['body' => 'Typed {{title}}'])
        // The sample value of {{title}}, substituted into text that exists only
        // in the form state.
        ->assertMountedActionModalSee('Typed Early mobilisation after cardiac surgery')
        ->assertMountedActionModalDontSee('The stored body nobody is looking at.');
});

it('is invisible and unreachable from another organization', function () {
    $theirs = withoutTenant(fn () => Conference::factory()->create());

    // Through the route, not livewire(): Filament's resolveRecord() throws
    // ModelNotFoundException, which Livewire's test harness rethrows instead of
    // rendering (RequestBroker disables exception handling for everything but
    // HttpException and AuthorizationException), so only a real request turns
    // it into the 404 a stranger sees.
    get(ConferenceResource::getUrl('emails', ['record' => $theirs->getRouteKey()], tenant: $this->organization))
        ->assertNotFound();
});

it('lets a plain member edit templates, because spec section 4 says so', function () {
    $member = User::factory()->create();
    $this->organization->addMember($member, OrganizationRole::Member);
    actingAs($member);

    templatesPage($this->conference)
        ->callTableAction('edit', EmailTemplateKey::SubmissionDraftSaved->value, [
            'subject' => 'Member subject',
            'body' => 'Member body',
        ])
        ->assertHasNoActionErrors();

    expect(EmailTemplate::query()->where('key', 'submission_draft_saved')->exists())->toBeTrue();
});

it('is linked from the conference view page', function () {
    livewire(ViewConference::class, [
        'record' => $this->conference->getRouteKey(),
    ])
        ->assertOk()
        ->assertSee(ConferenceResource::getUrl('emails', ['record' => $this->conference]), escape: false);
});

it('refuses a link placeholder in a subject line', function () {
    // {{status_link}} is a legal *body* placeholder for this key and an illegal
    // subject one: a rendered subject is stored in email_logs.subject, listed
    // in the admin panel and carried in a clear-text SMTP header, so it must
    // never be able to hold the author's bearer token.
    templatesPage($this->conference)
        ->callTableAction('edit', EmailTemplateKey::SubmissionDraftSaved->value, [
            'subject' => 'Your abstract {{status_link}}',
            'body' => 'Open {{status_link}} to edit it.',
        ])
        ->assertHasActionErrors(['subject']);

    expect(EmailTemplate::query()->where('key', 'submission_draft_saved')->exists())->toBeFalse();
});

it('warns about a placeholder the key does not declare in the body as well', function () {
    // Only the subject's rejection path was exercised, so deleting the body's
    // own ->rule() left every test in this file green while an undeclared
    // placeholder rendered literally in an author's inbox.
    templatesPage($this->conference)
        ->callTableAction('edit', EmailTemplateKey::SubmissionReceived->value, [
            'subject' => 'We have your abstract, {{author_name}}',
            'body' => 'Dear {{author_name}}, ask {{reviewer_name}} about it.',
        ])
        ->assertHasActionErrors(['body']);

    expect(EmailTemplate::query()->count())->toBe(0);
});

it('authorizes a save over an existing override as an update of that row', function () {
    EmailTemplate::factory()->for($this->conference)->create([
        'key' => EmailTemplateKey::SubmissionReceived->value,
        'subject' => 'Our own subject',
        'body' => 'Our own body',
    ]);

    // Saving over an override authorized `create`, so EmailTemplatePolicy's
    // update() was unreachable and any later tightening of it would silently
    // not apply. Gate::before short-circuits the ability being asked for,
    // whatever the policy would have answered, so this asserts the *question*
    // the page asks.
    Gate::before(
        fn ($user, string $ability): ?bool => $ability === 'update' ? false : null
    );

    templatesPage($this->conference)
        ->callTableAction('edit', EmailTemplateKey::SubmissionReceived->value, [
            'subject' => 'A newer subject',
            'body' => 'A newer body',
        ])
        ->assertForbidden();

    expect(EmailTemplate::query()->firstOrFail()->subject)->toBe('Our own subject');
});

it('still authorizes a first override as a create', function () {
    Gate::before(
        fn ($user, string $ability): ?bool => $ability === 'update' ? false : null
    );

    templatesPage($this->conference)
        ->callTableAction('edit', EmailTemplateKey::SubmissionReceived->value, [
            'subject' => 'A first subject',
            'body' => 'A first body',
        ])
        ->assertHasNoActionErrors();

    expect(EmailTemplate::query()->firstOrFail()->subject)->toBe('A first subject');
});
