<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Spec 5.8: "the platform admin is notified to add the host to the Coolify
 * resource so Traefik issues the certificate (documented runbook)."
 *
 * Queued like every other notification in this application (fact 20). Sent to
 * every is_platform_admin user, the shape RegisterOrganization already uses.
 */
class CustomDomainVerified extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Organization $organization, public string $domain) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('domain.mail.admin_subject', ['domain' => $this->domain]))
            ->line(__('domain.mail.admin_line_one', [
                'organization' => $this->organization->name,
                'domain' => $this->domain,
            ]))
            ->line(__('domain.mail.admin_line_two'))
            ->action(__('domain.mail.admin_action'), OrganizationResource::getUrl(
                'view', ['record' => $this->organization], panel: 'admin'
            ));
    }
}
