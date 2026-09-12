<?php

declare(strict_types=1);

/*
 * The custom-domain section of the organization profile, its three actions and
 * every sentence they can produce (spec 5.8, spec section 10).
 *
 * Spec section 10: Arabic is a copy of this file, not a branch in a Blade view.
 * Keys are grouped in the order an organizer meets them - section, fields,
 * records, actions, notices, errors - and no group is defined twice.
 */

return [
    'section' => [
        'heading' => 'Custom domain',
        'description' => 'Serve your conference pages from your own address, such as abstracts.example.org, instead of :platform.',
    ],

    'fields' => [
        'domain' => 'Your domain',
        'domain_help' => 'A hostname you control, without https:// — for example abstracts.example.org. Do not use a domain that is already serving a website: every address on it will point here.',
        'status' => 'Status',
    ],

    'state' => [
        'none' => 'No custom domain',
        'pending' => 'Waiting for DNS',
        'verified' => 'Verified',
    ],

    'records' => [
        'heading' => 'Publish these two records',
        'intro' => 'Add both records in the DNS panel for :domain, then come back and press Verify. DNS changes can take a few minutes to appear.',
        'txt_name' => 'TXT record name',
        'txt_value' => 'TXT record value',
        'cname_name' => 'CNAME record name',
        'cname_value' => 'CNAME points to',
        'cname_note' => 'The CNAME is what sends visitors here. We do not check it — if it is wrong, the address simply will not open.',
        'after' => 'Leave the TXT record in place. It is how we re-check the domain if you ever need to.',
    ],

    'actions' => [
        'claim' => 'Save domain',
        'verify' => 'Verify',
        'release' => 'Remove domain',
        'release_confirm_heading' => 'Remove :domain?',
        'release_confirm_body' => 'Your conference pages will go back to :platform addresses immediately. Anyone following a link to :domain will get an error until you point it somewhere else.',
    ],

    'notices' => [
        'claimed' => 'Saved. Publish the two records below, then press Verify.',
        'verified_title' => 'Verified',
        'verified_body' => ':domain is verified. Your conference pages are live there as soon as the platform team finishes the certificate — we have emailed them.',
        'released' => 'Removed. :domain no longer serves your pages.',
        'pending_admin' => 'Verified. The platform team has been emailed to finish the certificate; this usually takes less than a day.',
    ],

    'mail' => [
        'admin_subject' => 'Custom domain verified: :domain',
        'admin_line_one' => ':organization verified **:domain**.',
        'admin_line_two' => 'Add the host to the Coolify resource so Traefik issues the certificate. The runbook section "Custom domain for an organization" has the exact call.',
        'admin_action' => 'Open the organization',
    ],

    'errors' => [
        'required' => 'Enter the domain you want to use.',
        'not_a_domain' => 'That is not a domain name. Enter a hostname such as abstracts.example.org.',
        'too_long' => 'That domain is too long.',
        'label_too_long' => 'One part of that domain is longer than 63 characters.',
        'platform_host' => 'That address belongs to the platform. Use a domain you control.',
        'taken' => 'Another organization has already claimed that domain. If it is yours, write to :contact.',
        'none_claimed' => 'Save a domain before verifying it.',
        'no_record' => 'We could not find a TXT record at :name. Add it in your DNS panel and try again — changes can take a few minutes.',
        'token_mismatch' => 'We found a TXT record at :name, but not the value shown below. Check that you copied the whole value.',
        'lookup_failed' => 'We could not reach the DNS servers for that domain just now. Try again in a few minutes.',
        'already_verified' => 'That domain is already verified.',
        'not_approved' => 'Your organization has to be approved before you can use a custom domain.',
        // Task 2's Verify button is metered per actor. The plan reached for
        // `reviewer.errors.throttled`, but no lang file in this application
        // defines a throttle key at all - `grep -rn "throttl" lang/en/` is
        // empty - so the sentence lives in this namespace rather than in
        // another file's. The wording follows submission.errors.too_many.
        'throttled' => 'Too many checks in a row. Please try again in :seconds seconds.',
    ],
];
