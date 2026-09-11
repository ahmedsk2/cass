<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmailLogStatus;
use App\Models\EmailLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmailLog> */
class EmailLogFactory extends Factory
{
    protected $model = EmailLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => null,
            'conference_id' => null,
            'submission_id' => null,
            'template_key' => null,
            // The literal name rather than App\Mail\TemplatedMail::class: that
            // class arrives in Task 3, and Larastan level 6 rejects a `::class`
            // on a class that does not exist yet. Same stored value either way;
            // Task 3 swaps this for the constant once the mailable is real.
            'mailable' => 'App\Mail\TemplatedMail',
            'to_email' => fake()->unique()->safeEmail(),
            'subject' => 'A message from CASS',
            'status' => EmailLogStatus::Queued,
            'error' => null,
            'sent_at' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => ['status' => EmailLogStatus::Sent, 'sent_at' => now()]);
    }

    public function failed(string $error = 'Connection could not be established with host "smtp.example.org"'): static
    {
        return $this->state(fn () => ['status' => EmailLogStatus::Failed, 'error' => $error]);
    }
}
