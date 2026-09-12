<?php

declare(strict_types=1);

namespace App\Support\Domains;

/**
 * Everything this application knows about turning what an organizer typed into
 * a hostname a resolver would recognise, and about refusing the ones it must
 * not accept.
 *
 * Static, like App\Support\ShortCode and App\Support\Turnstile: there is no
 * state, the answer depends only on the argument and on config, and a value
 * object with one string property would add a constructor to every call site
 * for nothing.
 */
final class DomainName
{
    /** RFC 1035: 253 octets for the whole name, 63 for one label. */
    public const MAX_LENGTH = 253;

    public const MAX_LABEL_LENGTH = 63;

    /** The name the organizer publishes the token at. */
    public const TXT_PREFIX = '_cass-verify';

    /**
     * Lower-case, trimmed, without a trailing root dot, without a scheme or a
     * path, and IDN-encoded to its A-label. Never throws: an input this cannot
     * make sense of comes back as best it can and problem() refuses it.
     */
    public static function normalise(string $input): string
    {
        $value = trim($input);

        // An organizer pasting a URL is the most likely wrong input on this
        // field, and parse_url() is the cheapest way to be kind about it.
        if (str_contains($value, '://')) {
            $value = (string) (parse_url($value, PHP_URL_HOST) ?? '');
        }

        $value = rtrim(strtolower(trim($value)), '.');

        // intl is installed everywhere this runs (php -m, Dockerfile:25,
        // ci.yml:44). The A-label is what a resolver is asked for and what
        // Traefik puts on the certificate, so it is what is stored.
        if ($value !== '' && ! mb_check_encoding($value, 'ASCII')) {
            $ascii = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (is_string($ascii) && $ascii !== '') {
                $value = $ascii;
            }
        }

        return $value;
    }

    /**
     * The translation key of the reason this hostname cannot be a custom
     * domain, or null when it can. A key rather than a sentence so the caller
     * decides whether it is a validation message, an exception or a log line.
     */
    public static function problem(string $input): ?string
    {
        $value = self::normalise($input);

        if ($value === '') {
            return 'domain.errors.required';
        }

        if (strlen($value) > self::MAX_LENGTH) {
            return 'domain.errors.too_long';
        }

        $labels = explode('.', $value);

        // A single label is `localhost` or a machine name: there is no zone to
        // put a TXT record in and no certificate authority will issue for it.
        if (count($labels) < 2) {
            return 'domain.errors.not_a_domain';
        }

        // An IP literal parses as four labels of digits and would otherwise
        // pass the label rules below.
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return 'domain.errors.not_a_domain';
        }

        foreach ($labels as $label) {
            if (strlen($label) > self::MAX_LABEL_LENGTH) {
                return 'domain.errors.label_too_long';
            }

            // Letters, digits and inner hyphens. An empty label catches the
            // `a..b` case; a leading or trailing hyphen is invalid in a
            // hostname and is what an organizer produces by typing a dash
            // where they meant a dot.
            if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                return 'domain.errors.not_a_domain';
            }
        }

        // The last label cannot be all digits: that is the other shape an IP
        // address takes, and it is not a TLD.
        if (preg_match('/^[0-9]+$/', $labels[count($labels) - 1]) === 1) {
            return 'domain.errors.not_a_domain';
        }

        $platform = self::platformHost();

        if ($platform !== '' && ($value === $platform || str_ends_with($value, '.'.$platform))) {
            return 'domain.errors.platform_host';
        }

        return null;
    }

    public static function platformHost(): string
    {
        return strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: ''));
    }

    public static function txtRecordName(string $domain): string
    {
        return self::TXT_PREFIX.'.'.self::normalise($domain);
    }

    /**
     * What the organizer points their CNAME at. Configurable because a staging
     * deployment is not cass.towardpcc.com, and because the runbook's Coolify
     * step names the same value.
     */
    public static function cnameTarget(): string
    {
        $configured = (string) config('cass.domains.cname_target');

        return $configured !== '' ? strtolower($configured) : self::platformHost();
    }
}
