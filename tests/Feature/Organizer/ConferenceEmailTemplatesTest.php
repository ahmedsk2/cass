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

it('lists all eleven template keys of spec 5.9', function () {
    $page = templatesPage($this->conference)->assertOk();

    expect(EmailTemplateKey::cases())->toHaveCount(11);

    foreach (EmailTemplateKey::cases() as $key) {
        $page->assertSee($key->getLabel());
    }
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

it('offers neither edit nor reset for the two platform-wide keys', function () {
    foreach ([EmailTemplateKey::OrganizationApproved, EmailTemplateKey::OrganizationRejected] as $key) {
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
