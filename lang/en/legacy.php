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
        'orphan_submission' => 'A submissions row names a conference that is not in this dump, so neither it nor its authors, its file or its reviews were imported.',
        'no_authors' => 'The authors and presenter_names columns are both empty, so this abstract was imported with no author rows at all. The only address it carries is :email.',
        'invented_email' => 'The author :name has the non-routable address :email, because legacy stored one contact address per abstract and no author addresses at all. Nothing is ever sent to it (.invalid cannot resolve), and submission_authors.email is NOT NULL.',
        'contact_unmatched' => 'The contact address :email matched no author name, so the first author (:name) was made corresponding. A shared research-office mailbox is the usual cause; check the abstract.',
        'affiliation_kept' => 'The legacy affiliation reads [:value] and was imported as one. For the 2023 edition that column was labelled differently and sometimes holds something else entirely - one row holds a research question - so read it before you trust it.',
        'affiliation_dropped' => 'The legacy affiliation reads [:value], which is a person who also appears in the byline, so it was NOT imported as an affiliation. Nothing was deleted anywhere else; add the real institution by hand if you know it.',
        'file_missing' => 'The attachment :file is not in the uploads directory, so the abstract was imported without a file. Look for it; if it is gone, nothing else is needed.',
        'file_orphan' => 'The orphan file :file is on disk and no row references it - the residue of an abstract deleted directly in the legacy database. It was not imported: submission_files.submission_id is NOT NULL.',
        'file_duplicate' => 'The attachment :file holds bytes this abstract already has, so it was imported once. submission_files is unique on (submission_id, sha256).',
        'file_unreadable' => 'The attachment :file is in the uploads directory and could not be read, so the abstract was imported without it.',
        'file_unwritable' => 'The attachment :file could not be written to the private disk, so the abstract was imported without it. Check the free space on the cass-storage volume and run the import again.',
        'file_mime' => 'The attachment :file sniffs as :mime, which is not what a .:extension should look like inside. It was imported anyway - this is the owner\'s own archive, not a stranger\'s upload - but open it before anybody relies on it.',
        'file_oversize' => 'The attachment :file is :bytes bytes, over the :limit-byte upload limit a new submission would meet. It was imported anyway, because refusing the owner\'s own archive would lose it.',
        'orphan_review' => 'A set of :answers legacy answer rows names reviewer :reviewer or an abstract that is not in this dump, so no review was created for them.',
        'orphan_answer' => 'A legacy answer names question :question, which is not on the review form of this abstract\'s conference, so the answer was not imported.',
        'answer_not_numeric' => 'A Likert answer reads [:value], which is not a number, so it was imported with no score. It counts towards nothing until somebody corrects it.',
        'review_incomplete' => 'An incomplete review: :answered of :total questions were answered. It is imported submitted, because the committee did receive it, and the score is the weighted mean of the answers that exist.',
        'review_timestamps_synthetic' => 'Review timestamps are synthetic. The legacy database has no review header row and no review date at all, so every imported review is dated its own abstract\'s date plus one day.',
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
        'manual_review_unwritten' => ':count row(s) need a human, and they are listed below. A dry run writes no report file on purpose, so there is never a rehearsal\'s report on the volume to tell apart from the real run\'s.',
        'manual_review_none' => 'No row needed manual review.',
        'read_the_report' => 'Read that report before you tell anyone the import is done: every line in it is something this command refused to guess.',
        'done' => 'Imported into [:organization].',
        'rescore' => 'The scores are already computed: the import runs ComputeSubmissionScore over every imported conference itself. Run `php artisan cass:rescore {conference}` only if you correct an answer by hand afterwards.',
    ],

];
