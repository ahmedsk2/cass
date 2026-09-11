<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Actions\Submissions\SaveSubmissionDraft;
use App\Actions\Submissions\SendSubmissionStatusLink;
use App\Actions\Submissions\SubmitAbstract;
use App\Actions\Submissions\UpdateSubmission;
use App\Enums\CustomFieldType;
use App\Enums\PresentationPreference;
use App\Enums\SubmissionWindow;
use App\Exceptions\SubmissionNotAcceptable;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Support\Branding\OrganizationTheme;
use App\Support\Text\WordCounter;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Spec 5.3 step 2: one form, used twice. It is the page at
 * /c/{org}/{conference}/submit for a new abstract, and it is mounted again by
 * the status page with an existing submission for "Edit". The only difference
 * between the two is which action the buttons call, which is why it is one
 * component and not two.
 *
 * The layout is chosen in render() with the ->layout() view macro rather than
 * with the #[Layout] attribute, because components/layouts/conference.blade.php
 * takes three props (organization, conference, theme) and an attribute's
 * parameters have to be compile-time constants. The macro sets
 * PageComponentConfig::$type to 'component', so the layout is rendered as a
 * Blade component and its @props receive these values as attributes - exactly
 * as they do from <x-layouts.conference> on the public conference page.
 */
class SubmissionForm extends Component
{
    /**
     * A sanity bound on submissions.abstract, not the rule an author meets:
     * that one is the conference's own word limit. It exists because the column
     * is finite and every public write path here has to stay inside it - MySQL
     * in strict mode answers an over-long value with SQLSTATE 22001, which is a
     * 500 on an unauthenticated endpoint, while SQLite accepts it silently and
     * no test would ever show it.
     */
    private const ABSTRACT_MAX_CHARACTERS = 20000;

    /**
     * More authors than any real abstract carries, and far fewer than a crafted
     * payload would send: `authors` is public and unlocked, so Livewire fills it
     * wholesale from the request and SaveSubmissionDraft::syncAuthors() inserts
     * one row per entry inside a single transaction.
     */
    private const MAX_AUTHORS = 50;

    /** Route-bound, and never re-resolved from the request after mount. */
    #[Locked]
    public Organization $organization;

    #[Locked]
    public Conference $conference;

    /**
     * Set only in edit mode. Locked so a crafted Livewire payload cannot point
     * the form at somebody else's abstract between requests - without this, the
     * token check in mount() would be a check on a value the client can change.
     */
    #[Locked]
    public ?Submission $submission = null;

    /**
     * The author's plaintext access token, when the status page handed one over.
     * It is in the component snapshot, which is signed but not encrypted - and
     * that is acceptable here and nowhere else, because the author reached this
     * component through a URL that already contains the same token in the
     * address bar. It is never rendered into the page and never logged.
     */
    #[Locked]
    public ?string $token = null;

    #[Locked]
    public bool $isPreview = false;

    /**
     * True when the window was open on the request that built this form. The
     * view keeps rendering the form while it is true, so a deadline that passes
     * mid-session turns into the error windowIsOpen() adds rather than into a
     * closed panel where the author's typing used to be. Locked: the server
     * decides this once, at mount.
     */
    #[Locked]
    public bool $windowWasOpen = false;

    public string $title = '';

    public string $abstract = '';

    public ?int $track_id = null;

    public string $presentation_preference = '';

    public string $contact_phone = '';

    /** @var list<array<string, mixed>> */
    public array $authors = [];

    /** @var array<string, mixed> */
    public array $custom = [];

    public bool $agreed = false;

    // Task 7 adds: public array $uploads = [], public string $website_confirm = '',
    // public int $openedAt = 0, public string $turnstileToken = '',
    // public bool $humanVerified = false.

    public function mount(Organization $organization, Conference $conference, ?Submission $submission = null, ?string $token = null): void
    {
        $isPublic = $organization->isApproved() && $conference->isPubliclyVisible();

        abort_if(! $isPublic && ! $this->canPreview($organization), 404);

        $this->organization = $organization;
        $this->conference = $conference;
        $this->isPreview = ! $isPublic;
        $this->windowWasOpen = $conference->submissionWindow() === SubmissionWindow::Open;
        $this->submission = $submission;
        $this->token = $token;

        if ($submission !== null) {
            $this->fillFromSubmission($submission);

            return;
        }

        $this->authors = [$this->blankAuthor(isCorresponding: true)];
        $this->presentation_preference = $this->presentationOptions()->keys()->first() ?? '';
    }

    // --- Author rows ----------------------------------------------------

    public function addAuthor(): void
    {
        $this->authors[] = $this->blankAuthor(isCorresponding: false);
    }

    public function removeAuthor(int $index): void
    {
        // There is always at least one row. An author with no rows cannot be
        // told anything, and the form would have no email field at all.
        if (count($this->authors) <= 1 || ! isset($this->authors[$index])) {
            return;
        }

        $wasCorresponding = (bool) ($this->authors[$index]['is_corresponding'] ?? false);

        unset($this->authors[$index]);
        $this->authors = array_values($this->authors);

        if ($wasCorresponding) {
            $this->makeCorresponding(0);
        }
    }

    /** Exactly one at a time - the radio behaviour a checkbox column cannot give. */
    public function makeCorresponding(int $index): void
    {
        foreach (array_keys($this->authors) as $i) {
            $this->authors[$i]['is_corresponding'] = $i === $index;
        }
    }

    // --- The two buttons ------------------------------------------------

    /**
     * Spec 5.3 step 3: a draft needs a title and somewhere to send the link.
     * Everything else can wait, because the whole point of a draft is that the
     * author is not finished.
     */
    public function saveDraft(SaveSubmissionDraft $save, UpdateSubmission $update, SendSubmissionStatusLink $sendLink): mixed
    {
        // Task 7 inserts the honeypot, the minimum-fill-time check, the
        // per-IP throttle and the Turnstile verification into this guard.
        if (! $this->isWritable() || ! $this->windowIsOpen()) {
            return null;
        }

        // The corresponding author is whichever row is ticked, not row zero -
        // SaveSubmissionDraft::syncAuthors() promotes the first ticked row, and
        // an empty address there is an abstract nobody can ever be told about.
        // `mixed` and not `array`: the property is filled wholesale from the
        // request, so a hand-made payload can put a scalar in it, and a typed
        // closure parameter would answer that with a 500 before validation ever
        // runs. `?? false` is safe on every type PHP can put here.
        $ticked = collect($this->authors)
            ->search(fn (mixed $author): bool => (bool) ($author['is_corresponding'] ?? false));
        $index = $ticked === false ? 0 : (int) $ticked;

        // A draft is deliberately permissive about what is *missing* (spec 5.3:
        // a title and an address are enough) and just as deliberately strict
        // about what is too long: every rule below bounds a column, so the same
        // over-long paste is a field error here and not an SQLSTATE 22001 there.
        $this->validate([
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'abstract' => ['nullable', 'string', 'max:'.self::ABSTRACT_MAX_CHARACTERS],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'authors' => ['array', 'min:1', 'max:'.self::MAX_AUTHORS],
            'authors.*.name' => ['nullable', 'string', 'max:180'],
            'authors.*.email' => ['nullable', 'email:rfc', 'max:255'],
            'authors.*.affiliation' => ['nullable', 'string', 'max:255'],
            "authors.{$index}.email" => ['required', 'email:rfc', 'max:255'],
        ], [], $this->validationAttributes());

        if ($this->submission !== null) {
            $update->handle($this->submission, $this->payload());

            // Task 7 stores pending uploads here, on this branch too - the
            // status-page edit form has the same files section, and returning
            // before it would drop an attachment behind a success flash.
            session()->flash('status', __('submission.flash.draft_updated'));

            return $this->redirect(route('submission.status', ['token' => $this->token]), navigate: false);
        }

        $link = $save->handle($this->conference, $this->payload());

        // Adopt the row immediately. Task 7 inserts an upload gate below this
        // line that can still refuse, and a retry must edit this draft rather
        // than mint a second abstract with a second token. Both properties are
        // #[Locked], so the server may write them and the client may not.
        $this->submission = $link->submission;
        $this->token = $link->token;

        // The same token the redirect uses, so the emailed link and the page
        // the author lands on are one URL.
        try {
            $sendLink->handle($link->submission, $link->token);
        } catch (SubmissionNotAcceptable) {
            // The draft is saved and the redirect below carries the same token,
            // so the author still lands on their abstract; only the email is
            // lost. The corresponding-author rule above is what stops this
            // happening at all - this catch exists because saveDraft() is a
            // public, unauthenticated entry point and an uncaught throw here
            // would be a 500 on top of a row that was written successfully.
        }

        session()->flash('status', __('submission.flash.draft_saved'));

        return $this->redirect($link->url() ?? route('conference.show', [$this->organization, $this->conference]), navigate: false);
    }

    public function submit(SaveSubmissionDraft $save, UpdateSubmission $update, SubmitAbstract $submitAbstract): mixed
    {
        // Task 7 inserts the honeypot, the minimum-fill-time check, the
        // per-IP throttle and the Turnstile verification into this guard.
        if (! $this->isWritable() || ! $this->windowIsOpen()) {
            return null;
        }

        $this->validate($this->submitRules(), [], $this->validationAttributes());

        if ($this->submission !== null) {
            $update->handle($this->submission, $this->payload());
            $submission = $this->submission->refresh();
            $token = $this->token;
        } else {
            $link = $save->handle($this->conference, $this->payload());
            $submission = $link->submission;
            $token = $link->token;

            // Adopt the draft immediately. Everything below this line can still
            // refuse - SubmitAbstract's own blockers, and the upload gate Task 7
            // inserts - and a retry must edit this row rather than create a
            // second abstract with a second token and a second copy of every
            // file. Both properties are #[Locked]: the server writes them, the
            // client cannot.
            $this->submission = $submission;
            $this->token = $token;
        }

        try {
            // The action re-checks everything against the stored row. Anything
            // it still refuses becomes a field error rather than an exception
            // page, because the author can usually fix it.
            $submission = $submitAbstract->handle($submission, $this->agreed, $token);
        } catch (SubmissionNotAcceptable $exception) {
            $this->reportBlockers($exception->reasons);

            return null;
        }

        session()->flash('status', __('submission.flash.submitted', ['reference' => $submission->reference]));

        return $this->redirect(
            $token === null
                ? route('conference.show', [$this->organization, $this->conference])
                : route('submission.status', ['token' => $token]),
            navigate: false,
        );
    }

    // --- Rendering ------------------------------------------------------

    public function render(): mixed
    {
        $theme = OrganizationTheme::for($this->organization);
        $window = $this->conference->submissionWindow();

        return view('livewire.public.submission-form', [
            'theme' => $theme,
            'window' => $window,
            // The window notice is shown whenever the window is not open; the
            // form is a separate decision. A member previewing an unpublished
            // conference has no window at all and must still see the form the
            // preview exists for, and an author whose deadline passed while the
            // page was open must keep the text they typed. Both are refused by
            // isWritable() / windowIsOpen() on the way in, with a message,
            // rather than by a view that removes the fields.
            'showForm' => $this->isPreview || $window === SubmissionWindow::Open || $this->windowWasOpen,
            'tracks' => $this->tracks(),
            'customFields' => $this->customFields(),
            'presentationOptions' => $this->presentationOptions(),
            'wordCount' => WordCounter::count($this->abstract),
        ])->layout('components.layouts.conference', [
            'organization' => $this->organization,
            'conference' => $this->conference,
            'theme' => $theme,
        ]);
    }

    // --- Internals ------------------------------------------------------

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'title' => $this->title,
            'abstract' => $this->abstract,
            'track_id' => $this->track_id,
            'presentation_preference' => $this->presentation_preference === '' ? null : $this->presentation_preference,
            'contact_phone' => $this->contact_phone,
            'custom_field_values' => $this->custom,
            'authors' => $this->authors,
        ];
    }

    /** @return array<string, mixed> */
    private function submitRules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'abstract' => [
                'required', 'string',
                // Characters as well as words: the rule below counts one 100 KB
                // token as a single word, so it bounds the abstract an author
                // writes but not the bytes the column has to hold.
                'max:'.self::ABSTRACT_MAX_CHARACTERS,
                // The server-side word limit, expressed where the author sees
                // it. SubmitAbstract checks it again against the stored row.
                function (string $attribute, mixed $value, callable $fail): void {
                    $words = WordCounter::count((string) $value);
                    $limit = (int) $this->conference->word_limit;

                    if ($words > $limit) {
                        $fail(__('submission.errors.word_limit', ['words' => $words, 'limit' => $limit]));
                    }
                },
            ],
            'track_id' => ['nullable', Rule::in($this->tracks()->pluck('id')->all())],
            'presentation_preference' => ['required', Rule::in($this->presentationOptions()->keys()->all())],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'authors' => ['array', 'min:1', 'max:'.self::MAX_AUTHORS],
            'authors.*.name' => ['required', 'string', 'max:180'],
            'authors.*.email' => ['required', 'email:rfc', 'max:255'],
            'authors.*.affiliation' => ['nullable', 'string', 'max:255'],
            'agreed' => ['accepted'],
        ];

        foreach ($this->customFields() as $field) {
            $rules['custom.'.$field->key] = $this->customFieldRules($field);
        }

        return $rules;
    }

    /** @return list<mixed> */
    private function customFieldRules(CustomField $field): array
    {
        $rules = [$field->required ? 'required' : 'nullable'];

        return [...$rules, ...match ($field->type) {
            CustomFieldType::Number => ['numeric'],
            CustomFieldType::Select => [Rule::in(array_values((array) ($field->options ?? [])))],
            // `accepted` rather than `boolean` for a required checkbox: a
            // required yes/no question means yes.
            CustomFieldType::Checkbox => $field->required ? ['accepted'] : ['boolean'],
            CustomFieldType::Text => ['string', 'max:255'],
            CustomFieldType::Textarea => ['string', 'max:5000'],
        }];
    }

    /** @return array<string, string> */
    private function validationAttributes(): array
    {
        $attributes = [];

        foreach ($this->customFields() as $field) {
            $attributes['custom.'.$field->key] = (string) $field->label;
        }

        foreach (array_keys($this->authors) as $index) {
            $attributes["authors.{$index}.name"] = __('submission.authors.name_of', ['position' => $index + 1]);
            $attributes["authors.{$index}.email"] = __('submission.authors.email_of', ['position' => $index + 1]);
            $attributes["authors.{$index}.affiliation"] = __('submission.authors.affiliation_of', ['position' => $index + 1]);
        }

        return $attributes;
    }

    /**
     * Turns the action's sentences back into field errors where the field is
     * obvious, and into one form-level error where it is not. Without this a
     * server-only refusal (a presentation type removed from the conference
     * while the author was typing) would look like a button that does nothing.
     *
     * @param  list<string>  $reasons
     */
    private function reportBlockers(array $reasons): void
    {
        foreach ($reasons as $reason) {
            $field = match (true) {
                str_contains($reason, 'title') => 'title',
                str_contains($reason, 'words') || str_contains($reason, 'abstract') => 'abstract',
                str_contains($reason, 'track') => 'track_id',
                str_contains($reason, 'presentation') => 'presentation_preference',
                str_contains($reason, 'author') => 'authors.0.email',
                str_contains($reason, 'terms') => 'agreed',
                default => 'title',
            };

            $this->addError($field, $reason);
        }
    }

    /**
     * False when the window has closed, with the error already on the page and
     * the author's text still in the textarea - which is the whole point.
     *
     * A boolean rather than an abort() or a throw, and the same shape as the
     * passesBotChecks() Task 7 adds, because Livewire rethrows anything that is
     * not a ValidationException (vendor/livewire/livewire/src/Wrapped.php): a
     * SubmissionNotAcceptable out of a Livewire action is a 500 page on a
     * public, unauthenticated endpoint - it would throw away exactly the typing
     * the message is trying to save, and a component test would error instead
     * of asserting the field error.
     */
    private function windowIsOpen(): bool
    {
        if ($this->conference->acceptsSubmissions()) {
            return true;
        }

        $this->addError('title', __('submission.errors.window_closed'));

        return false;
    }

    /**
     * A preview shows the form; it never writes. The badge already tells the
     * member the page is not public - saving from it would create abstracts,
     * burn reference numbers off conferences.submission_counter and queue
     * branded email for a conference, or an organization, the platform has
     * taken offline, while /s/{token} 404s so the author never sees any of it.
     */
    private function isWritable(): bool
    {
        if (! $this->isPreview) {
            return true;
        }

        $this->addError('title', __('submission.errors.preview_readonly'));

        return false;
    }

    /** @return Collection<string, string> */
    private function presentationOptions(): Collection
    {
        /** @var list<string> $offered */
        $offered = $this->conference->presentation_types ?? [];

        return collect(PresentationPreference::cases())
            ->filter(fn (PresentationPreference $case): bool => in_array($case->value, $offered, true))
            ->mapWithKeys(fn (PresentationPreference $case): array => [$case->value => $case->getLabel()]);
    }

    /** @var \Illuminate\Database\Eloquent\Collection<int, CustomField>|null */
    private ?\Illuminate\Database\Eloquent\Collection $customFieldCache = null;

    /** @var \Illuminate\Database\Eloquent\Collection<int, Track>|null */
    private ?\Illuminate\Database\Eloquent\Collection $trackCache = null;

    /**
     * Livewire builds a fresh component for every request, so these two are
     * per-request memos and cannot go stale between requests. They exist
     * because spec section 10 gives this page the same 300 ms budget as the
     * conference page, and one render plus one validate asked for the same two
     * lists five times.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CustomField>
     */
    private function customFields(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->customFieldCache ??= $this->conference->customFields()->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Track> */
    private function tracks(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->trackCache ??= $this->conference->tracks()->get();
    }

    /** @return array<string, mixed> */
    private function blankAuthor(bool $isCorresponding): array
    {
        return [
            'name' => '',
            'email' => '',
            'affiliation' => '',
            'is_presenter' => $isCorresponding,
            'is_corresponding' => $isCorresponding,
        ];
    }

    private function fillFromSubmission(Submission $submission): void
    {
        $this->title = (string) $submission->title;
        $this->abstract = (string) $submission->abstract;
        $this->track_id = $submission->track_id === null ? null : (int) $submission->track_id;
        // `->` and not `?->`, exactly as in NewSubmissionNotice: the null
        // coalesce already swallows a property read on null, so the nullsafe is
        // redundant and Larastan says so (nullsafe.neverNull).
        $this->presentation_preference = $submission->presentation_preference->value ?? '';
        $this->contact_phone = (string) $submission->contact_phone;
        $this->custom = (array) ($submission->custom_field_values ?? []);
        // Editing an abstract that was already submitted means the terms were
        // already accepted; asking again on every edit is friction with no
        // added consent.
        $this->agreed = $submission->submitted_at !== null;

        $this->authors = $submission->authors->map(fn ($author): array => [
            'name' => (string) $author->name,
            'email' => (string) $author->email,
            'affiliation' => (string) $author->affiliation,
            'is_presenter' => (bool) $author->is_presenter,
            'is_corresponding' => (bool) $author->is_corresponding,
        ])->values()->all();

        if ($this->authors === []) {
            $this->authors = [$this->blankAuthor(isCorresponding: true)];
        }
    }

    /** The same rule the public conference page applies (Plan 2, Task 4). */
    private function canPreview(Organization $organization): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasVerifiedEmail()
            && $user->roleIn($organization) !== null;
    }
}
