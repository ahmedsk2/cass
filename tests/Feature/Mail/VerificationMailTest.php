<?php

declare(strict_types=1);

use App\Enums\EmailLogStatus;
use App\Enums\OrganizationRole;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\QueuedVerifyEmail;

use function Pest\Laravel\actingAs;

// RegisterOrganizationTest fakes notifications, so it proves that this email
// is SENT and has never once rendered it. These two do, through the real
// mailer (MAIL_MAILER=array, QUEUE_CONNECTION=sync in phpunit.xml).

it('delivers the registration verification email and logs it as sent', function () {
    $user = User::factory()->unverified()->create(['email' => 'sara@example.org']);

    // What RegisterOrganization::handle() calls for every new owner.
    $user->sendEmailVerificationNotification();

    expect(EmailLog::query()->sole())
        ->mailable->toBe(QueuedVerifyEmail::class)
        ->to_email->toBe('sara@example.org')
        ->status->toBe(EmailLogStatus::Sent);
});

it('links the verification email to the organizer panel, which accepts it', function () {
    // The registration shape: an unverified owner of a pending organization.
    // User::canAccessPanel('organizer') asks for a membership, nothing more.
    $user = User::factory()->unverified()->create();
    Organization::factory()->create()->addMember($user, OrganizationRole::Owner);

    $url = (string) (new QueuedVerifyEmail)->toMail($user)->actionUrl;

    expect($url)->toContain('/org/email-verification/verify/');

    actingAs($user)->get($url)->assertRedirect();

    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});
