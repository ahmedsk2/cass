<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Resources\Submissions\Pages;

use App\Actions\Reviews\ReopenReview;
use App\Actions\Reviews\SaveReviewDraft;
use App\Actions\Reviews\SubmitReview;
use App\Exceptions\ReviewNotAcceptable;
use App\Filament\Reviewer\Resources\Submissions\SubmissionResource;
use App\Models\Conference;
use App\Models\Review;
use App\Models\ReviewForm;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Support\Reviews\ReviewFormSchema;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Spec 5.4 step 4: "Review page shows the abstract (authors hidden when blind),
 * files, and the review form."
 *
 * The record is resolved through SubmissionResource::getEloquentQuery(), which
 * is ReviewerScope - so an abstract outside this reviewer's pool or assignments
 * is a **404**, not a 403. A reviewer has no business learning that it exists.
 *
 * @property-read Schema $form
 */
class ReviewSubmission extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SubmissionResource::class;

    protected string $view = 'filament.reviewer.resources.submissions.pages.review-submission';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        // Defence in depth behind the scoped query, exactly as on
        // ConferenceShortLink: the policy is the second, independent gate.
        abort_unless(Gate::allows('view', $this->getRecord()), 404);

        $this->form->fill(ReviewFormSchema::fill($this->review()));
    }

    /**
     * `defaultForm` runs before `form` (fact 31) and is where the state path
     * lives; `form` carries only the components.
     */
    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(ReviewFormSchema::components($this->reviewForm(), disabled: $this->isReadOnly()))
            ->columns(1);
    }

    public function review(): ?Review
    {
        $reviewer = $this->reviewer();

        if ($reviewer === null) {
            return null;
        }

        return Review::query()
            ->where('submission_id', $this->getSubmission()->getKey())
            ->where('reviewer_user_id', $reviewer->getKey())
            ->first();
    }

    public function reviewForm(): ReviewForm
    {
        return app(SaveReviewDraft::class)->form($this->getSubmission());
    }

    /**
     * Submitted, or the conference has moved on: the form renders disabled.
     *
     * acceptsReviewWrites(), not isOpenToReviewers(): a Decided conference is
     * still readable (the reviewer can open the page and see what they wrote)
     * but is no longer writable, and the three actions behind this all refuse
     * there too.
     */
    public function isReadOnly(): bool
    {
        return $this->review()?->isSubmitted() === true
            || ! $this->getConference()->acceptsReviewWrites();
    }

    public function deadlineHasPassed(): bool
    {
        return ! $this->getConference()->reviewWindowIsOpen();
    }

    public function getTitle(): string
    {
        return (string) $this->getSubmission()->title;
    }

    public function getSubmission(): Submission
    {
        /** @var Submission $submission */
        $submission = $this->getRecord();

        return $submission;
    }

    public function getConference(): Conference
    {
        return $this->getSubmission()->conference;
    }

    /** Spec 5.4 step 4. One call, to the one method that owns the rule. */
    public function isBlind(): bool
    {
        return $this->getConference()->hidesAuthorsFrom($this->reviewer());
    }

    /** @return Collection<int, SubmissionFile> */
    public function getFiles(): Collection
    {
        /** @var Collection<int, SubmissionFile> $files */
        $files = $this->getSubmission()->files;

        return $files;
    }

    /**
     * A fresh 30-minute signed URL per render, minted only after the policy
     * says this person may read the file; `blind` is baked into the signature
     * so the name cannot be recovered from the URL (fact 19).
     */
    public function fileUrl(SubmissionFile $file): ?string
    {
        return Gate::allows('view', $file)
            ? $file->temporaryUrl(blind: $this->isBlind())
            : null;
    }

    public function fileName(SubmissionFile $file): string
    {
        return $this->isBlind() ? $file->blindName() : (string) $file->original_name;
    }

    /**
     * The conference's custom fields, printed with the label the organizer
     * wrote rather than the derived key - the same rendering the organizer's
     * infolist does.
     *
     * Minus anything the organizer marked `hide_from_reviewers`, when the reader
     * is blinded. Hiding the authors block is worth nothing if an organizer's
     * own "Institution", "Department" or "Funding source" question prints the
     * same answer two sections down, and only the organizer knows which of their
     * questions identify an author - which is why Task 1 added the column and
     * the toggle rather than trying to guess from the label.
     *
     * Driven off the `custom_fields` rows rather than the answer bag, so a field
     * that was deleted (or hidden) stops printing even though the answer is
     * still in `submissions.custom_field_values`.
     *
     * @return array<int, string>
     */
    public function getCustomFieldLines(): array
    {
        $submission = $this->getSubmission();

        $fields = $submission->conference->customFields()
            ->when($this->isBlind(), fn (Builder $query): Builder => $query->where('hide_from_reviewers', false))
            ->pluck('label', 'key');

        $lines = [];

        foreach ((array) $submission->custom_field_values as $key => $value) {
            if (! $fields->has($key)) {
                continue;
            }

            $printable = match (true) {
                is_bool($value) => $value ? __('reviewer.review.yes') : __('reviewer.review.no'),
                is_scalar($value) => (string) $value,
                default => json_encode($value) ?: '',
            };

            $lines[] = $fields[$key].': '.$printable;
        }

        return $lines;
    }

    public function reviewer(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveDraft')
                ->label(__('reviewer.review.save_draft'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->visible(fn (): bool => ! $this->isReadOnly())
                ->action(function (SaveReviewDraft $save): void {
                    // getRawState(), not getState(): a draft is deliberately
                    // not validated (HasState.php:450 - getState() validates),
                    // and half an answer has to survive a coffee break.
                    /** @var array<string, mixed> $state */
                    $state = $this->form->getRawState();

                    try {
                        $save->handle($this->getSubmission(), $this->requireReviewer(), $this->answersFrom($state));
                    } catch (ReviewNotAcceptable $exception) {
                        $this->refuse($exception);

                        return;
                    }

                    Notification::make()->success()->title(__('reviewer.notices.draft_saved'))->send();
                }),

            Action::make('submitReview')
                ->label(__('reviewer.review.submit'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->requiresConfirmation()
                ->modalHeading(__('reviewer.review.submit_heading'))
                ->modalDescription(__('reviewer.review.submit_description'))
                ->visible(fn (): bool => ! $this->isReadOnly())
                ->action(function (SubmitReview $submit): void {
                    /** @var array<string, mixed> $state */
                    $state = $this->form->getRawState();
                    $answers = $this->answersFrom($state);

                    try {
                        $submit->handle($this->getSubmission(), $this->requireReviewer(), $answers);
                    } catch (ReviewNotAcceptable $exception) {
                        $this->refuse($exception);

                        return;
                    }

                    Notification::make()->success()->title(__('reviewer.notices.submitted'))->send();
                }),

            Action::make('reopenReview')
                ->label(__('reviewer.review.reopen'))
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(__('reviewer.review.reopen_heading'))
                ->visible(function (): bool {
                    $review = $this->review();

                    return $review !== null
                        && Gate::allows('reopen', $review)
                        && app(ReopenReview::class)->blockers($review) === [];
                })
                ->action(function (ReopenReview $reopen): void {
                    $review = $this->review();

                    if ($review === null) {
                        return;
                    }

                    Gate::authorize('reopen', $review);

                    try {
                        $reopen->handle($review, $this->requireReviewer());
                    } catch (ReviewNotAcceptable $exception) {
                        $this->refuse($exception);

                        return;
                    }

                    Notification::make()->success()->title(__('reviewer.notices.reopened'))->send();
                }),

            Action::make('backToQueue')
                ->label(__('reviewer.review.back'))
                ->icon(Heroicon::OutlinedQueueList)
                ->color('gray')
                ->url(fn (): string => SubmissionResource::getUrl('index', panel: 'reviewer')),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function answersFrom(array $state): array
    {
        $answers = $state['answers'] ?? [];

        return is_array($answers) ? $answers : [];
    }

    private function requireReviewer(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * Per-question messages become field errors on the very fields they belong
     * to, so a reviewer sees "answer this one" beside the question rather than
     * a banner listing nine prompts.
     */
    private function refuse(ReviewNotAcceptable $exception): void
    {
        foreach ($exception->fieldErrors as $ulid => $message) {
            $this->addError('data.answers.'.$ulid, $message);
        }

        if ($exception->fieldErrors === []) {
            Notification::make()->danger()
                ->title(__('reviewer.notices.refused'))
                ->body(e($exception->getMessage()))
                ->persistent()
                ->send();
        }
    }
}
