<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
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
        'name', 'type', 'country', 'website', 'contact_email', 'purpose',
        'logo_path', 'primary_color', 'accent_color',
    ];

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'status' => OrganizationStatus::class,
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function addMember(User $user, OrganizationRole $role): void
    {
        $this->members()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
    }

    public function isApproved(): bool
    {
        return $this->status === OrganizationStatus::Approved;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'custom_domain', 'custom_domain_verified_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
