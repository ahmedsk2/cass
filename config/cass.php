<?php

declare(strict_types=1);

return [
    'platform_name' => env('CASS_PLATFORM_NAME', 'CASS'),
    'platform_contact_email' => env('CASS_CONTACT_EMAIL', 'cass@towardpcc.com'),
    'admin_email' => env('CASS_ADMIN_EMAIL'),
    'admin_password' => env('CASS_ADMIN_PASSWORD'),
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],
    // Scans counted per client IP per link per minute. The /q redirect itself
    // is never refused (spec 5.7); this only stops a script inflating the
    // counter, so a lecture hall behind one NAT still reaches the page.
    'short_link_rate_limit' => (int) env('CASS_SHORT_LINK_RATE_LIMIT', 60),
    // Second ceiling, per link per minute with no client in the key. The
    // client IP is read from a header the client sets, so the cap above can
    // be minted once per request; this one bounds how fast one link's visit
    // rows can grow whatever address is claimed.
    'short_link_rate_limit_per_link' => (int) env('CASS_SHORT_LINK_RATE_LIMIT_PER_LINK', 600),
    // Visit rows older than this are deleted nightly by `model:prune`. The
    // sharing page reports 30 days, so nothing inside the window is lost.
    'short_link_visit_retention_days' => (int) env('CASS_SHORT_LINK_VISIT_RETENTION_DAYS', 90),
    'qr' => [
        // The PNG is rendered at whole-module scale, so the real width is the
        // smallest multiple of the module count that reaches this size.
        'png_min_size' => (int) env('CASS_QR_PNG_MIN_SIZE', 1024),
    ],
    // Spec section 8: downloads only through signed, expiring routes. Long
    // enough for a mail client that prefetches links, short enough that a
    // forwarded URL is dead on arrival.
    'file_url_minutes' => (int) env('CASS_FILE_URL_MINUTES', 30),
    // Spec section 8: 10 MB per file. Under docker/php.ini's
    // upload_max_filesize=12M and Livewire's own default max:12288, so the
    // refusal an author meets is this one - with a sentence - rather than a
    // blank 413 from PHP.
    'max_file_bytes' => (int) env('CASS_MAX_FILE_BYTES', 10 * 1024 * 1024),

    // Spec section 9: "submission 5/min/IP". Applied inside the Livewire
    // component (a Livewire action is one POST to /livewire/update, so route
    // middleware cannot tell a save from a submit) with App\Support\ClientIp.
    'submission_rate_limit' => (int) env('CASS_SUBMISSION_RATE_LIMIT', 5),

    // A form nobody could have read, let alone filled, in this many seconds was
    // not filled by a person. Four is low enough that a determined author
    // pasting a prepared abstract still gets through.
    'submission_min_seconds' => (int) env('CASS_SUBMISSION_MIN_SECONDS', 4),

    // Spec section 9: /s/{token} is 20 GETs per minute per address. It lives
    // here rather than with the submission knobs in Task 7 because the limiter
    // that reads it is registered in this task, alongside the route.
    'status_page_rate_limit' => (int) env('CASS_STATUS_PAGE_RATE_LIMIT', 20),

    // Spec 5.4 step 1 and spec section 9: a 14-day hashed-token invitation,
    // and the invitation-accept rate limit of 10 per minute per address.
    'invitations' => [
        'expiry_days' => (int) env('CASS_INVITATION_EXPIRY_DAYS', 14),
        'accept_rate_limit' => (int) env('CASS_INVITATION_RATE_LIMIT', 10),
        // Spec section 9's "login 5/min/email+IP", applied to the password
        // confirmation on /invite/{token} - the one place outside a Filament
        // panel where a password is checked at all.
        'login_rate_limit' => (int) env('CASS_INVITATION_LOGIN_RATE_LIMIT', 5),
        // A pasted reviewer list is a bulk-mail primitive available to every
        // organization member: cap one batch and meter the actor, or one member
        // can spend the platform's sending reputation in a single click.
        // `list_max` mirrors App\Support\Reviews\ReviewerList::MAX_ENTRIES for
        // anything that wants to read the bound from configuration; the parser
        // itself uses the constant, because a parser with no database must not
        // need a container to answer.
        'list_max' => (int) env('CASS_INVITATION_LIST_MAX', 100),
        'send_rate_limit' => (int) env('CASS_INVITATION_SEND_LIMIT', 200),
    ],

    'review' => [
        // Spec 5.5 skips a reviewer "whose email domain matches an author's
        // email domain". Applied literally in this region that rule would skip
        // almost everybody, because most authors and most reviewers use a free
        // mailbox. A domain on this list is never treated as a conflict on its
        // own; an exact email match still is.
        'free_email_domains' => [
            'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.co.uk', 'outlook.com',
            'live.com', 'msn.com', 'yahoo.com', 'yahoo.co.uk', 'ymail.com', 'icloud.com',
            'me.com', 'aol.com', 'gmx.com', 'proton.me', 'protonmail.com', 'zoho.com',
            'qq.com', '163.com', 'mail.ru', 'yandex.com',
        ],
    ],

    'reminders' => [
        // Reviewer reminders go out in the first run of the hour at or after
        // this local hour in the conference's own timezone (spec section 10:
        // timezone per conference).
        'send_hour' => (int) env('CASS_REMINDER_HOUR', 7),
        // The organizer's manual "Send reminder now", per conference.
        'manual_throttle_hours' => (int) env('CASS_REMINDER_MANUAL_THROTTLE_HOURS', 12),
    ],

    // Spec 5.6 (decisions) and spec section 10 (the 500-row ranking budget).
    'decisions' => [
        // Rows the ranking table shows per page before the organizer asks for
        // more. 50 is two screens of scrolling and one query; the page also
        // offers 100, 250 and "all", and "all" over 500 rows is what the
        // performance test in Task 4 measures.
        'page_size' => (int) env('CASS_RANKING_PAGE_SIZE', 50),
        // How many decision emails one "Send decision emails" click queues
        // before it stops and tells the organizer to click again. The whole run
        // happens inside one php-fpm request (docker/php.ini's 60-second
        // budget), and each row is a render, a token mint, an email_logs insert
        // and a queue push. 200 is comfortably inside it for the conference
        // sizes this platform is for; raise it only after measuring.
        'send_chunk' => (int) env('CASS_DECISION_SEND_CHUNK', 200),
        // How many rows one "Decide selected" click may carry. The bulk loop
        // asks the Gate once per row and then runs ApplyDecision's own
        // currentDecision() read and transaction - measured at eleven queries
        // per row - so an unbounded select-all over the 500 abstracts spec
        // section 10 budgets for is roughly 5,500 queries inside php-fpm's
        // 60-second window (docker/php.ini). Filament applies this as a LIMIT
        // on the selection (Tables\Concerns\HasBulkActions), so the run is
        // bounded rather than refused, and the organizer clicks again -
        // the same shape as send_chunk. ApplyDecision commits per row, so a
        // run that did time out would lose only the report, never the writes.
        'decide_chunk' => (int) env('CASS_DECISION_DECIDE_CHUNK', 250),
    ],

    'countries' => [
        'SA' => 'Saudi Arabia', 'AE' => 'United Arab Emirates', 'BH' => 'Bahrain', 'KW' => 'Kuwait',
        'OM' => 'Oman', 'QA' => 'Qatar', 'EG' => 'Egypt', 'JO' => 'Jordan', 'LB' => 'Lebanon',
        'IQ' => 'Iraq', 'MA' => 'Morocco', 'TN' => 'Tunisia', 'DZ' => 'Algeria', 'SD' => 'Sudan',
        'YE' => 'Yemen', 'SY' => 'Syria', 'PS' => 'Palestine', 'LY' => 'Libya', 'PK' => 'Pakistan',
        'IN' => 'India', 'TR' => 'Türkiye', 'GB' => 'United Kingdom', 'US' => 'United States',
        'CA' => 'Canada', 'AU' => 'Australia', 'DE' => 'Germany', 'FR' => 'France', 'IT' => 'Italy',
        'ES' => 'Spain', 'NL' => 'Netherlands', 'IE' => 'Ireland', 'MY' => 'Malaysia', 'ID' => 'Indonesia',
        'ZA' => 'South Africa', 'NG' => 'Nigeria', 'KE' => 'Kenya', 'BR' => 'Brazil', 'MX' => 'Mexico',
        'JP' => 'Japan', 'KR' => 'South Korea', 'CN' => 'China', 'SG' => 'Singapore', 'OTHER' => 'Other',
    ],
];
