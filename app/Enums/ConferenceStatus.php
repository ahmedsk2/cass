<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ConferenceStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
    case Reviewing = 'reviewing';
    case Decided = 'decided';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Open for submissions',
            self::Closed => 'Submissions closed',
            self::Reviewing => 'Under review',
            self::Decided => 'Decisions sent',
            self::Archived => 'Archived',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Open => 'success',
            self::Closed => 'warning',
            self::Reviewing => 'info',
            self::Decided => 'primary',
            self::Archived => 'danger',
        };
    }

    /**
     * The full lifecycle. Plan 2 drives Draft/Closed -> Open, Open -> Closed
     * and anything -> Archived; Plan 4 drives Closed -> Reviewing and Plan 5
     * drives Reviewing -> Decided. The table is written once, here, so the
     * later plans add behaviour rather than re-deciding the graph.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Open, self::Archived],
            self::Open => [self::Closed, self::Archived],
            self::Closed => [self::Open, self::Reviewing, self::Archived],
            self::Reviewing => [self::Decided, self::Closed, self::Archived],
            self::Decided => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /** Visible on `/c/{org}/{conference}` to anyone. */
    public function isPublic(): bool
    {
        return ! in_array($this, [self::Draft, self::Archived], true);
    }

    /** The status gate on new submissions; the date window is checked separately. */
    public function acceptsSubmissions(): bool
    {
        return $this === self::Open;
    }
}
