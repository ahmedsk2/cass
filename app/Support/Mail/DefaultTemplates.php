<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\EmailTemplateKey;

/**
 * The platform defaults, read from lang/en/mail.php (spec section 10: every
 * string in a language file, so Arabic is a new file and not a new branch).
 *
 * One accessor so RenderEmailTemplate never touches __() and so a key with no
 * entry fails here, loudly, instead of producing an email whose subject reads
 * "mail.templates.whatever.subject".
 */
final class DefaultTemplates
{
    public static function for(EmailTemplateKey $key): RenderedTemplate
    {
        $subject = __('mail.templates.'.$key->value.'.subject');
        $body = __('mail.templates.'.$key->value.'.body');

        if (! is_string($subject) || ! is_string($body) || str_starts_with($subject, 'mail.templates.')) {
            throw new \RuntimeException("No platform default email template for [{$key->value}]. Add it to lang/en/mail.php.");
        }

        return new RenderedTemplate(subject: $subject, body: $body);
    }

    /**
     * Every default, for the editor's list and for the test that checks each
     * one only uses placeholders its key declares.
     *
     * @return array<string, RenderedTemplate>
     */
    public static function all(): array
    {
        $templates = [];

        foreach (EmailTemplateKey::cases() as $key) {
            $templates[$key->value] = self::for($key);
        }

        return $templates;
    }
}
