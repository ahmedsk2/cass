<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReviewMode: string implements HasLabel
{
    case OpenPool = 'open_pool';
    case Assigned = 'assigned';

    public function getLabel(): string
    {
        return match ($this) {
            self::OpenPool => 'Open pool - every reviewer sees every abstract',
            self::Assigned => 'Assigned - each abstract goes to named reviewers',
        };
    }

    public function needsReviewersPerSubmission(): bool
    {
        return $this === self::Assigned;
    }
}
