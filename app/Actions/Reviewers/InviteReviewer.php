<?php

declare(strict_types=1);

namespace App\Actions\Reviewers;

use App\Actions\Mail\SendTemplatedEmail;
use App\Enums\EmailTemplateKey;
use App\Enums\ReviewerStatus;
use App\Exceptions\MemberChangeRefused;
use App\Models\Conference;
use App\Models\ReviewerInvitation;
use App\Models\User;
use App\Support\Tokens\InvitationToken;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Spec 5.4 step 1. Invite, re-invite and resend are all this one method, for
 * the same reason InviteMember is: there is no unique index on
 * (conference_id, email) - a withdrawn invitation keeps its row - so the live
 * row is refreshed with a new token rather than joined by a second one.
 */
class InviteReviewer
{
    public function __construct(private readonly SendTemplatedEmail $sendTemplatedEmail) {}

    /** @return list<string> empty when the invitation may be sent */
    public function blockers(Conference $conference, string $email, User $actor): array
    {
        $reasons = [];
        $email = mb_strtolower(trim($email));

        // Same guarded hop as the policies: Organization soft-deletes while its
        // conferences survive, and User::roleIn() takes a non-nullable
        // Organization - so an unguarded walk is a TypeError 500 where a refusal
        // belongs.
        $organization = $conference->organization;

        if ($organization === null || $actor->roleIn($organization) === null) {
            $reasons[] = __('reviewer.errors.not_allowed');
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $reasons[] = __('reviewer.errors.bad_email');
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null && $conference->reviewers()
            ->where('user_id', $existing->getKey())
            ->where('status', ReviewerStatus::Active->value)
            ->exists()) {
            $reasons[] = __('reviewer.errors.already_reviewing', ['email' => $email]);
        }

        return array_values(array_unique($reasons));
    }

    public function handle(
        Conference $conference,
        string $email,
        ?string $name,
        ?string $affiliation,
        User $actor,
    ): ReviewerInvitation {
        $reasons = $this->blockers($conference, $email, $actor);

        if ($reasons !== []) {
            throw new MemberChangeRefused($reasons);
        }

        $email = mb_strtolower(trim($email));
        $name = $name === null || trim($name) === '' ? null : trim($name);
        $plain = InvitationToken::generate();

        // Meter the actor, not the conference. ReviewerInvitationPolicy::create()
        // admits every organization member down to a plain `member` (spec
        // section 4 gives "Invite reviewers, assign, decide" to all of them),
        // and the pasted list turns one click into a hundred organization-signed
        // emails to addresses of the sender's choosing. Without a limiter that
        // is a spam relay wearing the platform's sending reputation. A hundred a
        // click and 200 an hour is more than any real conference needs and far
        // less than a relay wants.
        //
        // It is consulted HERE, before the row below is touched, and that
        // ordering is load-bearing. This method re-invites by refreshing the
        // live row in place - new `token_hash`, new `expires_at` - so a limiter
        // that threw *after* the forceFill would have already invalidated a
        // link the reviewer was emailed an hour ago, for a message that then
        // never leaves; on a first invitation it would instead leave a phantom
        // "Invited" row nobody ever received a mail for. Refusing first leaves
        // the row byte-for-byte as it was, which is what the send-limit test
        // asserts with its row count and its two $live expectations.
        $key = 'invitation-send:'.$actor->getKey();

        if (RateLimiter::tooManyAttempts($key, (int) config('cass.invitations.send_rate_limit'))) {
            throw new MemberChangeRefused([__('reviewer.errors.send_limit')]);
        }

        RateLimiter::hit($key, 3600);

        $invitation = $conference->reviewerInvitations()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->latest('id')
            ->first() ?? new ReviewerInvitation;

        $invitation->forceFill([
            'conference_id' => $conference->getKey(),
            'email' => $email,
            // Never blanked by a later bare invitation: the organizer typed
            // these once and a pasted list without a name must not erase them.
            'name' => $name ?? $invitation->name,
            'affiliation' => $affiliation ?? $invitation->affiliation,
            'token_hash' => InvitationToken::hash($plain),
            'invited_by' => $actor->getKey(),
            'expires_at' => now()->addDays((int) config('cass.invitations.expiry_days')),
            'accepted_at' => null,
            'accepted_by' => null,
            'revoked_at' => null,
        ])->save();

        $this->sendTemplatedEmail->handle(
            EmailTemplateKey::ReviewerInvitation,
            $conference,
            $email,
            self::placeholderValues(
                $conference,
                $invitation->invitedName() ?? $email,
                route('invitation.accept', ['token' => $plain]),
            ),
        );

        activity()
            ->performedOn($invitation)
            ->causedBy($actor)
            ->withProperties(['email' => $email, 'conference_id' => $conference->getKey()])
            ->log('reviewer.invited');

        return $invitation;
    }

    /**
     * The spec 5.9 placeholder bag for all three reviewer keys, in one place so
     * Task 10's reminders cannot drift from Task 4's invitation - the same
     * pattern as SubmitAbstract::placeholderValues().
     *
     * `{{review_link}}` is whatever the caller passes: the accept URL for an
     * invitation, the reviewer's queue for a reminder (spec 5.4 steps 1 and 5).
     *
     * @return array<string, string|null>
     */
    public static function placeholderValues(Conference $conference, string $reviewerName, string $reviewLink): array
    {
        $deadline = $conference->reviewDeadlineInConferenceTimezone();

        return [
            'reviewer_name' => $reviewerName,
            'conference' => (string) $conference->name,
            'organization' => (string) $conference->organization->name,
            'deadline' => $deadline === null
                ? __('reviewer.mail.no_deadline')
                : $deadline->format('j F Y, H:i').' ('.$conference->timezone.')',
            'review_link' => $reviewLink,
        ];
    }
}
