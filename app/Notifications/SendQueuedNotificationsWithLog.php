<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\EmailLogStatus;
use App\Models\EmailLog;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Str;
use Throwable;

/**
 * The job every queued notification travels in - this application's own and
 * Filament's password-reset and verification mail alike - because
 * AppServiceProvider binds it in place of Laravel's, and
 * NotificationSender::queueNotification() resolves the job from the container.
 *
 * It adds one thing: on the FINAL failure it marks the notification's
 * `email_logs` row failed, the way TemplatedMail::failed() marks a templated
 * one. The worker calls failed() once, after the last try, through
 * CallQueuedHandler::failed(), which has already decrypted and unserialized the
 * job. An intermediate attempt never reaches this method; the worker releases
 * the job instead, and RecordOutgoingEmail reuses the row.
 *
 * The row is found by EmailLog::ulidForNotification(): the notification's own
 * id, one per recipient, set before the job is queued and serialized with it.
 * Guarded on `queued` for the reason TemplatedMail::failed() gives, and on the
 * mail channel because Laravel queues one job per channel with the SAME id - a
 * failed database-channel job must not mark a mail that was delivered or is
 * still being retried.
 *
 * Do not rename or move this class without draining the queue first: a job
 * already in `jobs` or `failed_jobs` names it in its payload.
 */
class SendQueuedNotificationsWithLog extends SendQueuedNotifications
{
    /**
     * @param  Throwable|null  $e
     */
    public function failed($e): void
    {
        parent::failed($e);

        $ulid = EmailLog::ulidForNotification($this->notification->id);

        if ($ulid === null || ! in_array('mail', (array) $this->channels, true)) {
            return;
        }

        EmailLog::query()
            ->where('ulid', $ulid)
            ->where('status', EmailLogStatus::Queued->value)
            ->update([
                'status' => EmailLogStatus::Failed->value,
                'error' => Str::limit((string) $e?->getMessage(), 2000, ''),
                'updated_at' => now(),
            ]);
    }
}
