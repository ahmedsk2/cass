<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Enums\ReviewerStatus;
use App\Notifications\QueuedVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasTenants, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;
    use Notifiable;

    protected $fillable = ['name', 'email', 'password', 'locale', 'timezone'];

    protected $hidden = ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    /**
     * @return BelongsToMany<Organization, $this, OrganizationMember>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_members')
            ->using(OrganizationMember::class)
            ->withPivot(['role', 'notify_on_submission'])
            ->withTimestamps();
    }

    /** @return HasMany<ConferenceReviewer, $this> */
    public function conferenceReviewerships(): HasMany
    {
        return $this->hasMany(ConferenceReviewer::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewer_user_id');
    }

    /** @return HasMany<ReviewAssignment, $this> */
    public function reviewAssignments(): HasMany
    {
        return $this->hasMany(ReviewAssignment::class, 'reviewer_user_id');
    }

    /**
     * The gate on the reviewer panel (wired in Task 5) and on every reviewer
     * query. A removed reviewer is not one.
     */
    public function isActiveReviewer(?Conference $conference = null): bool
    {
        $query = $this->conferenceReviewerships()->where('status', ReviewerStatus::Active->value);

        if ($conference !== null) {
            $query->where('conference_id', $conference->getKey());
        }

        return $query->exists();
    }

    public function roleIn(Organization $organization): ?OrganizationRole
    {
        $member = $this->organizations()->whereKey($organization)->first();

        return $member?->pivot->role;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->is_platform_admin && $this->hasVerifiedEmail(),
            // Verification is enforced by the panel's email-verification middleware,
            // which redirects unverified users to the prompt instead of a bare 403.
            'organizer' => $this->organizations()->exists(),
            default => false,
        };
    }

    /**
     * @return Collection<int, Organization>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->organizations;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->organizations()->whereKey($tenant)->exists();
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail);
    }
}
