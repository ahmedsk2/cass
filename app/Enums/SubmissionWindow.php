<?php

declare(strict_types=1);

namespace App\Enums;

enum SubmissionWindow: string
{
    case NotConfigured = 'not_configured';
    case Upcoming = 'upcoming';
    case Open = 'open';
    case Closed = 'closed';
}
