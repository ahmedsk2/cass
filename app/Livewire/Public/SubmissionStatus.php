<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Actions\Submissions\WithdrawSubmission;
use App\Exceptions\SubmissionNotAcceptable;
use App\Models\Submission;
use App\Support\Branding\OrganizationTheme;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Spec 5.3 step 5. There is no account here: the 64 characters in the URL are
 * the whole credential, and everything this page shows or allows follows from
 * the single indexed lookup in mount().
 *
 * Two notes on what is deliberately *not* done:
 *
 * - No constant-time comparison. The lookup is an equality test on a unique
 *   index over a SHA-256 hash of 256 bits of entropy; a timing oracle on an
 *   index probe is not a path to guessing one, and pretending otherwise would
 *   mean loading every row to compare in PHP.
 * - No distinction between "no such token" and "token for something you may not
 *   see". Both are 404, from the same line.
 */
class SubmissionStatus extends Component
{
    #[Locked]
    public Submission $submission;

    /**
     * The plaintext token. It is in the component snapshot, which is signed but
     * not encrypted - and that is fine here specifically because the same value
     * is in the address bar of the page the snapshot belongs to. It is passed
     * down to the nested form so an edit keeps the author on the same URL.
     */
    #[Locked]
    public string $token;

    public bool $editing = false;

    public function mount(string $token): void
    {
        $submission = Submission::findByPlainToken($token);

        abort_if($submission === null, 404);

        // The same visibility rule as the conference page and the short link: a
        // draft, archived or suspended conference is offline to everyone
        // outside the panel, and an author's own link is not an exception.
        abort_unless(
            $submission->conference->isPubliclyVisible()
                && $submission->conference->organization->isApproved(),
            404,
        );

        $this->submission = $submission;
        $this->token = $token;
    }

    public function startEditing(): void
    {
        $this->editing = $this->submission->isOpenToAuthor();
    }

    public function cancelEditing(): void
    {
        $this->editing = false;
        $this->submission->refresh();
    }

    public function withdraw(WithdrawSubmission $withdraw): void
    {
        try {
            // No $actor: nobody is logged in. WithdrawSubmission records "by
            // author" in the activity log for exactly this call - and, because
            // there is no actor, it also enforces the submission window, which
            // the organizer's call deliberately does not. The window check
            // belongs there and not here: the button being hidden after the
            // deadline is not a check, and this component is reachable by
            // anyone holding the token.
            $this->submission = $withdraw->handle($this->submission);
        } catch (SubmissionNotAcceptable $exception) {
            $this->addError('withdraw', $exception->getMessage());

            return;
        }

        $this->editing = false;
        session()->flash('status', __('submission.status.withdrawn_flash'));
    }

    public function render(): mixed
    {
        $conference = $this->submission->conference;
        $organization = $conference->organization;
        $theme = OrganizationTheme::for($organization);

        return view('livewire.public.submission-status', [
            'conference' => $conference,
            'organization' => $organization,
            'theme' => $theme,
            'authors' => $this->submission->authors()->get(),
            'files' => $this->submission->files()->get(),
            'canChange' => $this->submission->isOpenToAuthor(),
        ])->layout('components.layouts.conference', [
            'organization' => $organization,
            'conference' => $conference,
            'theme' => $theme,
            // This page prints author names, affiliations and email addresses
            // behind nothing but a URL. Keeping it out of search indexes is the
            // difference between a private link and a published one.
            'noindex' => true,
        ]);
    }
}
