<?php

declare(strict_types=1);

namespace App\Support\Invitations;

use App\Contracts\Invitation;
use App\Models\OrganizationInvitation;
use App\Models\ReviewerInvitation;
use App\Support\Tokens\InvitationToken;

/**
 * One plaintext token, two tables, one answer.
 *
 * Order is fixed - organization invitations first - so a collision across the
 * two unique indexes has defined behaviour rather than lucky behaviour. With
 * 256 bits of entropy per token that is a 2^-256 event; stating the order costs
 * one sentence and removes the question.
 *
 * This returns rows in every state, not only pending ones: `/invite/{token}`
 * explains an expired or withdrawn invitation rather than 404ing at someone who
 * has already proved they hold the emailed secret. `AcceptInvitation::blockers()`
 * is what refuses.
 */
class InvitationLookup
{
    public function find(string $plainToken): ?Invitation
    {
        if ($plainToken === '') {
            return null;
        }

        $hash = InvitationToken::hash($plainToken);

        return OrganizationInvitation::query()->where('token_hash', $hash)->first()
            ?? ReviewerInvitation::query()->where('token_hash', $hash)->first();
    }
}
