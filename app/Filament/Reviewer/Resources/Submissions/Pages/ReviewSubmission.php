<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Resources\Submissions\Pages;

use App\Filament\Reviewer\Resources\Submissions\SubmissionResource;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Spec 5.4 step 4: "Review page shows the abstract (authors hidden when blind),
 * files, and the review form."
 *
 * This task builds everything except the form; Task 7 adds `form()`, the three
 * header actions and the state. The record is resolved through
 * SubmissionResource::getEloquentQuery(), which is ReviewerScope - so an
 * abstract outside this reviewer's pool or assignments is a **404**, not a 403.
 * A reviewer has no business learning that it exists.
 */
class ReviewSubmission extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SubmissionResource::class;

    protected string $view = 'filament.reviewer.resources.submissions.pages.review-submission';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        // Defence in depth behind the scoped query, exactly as on
        // ConferenceShortLink: the policy is the second, independent gate.
        abort_unless(Gate::allows('view', $this->getRecord()), 404);
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
            Action::make('backToQueue')
                ->label(__('reviewer.review.back'))
                ->icon(Heroicon::OutlinedQueueList)
                ->color('gray')
                ->url(fn (): string => SubmissionResource::getUrl('index', panel: 'reviewer')),
        ];
    }
}
