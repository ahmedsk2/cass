<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PresentationPreference;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Submission;
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
