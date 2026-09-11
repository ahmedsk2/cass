<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Enums\EmailTemplateKey;
use App\Models\Conference;
use App\Models\EmailTemplate;
use InvalidArgumentException;

class SaveEmailTemplate
{
    public function handle(Conference $conference, EmailTemplateKey $key, string $subject, string $body): EmailTemplate
    {
        if (! $key->isConferenceScoped()) {
            // The page hides the action for these two, so reaching here means a
            // hand-made Livewire call. Fail rather than store a row nothing
            // will ever read.
            throw new InvalidArgumentException("[{$key->value}] is a platform-wide template and cannot be overridden per conference.");
        }

        // Not firstOrNew(['key' => ...]): HasOneOrMany::firstOrNew() builds the
        // new row with newInstance($attributes), which runs fill() - and `key`
        // is not fillable (it identifies the row), so with
        // Model::preventSilentlyDiscardingAttributes() on outside production
        // that is a MassAssignmentException on the very first override rather
        // than a saved row.
        $template = $conference->emailTemplates()->where('key', $key->value)->first() ?? new EmailTemplate;

        $template->fill(['subject' => $subject, 'body' => $body]);
        $template->conference()->associate($conference);
        $template->key = $key->value;
        $template->save();

        return $template;
    }
}
