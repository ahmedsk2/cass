<?php

declare(strict_types=1);

/*
 * The platform's own English: the two public layouts, the landing page, about,
 * privacy, terms, the contact form and the registration form - everything
 * Plans 1 and 2 hardcoded in a Blade view.
 *
 * Spec section 10: locale `en` only in v1, with every string here so Arabic is
 * a copy of this file and not a branch in a view.
 *
 * The `dashboard` and `short_link` groups at the end are the two ORGANIZER
 * views of the same vintage. They are not public pages, and they are here
 * rather than in a file of their own because they are the last two Plan 2 views
 * whose English never moved - a second file for seven strings would be a file a
 * translator has to be told about.
 *
 * Strings that already existed are NOT copied here. `submission.fields.honeypot`,
 * `submission.buttons.submit`, `submission.window.*` and four `members.invite.*`
 * keys are used verbatim by these views; two keys that say the same sentence are
 * two keys a translator can leave disagreeing with each other.
 */

return [

    /*
     * The document direction. A key rather than a locale check in a Blade
     * view, so that lang/ar/public.php is a copy of this file with one word
     * changed and no view branches on a language name - which is exactly what
     * spec section 10 asks for.
     */
    'dir' => 'ltr',

    'meta' => [
        'description' => 'CASS collects conference abstracts, runs peer review and announces decisions. Every conference gets its own submission page, short link and QR code.',
    ],

    'nav' => [
        'about' => 'About',
        'contact' => 'Contact',
        'login' => 'Organizer login',
        'register' => 'Register organization',
    ],

    'footer' => [
        'copyright' => '© :year :platform · Conference Abstract Submission System',
        'powered_by' => ':organization · powered by :platform',
        'privacy' => 'Privacy',
        'terms' => 'Terms',
    ],

    'landing' => [
        'eyebrow' => 'For conference organizers',
        'title' => 'Conference Abstract Submission System',
        'lead' => 'Collect abstracts, run peer review, and announce decisions from one place. Every conference gets its own submission page, short link and QR code you can print on a poster.',
        'reviewers' => 'Reviewers sign in from the invitation link they received by email.',
        'hero_alt' => 'Researcher reviewing an abstract on screen',

        'features' => [
            'heading' => 'Everything a scientific committee needs',
            'submission_title' => 'Submission page with QR code',
            'submission_body' => 'Publish a call for abstracts with a word limit, file upload and your own fields. Download the QR code and short link for posters and emails.',
            'review_title' => 'Peer review that fits your committee',
            'review_body' => 'Invite reviewers by email. Let every reviewer score every abstract, or assign a balanced set to each. Blind review and automatic deadline reminders included.',
            'decisions_title' => 'Scores, ranking, decisions',
            'decisions_body' => 'Weighted scoring, a ranked table with export, and accept, poster, waitlist or reject decisions sent with templated emails.',
        ],

        'steps' => [
            'heading' => 'How it works',
            'register_title' => 'Register your organization',
            'register_body' => 'We approve new organizations within two working days.',
            'create_title' => 'Create a conference',
            'create_body' => 'Set deadlines, the review form and your branding.',
            'share_title' => 'Share the QR code',
            'share_body' => 'Authors submit from any device. You see submissions arrive.',
            'decide_title' => 'Review and decide',
            'decide_body' => 'Reviewers score, you rank and notify authors in bulk.',
        ],
    ],

    'about' => [
        'title' => 'About',
        'heading' => 'About CASS',
        'history' => 'CASS began in 2023 as the abstract system for the Common Pediatric Diseases Symposium in Saudi Arabia. After two editions and several hundred peer reviews it was rebuilt as a platform any conference organizer can use.',
        'team' => 'It is built and operated by a team of clinicians and engineers who run scientific meetings themselves. The aim is simple: fewer spreadsheets and email threads for committees, and a clear, fast submission experience for authors.',
        /*
         * One sentence split across two links in the view, so it is one key
         * with two placeholders rather than three fragments a translator
         * cannot reorder. Both links are built and escaped in the view.
         */
        'contact' => 'Email :email or use the :form.',
        'contact_form' => 'contact form',
    ],

    'privacy' => [
        'title' => 'Privacy',
        'heading' => 'Privacy policy',
        'updated' => 'Last updated 10 September 2026.',
        'collect_heading' => 'What we collect',
        'collect_body' => 'Organizer and reviewer accounts store a name, email address and password hash. Abstract submissions store the content authors provide: title, abstract text, author names, affiliations, contact details and uploaded files. We record the time of actions such as submissions, reviews and decisions, and count visits to short links without storing personal data.',
        'use_heading' => 'How we use it',
        'use_body' => 'Only to run the conference you submitted to or organize: peer review, notifications about your submission or reviewing tasks, and platform administration. We do not sell data or use it for advertising.',
        'access_heading' => 'Who can see it',
        'access_body' => 'Members of the organizing organization see submissions to their conferences. Reviewers see the abstracts they are asked to review; when blind review is enabled they do not see author names. Platform administrators can access all data for support and security.',
        'retention_heading' => 'Retention and deletion',
        'retention_body' => 'Data is kept for the life of the conference record. Organizers may delete a conference, and authors may ask the organizer or us to delete a submission. Write to :email.',
        'hosting_heading' => 'Hosting',
        'hosting_body' => 'The service is hosted in Saudi Arabia. Email is sent through the platform mail server; transactional email is logged for delivery troubleshooting.',
    ],

    'terms' => [
        'title' => 'Terms',
        'heading' => 'Terms of use',
        /*
         * Its own key, not a reuse of privacy.updated: two legal documents
         * that happen to share a date today must be able to be revised apart.
         */
        'updated' => 'Last updated 10 September 2026.',
        'organizers_heading' => 'Organizers',
        'organizers_body' => 'You are responsible for the content of your conference pages, for obtaining reviewers\' consent, and for the decisions you communicate to authors. Organizations are approved by the platform team and may be suspended for misuse.',
        'authors_heading' => 'Authors',
        'authors_body' => 'By submitting, you confirm the work is original, all listed authors have agreed to the submission, and any required ethical approvals were obtained. Submissions are shared with the organizing committee and its reviewers.',
        'reviewers_heading' => 'Reviewers',
        'reviewers_body' => 'Abstracts you review are confidential to the review process and must not be shared or used for other purposes.',
        'availability_heading' => 'Availability',
        'availability_body' => 'The service is provided as is. We aim for continuous availability around deadlines but do not guarantee it; organizers should allow margin before critical dates.',
    ],

    'contact' => [
        'lead' => 'Questions about running your conference on CASS, or about a submission? Write to us.',
        'sent' => 'Thank you. We will reply to your email address soon.',
        'name' => 'Name',
        'email' => 'Email',
        'message' => 'Message',
        'send' => 'Send message',
    ],

    'register' => [
        'heading' => 'Register your organization',
        'lead' => 'Create an organizer account. The platform team reviews every new organization before its conferences go public, usually within two working days.',
        'account' => 'Your account',
        'email' => 'Email',
        'organization' => 'Your organization',
        'organization_name' => 'Organization name',
        'type' => 'Type',
        'country' => 'Country',
        'website' => 'Website',
        'optional' => '(optional)',
        'website_placeholder' => 'https://',
        'purpose' => 'What will you use CASS for?',
        'purpose_placeholder' => 'e.g. Abstract submission and review for our annual symposium',
        'terms' => 'I agree to the :terms and :privacy.',
        'terms_link' => 'terms of use',
        'privacy_link' => 'privacy policy',
        'submit' => 'Create account',
        'submitting' => 'Creating…',
        'sign_in' => 'Already registered? :link',
        'sign_in_link' => 'Sign in',
    ],

    /*
     * The organizer dashboard's three banners. Plan 2 wrote them in English in
     * the view and no sweep has covered them since.
     */
    'dashboard' => [
        'pending_title' => ':organization is awaiting approval.',
        'pending_body' => 'You can complete your organization profile and branding now. Publishing a conference becomes available once the platform team approves your organization. We usually respond within two working days.',
        'suspended_title' => 'This organization is suspended.',
        'suspended_reason' => 'Reason: :reason',
        'suspended_contact' => 'Contact :email if you believe this is a mistake.',
        'welcome_title' => 'Welcome to :organization.',
        'welcome_body' => 'Create a conference, set its dates and review form, then publish it to get a public page, a short link and a printable QR poster.',
    ],

    /*
     * The organizer short-link page (spec 5.7). `day_tooltip` is the sparkline
     * bar's title attribute, which is the only text a keyboard or screen-reader
     * user gets from that chart.
     */
    'short_link' => [
        'not_shared_heading' => 'Not shared yet',
        'not_shared_body' => 'Publishing this conference creates a short link and a QR code you can print. Publish it from the conference page when the dates and review form are ready.',
        'heading' => 'Short link',
        'code' => 'Code :code. It points at :url and never changes, so a printed poster keeps working after you close and reopen submissions.',
        'scans_heading' => 'Scans',
        'total' => 'Total scans',
        'last_30_days' => 'Last 30 days',
        'day_tooltip' => ':day: :count',
        'privacy_note' => 'Only a timestamp is stored for each scan. No IP address, device or location is recorded.',
    ],

];
