<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Exceptions\SubmissionNotAcceptable;
use App\Models\Submission;

/**
 * Spec 5.3 step 5: the author edits until the deadline. The status does not
 * move - editing a submitted abstract leaves it submitted, and editing a draft
 * leaves it a draft - so this is SaveSubmissionDraft plus one gate.
 */
class UpdateSubmission
{
    public function __construct(private readonly SaveSubmissionDraft $save) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Submission $submission, array $data): Submission
    {
        if (! $submission->isOpenToAuthor()) {
            throw SubmissionNotAcceptable::because(
                $submission->status->isOpenToAuthor()
                    ? 'Submissions for this conference are closed, so this abstract can no longer be changed.'
                    : 'An abstract that is '.strtolower($submission->status->getLabel()).' can no longer be changed.',
            );
        }

        return $this->save->handle($submission->conference, $data, $submission)->submission;
    }
}
