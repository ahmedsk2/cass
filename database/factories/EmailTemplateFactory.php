<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmailTemplateKey;
use App\Models\Conference;
use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmailTemplate> */
class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conference_id' => Conference::factory(),
            'key' => EmailTemplateKey::SubmissionReceived->value,
            'subject' => 'We have your abstract, {{author_name}}',
            'body' => "Dear {{author_name}},\n\nYour abstract **{{title}}** is reference {{reference}}.\n",
        ];
    }
}
