<?php

declare(strict_types=1);

use App\Actions\Organizations\ClaimCustomDomain;
use App\Actions\Organizations\VerifyCustomDomain;
use App\Contracts\DnsResolver;
use App\Enums\EmailLogStatus;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CustomDomainVerified;
use App\Support\Domains\FakeDnsResolver;

/**
 * No Notification::fake() anywhere in this file, deliberately, and that is the
 * whole point of it existing beside the two files that do.
 *
 * With the fake installed, CustomDomainVerified::toMail() is never built: the
 * four domain.mail.* keys are never resolved, OrganizationResource::getUrl(
 * panel: 'admin') is never called from outside a panel request, and no
 * email_logs row is written - so the one message spec 5.8 is actually about
 * could be broken in every one of those ways with a green suite.
 *
 * phpunit.xml pins MAIL_MAILER=array and QUEUE_CONNECTION=sync, so the queued
 * notification is delivered inside this request and the mail events fire for
 * real, exactly as tests/Feature/Mail/EmailLogPipelineTest.php relies on.
 */
beforeEach(function () {
    config()->set('app.url', 'https://cass.towardpcc.com');
    config()->set('cass.domains.cname_target', 'cass.towardpcc.com');

    $this->dns = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $this->dns);

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->actor = User::factory()->create();
    $this->admin = User::factory()->platformAdmin()->create(['email' => 'platform@example.org']);
});

it('writes a rendered email_logs row for the one letter a verified domain sends', function () {
    $organization = app(ClaimCustomDomain::class)->handle($this->organization, 'abstracts.example.org', $this->actor);
    $this->dns->set('_cass-verify.abstracts.example.org', [(string) $organization->custom_domain_token]);

    app(VerifyCustomDomain::class)->handle($organization, $this->actor);

    $log = EmailLog::query()->where('to_email', 'platform@example.org')->firstOrFail();

    expect($log->mailable)->toBe(CustomDomainVerified::class)
        ->and($log->status)->toBe(EmailLogStatus::Sent)
        // Not a spec 5.9 template key: it is a platform message about a
        // platform chore, not an organizer-editable conference letter.
        ->and($log->template_key)->toBeNull()
        ->and($log->subject)->toBe(__('domain.mail.admin_subject', ['domain' => 'abstracts.example.org']));
});

it('renders the admin letter with the host to add and a link into the admin panel', function () {
    $organization = app(ClaimCustomDomain::class)->handle($this->organization, 'abstracts.example.org', $this->actor);
    $this->dns->set('_cass-verify.abstracts.example.org', [(string) $organization->custom_domain_token]);

    // Built by hand rather than read out of the array transport, because this
    // is the assertion that would catch a missing lang key or a getUrl() that
    // throws outside a panel request - the two ways this letter breaks.
    $mail = (new CustomDomainVerified($organization->fresh(), 'abstracts.example.org'))->toMail($this->admin);
    $rendered = (string) $mail->render();

    expect($rendered)->toContain('abstracts.example.org')
        ->and($rendered)->toContain('Alpha Society')
        ->and($rendered)->toContain(__('domain.mail.admin_action'))
        ->and($rendered)->toContain(OrganizationResource::getUrl('view', ['record' => $organization], panel: 'admin'))
        // A missing key renders as the key itself, which is the failure this
        // whole file exists to make visible.
        ->and($rendered)->not->toContain('domain.mail.');
});
