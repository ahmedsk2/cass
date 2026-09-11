<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Invitation;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Exceptions\InvitationNotAcceptable;
use App\Filament\Organizer\Pages\Dashboard;
use Carbon\CarbonInterface;
use Database\Factories\OrganizationInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrganizationInvitation extends Model implements Invitation
{
    /** @use HasFactory<OrganizationInvitationFactory> */
    use HasFactory;

    /**
     * Nothing is fillable. `role` and `token_hash` in particular are a
     * privilege escalation with a form field attached; every column is written
     * by InviteMember or AcceptInvitation with forceFill().
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrganizationInvitation $invitation): void {
            $invitation->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function invitationStatus(): InvitationStatus
    {
        return match (true) {
            // Revoked wins: an organizer who withdrew an invitation must see
            // that answer whatever the clock or an earlier accept says.
            $this->revoked_at !== null => InvitationStatus::Revoked,
            $this->accepted_at !== null => InvitationStatus::Accepted,
            // `expires_at` is NOT NULL in the migration and every writer sets
            // it, so there is no null branch to guard: Larastan reads the cast
            // and rejects a comparison that can only ever be true.
            $this->expires_at->isPast() => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    public function invitedEmail(): string
    {
        return mb_strtolower(trim((string) $this->email));
    }

    public function invitedName(): ?string
    {
        return null;
    }

    /**
     * Null-guarded because Organization soft-deletes while its invitations
     * survive, and the public `/invite/{token}` view prints
     * `invitationHeadline()` before it prints the refusal block - so an
     * unguarded walk would 500 the page instead of explaining itself.
     * AcceptInvitation::blockers() refuses the same row outright.
     */
    public function invitingOrganizationName(): string
    {
        // `->`, not `?->`: `??` evaluates its left side in isset mode, which
        // already tolerates a null relation anywhere in the chain - and a
        // nullsafe fetch on the left of it is what PHPStan's nullsafe.neverNull
        // rule refuses, here and at the three twins of this line.
        return (string) ($this->organization->name ?? __('members.invite.unknown_organization'));
    }

    public function invitationHeadline(): string
    {
        return __('members.invite.headline', [
            'organization' => $this->invitingOrganizationName(),
            'role' => $this->role->getLabel(),
        ]);
    }

    public function invitationExpiresAt(): ?CarbonInterface
    {
        return $this->expires_at;
    }

    public function grantTo(User $user): void
    {
        $organization = $this->organization;

        if ($organization === null) {
            // Soft-deleted since the link was sent. AcceptInvitation::blockers()
            // refuses before this is reached; this is the belt to that brace for
            // a hand-made call, and a refusal rather than a silent no-op so the
            // invitation is not stamped accepted against nothing.
            throw InvitationNotAcceptable::because(__('members.invite.blocked.organization_gone'));
        }

        // A sitting member keeps the role they already have. addMember() is
        // syncWithoutDetaching([$id => ['role' => ...]]), and sync() UPDATES the
        // pivot of an id it already holds
        // (Illuminate\Database\Eloquent\Relations\Concerns\InteractsWithPivotTable
        // ::attachNew, :250-253) whenever attributes are given - so without this
        // guard an invitation would be a way around ChangeMemberRole's rules:
        // the last-owner invariant, the nobody-changes-their-own-role rule and
        // the owner-grants-owner rule all live there, and a stale link would
        // silently rewrite a sitting admin's row. Changing a role is
        // ChangeMemberRole's job, with a policy in front of it.
        if ($user->roleIn($organization) !== null) {
            return;
        }

        $organization->addMember($user, $this->role);
    }

    /**
     * The audit entry lives here rather than in AcceptInvitation for two
     * reasons: the description differs per invitation kind (spec section 9 asks
     * for an audit of reviewer removals and organization changes, which are
     * different events), and `activity()->performedOn()` wants a Model - which
     * `$this` is and the `Invitation` interface is not, so putting it in the
     * action would need an intersection type on every signature.
     */
    public function markAccepted(User $user): void
    {
        $this->forceFill(['accepted_at' => now(), 'accepted_by' => $user->getKey()])->save();

        activity()
            ->performedOn($this)
            ->causedBy($user)
            ->withProperties(['organization_id' => $this->organization_id, 'role' => $this->role->value])
            ->log('organization.invitation_accepted');
    }

    public function landingUrl(): string
    {
        $organization = $this->organization;

        // Only reachable after a successful accept, which grantTo() above
        // refuses without an organization - but this is a *public* page and a
        // panel URL builder that is handed null throws, so answer the panel root
        // rather than 500 on the way to it.
        return $organization === null
            ? url('/org')
            : Dashboard::getUrl(panel: 'organizer', tenant: $organization);
    }
}
