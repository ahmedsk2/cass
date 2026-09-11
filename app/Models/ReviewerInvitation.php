<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Invitation;
use App\Enums\InvitationStatus;
use App\Enums\ReviewerStatus;
use Carbon\CarbonInterface;
use Database\Factories\ReviewerInvitationFactory;
use Filament\FilamentManager;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ReviewerInvitation extends Model implements Invitation
{
    /** @use HasFactory<ReviewerInvitationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ReviewerInvitation $invitation): void {
            $invitation->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function invitationStatus(): InvitationStatus
    {
        return match (true) {
            $this->revoked_at !== null => InvitationStatus::Revoked,
            $this->accepted_at !== null => InvitationStatus::Accepted,
            // See the twin comment on OrganizationInvitation: the column is
            // NOT NULL, so a null guard here is dead code Larastan rejects.
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
        $name = trim((string) $this->name);

        return $name === '' ? null : $name;
    }

    public function invitingOrganizationName(): string
    {
        return (string) $this->conference->organization->name;
    }

    public function invitationHeadline(): string
    {
        return __('reviewer.invite.headline', [
            'organization' => $this->invitingOrganizationName(),
            'conference' => (string) $this->conference->name,
        ]);
    }

    public function invitationExpiresAt(): ?CarbonInterface
    {
        return $this->expires_at;
    }

    public function grantTo(User $user): void
    {
        // Not firstOrNew(): it builds the missing instance with fill(), and
        // every column on ConferenceReviewer is guarded, so the lookup keys
        // themselves would throw MassAssignmentException. The query is the same
        // one; only the "not found" branch differs, and forceFill() below sets
        // both keys anyway.
        $reviewer = ConferenceReviewer::query()
            ->where('conference_id', $this->conference_id)
            ->where('user_id', $user->getKey())
            ->first() ?? new ConferenceReviewer;

        $reviewer->forceFill([
            'conference_id' => $this->conference_id,
            'user_id' => $user->getKey(),
            'status' => ReviewerStatus::Active,
            // Only overwritten when the invitation carried one, so accepting a
            // second, bare invitation does not erase an affiliation the
            // organizer typed the first time.
            'affiliation' => $this->affiliation ?? $reviewer->affiliation,
            'invited_by' => $this->invited_by ?? $reviewer->invited_by,
            'invited_at' => $reviewer->invited_at ?? $this->created_at ?? now(),
            'accepted_at' => $reviewer->accepted_at ?? now(),
            // Reactivation: a reviewer the organizer removed and re-invited is
            // active again, and their old reviews are still theirs.
            'removed_at' => null,
        ])->save();
    }

    /** See the twin comment on OrganizationInvitation::markAccepted(). */
    public function markAccepted(User $user): void
    {
        $this->forceFill(['accepted_at' => now(), 'accepted_by' => $user->getKey()])->save();

        activity()
            ->performedOn($this)
            ->causedBy($user)
            ->withProperties(['conference_id' => $this->conference_id])
            ->log('reviewer.invitation_accepted');
    }

    public function landingUrl(): string
    {
        // isStrict: false, so this answers correctly in a request where the
        // reviewer panel is not registered - which is every request until
        // Task 5 adds ReviewerPanelProvider. Once it is registered the panel's
        // own path is `review`, so the value does not change.
        //
        // Through the manager rather than the Filament facade: the facade's own
        // `@method static Panel getPanel(...)` docblock
        // (vendor/filament/filament/src/Facades/Filament.php:93) drops the
        // `| null` the real signature carries (FilamentManager.php:372), so the
        // nullsafe call that this line exists for reads as dead code to
        // Larastan. FilamentManager::class is aliased to 'filament' in
        // FilamentServiceProvider:69, so this is the same instance.
        $panel = app(FilamentManager::class)->getPanel('reviewer', isStrict: false);

        return $panel?->getUrl() ?? url('/review');
    }
}
