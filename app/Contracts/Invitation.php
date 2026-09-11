<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\InvitationStatus;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Everything `/invite/{token}` and App\Actions\Invitations\AcceptInvitation
 * need of an invitation, and nothing else. Two models implement it -
 * OrganizationInvitation and ReviewerInvitation - and they have separate tables
 * with separate real foreign keys (see the Task 1 preamble). This interface is
 * where they are the same.
 *
 * Every implementer is an Eloquent model, so `grantTo()` and `markAccepted()`
 * are free to write with forceFill().
 */
interface Invitation
{
    public function invitationStatus(): InvitationStatus;

    /** Always lower-cased and trimmed: it is compared with users.email. */
    public function invitedEmail(): string;

    /** The name the inviter typed, when there was one to type. */
    public function invitedName(): ?string;

    public function invitingOrganizationName(): string;

    /** One sentence telling the invitee what they are about to accept. */
    public function invitationHeadline(): string;

    public function invitationExpiresAt(): ?CarbonInterface;

    /**
     * Attach the user. Idempotent, and called inside AcceptInvitation's
     * transaction, so it may assume it runs at most once per accept but must
     * survive being called again.
     */
    public function grantTo(User $user): void;

    /** Stamp acceptance. Separate from grantTo() so the order is explicit. */
    public function markAccepted(User $user): void;

    /** Where the invitee lands once the invitation is accepted. */
    public function landingUrl(): string;
}
