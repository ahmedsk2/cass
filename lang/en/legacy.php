<?php

declare(strict_types=1);

/*
 * `cass:import-legacy` (spec 5.10): every sentence the command, the action and
 * the manual-review report can produce.
 *
 * Spec section 10: Arabic is a copy of this file, not a branch in the code.
 * Keys are grouped the way an operator meets them - the refusals that end a run
 * before it starts, the reasons a row needs a human, the report's own headings,
 * and the command's output.
 *
 * **No key here ever interpolates a legacy token.** The real dump still carries
 * three live plaintext invitation tokens, and the report is a file on the
 * cass-storage volume that outlives the dump; a reason names the legacy row and
 * says what was dropped.
 */

return [

    'errors' => [
        'no_organization' => 'No organization has the slug [:slug]. The import adopts an organization you have already created in the admin panel - with its name, type, country and branding - and never invents one. Create it, then run this again.',
        'not_approved' => 'The organization [:slug] is not approved, so its conferences would not be public. Approve it in the admin panel first.',
        'unreadable_dump' => 'Cannot read the legacy dump at [:path].',
        'no_uploads' => 'The legacy uploads directory [:path] is not a directory. Point --uploads-dir at the flat folder of PDFs from the legacy zip.',
        'no_slug' => 'Pass --organization-slug=example-society. The import writes into an organization you have already created and refuses to guess which one.',
    ],

    /*
     * One line per row the mapper would have had to guess about. Each one names
     * what was done and what a human should check, because "needs review" with
     * no verb is a line nobody can act on.
     */
    'review' => [
        'manager_widened' => ':email managed legacy edition(s) :editions and nothing else. v2 scopes an organizer to the whole organization (spec section 3), so in :organization they can now reach every conference, including ones created later. Demote them to a member if that is too much.',
        'missing_manager' => 'A conference_managers row names a manager who is not in the legacy users table, so no membership was created for it.',
        'orphan_reviewer' => 'A conference_reviewers row has no conference or no reviewer - the junk shape a tokenless request to the legacy accept page inserted. Nothing was created for it.',
        'reviewer_never_reviewed' => ':email is attached to [:conference] as a reviewer and wrote no answers at all. Remove them if they never took part.',
        'orphan_form' => 'An evaluation_forms row names a conference that is not in this dump, so neither it nor its questions were imported.',
        'orphan_invitation' => 'A reviewer_invitations row names a conference that is not in this dump, so it was not imported.',
        'invitation_token_dropped' => 'A plaintext token was present and was not carried; the invitation is imported expired and revoked, so it cannot be used. Invite :email again if they are still wanted.',
        'question_order_synthesised' => 'Every question on this legacy form carried order_column = 0, so the order reviewers will see is the order the rows were created in. Check it against the original form before reviewing starts.',
        'question_created_before_form' => 'This question is older than the form it belongs to, so it was copied in by hand rather than added through the legacy screen. Check that it belongs here.',
        'unknown_role' => 'The legacy role [:role] has no rule in this mapper, so :email was imported with no organization membership. Give them one by hand if they need it.',
        'encoding' => 'A value is not valid UTF-8 and was imported with its invalid bytes substituted rather than transcoded. The excerpt reads: :excerpt',
    ],

    'report' => [
        'title' => 'Legacy import: rows a human has to look at',
        'generated' => 'Generated :at for organization :organization.',
        'none' => 'Nothing needs manual review.',
        'summary' => ':rows row(s) across :tables legacy table(s).',
        'group' => ':table (:rows)',
    ],

    'command' => [
        'description' => 'Import the legacy conferences, abstracts, reviewers and reviews from a mysqldump',
        'dry_run' => 'Dry run: everything below really ran, inside a transaction that was rolled back. Nothing was written.',
        'created' => 'Created',
        'skipped' => 'Skipped (already imported)',
        'nothing' => 'Nothing was created and nothing was skipped: check that the dump is the one you meant.',
        'manual_review' => ':count row(s) need a human. The report is on the local disk at :path.',
        'manual_review_none' => 'No row needed manual review.',
        'read_the_report' => 'Read that report before you tell anyone the import is done: every line in it is something this command refused to guess.',
        'done' => 'Imported into [:organization].',
        'rescore' => 'Now run `php artisan cass:rescore {conference}` for each imported conference: the import writes reviews directly and there is no SubmitReview to compute a score.',
    ],

];
