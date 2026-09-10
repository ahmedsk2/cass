<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

class OrganizationMember extends Pivot
{
    public $incrementing = true;

    protected $table = 'organization_members';

    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'notify_on_submission' => 'boolean',
        ];
    }
}
