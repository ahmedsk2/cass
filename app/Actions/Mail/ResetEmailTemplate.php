<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Enums\EmailTemplateKey;
use App\Models\Conference;

/**
 * "Reset to default" is a delete: with no override row, RenderEmailTemplate
 * falls back to DefaultTemplates, which is the platform default in the
 * recipient's language. Storing a copy of the default instead would freeze
 * today's English wording into the database for ever.
 */
class ResetEmailTemplate
{
    public function handle(Conference $conference, EmailTemplateKey $key): void
    {
        $conference->emailTemplates()->where('key', $key->value)->delete();
    }
}
