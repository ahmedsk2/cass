<?php

declare(strict_types=1);

namespace App\Support\Reviews;

/**
 * Spec 5.4 step 1: "Organizer invites reviewers by name and email (single or
 * pasted list)."
 *
 * The two shapes the spec names are `Name <email>` and a bare `email`. The two
 * it does not name are what a spreadsheet paste actually produces - comma,
 * semicolon or tab separated, in either order - and refusing those would mean
 * an organizer hand-editing forty lines. Whichever half of a separated line
 * looks like an address is the address; the other half is the name.
 *
 * Nothing here touches the database: it is a pure parse with a unit test, and
 * InviteReviewerList decides what to do with the result.
 */
final readonly class ReviewerList
{
    /**
     * The cap on one paste, and the reason there is one.
     *
     * InviteReviewerList loops synchronously: every entry is roughly eight
     * queries plus a rendered template plus an `email_logs` insert plus a queued
     * mailable, all inside ONE Livewire POST. php-fpm gives that request 60
     * seconds (`docker/php.ini` `max_execution_time=60`) and nginx gives up on
     * it at the same point (`docker/nginx.conf` `fastcgi_read_timeout 60s`), and
     * the textarea accepts 20,000 characters - about 2,500 bare addresses. A
     * 400-line paste would 504 halfway through with no report, and the
     * organizer's retry would re-mint tokens that invalidate the links already
     * delivered to the first half. A hundred at a time is a paste that finishes.
     *
     * It is also the outer bound on the bulk-mail primitive this feature is:
     * InviteReviewer additionally meters the actor
     * (`cass.invitations.send_rate_limit`), so the cap bounds one click and the
     * limiter bounds one hour.
     */
    public const MAX_ENTRIES = 100;

    /**
     * @param  list<array{name: string|null, email: string}>  $entries
     * @param  list<string>  $errors  one sentence per unusable line, with its number
     * @param  list<string>  $duplicates  addresses that appeared more than once
     */
    private function __construct(
        public array $entries,
        public array $errors,
        public array $duplicates,
    ) {}

    public static function parse(string $text): self
    {
        $entries = [];
        $errors = [];
        $duplicates = [];
        $seen = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $rawLine) {
            $line = trim($rawLine);
            $number = $index + 1;

            if ($line === '') {
                continue;
            }

            [$name, $email] = self::split($line);

            if ($email === null) {
                $errors[] = __('reviewer.list.bad_line', ['line' => $number, 'text' => $line]);

                continue;
            }

            if (isset($seen[$email])) {
                if (! in_array($email, $duplicates, true)) {
                    $duplicates[] = $email;
                }

                continue;
            }

            $seen[$email] = true;

            // Stop collecting at the cap and say so, rather than handing
            // InviteReviewerList a batch that cannot finish inside one request.
            // The message goes in `errors`, which ConferenceReviewers shows
            // first, so the organizer is told what did NOT happen.
            if (count($entries) >= self::MAX_ENTRIES) {
                $errors[] = __('reviewer.list.too_many', ['max' => self::MAX_ENTRIES]);

                break;
            }

            $entries[] = ['name' => $name, 'email' => $email];
        }

        return new self($entries, $errors, $duplicates);
    }

    /**
     * @return array{0: string|null, 1: string|null} the name and the address, either of which may be null
     */
    private static function split(string $line): array
    {
        // `Name <email>` first, because a display name may itself contain a
        // comma ("Khan, Omar <omar@example.org>").
        if (preg_match('/^(.*?)<\s*([^<>\s]+)\s*>$/', $line, $matches) === 1) {
            return [self::cleanName($matches[1]), self::cleanEmail($matches[2])];
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/[,;\t]+/', $line) ?: []), fn (string $p): bool => $p !== ''));

        if (count($parts) === 1) {
            return [null, self::cleanEmail($parts[0])];
        }

        if (count($parts) === 2) {
            $first = self::cleanEmail($parts[0]);
            $second = self::cleanEmail($parts[1]);

            if ($first !== null && $second === null) {
                return [self::cleanName($parts[1]), $first];
            }

            if ($second !== null && $first === null) {
                return [self::cleanName($parts[0]), $second];
            }
        }

        return [null, null];
    }

    private static function cleanEmail(string $value): ?string
    {
        $value = mb_strtolower(trim($value, " \t\"'<>"));

        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : $value;
    }

    private static function cleanName(string $value): ?string
    {
        $value = trim($value, " \t\"'");

        return $value === '' ? null : $value;
    }
}
