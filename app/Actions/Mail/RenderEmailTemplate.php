<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Enums\EmailTemplateKey;
use App\Models\Conference;
use App\Support\Mail\DefaultTemplates;
use App\Support\Mail\RenderedTemplate;

/**
 * Turns a template key plus a bag of values into the finished subject and
 * Markdown body.
 *
 * Three rules, each of which exists because of a specific way this goes wrong:
 *
 * 1. **Values are escaped before substitution, not after.** Escaping the
 *    finished string would also neuter the organizer's own markdown, and
 *    escaping nothing would let an author's own title - which they type, and
 *    which nobody reviews - become a link in an email the organizers trust. The
 *    replacement set is the one Laravel's own EncodedHtmlString uses when
 *    Markdown::withSecuredEncoding() is on (`[`, `<`, `>`), which is exactly
 *    what tests/Feature/NotificationMarkdownTest.php already pins for Plan 1's
 *    notifications.
 * 2. **`<` is escaped across the whole finished body.** Illuminate\Mail\Markdown
 *    parses with CommonMark's default `html_input`, which is *allow*, so raw
 *    HTML an organizer typed into the editor would reach the recipient. Only
 *    `<` is escaped, never `>`, so a markdown blockquote still renders.
 * 3. **An unknown placeholder is left literal.** Blanking it turns a typo into a
 *    sentence with a hole in it that nobody notices until an author asks what
 *    "your abstract  is received" means. Left literal, the organizer sees
 *    `{{titel}}` in the live preview.
 */
class RenderEmailTemplate
{
    /** Matches Laravel's EncodedHtmlString replacements. */
    private const VALUE_ESCAPES = ['[' => '\[', '<' => '&lt;', '>' => '&gt;'];

    /**
     * @param  array<string, string|null>  $values
     */
    public function handle(EmailTemplateKey $key, ?Conference $conference, array $values): RenderedTemplate
    {
        $template = $this->template($key, $conference);

        return new RenderedTemplate(
            subject: $this->renderSubject($template->subject, $values),
            body: $this->renderBody($template->body, $values),
        );
    }

    /**
     * The stored override if the organizer made one, otherwise the platform
     * default. Only conference-scoped keys can be overridden (see
     * EmailTemplateKey::isConferenceScoped()).
     */
    public function template(EmailTemplateKey $key, ?Conference $conference): RenderedTemplate
    {
        if ($conference !== null && $key->isConferenceScoped()) {
            $override = $conference->emailTemplates()->where('key', $key->value)->first();

            if ($override !== null) {
                return new RenderedTemplate(subject: (string) $override->subject, body: (string) $override->body);
            }
        }

        return DefaultTemplates::for($key);
    }

    /**
     * A subject is a message header, not markup: no escaping (which would put
     * `&amp;` in front of a reader) but every CR and LF removed, because a
     * value reaching a header unfiltered is header injection.
     *
     * @param  array<string, string|null>  $values
     */
    private function renderSubject(string $subject, array $values): string
    {
        $rendered = $this->substitute($subject, $values, escape: false);

        return trim((string) preg_replace('/[\r\n]+/', ' ', $rendered));
    }

    /** @param  array<string, string|null>  $values */
    private function renderBody(string $body, array $values): string
    {
        return str_replace('<', '&lt;', $this->substitute($body, $values, escape: true));
    }

    /** @param  array<string, string|null>  $values */
    private function substitute(string $text, array $values, bool $escape): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/',
            function (array $match) use ($values, $escape): string {
                $name = $match[1];

                // Not array_key_exists: a null value is as much "we do not have
                // this" as a missing key, and both should show the organizer
                // that the placeholder did not resolve.
                if (! isset($values[$name])) {
                    return $match[0];
                }

                $value = (string) $values[$name];

                return $escape ? strtr($value, self::VALUE_ESCAPES) : $value;
            },
            $text,
        );
    }
}
