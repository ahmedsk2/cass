<?php

declare(strict_types=1);

namespace App\Support\Html;

use Illuminate\Support\HtmlString;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Filament's shared `Str::sanitizeHtml()` macro allows `style` and `class` on
 * every element, which lets an organizer paste CSS - or reuse the app's own
 * compiled utility classes - to restyle a public page. Conference descriptions
 * go through this stricter config instead.
 *
 * It starts from Symfony's W3C "safe" set rather than an explicit element
 * allowlist, because the RichEditor stores whatever its TipTap schema accepts
 * (a pasted call for abstracts can contain tables, block quotes, h1-h6,
 * sub/sup, mark and small) and any element that is neither allowed nor blocked
 * is dropped *with its text*. What it then removes from that set:
 *
 * - `class`, `id`, `name` and `style` on every element: the first two let
 *   organizer HTML spoof platform chrome with classes the app already
 *   compiles, and `id`/`name` can shadow elements the page's own scripts read.
 * - every media, canvas, button and dialog element: W3C calls them safe, but
 *   they load from any host (there is no CSP until Plan 6, so a `poster` URL
 *   would beacon every visitor's IP - which the scan counter deliberately does
 *   not store) or fake an affordance. `button`/`dialog` are *blocked*, not
 *   dropped, so their text survives without the affordance.
 */
final class RichText
{
    /** Removed with their children; none of them carries readable text. */
    private const DROPPED = ['img', 'video', 'audio', 'source', 'track', 'picture', 'canvas', 'iframe', 'object', 'embed'];

    /** Tag removed, text kept. */
    private const BLOCKED = ['button', 'dialog', 'form', 'input', 'select', 'textarea'];

    public static function sanitize(?string $html): HtmlString
    {
        if ($html === null || trim($html) === '') {
            return new HtmlString('');
        }

        return new HtmlString(self::sanitizer()->sanitize($html));
    }

    private static function sanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->dropAttribute('class', droppedElements: '*')
            ->dropAttribute('id', droppedElements: '*')
            ->dropAttribute('name', droppedElements: '*')
            ->dropAttribute('style', droppedElements: '*')
            ->forceAttribute('a', 'rel', 'nofollow noopener noreferrer')
            ->forceAttribute('a', 'target', '_blank')
            ->withMaxInputLength(100_000);

        foreach (self::DROPPED as $element) {
            $config = $config->dropElement($element);
        }

        foreach (self::BLOCKED as $element) {
            $config = $config->blockElement($element);
        }

        return new HtmlSanitizer($config);
    }
}
