<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Support\Domains\DomainName;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'name', 'type', 'country', 'website', 'contact_email', 'publish_contact_email', 'purpose',
        'logo_path', 'primary_color', 'accent_color',
    ];

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'status' => OrganizationStatus::class,
            'publish_contact_email' => 'boolean',
            // Never fillable (see the migration): `cass:demo-seed` sets it with
            // forceFill and `cass:demo-reset` refuses every organization that
            // does not carry it.
            'is_demo' => 'boolean',
            'approved_at' => 'datetime',
            'custom_domain_verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            $organization->ulid ??= (string) Str::ulid();
            $organization->slug ??= static::uniqueSlug($organization->name);
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        $n = 2;
        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsToMany<User, $this, OrganizationMember>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members')
            ->using(OrganizationMember::class)
            ->withPivot(['role', 'notify_on_submission'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this, OrganizationMember>
     */
    public function owners(): BelongsToMany
    {
        return $this->members()->withPivotValue('role', OrganizationRole::Owner->value);
    }

    /** @return HasMany<Conference, $this> */
    public function conferences(): HasMany
    {
        return $this->hasMany(Conference::class);
    }

    /** @return HasMany<OrganizationInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * A domain is only a *routing* fact once it is verified. Every reader in
     * this application asks this question and not `custom_domain !== null`,
     * because a claimed-but-unverified domain is somebody halfway through a
     * DNS panel and must serve nothing.
     */
    public function hasVerifiedCustomDomain(): bool
    {
        return $this->custom_domain !== null && $this->custom_domain_verified_at !== null;
    }

    /** The host this organization's public pages are served from, or null for the platform's own. */
    public function customDomainHost(): ?string
    {
        return $this->hasVerifiedCustomDomain() ? (string) $this->custom_domain : null;
    }

    /** The full name the TXT record lives at, or null when no domain is claimed. */
    public function customDomainTxtName(): ?string
    {
        return $this->custom_domain === null ? null : DomainName::txtRecordName((string) $this->custom_domain);
    }

    /**
     * https://{domain} with no trailing slash. Plain concatenation rather than
     * url(): url() builds from APP_URL, which is the one host this method
     * exists to avoid.
     */
    public function customDomainUrl(): ?string
    {
        $host = $this->customDomainHost();

        return $host === null ? null : 'https://'.$host;
    }

    public function addMember(User $user, OrganizationRole $role): void
    {
        $this->members()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
    }

    public function isApproved(): bool
    {
        return $this->status === OrganizationStatus::Approved;
    }

    /**
     * `contact_email` is how the platform reaches the organization, which for
     * an organization that has never edited its profile can be the owner's
     * personal login address. Public pages print it only after the organizer
     * has explicitly asked for that.
     */
    public function publishesContactEmail(): bool
    {
        return $this->publish_contact_email === true && filled($this->contact_email);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'custom_domain', 'custom_domain_verified_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
