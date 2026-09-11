<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Actions\Mail\SendTemplatedEmail;
use App\Enums\CustomFieldType;
use App\Enums\EmailTemplateKey;
use App\Enums\SubmissionStatus;
use App\Exceptions\SubmissionNotAcceptable;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\NewSubmissionNotice;
use App\Support\Text\WordCounter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Spec 5.3 step 3 and 4. The gate and the transition, in the same shape as
 * Plan 2's PublishConference: blockers() is a pure read that the form calls to
 * paint errors, handle() throws if a caller ignored it.
 *
 * Every rule here is re-checked against the *stored* row, never against the
 * request that produced it - the page is a convenience and a hand-made Livewire
 * call is not, and spec 5.3 says "Submit validates everything server-side".
 */
class SubmitAbstract
{
    public function __construct(
        private readonly AllocateReference $allocateReference,
        private readonly IssueSubmissionToken $issueToken,
        private readonly SendTemplatedEmail $sendTemplatedEmail,
    ) {}

    /**
     * @return list<string> empty when the abstract may be submitted
     */
    public function blockers(Submission $submission, bool $agreed): array
    {
        $conference = $submission->conference;
        $reasons = [];

        if ($submission->status !== SubmissionStatus::Draft) {
            $reasons[] = self::notADraft($submission->status);
        }

        if (! $conference->acceptsSubmissions()) {
            $reasons[] = 'Submissions for this conference are closed.';
        }

        if (trim((string) $submission->title) === '') {
            $reasons[] = 'Give your abstract a title.';
        }

        $abstract = trim((string) $submission->abstract);

        if ($abstract === '') {
            $reasons[] = 'Write the abstract itself.';
        } else {
            $words = WordCounter::count($abstract);
            $limit = (int) $conference->word_limit;

            if ($words > $limit) {
                $reasons[] = "The abstract is {$words} words. The limit is {$limit} words.";
            }
        }

        if ($submission->track_id !== null && ! $conference->tracks()->whereKey($submission->track_id)->exists()) {
            $reasons[] = 'Choose a track offered by this conference.';
        }

        /** @var list<string> $offered */
        $offered = $conference->presentation_types ?? [];
        $preference = $submission->presentation_preference?->value;

        if ($offered !== [] && ($preference === null || ! in_array($preference, $offered, true))) {
            $reasons[] = 'Choose a presentation preference this conference offers.';
        }

        $authors = $submission->authors()->get();

        if ($authors->isEmpty()) {
            $reasons[] = 'Add at least one author.';
        } else {
            $corresponding = $authors->where('is_corresponding', true);

            if ($corresponding->count() !== 1) {
                $reasons[] = 'Mark exactly one author as the corresponding author.';
            } elseif (filter_var($corresponding->first()?->email, FILTER_VALIDATE_EMAIL) === false) {
                $reasons[] = 'The corresponding author needs a valid email address.';
            }

            foreach ($authors as $author) {
                if (trim((string) $author->name) === '' || filter_var($author->email, FILTER_VALIDATE_EMAIL) === false) {
                    $reasons[] = 'Every author needs a name and a valid email address.';
                    break;
                }
            }
        }

        foreach ($this->customFieldBlockers($conference, $submission) as $reason) {
            $reasons[] = $reason;
        }

        if (! $agreed) {
            $reasons[] = 'You have to accept the terms before submitting.';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  string|null  $plainToken  the author's existing token, when the caller has it.
     *                                   Passing null mints a new one and kills every
     *                                   link already in circulation.
     */
    public function handle(Submission $submission, bool $agreed, ?string $plainToken = null): Submission
    {
        $reasons = $this->blockers($submission, $agreed);

        if ($reasons !== []) {
            throw new SubmissionNotAcceptable($reasons);
        }

        $conference = $submission->conference;

        // One transaction around the counter and the row, so a failure between
        // them cannot burn a reference number. The emails are deliberately
        // queued *after* it commits: a queue worker that picks the job up
        // before the commit lands would read a submission with no reference.
        $submission = DB::transaction(function () use ($submission, $conference): Submission {
            // blockers() above read the status off the instance the caller is
            // holding, and nothing has locked or re-read the row since. Two
            // requests submitting the same draft - a double-clicked button is
            // enough - would both pass that gate, draw two references and burn
            // one, and the first confirmation email would quote a number the
            // row no longer holds while the members got two notices. So the
            // one transition that must not happen twice is re-checked here,
            // under the row lock, against what is actually stored. SQLite
            // ignores the lock clause and serialises writes anyway, so the
            // stale-status half is what a local test can show.
            /** @var Submission $locked */
            $locked = Submission::query()
                ->whereKey($submission->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== SubmissionStatus::Draft) {
                throw SubmissionNotAcceptable::because(self::notADraft($locked->status));
            }

            $reference = $this->allocateReference->handle($conference);

            $submission->forceFill([
                'status' => SubmissionStatus::Submitted,
                'reference' => $reference,
                'submitted_at' => now(),
                'last_edited_at' => now(),
            ])->save();

            return $submission;
        });

        $token = $plainToken ?? $this->issueToken->handle($submission);

        $author = $submission->correspondingAuthor();

        if ($author !== null) {
            $this->sendTemplatedEmail->handle(
                EmailTemplateKey::SubmissionReceived,
                $conference,
                (string) $author->email,
                self::placeholderValues($submission, (string) $author->name, $token),
                $submission,
            );
        }

        Notification::send(self::notifiableMembers($conference), new NewSubmissionNotice($submission));

        activity()->performedOn($submission)->log('submission.submitted');

        return $submission->refresh();
    }

    /**
     * The organization members who asked to hear about new abstracts
     * (`organization_members.notify_on_submission`, default true).
     *
     * Static and public because SendSubmissionStatusLink, the panel and the
     * tests all need the same definition, and duplicating a `wherePivot` is how
     * an opt-out quietly stops working.
     *
     * @return Collection<int, User>
     */
    public static function notifiableMembers(Conference $conference): Collection
    {
        /** @var Collection<int, User> $members */
        $members = $conference->organization
            ->members()
            ->wherePivot('notify_on_submission', true)
            ->get();

        return $members;
    }

    /**
     * The spec 5.9 placeholder bag for the two author-facing keys. One method
     * so a template that starts using `{{deadline}}` does not need a second
     * call site updated.
     *
     * @return array<string, string|null>
     */
    public static function placeholderValues(Submission $submission, string $authorName, string $token): array
    {
        $conference = $submission->conference;

        return [
            'author_name' => $authorName,
            'title' => (string) $submission->title,
            'reference' => $submission->reference,
            'conference' => (string) $conference->name,
            'organization' => (string) $conference->organization->name,
            'deadline' => $conference->deadlineInConferenceTimezone()?->format('j F Y, H:i').' ('.$conference->timezone.')',
            'status_link' => $submission->statusUrl($token),
        ];
    }

    /**
     * The one wording for "this is not a draft any more", said by blockers()
     * about the instance the caller holds and again by handle() about the
     * locked row. One method so the advisory answer and the answer that
     * actually refuses cannot drift apart.
     */
    private static function notADraft(SubmissionStatus $status): string
    {
        return $status === SubmissionStatus::Submitted
            ? 'This abstract has already been submitted.'
            : 'An abstract that is '.strtolower($status->getLabel()).' cannot be submitted.';
    }

    /** @return list<string> */
    private function customFieldBlockers(Conference $conference, Submission $submission): array
    {
        /** @var array<string, mixed> $values */
        $values = $submission->custom_field_values ?? [];
        $reasons = [];

        /** @var CustomField $field */
        foreach ($conference->customFields()->get() as $field) {
            $value = $values[$field->key] ?? null;
            $missing = $value === null || $value === '' || ($field->type === CustomFieldType::Checkbox && $value === false);

            if ($field->required && $missing) {
                $reasons[] = "\"{$field->label}\" is required.";

                continue;
            }

            if ($missing) {
                continue;
            }

            $valid = match ($field->type) {
                CustomFieldType::Number => is_numeric($value),
                CustomFieldType::Select => in_array($value, array_values((array) ($field->options ?? [])), true),
                CustomFieldType::Checkbox => is_bool($value),
                CustomFieldType::Text, CustomFieldType::Textarea => is_string($value) && mb_strlen($value) <= 5000,
            };

            if (! $valid) {
                $reasons[] = "\"{$field->label}\" does not have a valid answer.";
            }
        }

        return $reasons;
    }
}
