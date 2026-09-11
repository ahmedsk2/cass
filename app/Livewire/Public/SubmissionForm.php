<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Actions\Submissions\DeleteSubmissionFile;
use App\Actions\Submissions\SaveSubmissionDraft;
use App\Actions\Submissions\SendSubmissionStatusLink;
use App\Actions\Submissions\StoreSubmissionFile;
use App\Actions\Submissions\SubmitAbstract;
use App\Actions\Submissions\UpdateSubmission;
use App\Enums\CustomFieldType;
use App\Enums\PresentationPreference;
use App\Enums\SubmissionWindow;
use App\Exceptions\SubmissionFileRejected;
use App\Exceptions\SubmissionNotAcceptable;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\Track;
use App\Models\User;
use App\Support\Branding\OrganizationTheme;
use App\Support\ClientIp;
use App\Support\Text\WordCounter;
use App\Support\Turnstile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

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
    use WithFileUploads;

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

    /**
     * Pending uploads, not yet stored. They stay in Livewire's temporary-upload
     * directory until a submission row exists to attach them to - which for a
     * brand new abstract is only after SaveSubmissionDraft has run.
     *
     * `mixed` and not `list<TemporaryUploadedFile>`, deliberately: the property
     * is public and unlocked, so Livewire fills it wholesale from the request
     * and a crafted payload can put a string - or anything else - in it. The
     * docblock says what a client may send, not what the component wishes it
     * sent; pendingUploads() is what turns it back into a list of files, and
     * every reader goes through that.
     *
     * @var array<array-key, mixed>
     */
    public array $uploads = [];

    /** Honeypot. A real author never sees it, so a value in it is a bot. */
    public string $website_confirm = '';

    /**
     * When the form was mounted. #[Locked] so the client cannot backdate it,
     * which is the only thing that makes the minimum-fill-time check mean
     * anything.
     */
    #[Locked]
    public int $openedAt = 0;

    public string $turnstileToken = '';

    /**
     * A Turnstile token is single-use. Once Cloudflare has said yes for this
     * component instance, a later validation failure must not re-redeem it:
     * the second answer is `timeout-or-duplicate`, and the widget sits inside
     * wire:ignore, so nothing would mint a replacement until its own
     * refresh-expired timer fires minutes later. #[Locked] because it is a
     * server-side fact about a past HTTP call, not a form field.
     */
    #[Locked]
    public bool $humanVerified = false;

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
        $this->openedAt = now()->timestamp;

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

    // --- Files ----------------------------------------------------------

    /**
     * Runs on every change to the uploads array. Size and count are cheap and
     * are the two an author gets wrong most often, so they are answered here
     * rather than after the whole form is filled in.
     */
    public function updatedUploads(): void
    {
        // First, before anything else can look at the array, and before the
        // rules below can throw. A ValidationException out of this hook does
        // *not* stop the response: Wrapped::__call triggers the `exception`
        // hook, SupportValidation stops propagation, and Livewire renders the
        // component anyway - at which point the files partial calls
        // getClientOriginalName() on whatever the client sent. Without this
        // line, `uploads=["x"]` is a fatal Error on a public, unauthenticated
        // page.
        $this->uploads = $this->pendingUploads();

        $this->validate($this->uploadRules(), [], ['uploads' => __('submission.files.label')]);
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
        $this->resetErrorBag('uploads');
    }

    /** Edit mode only: drop a file that is already stored. */
    public function deleteFile(string $ulid, DeleteSubmissionFile $delete): void
    {
        if ($this->submission === null || ! $this->submission->isOpenToAuthor()) {
            return;
        }

        $file = $this->submission->files()->where('ulid', $ulid)->first();

        if ($file instanceof SubmissionFile) {
            $delete->handle($file);
            $this->submission->unsetRelation('files');
        }
    }

    // --- The two buttons ------------------------------------------------

    /**
     * Spec 5.3 step 3: a draft needs a title and somewhere to send the link.
     * Everything else can wait, because the whole point of a draft is that the
     * author is not finished.
     */
    public function saveDraft(SaveSubmissionDraft $save, UpdateSubmission $update, SendSubmissionStatusLink $sendLink, StoreSubmissionFile $store): mixed
    {
        if (! $this->isWritable() || ! $this->passesBotChecks() || ! $this->windowIsOpen()) {
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

        // The attributes are passed explicitly, exactly as every other
        // validate() call in this component does. Livewire's
        // getValidationAttributes() fallback uses method_exists(), which is true
        // for this component's *private* validationAttributes(), and then calls
        // it from outside the class - so a bare $this->validate($rules) here
        // would be a BadMethodCallException through __call, not a validation.
        $this->validate($this->uploadRules(), [], ['uploads' => __('submission.files.label')]);

        if ($this->submission !== null) {
            try {
                // The window was open when this request started, but the
                // abstract itself may no longer be open to its author: an
                // organizer can withdraw it while the form is on screen, and a
                // deleted conference closes it too. UpdateSubmission answers
                // that with SubmissionNotAcceptable, and Livewire rethrows
                // anything that is not a ValidationException - a 500 on a
                // public, unauthenticated page, over the author's own typing.
                $update->handle($this->submission, $this->payload());
            } catch (SubmissionNotAcceptable $exception) {
                $this->reportBlockers($exception->reasons);

                return null;
            }

            // The status-page edit form has the same files section, and
            // returning before this would drop an attachment behind a success
            // flash.
            if (! $this->storeUploads($this->submission, $store)) {
                return null;
            }

            session()->flash('status', __('submission.flash.draft_updated'));

            // The same guard submit() applies at the end of this class, for the
            // same reason: `token` is nullable, and route() with a null
            // parameter is a UrlGenerationException - a 500 thrown over an edit
            // that has already been written.
            return $this->redirect(
                $this->token === null
                    ? route('conference.show', [$this->organization, $this->conference])
                    : route('submission.status', ['token' => $this->token]),
                navigate: false,
            );
        }

        $link = $save->handle($this->conference, $this->payload());

        // Adopt the row immediately. The upload gate below this line can still
        // refuse, and a retry must edit this draft rather than mint a second
        // abstract with a second token. Both properties are #[Locked], so the
        // server may write them and the client may not.
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

        if (! $this->storeUploads($link->submission, $store)) {
            return null;
        }

        session()->flash('status', __('submission.flash.draft_saved'));

        return $this->redirect($link->url() ?? route('conference.show', [$this->organization, $this->conference]), navigate: false);
    }

    public function submit(SaveSubmissionDraft $save, UpdateSubmission $update, SubmitAbstract $submitAbstract, StoreSubmissionFile $store): mixed
    {
        if (! $this->isWritable() || ! $this->passesBotChecks() || ! $this->windowIsOpen()) {
            return null;
        }

        $this->validate($this->submitRules(), [], $this->validationAttributes());

        if ($this->submission !== null) {
            try {
                // Same refusal as saveDraft()'s, for the same reason: the
                // abstract can stop being the author's to change between the
                // render that drew this form and the press of the button.
                $update->handle($this->submission, $this->payload());
            } catch (SubmissionNotAcceptable $exception) {
                $this->reportBlockers($exception->reasons);

                return null;
            }

            $submission = $this->submission->refresh();
            $token = $this->token;
        } else {
            $link = $save->handle($this->conference, $this->payload());
            $submission = $link->submission;
            $token = $link->token;

            // Adopt the draft immediately. Everything below this line can still
            // refuse - SubmitAbstract's own blockers, and the upload gate below
            // - and a retry must edit this row rather than create a second
            // abstract with a second token and a second copy of every file.
            // Both properties are #[Locked]: the server writes them, the client
            // cannot.
            $this->submission = $submission;
            $this->token = $token;
        }

        // Validated before the row is touched (count, size, extension) and
        // stored after it exists (content sniff). A content mismatch therefore
        // leaves a draft behind, which is the friendliest failure available:
        // the author fixes the file and presses Submit again - and because the
        // branch above adopted the row into $this->submission, that second
        // press edits the same abstract instead of creating another one.
        if (! $this->storeUploads($submission, $store)) {
            return null;
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
            'storedFiles' => $this->submission?->files()->get() ?? collect(),
            'turnstileSiteKey' => Turnstile::siteKey(),
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
            ...$this->uploadRules(),
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
        // The organization as well as the window. mount() checks approval once,
        // and every later Livewire request re-checks only the conference - so a
        // page opened before a suspension could still create abstracts, burn
        // reference numbers and queue branded email for an organization the
        // platform has taken offline, while /s/{token} 404s so the author never
        // sees any of it. An archived conference is caught by the window; a
        // suspended organization was not. Both #[Locked] properties are
        // re-resolved from the database on every request, so this reads the
        // current row and not the one mount() saw.
        if ($this->organization->isApproved() && $this->conference->acceptsSubmissions()) {
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

    /**
     * Spec 5.3: honeypot, per-IP rate limit, Turnstile when configured, plus a
     * minimum fill time.
     *
     * A filled honeypot returns *true from the caller's point of view* -
     * `handledAsBot()` sets a redirect and the caller stops - because telling a
     * bot which check it failed is telling it which check to remove. Every
     * other refusal is a visible error, because a person hitting one has done
     * nothing wrong and needs to know what happened.
     */
    private function passesBotChecks(): bool
    {
        $key = 'submission:'.ClientIp::from(request());

        // A separate key for the penalties. RateLimiter::hit() sets the decay
        // only on the *first* hit for a key (Illuminate\Cache\RateLimiter::
        // increment uses cache->add), so mixing a 600-second penalty and the
        // 60-second budget on one key would make the limit five per ten minutes
        // for everyone behind that address - not the 5/min/IP spec section 9
        // gives real authors, who on a conference NAT share one IP.
        $penaltyKey = 'submission-penalty:'.ClientIp::from(request());

        if ($this->website_confirm !== '') {
            RateLimiter::hit($penaltyKey, 600);
            $this->handledAsBot();

            return false;
        }

        if (now()->timestamp - $this->openedAt < (int) config('cass.submission_min_seconds')) {
            RateLimiter::hit($penaltyKey, 600);
            $this->addError('title', __('submission.errors.too_fast'));

            return false;
        }

        if (RateLimiter::tooManyAttempts($penaltyKey, (int) config('cass.submission_rate_limit'))
            || RateLimiter::tooManyAttempts($key, (int) config('cass.submission_rate_limit'))) {
            $this->addError('title', __('submission.errors.too_many', [
                'seconds' => max(RateLimiter::availableIn($key), RateLimiter::availableIn($penaltyKey)),
            ]));

            return false;
        }

        RateLimiter::hit($key, 60);

        // Only once per component instance. This method runs before
        // $this->validate(), so a submit that fails on the word limit has
        // already been here - and re-redeeming the same token would answer
        // `timeout-or-duplicate`, locking the author out of their own form.
        if (! $this->humanVerified) {
            if (! Turnstile::verify($this->turnstileToken === '' ? null : $this->turnstileToken, ClientIp::from(request()))) {
                // The widget mints a single-use token; a failed verification
                // means the author has to solve it again, so clear it and ask
                // the widget - which lives inside wire:ignore - for a new one.
                $this->turnstileToken = '';
                $this->dispatch('turnstile-reset');
                $this->addError('turnstileToken', __('submission.errors.turnstile'));

                return false;
            }

            $this->humanVerified = true;
        }

        return true;
    }

    /** Looks exactly like success and writes nothing. */
    private function handledAsBot(): void
    {
        session()->flash('status', __('submission.flash.draft_saved'));

        $this->redirect(route('conference.show', [$this->organization, $this->conference]), navigate: false);
    }

    /** @return array<string, mixed> */
    private function uploadRules(): array
    {
        $remaining = max(0, (int) $this->conference->max_files - $this->storedFileCount());

        return [
            'uploads' => ['array', 'max:'.$remaining],
            'uploads.*' => [
                'file',
                // Kilobytes, which is what the `max` rule speaks.
                'max:'.(int) floor((int) config('cass.max_file_bytes') / 1024),
                'extensions:'.implode(',', $this->conference->allowedFileTypes()),
            ],
        ];
    }

    private function storedFileCount(): int
    {
        return $this->submission?->files()->count() ?? 0;
    }

    /**
     * Called after the row exists and before the submit transition. A rejection
     * here leaves the abstract as a saved draft with a visible error rather
     * than throwing the author's typing away - which is why the caller checks
     * the return value instead of catching an exception.
     */
    private function storeUploads(Submission $submission, StoreSubmissionFile $store): bool
    {
        // The same normalisation updatedUploads() applies, repeated because
        // this method hands each entry straight to an action and is reached
        // from two public, unauthenticated buttons.
        $pending = $this->pendingUploads();
        $this->uploads = $pending;

        foreach ($pending as $index => $upload) {
            try {
                $store->handle($submission, $upload);
            } catch (SubmissionFileRejected $exception) {
                // Only what has *not* been stored is still pending. Clearing the
                // list wholesale at the end would leave an already-stored file
                // in it on the way out of this branch, and the next Submit would
                // hand the same bytes to StoreSubmissionFile again - which
                // answers duplicate(). The author would be stuck on an error
                // about a file they never attached twice, unable to clear it
                // without also dropping the one that worked.
                $this->uploads = array_values($this->uploads);
                $this->addError('uploads', $exception->getMessage());

                return false;
            }

            unset($this->uploads[$index]);
        }

        $this->uploads = array_values($this->uploads);

        return true;
    }

    /**
     * `uploads` as the list of real temporary uploads it is supposed to be.
     *
     * Everything that reads the array comes through here, because the property
     * is public, unlocked and filled wholesale from the request: the view would
     * otherwise call getClientOriginalName() on a crafted string, and
     * StoreSubmissionFile would be handed something that is not a file at all.
     *
     * @return list<TemporaryUploadedFile>
     */
    private function pendingUploads(): array
    {
        return array_values(array_filter(
            $this->uploads,
            static fn (mixed $upload): bool => $upload instanceof TemporaryUploadedFile,
        ));
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
