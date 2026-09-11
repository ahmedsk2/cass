<?php

declare(strict_types=1);

/*
 * Platform default email templates, one entry per App\Enums\EmailTemplateKey.
 *
 * `body` is Markdown. `{{placeholder}}` values are substituted by
 * App\Actions\Mail\RenderEmailTemplate, which escapes each value before
 * substitution - so a placeholder may appear anywhere, including inside a
 * markdown link's text, without letting the value become markup.
 *
 * A key may use only the placeholders EmailTemplateKey::placeholders() declares
 * for it; tests/Unit/RenderEmailTemplateTest.php enforces that. Adding Arabic
 * is copying this file to lang/ar/mail.php - no code changes (spec section 10).
 *
 * `{{status_link}}` and `{{review_link}}` are always written as an explicit
 * markdown link, `[{{status_link}}]({{status_link}})`, and a translation must
 * keep them that way. Illuminate\Mail\Markdown::converter() registers
 * CommonMarkCoreExtension and TableExtension and nothing else - no Autolink -
 * so a bare URL on its own line renders as a plain paragraph and a client that
 * does not linkify for itself (Outlook desktop) leaves the recipient with no
 * clickable route to the page. The URL is its own link text on purpose: the
 * status link is a bearer credential and the reader should be able to see where
 * it points before following it. tests/Unit/RenderEmailTemplateTest.php pins
 * this for every key.
 */

return [
    'templates' => [

        'submission_received' => [
            'subject' => 'Abstract {{reference}} received — {{conference}}',
            'body' => <<<'MARKDOWN'
            Dear {{author_name}},

            Thank you. We have received your abstract for **{{conference}}**.

            - **Reference:** {{reference}}
            - **Title:** {{title}}

            Keep the reference: it is how we will refer to your abstract in every message from now on.

            You can review, edit or withdraw your abstract until the submission deadline ({{deadline}}) here:

            [{{status_link}}]({{status_link}})

            This link is personal. Anyone who has it can edit your abstract, so please do not forward it.

            {{organization}}
            MARKDOWN,
        ],

        'submission_draft_saved' => [
            'subject' => 'Your draft abstract for {{conference}} is saved',
            'body' => <<<'MARKDOWN'
            Dear {{author_name}},

            Your draft abstract **{{title}}** is saved for **{{conference}}**. It has **not** been submitted yet.

            Continue and submit it here:

            [{{status_link}}]({{status_link}})

            The submission deadline is {{deadline}}. A draft that is never submitted is not considered.

            This link is personal. Anyone who has it can edit your abstract, so please do not forward it.

            {{organization}}
            MARKDOWN,
        ],

        'reviewer_invitation' => [
            'subject' => 'Invitation to review abstracts for {{conference}}',
            'body' => <<<'MARKDOWN'
            Dear {{reviewer_name}},

            {{organization}} invites you to review abstracts submitted to **{{conference}}**.

            Accept the invitation and see your queue here:

            [{{review_link}}]({{review_link}})

            Reviews are due by {{deadline}}.

            Thank you for giving your time to this.

            {{organization}}
            MARKDOWN,
        ],

        'reviewer_reminder' => [
            'subject' => 'Reminder: your reviews for {{conference}} are due {{deadline}}',
            'body' => <<<'MARKDOWN'
            Dear {{reviewer_name}},

            A reminder that your reviews for **{{conference}}** are due by {{deadline}}.

            Your queue is here:

            [{{review_link}}]({{review_link}})

            If you can no longer review, please tell us so we can reassign the abstracts.

            {{organization}}
            MARKDOWN,
        ],

        'reviewer_overdue' => [
            'subject' => 'Your reviews for {{conference}} are overdue',
            'body' => <<<'MARKDOWN'
            Dear {{reviewer_name}},

            The review deadline for **{{conference}}** ({{deadline}}) has passed and some of your reviews are still outstanding.

            Please complete them as soon as you can:

            [{{review_link}}]({{review_link}})

            If you can no longer review, please tell us so we can reassign the abstracts.

            {{organization}}
            MARKDOWN,
        ],

        'decision_accepted_oral' => [
            'subject' => '{{reference}} accepted for oral presentation — {{conference}}',
            'body' => <<<'MARKDOWN'
            Dear {{author_name}},

            We are pleased to tell you that your abstract has been **{{decision}}** for **{{conference}}**.

            - **Reference:** {{reference}}
            - **Title:** {{title}}

            Details of your session will follow. You can see your abstract here:

            [{{status_link}}]({{status_link}})

            Congratulations, and we look forward to your presentation.

            {{organization}}
            MARKDOWN,
        ],

        'decision_accepted_poster' => [
            'subject' => '{{reference}} accepted as a poster — {{conference}}',
            'body' => <<<'MARKDOWN'
            Dear {{author_name}},

            We are pleased to tell you that your abstract has been **{{decision}}** for **{{conference}}**.

            - **Reference:** {{reference}}
            - **Title:** {{title}}

            Poster dimensions and the display schedule will follow. You can see your abstract here:

            [{{status_link}}]({{status_link}})

            Congratulations, and we look forward to seeing your poster.

            {{organization}}
            MARKDOWN,
        ],

        'decision_waitlisted' => [
            'subject' => '{{reference}} is on the waiting list — {{conference}}',
            'body' => <<<'MARKDOWN'
            Dear {{author_name}},

            Your abstract **{{title}}** ({{reference}}) has been **{{decision}}** for **{{conference}}**.

            The programme is full, but places do become available. We will contact you as soon as we know more.

            You can see your abstract here:

            [{{status_link}}]({{status_link}})

            Thank you for submitting to {{conference}}.

            {{organization}}
            MARKDOWN,
        ],

        'decision_rejected' => [
            'subject' => 'Decision on abstract {{reference}} — {{conference}}',
            'body' => <<<'MARKDOWN'
            Dear {{author_name}},

            Thank you for submitting **{{title}}** ({{reference}}) to **{{conference}}**.

            The review panel received many more abstracts than the programme can hold, and yours was **{{decision}}** on this occasion.

            We know this is disappointing. We hope you will submit again next year.

            [{{status_link}}]({{status_link}})

            {{organization}}
            MARKDOWN,
        ],

        'organization_approved' => [
            'subject' => '{{organization}} is approved on CASS',
            'body' => <<<'MARKDOWN'
            **{{organization}}** has been approved. You can now create and publish conferences.

            [{{status_link}}]({{status_link}})
            MARKDOWN,
        ],

        'organization_rejected' => [
            'subject' => 'About your CASS registration for {{organization}}',
            'body' => <<<'MARKDOWN'
            We were not able to approve **{{organization}}** at this time.

            If you think this is a mistake, reply to this email and we will look again.

            [{{status_link}}]({{status_link}})
            MARKDOWN,
        ],

    ],
];
