<?php

declare(strict_types=1);

/*
 * The spreadsheets an organizer downloads: the submission list (CSV,
 * ExportSubmissionsCsv) and the ranking (CSV and XLSX, both through
 * RankingRows::headers()). Spec section 10: Arabic is a copy of this file.
 *
 * One heading per column, shared by both files wherever they print the same
 * column, so a translation cannot leave the two exports disagreeing about what
 * "Submitted at" is called. Only the words are here - the ORDER of the columns
 * is the code's, because it is the file format.
 */

return [

    'headings' => [
        'reference' => 'Reference',
        'conference' => 'Conference',
        'title' => 'Title',
        'status' => 'Status',
        'track' => 'Track',
        'presentation_preference' => 'Presentation preference',
        'score' => 'Score',
        'spread' => 'Spread',
        'reviews' => 'Reviews',
        'decision' => 'Decision',
        'decision_letter_sent' => 'Decision letter sent',
        'corresponding_author' => 'Corresponding author',
        'corresponding_email' => 'Corresponding email',
        'all_authors' => 'All authors',
        'affiliations' => 'Affiliations',
        'contact_phone' => 'Contact phone',
        'word_count' => 'Word count',
        'files' => 'Files',
        'extra_answers' => 'Extra answers',
        'submitted_at' => 'Submitted at',
        'last_edited_at' => 'Last edited at',
    ],

    // A checkbox answer inside the "Extra answers" cell of the submission list.
    // Lower-case on purpose: it is a value in a sentence-like cell
    // ("needs_projector: yes"), not a heading.
    'answers' => [
        'yes' => 'yes',
        'no' => 'no',
    ],

];
