<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Decision;
use App\Enums\PresentationPreference;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\SubmissionDecision;
use App\Models\User;
use App\Support\Text\WordCounter;
use App\Support\Tokens\SubmissionToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Submission> */
class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $abstract = fake()->paragraphs(3, true);

        return [
            'conference_id' => Conference::factory()->published(),
            'track_id' => null,
            'title' => ucfirst(fake()->words(6, true)),
            'abstract' => $abstract,
            // Factories run inside Model::unguarded(), so the guarded columns
            // above can be set here; they still throw through fill().
            'word_count' => WordCounter::count($abstract),
            'presentation_preference' => PresentationPreference::Oral,
            'contact_phone' => '+966500000000',
            'custom_field_values' => null,
            'status' => SubmissionStatus::Draft,
            'access_token_hash' => SubmissionToken::hash(SubmissionToken::generate()),
            'submitted_at' => null,
            'withdrawn_at' => null,
            'last_edited_at' => now(),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubmissionStatus::Submitted,
            'submitted_at' => now(),
            'reference' => strtoupper(fake()->bothify('????##')).'-'.fake()->numerify('###'),
        ]);
    }

    public function withdrawn(): static
    {
        return $this->submitted()->state(fn () => [
            'status' => SubmissionStatus::Withdrawn,
            'withdrawn_at' => now(),
        ]);
    }

    /**
     * A submission that already carries denormalised scores, for the ranking
     * and export tests that do not care how the numbers got there. Nothing in
     * production writes these columns except ComputeSubmissionScore; a factory
     * runs inside Model::unguarded(), so it may.
     */
    public function scored(float $score = 72.5, ?float $spread = 4.0, int $reviews = 2): static
    {
        return $this->submitted()->state(fn (): array => [
            'score' => $score,
            'score_spread' => $spread,
            'review_count' => $reviews,
            'scored_at' => now(),
        ]);
    }

    /**
     * A decided submission, with the matching status AND the history row the
     * denormalised columns are a view of. `notified` also stamps
     * decision_notified_at, which is what SendDecisionEmails skips on and what
     * gates the letter on /s/{token}.
     *
     * The afterCreating() half is not decoration. Writing only the columns
     * produces a fixture that looks like a hand-written UPDATE, and
     * SendOneDecisionEmail::blockers() (Task 7) refuses exactly that with
     * `no_history` - so without the row every case in DecisionEmailsTest would
     * skip both of its fixtures and report `sent` 0.
     */
    public function decided(Decision $decision = Decision::AcceptedOral, bool $notified = false): static
    {
        return $this->submitted()
            ->state(fn (): array => [
                'decision' => $decision,
                'status' => $decision->submissionStatus(),
                'decision_notified_at' => $notified ? now() : null,
            ])
            ->afterCreating(function (Submission $submission) use ($decision, $notified): void {
                $row = new SubmissionDecision;
                $row->forceFill([
                    'submission_id' => $submission->getKey(),
                    'decision' => $decision,
                    'decided_by' => User::factory()->create()->getKey(),
                    'decided_at' => now(),
                    'note' => null,
                    'letter_subject' => $notified ? 'A decision on your abstract' : null,
                    'letter_markdown' => $notified ? 'Dear author, a decision has been made.' : null,
                    'notified_at' => $notified ? now() : null,
                ])->save();
            });
    }

    /**
     * The shape every flow test needs: one corresponding, presenting author.
     * `afterCreating` rather than a state, because the author is a second row.
     */
    public function withCorrespondingAuthor(string $email = 'author@example.org', string $name = 'Dr Sara Al-Harbi'): static
    {
        return $this->afterCreating(function (Submission $submission) use ($email, $name): void {
            SubmissionAuthorFactory::new()
                ->for($submission)
                ->corresponding()
                ->create(['name' => $name, 'email' => $email, 'sort' => 1]);
        });
    }
}
