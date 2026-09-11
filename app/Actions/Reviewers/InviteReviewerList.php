<?php

declare(strict_types=1);

namespace App\Actions\Reviewers;

use App\Exceptions\MemberChangeRefused;
use App\Models\Conference;
use App\Models\User;
use App\Support\Reviews\ReviewerList;

/**
 * The pasted-list half of spec 5.4 step 1. Every line is reported on: invited,
 * skipped (already reviewing, or a duplicate inside the paste) or unusable.
 * Nothing is silently dropped, because a list of forty addresses with one typo
 * is exactly the case where silence costs a reviewer.
 */
class InviteReviewerList
{
    public function __construct(private readonly InviteReviewer $inviteReviewer) {}

    /**
     * @return array{invited: int, skipped: list<string>, errors: list<string>}
     */
    public function handle(Conference $conference, string $text, User $actor): array
    {
        $parsed = ReviewerList::parse($text);

        $invited = 0;
        $skipped = [];
        $errors = $parsed->errors;

        foreach ($parsed->duplicates as $duplicate) {
            $skipped[] = __('reviewer.list.duplicate', ['email' => $duplicate]);
        }

        foreach ($parsed->entries as $entry) {
            try {
                $this->inviteReviewer->handle($conference, $entry['email'], $entry['name'], null, $actor);
                $invited++;
            } catch (MemberChangeRefused $exception) {
                // The hourly send limit is about the whole run, not this line:
                // once it trips, every remaining entry would fail for the same
                // reason and print the same sentence forty times. Report it once
                // and stop, so the count of what WAS sent stays honest.
                if ($exception->getMessage() === __('reviewer.errors.send_limit')) {
                    $errors[] = $exception->getMessage();

                    break;
                }

                // One bad entry must not abandon the other thirty-nine.
                $skipped[] = $exception->getMessage();
            }
        }

        return ['invited' => $invited, 'skipped' => $skipped, 'errors' => $errors];
    }
}
