<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Decision;
use App\Models\Submission;
use App\Models\SubmissionDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubmissionDecision> */
class SubmissionDecisionFactory extends Factory
{
    protected $model = SubmissionDecision::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory()->submitted(),
            'decision' => Decision::AcceptedOral,
            'decided_by' => User::factory(),
            'decided_at' => now(),
            'note' => null,
            'letter_subject' => null,
            'letter_markdown' => null,
            'notified_at' => null,
        ];
    }

    /** The state a sent decision is in: a stored letter and a timestamp. */
    public function notified(string $markdown = 'Dear author, your abstract has been **accepted**.'): static
    {
        return $this->state(fn (): array => [
            'letter_subject' => 'A decision on your abstract',
            'letter_markdown' => $markdown,
            'notified_at' => now(),
        ]);
    }
}
