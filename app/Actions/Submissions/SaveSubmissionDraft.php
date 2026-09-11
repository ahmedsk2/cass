<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\PresentationPreference;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Submission;
use App\Support\Submissions\SubmissionLink;
use App\Support\Text\WordCounter;
use App\Support\Tokens\SubmissionToken;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one write path for an abstract's own fields. Both public buttons go
 * through it: "Save draft" calls it and stops, "Submit" calls it and then calls
 * SubmitAbstract.
 *
 * It is permissive by design - spec 5.3 says a draft needs only a title and a
 * corresponding address - but it is not credulous: the three things it refuses
 * to store are a cross-conference track, a custom-field key the conference does
 * not define, and anything the caller invented for a guarded column. Those are
 * not validation failures to report, they are values that must never reach the
 * database, so they are dropped silently here and reported properly by
 * SubmitAbstract::blockers() where the author can still act on them.
 */
class SaveSubmissionDraft
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Conference $conference, array $data, ?Submission $existing = null): SubmissionLink
    {
        // conference_id is force-filled below on every save, new row or not, so
        // a mismatched pair would move an existing abstract into another
        // organization's conference - and, because the track and custom-field
        // filters are applied against the conference that was passed in, wipe
        // both on the way. Nothing but caller discipline prevented it, and a
        // cross-tenant write is not a thing to leave to discipline. It is an
        // argument error rather than a SubmissionNotAcceptable: no author can
        // act on it and no author can cause it.
        if ($existing !== null && $existing->exists && (int) $existing->conference_id !== (int) $conference->getKey()) {
            throw new InvalidArgumentException(
                'This abstract belongs to another conference. An abstract is never moved between conferences.'
            );
        }

        return DB::transaction(function () use ($conference, $data, $existing): SubmissionLink {
            $submission = $existing ?? new Submission;
            $isNew = ! $submission->exists;

            $abstract = trim((string) ($data['abstract'] ?? ''));

            $submission->fill([
                'title' => trim((string) ($data['title'] ?? '')),
                'abstract' => $abstract,
                'track_id' => $this->trackId($conference, $data['track_id'] ?? null),
                'presentation_preference' => $this->presentationPreference($data['presentation_preference'] ?? null),
                'contact_phone' => $this->nullIfBlank($data['contact_phone'] ?? null),
                'custom_field_values' => $this->customFieldValues($conference, $data['custom_field_values'] ?? null),
            ]);

            // Guarded columns, all of them written here and nowhere else on
            // this path. word_count is recomputed rather than taken from the
            // page: the live counter in the browser is a convenience, not a
            // source of truth.
            $submission->forceFill([
                'conference_id' => $conference->getKey(),
                'word_count' => WordCounter::count($abstract),
                'last_edited_at' => now(),
            ]);

            // The real hash goes in with the INSERT. A shared placeholder in a
            // UNIQUE char(64) makes every concurrent first save queue on one
            // index record for the length of this transaction - which also
            // inserts every author row - so the hour before a deadline, when
            // the most authors are saving at once, is exactly when it costs the
            // most: lock-wait timeouts on MySQL for a query SQLite serialises
            // anyway, so no test in this suite would ever show it.
            $token = null;

            if ($isNew) {
                $token = SubmissionToken::generate();

                $submission->forceFill([
                    'status' => SubmissionStatus::Draft,
                    'access_token_hash' => SubmissionToken::hash($token),
                ]);
            }

            $submission->save();

            $this->syncAuthors($submission, is_array($data['authors'] ?? null) ? $data['authors'] : []);

            return new SubmissionLink($submission->refresh()->load('authors'), $token);
        });
    }

    /**
     * Replaced wholesale, not merged. The form sends the complete list every
     * time, and merging would resurrect an author the author deleted.
     *
     * Exactly one corresponding author always comes out: the first one ticked,
     * or the first author if nobody was ticked. spec 5.3 makes the
     * corresponding address the only way to reach this person, so a draft
     * without one would be a draft nobody could ever be told about.
     *
     * @param  list<array<string, mixed>>  $authors
     */
    private function syncAuthors(Submission $submission, array $authors): void
    {
        $submission->authors()->delete();

        $rows = [];
        $sort = 0;

        foreach ($authors as $author) {
            $email = mb_strtolower(trim((string) ($author['email'] ?? '')));
            $name = trim((string) ($author['name'] ?? ''));

            if ($email === '' && $name === '') {
                continue; // An empty row the author added and never filled in.
            }

            $rows[] = [
                'sort' => ++$sort,
                'name' => $name,
                'email' => $email,
                'affiliation' => $this->nullIfBlank($author['affiliation'] ?? null),
                'is_presenter' => (bool) ($author['is_presenter'] ?? false),
                'is_corresponding' => (bool) ($author['is_corresponding'] ?? false),
            ];
        }

        $corresponding = null;
        foreach ($rows as $index => $row) {
            if ($row['is_corresponding']) {
                $corresponding = $corresponding ?? $index;
            }
            $rows[$index]['is_corresponding'] = false;
        }

        if ($rows !== []) {
            $rows[$corresponding ?? array_key_first($rows)]['is_corresponding'] = true;
        }

        foreach ($rows as $row) {
            $submission->authors()->create($row);
        }

        $submission->unsetRelation('authors');
    }

    /**
     * A choice the enum does not define is dropped, exactly like a foreign
     * track, and reported by SubmitAbstract::blockers() instead.
     *
     * Not passed straight through: the column is cast to PresentationPreference
     * and Eloquent's enum cast calls `PresentationPreference::from()` on the way
     * in, so an unrecognised string is an uncaught ValueError - a 500 on the
     * public save-draft path, whose own validation covers only the title and
     * the corresponding address.
     */
    private function presentationPreference(mixed $value): ?string
    {
        if ($value instanceof PresentationPreference) {
            return $value->value;
        }

        return is_string($value) ? PresentationPreference::tryFrom($value)?->value : null;
    }

    /** A track must belong to this conference or be absent. */
    private function trackId(Conference $conference, mixed $trackId): ?int
    {
        if (! is_numeric($trackId)) {
            return null;
        }

        return $conference->tracks()->whereKey((int) $trackId)->exists() ? (int) $trackId : null;
    }

    /**
     * Only keys the conference actually defines survive. The column is JSON and
     * is rendered back into a form, so an arbitrary key from a hand-made
     * request would otherwise be stored for ever and shown to organizers.
     *
     * @return array<string, mixed>|null
     */
    private function customFieldValues(Conference $conference, mixed $values): ?array
    {
        if (! is_array($values) || $values === []) {
            return null;
        }

        /** @var list<string> $keys */
        $keys = $conference->customFields()->pluck('key')->all();

        $filtered = array_intersect_key($values, array_flip($keys));

        return $filtered === [] ? null : $filtered;
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }
}
