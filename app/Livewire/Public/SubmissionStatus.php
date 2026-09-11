<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use Livewire\Component;

/**
 * PLACEHOLDER. Task 8 of this plan replaces this file wholesale with the real
 * author status page; nothing here is the design.
 *
 * It exists because Task 4 has to register the `/s/{token}` route - Task 5
 * onwards resolves its *name* through Submission::statusUrl(), and an
 * unregistered name is a RouteNotFoundException - while the component itself
 * is Task 8's. A route action naming a class that does not exist is not
 * actually free: Illuminate\Routing\RouteAction::makeInvokable() calls
 * method_exists() on it, which throws "Invalid route action" at registration,
 * and Illuminate\Foundation\Console\RouteListCommand::isVendorRoute() reflects
 * on it, which breaks `php artisan route:list` for every route in the app.
 *
 * Until Task 8 lands there is no status page, and this says so honestly rather
 * than rendering an empty one.
 */
class SubmissionStatus extends Component
{
    public function mount(string $token): void
    {
        abort(404);
    }
}
