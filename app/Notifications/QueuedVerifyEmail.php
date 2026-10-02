<?php

declare(strict_types=1);

namespace App\Notifications;

use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The verification email RegisterOrganization sends every new owner, through
 * User::sendEmailVerificationNotification().
 */
class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    /**
     * Laravel's own verificationUrl() signs a route named `verification.verify`,
     * and this application has never defined one - only the panels' routes
     * exist. So until this method, every one of these emails threw
     * RouteNotFoundException in the worker before it was rendered: no
     * `email_logs` row, three tries, one `failed_jobs` entry, and a new owner
     * whose only way to verify was the panel prompt's "Resend" button.
     *
     * The organizer panel's route, because RegisterOrganization - the only
     * caller - has just made this user an organization owner, and the organizer
     * panel is where an owner signs in. It is the same URL Filament's own resend
     * button builds (EmailVerificationPrompt::sendEmailVerificationNotification()),
     * computed here in the worker so the 60-minute expiry starts when the mail
     * is sent.
     *
     * @param  mixed  $notifiable
     */
    protected function verificationUrl($notifiable): string
    {
        return Filament::getPanel('organizer')->getVerifyEmailUrl($notifiable);
    }
}
