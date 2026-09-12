<?php

declare(strict_types=1);

namespace App\Actions\Demo;

use App\Actions\Conferences\CloseSubmissions;
use App\Actions\Conferences\CreateConference;
use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Actions\Conferences\PublishConference;
use App\Actions\Conferences\StartReviewing;
use App\Actions\Decisions\ApplyDecision;
use App\Actions\Invitations\AcceptInvitation;
use App\Actions\Mail\SendTemplatedEmail;
use App\Actions\Organizations\ApproveOrganization;
use App\Actions\Reviewers\InviteReviewer;
use App\Actions\Reviews\SaveReviewDraft;
use App\Actions\Reviews\SubmitReview;
use App\Actions\Submissions\SaveSubmissionDraft;
use App\Actions\Submissions\StoreSubmissionFile;
use App\Actions\Submissions\SubmitAbstract;
use App\Actions\Submissions\WithdrawSubmission;
use App\Enums\CustomFieldType;
use App\Enums\Decision;
use App\Enums\DemoStage;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Enums\ReviewMode;
use App\Enums\ReviewQuestionType;
use App\Enums\ReviewStatus;
use App\Enums\SubmissionStatus;
use App\Exceptions\DemoRefused;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\User;
use App\Support\Demo\DemoAbstracts;
use App\Support\Demo\DemoPdf;
use App\Support\Demo\DemoSeedReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Everything behind `php artisan cass:demo-seed`.
 *
 * The point of this class is that the owner can drive the *whole* loop on the
 * live site - publish, submit, close, review, rank, decide, send - without
 * typing fifteen abstracts first, and can then remove every trace of it with
 * `cass:demo-reset`. So it goes through the real actions wherever one exists;
 * the two places it cannot are marked with the reason.
 *
 * **It never sends anything.** See silenceOutgoingMail(): nothing leaves, and
 * no `email_logs` row is written either, so an organizer opening the mail log
 * after the demo does not find a hundred letters nobody received. Nothing is
 * restored afterwards, because nothing persistent is changed - this runs in a
 * one-shot console process whose container dies with it.
 *
 * **Idempotence is the caller's job**, and it is by slug: the command refuses to
 * run at all while `demo-society` exists. Half-seeding then resuming would mean
 * every step below having to decide what it meant to find its own work already
 * there, and the reset command is one line.
 */
final class SeedDemo
{
    public const ORGANIZATION_SLUG = 'demo-society';

    public const ORGANIZATION_NAME = 'Demo Pediatric Society';

    public const CONFERENCE_SLUG = 'demo-2027';

    public const CONFERENCE_NAME = 'Demo Pediatric Critical Care Conference 2027';

    public const REFERENCE_PREFIX = 'DEMO27';

    /** How many of the twelve submitted abstracts get two submitted reviews. */
    private const REVIEWED = 8;

    /** How many get a draft from the third reviewer and nothing else. */
    private const DRAFTED = 2;

    /** @var list<array{email: string, name: string, affiliation: string}> */
    public const REVIEWERS = [
        ['email' => 'demo.reviewer1@example.com', 'name' => 'Salim Al-Hinai', 'affiliation' => 'Coastal Children\'s Hospital, Muscat'],
        ['email' => 'demo.reviewer2@example.com', 'name' => 'Amina Al-Saleh', 'affiliation' => 'Demo Children\'s Hospital, Riyadh'],
        ['email' => 'demo.reviewer3@example.com', 'name' => 'Kareem Al-Attiyah', 'affiliation' => 'Riverside Paediatric Institute, Doha'],
    ];

    /** @var list<array{name: string, description: string}> */
    private const TRACKS = [
        ['name' => 'Sepsis and shock', 'description' => 'Recognition, resuscitation, haemodynamics and antimicrobial timing.'],
        ['name' => 'Respiratory support', 'description' => 'Non-invasive and invasive ventilation, weaning and extubation.'],
        ['name' => 'Quality improvement', 'description' => 'Safety, human factors, pathways and measurement.'],
    ];

    /** @var list<string> */
    private const COMMENTS = [
        'Clear question and an honest discussion of the limitation. The single-centre design is stated up front rather than buried, which I appreciated. Worth an oral slot if the programme has room.',
        'Methods are appropriate and the denominator is defined. I would want the confidence intervals in the presentation itself rather than only in the poster.',
        'Interesting and well written, but the conclusion reaches slightly beyond what a retrospective cohort of this size can support. Softening it would make the abstract stronger, not weaker.',
        'A useful improvement project with a measurable outcome and a plausible mechanism. The sustainability question is the one the audience will ask first.',
        'The topic matters and the data are clean, though the analysis adds little beyond descriptive statistics. Better suited to a poster where a conversation is possible.',
        'Well constructed, and the negative finding is reported as carefully as the positive ones. I would be happy to see this presented in the main hall.',
    ];

    /** @var array<string, string> */
    private const DECISION_NOTES = [
        'accepted_oral' => 'Ranked in the top group by the committee; scheduled for a main-hall slot.',
        'accepted_poster' => 'Accepted for poster presentation; strong work with a narrower audience.',
        'waitlisted' => 'Held pending the final programme; not yet reviewed by two reviewers.',
        'rejected' => 'Not accepted for this meeting; the authors are encouraged to resubmit next year.',
    ];

    /** @return list<string> */
    public static function reviewerEmails(): array
    {
        return array_map(static fn (array $reviewer): string => $reviewer['email'], self::REVIEWERS);
    }

    /**
     * The `demo-society` row, trashed or not. Trashed counts: Organization
     * soft-deletes and `Organization::uniqueSlug()` checks `withTrashed()`, so
     * a trashed row would still take the slug and the seeder would quietly
     * publish at `demo-society-2`.
     */
    public function existingDemoOrganization(): ?Organization
    {
        return Organization::withTrashed()->where('slug', self::ORGANIZATION_SLUG)->first();
    }

    /**
     * The account that will own the demo organization. It has to exist already
     * and nothing here ever writes to it - not the name, and above all not the
     * password: this is the platform owner's own login.
     */
    public function owner(?string $email): User
    {
        $email = mb_strtolower(trim($email ?? (string) config('cass.admin_email')));

        if ($email === '') {
            throw DemoRefused::because(
                'No owner address. Pass --owner-email, or set CASS_ADMIN_EMAIL so the platform admin owns the demo organization.'
            );
        }

        $owner = User::query()->where('email', $email)->first();

        if (! $owner instanceof User) {
            throw DemoRefused::because(
                "No account exists for [{$email}]. The demo owner has to be an existing user; this command never creates one, and never changes a password."
            );
        }

        return $owner;
    }

    public function handle(DemoStage $stage, User $owner, ?string $reviewerPassword = null): DemoSeedReport
    {
        $this->silenceOutgoingMail();

        $generated = $reviewerPassword === null
            ? Str::password(16, symbols: false, spaces: false)
            : null;

        $password = $reviewerPassword ?? (string) $generated;

        $organization = $this->organization($owner);
        $conference = $this->conference($organization, $owner);

        $this->submissions($conference, $owner);

        // Deliberately at every stage, not only from `reviewing` on.
        // StartReviewing refuses a conference with no active reviewer, so they
        // have to exist before that transition anyway - and seeding them at
        // `open` as well means the summary's three addresses and one password
        // are true whichever stage was asked for, and the owner can walk the
        // reviewer panel from an empty queue forwards.
        $reviewers = $this->reviewers($conference, $owner, $password);

        if ($stage->reaches(DemoStage::Reviewing)) {
            $conference = app(CloseSubmissions::class)->handle($conference, $owner);
            $conference = app(StartReviewing::class)->handle($conference, $owner);

            $this->reviews($conference, $reviewers);
        }

        if ($stage->reaches(DemoStage::Decided)) {
            // Decisions only. Not the letters and not `Reviewing -> Decided`:
            // those are the two buttons the demo exists to let the owner press.
            $this->decisions($conference, $owner);
        }

        return $this->report($stage, $organization, $conference->refresh(), $owner, $generated);
    }

    /**
     * Nothing leaves, and nothing is logged as if it had.
     *
     * `config(['mail.default' => 'array'])` on its own would not be enough:
     * SendTemplatedEmail *queues*, and in production the queue worker is a
     * separate process holding the real mail configuration, so a job pushed
     * here would be delivered for real a second later. `Mail::fake()` replaces
     * the manager itself, so the job is never pushed; `Notification::fake()`
     * does the same for every `Notification::send()` in the actions below.
     *
     * The third line is the one that keeps `email_logs` empty - see
     * SilentTemplatedEmail - and it is also why every action in this class is
     * resolved out of the container *after* this method has run: SubmitAbstract
     * and InviteReviewer both take SendTemplatedEmail by constructor injection,
     * so one injected into this class's own constructor would already be
     * holding the real one.
     */
    private function silenceOutgoingMail(): void
    {
        config(['mail.default' => 'array']);

        Mail::fake();
        Notification::fake();

        app()->instance(SendTemplatedEmail::class, app(SilentTemplatedEmail::class));
    }

    /**
     * RegisterOrganization is the real action for this, and it cannot be used:
     * it creates the owner's account from a name, an address and a password in
     * the same transaction, and the demo owner is an existing account whose
     * password must not be touched. So the row is built exactly as that action
     * builds it - the same fillable set, the same Organization::addMember() -
     * and approval then goes through the real ApproveOrganization.
     */
    private function organization(User $owner): Organization
    {
        $organization = new Organization;

        $organization->fill([
            'name' => self::ORGANIZATION_NAME,
            'type' => OrganizationType::Society,
            'country' => 'SA',
            'website' => 'https://www.example.org',
            'contact_email' => 'demo.society@example.com',
            // Off, as it is for a new organization: the address above is
            // fictional and nothing should print it on a public page.
            'publish_contact_email' => false,
            'purpose' => 'Demonstration data for the CASS abstract submission system. Everything under this organization is fictional and is removed by php artisan cass:demo-reset.',
            'primary_color' => '#176BB8',
            'accent_color' => '#0F4C8A',
        ]);

        // Fixed rather than derived, because the reset command and the runbook
        // both name it. Organization::booted() only derives a slug when none
        // was set.
        $organization->slug = self::ORGANIZATION_SLUG;

        // Not fillable, on purpose: a profile form that could set this would
        // turn a real organization into one cass:demo-reset agrees to destroy.
        $organization->forceFill(['is_demo' => true]);

        $organization->save();

        $organization->addMember($owner, OrganizationRole::Owner);

        // Approved by a platform admin when the platform has one, so the
        // activity entry names the right person; by the owner otherwise, which
        // is the case on a machine where the admin seeder has not run.
        $admin = User::query()->where('is_platform_admin', true)->orderBy('id')->first() ?? $owner;

        app(ApproveOrganization::class)->handle($organization, $admin);

        return $organization->refresh();
    }

    private function conference(Organization $organization, User $owner): Conference
    {
        $conference = app(CreateConference::class)->handle($organization, [
            'name' => self::CONFERENCE_NAME,
            'slug' => self::CONFERENCE_SLUG,
            'short_description' => 'A fictional conference used to demonstrate the abstract submission, review and decision workflow end to end.',
            'description' => '<p>This conference exists only to demonstrate CASS. Every abstract, author, reviewer and decision under it is invented, and every address is inside the <code>example.com</code> domain that RFC 2606 reserves for exactly this purpose.</p><p>Delete the whole thing with <code>php artisan cass:demo-reset --confirm</code>.</p>',
            'venue' => 'Demo Convention Centre, Riyadh',
            'city' => 'Riyadh',
            'country' => 'SA',
            'starts_at' => '2027-02-18',
            'ends_at' => '2027-02-20',
            'timezone' => 'Asia/Riyadh',
            // A minute in the past, not `now()`: Conference::submissionWindow()
            // reads an opening date in the future as "upcoming", and a window
            // that opens on the same tick it is checked is a race nobody needs.
            'submission_opens_at' => now()->subMinute(),
            'submission_deadline' => now()->addDays(30),
            'review_deadline' => now()->addDays(45),
            'review_mode' => ReviewMode::OpenPool,
            'blind_review' => true,
            'reviewers_per_submission' => 2,
            'word_limit' => 300,
            'max_files' => 1,
            'allowed_file_types' => ['pdf'],
            'presentation_types' => ['oral', 'poster', 'either'],
            'terms' => 'Presenting authors must register for the conference. This is demonstration text.',
            'reference_prefix' => self::REFERENCE_PREFIX,
        ]);

        foreach (self::TRACKS as $sort => $track) {
            $conference->tracks()->create([
                'name' => $track['name'],
                'description' => $track['description'],
                'sort' => $sort + 1,
            ]);
        }

        // There is no action for custom fields: the organizer creates them in a
        // Filament relation manager, which validates and writes the model
        // directly. `key` is derived from the label by CustomField::booted(),
        // which is what DemoAbstracts stores its answers against.
        $conference->customFields()->create([
            'label' => 'Ethics approval reference',
            'help_text' => 'The reference your institutional review board issued, or "not required".',
            'type' => CustomFieldType::Text,
            'required' => true,
            'sort' => 1,
        ]);

        $conference->customFields()->create([
            'label' => 'Study type',
            'type' => CustomFieldType::Select,
            'options' => ['Original research', 'Case report', 'QI project'],
            'required' => false,
            // Shown to organizers, hidden from a blinded reviewer - the flag
            // exists so a demo can show what blind review actually hides.
            'hide_from_reviewers' => true,
            'sort' => 2,
        ]);

        // CreateConference already called this; it is idempotent and returns
        // the form it made, which is the cheapest way to get hold of it.
        $form = app(CreateDefaultReviewForm::class)->handle($conference);

        // The nine-question default is all Likert, so a demo of it would have
        // no reviewer prose anywhere - and "what did the reviewers say" is the
        // first thing an organizer looks for. Appended before any review is
        // submitted, which is when spec section 3 locks the form.
        $form->questions()->create([
            'prompt' => 'Comments for the committee (not shown to the author)',
            'help_text' => 'Anything the committee should read alongside the scores.',
            'type' => ReviewQuestionType::Text,
            'required' => false,
        ]);

        return app(PublishConference::class)->handle($conference->refresh(), $owner);
    }

    private function submissions(Conference $conference, User $owner): void
    {
        $saveDraft = app(SaveSubmissionDraft::class);
        $storeFile = app(StoreSubmissionFile::class);
        $submitAbstract = app(SubmitAbstract::class);
        $withdraw = app(WithdrawSubmission::class);

        /** @var list<int> $trackIds */
        $trackIds = $conference->tracks()->orderBy('sort')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        foreach (DemoAbstracts::all() as $index => $entry) {
            $link = $saveDraft->handle($conference, [
                'title' => $entry['title'],
                'abstract' => $entry['abstract'],
                'track_id' => $trackIds[$entry['track']] ?? null,
                'presentation_preference' => $entry['preference'],
                'contact_phone' => '+96650000'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'custom_field_values' => [
                    'ethics_approval_reference' => $entry['ethics'],
                    'study_type' => $entry['study_type'],
                ],
                'authors' => $this->authors($entry),
            ]);

            $submission = $link->submission;

            if ($entry['state'] !== DemoAbstracts::STATE_DRAFT) {
                // The token the draft save just minted is handed back in, so
                // SubmitAbstract keeps it rather than rotating it - exactly
                // what the public form does.
                $submission = $submitAbstract->handle($submission, agreed: true, plainToken: $link->token);
            }

            // After the submit, so the generated PDF can print the reference
            // the abstract was just given.
            $this->attachPdf($storeFile, $submission);

            if ($entry['state'] === DemoAbstracts::STATE_WITHDRAWN) {
                // The organizer's path, not the author's: it is the one that
                // works whatever the submission window is doing.
                $withdraw->handle($submission, $owner);
            }
        }
    }

    /**
     * @param  array{corresponding: int, authors: list<array{name: string, email: string, affiliation: string}>}  $entry
     * @return list<array<string, mixed>>
     */
    private function authors(array $entry): array
    {
        $authors = [];

        foreach ($entry['authors'] as $index => $author) {
            $authors[] = [
                'name' => $author['name'],
                'email' => $author['email'],
                'affiliation' => $author['affiliation'],
                'is_presenter' => $index === 0,
                'is_corresponding' => $index === $entry['corresponding'],
            ];
        }

        return $authors;
    }

    /**
     * Through StoreSubmissionFile, which sniffs the content with finfo and
     * refuses anything that is not really a PDF - so this also proves the
     * hand-written bytes in DemoPdf are one.
     */
    private function attachPdf(StoreSubmissionFile $storeFile, Submission $submission): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cass-demo-');

        if ($path === false) {
            throw DemoRefused::because('Could not create a temporary file for the demo attachment.');
        }

        try {
            file_put_contents($path, DemoPdf::render(
                (string) $submission->title,
                (string) ($submission->reference ?? 'Draft - not yet submitted'),
            ));

            $storeFile->handle($submission, new UploadedFile(
                $path,
                Str::limit(Str::slug((string) $submission->title), 60, '').'.pdf',
                'application/pdf',
                null,
                test: true,
            ));
        } finally {
            @unlink($path);
        }
    }

    /**
     * The real invitation path, headless: InviteReviewer mints the row and the
     * (silenced) email, and AcceptInvitation does what the reviewer clicking
     * the link would do - creates the account verified, or attaches an existing
     * one, and writes the active `conference_reviewers` row through
     * ReviewerInvitation::grantTo().
     *
     * @return list<User>
     */
    private function reviewers(Conference $conference, User $owner, string $password): array
    {
        $invite = app(InviteReviewer::class);
        $accept = app(AcceptInvitation::class);

        $users = [];

        foreach (self::REVIEWERS as $reviewer) {
            $invitation = $invite->handle(
                $conference,
                $reviewer['email'],
                $reviewer['name'],
                $reviewer['affiliation'],
                $owner,
            );

            $existing = User::query()->where('email', $reviewer['email'])->first();

            if ($existing instanceof User) {
                // A demo reviewer account left behind by an earlier run that
                // could not remove it (they belong to another conference too).
                // Their password is theirs and is not rewritten here.
                $accept->handle($invitation, $existing);

                $users[] = $existing;

                continue;
            }

            $users[] = $accept->forNewAccount($invitation, $reviewer['name'], $password);
        }

        return $users;
    }

    /**
     * @param  list<User>  $reviewers
     */
    private function reviews(Conference $conference, array $reviewers): void
    {
        $saveDraft = app(SaveReviewDraft::class);
        $submitReview = app(SubmitReview::class);

        $form = $conference->reviewForm()->firstOrFail();

        /** @var list<ReviewQuestion> $questions */
        $questions = $form->questions()->orderBy('sort')->orderBy('id')->get()->all();

        /** @var list<Submission> $reviewable */
        $reviewable = $conference->submissions()
            ->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value])
            ->orderBy('id')
            ->get()
            ->all();

        foreach (array_slice($reviewable, 0, self::REVIEWED) as $index => $submission) {
            foreach ([$reviewers[0], $reviewers[1]] as $seat => $reviewer) {
                $answers = $this->answers($questions, $index, $seat);

                // Saved then submitted, which is the order a reviewer using the
                // panel produces and the order that exercises both actions.
                $saveDraft->handle($submission, $reviewer, $answers);
                $submitReview->handle($submission, $reviewer, $answers);
            }
        }

        // The third reviewer leaves drafts and submits nothing, so the progress
        // meter, the coverage summary and the reminder command all have
        // outstanding work to describe.
        foreach (array_slice($reviewable, self::REVIEWED, self::DRAFTED) as $index => $submission) {
            $saveDraft->handle($submission, $reviewers[2], $this->partialAnswers($questions, $index));
        }
    }

    /**
     * Deterministic, and spread on purpose: `$level` falls as the list goes on
     * so the ranking is a ranking rather than twelve identical means, and the
     * two seats disagree by a point so `score_spread` is never zero.
     *
     * @param  list<ReviewQuestion>  $questions
     * @return array<string, mixed>
     */
    private function answers(array $questions, int $index, int $seat): array
    {
        $levels = [5, 5, 4, 4, 4, 3, 3, 2];
        $level = $levels[$index % count($levels)];

        $answers = [];

        foreach ($questions as $position => $question) {
            $ulid = (string) $question->ulid;

            if ($question->type === ReviewQuestionType::Text) {
                $answers[$ulid] = self::COMMENTS[($index + $seat) % count(self::COMMENTS)];

                continue;
            }

            $min = (int) ($question->scale_min ?? 1);
            $max = (int) ($question->scale_max ?? 5);

            $answers[$ulid] = max($min, min($max, $level - $seat - ($position % 3)));
        }

        return $answers;
    }

    /**
     * What a reviewer who answered the first few questions and closed the
     * laptop leaves behind. A draft stores only the questions it names, which
     * is the whole point of SaveReviewDraft.
     *
     * @param  list<ReviewQuestion>  $questions
     * @return array<string, mixed>
     */
    private function partialAnswers(array $questions, int $index): array
    {
        $answers = [];

        foreach (array_slice($questions, 0, 3) as $position => $question) {
            $min = (int) ($question->scale_min ?? 1);
            $max = (int) ($question->scale_max ?? 5);

            $answers[(string) $question->ulid] = max($min, min($max, 3 + (($index + $position) % 2)));
        }

        return $answers;
    }

    /**
     * Four accepted for an oral slot, four for a poster, two waitlisted and two
     * rejected, in ranking order. The four abstracts nobody reviewed sort last
     * and take the waitlist and the rejections, which is also what an organizer
     * would see if they decided a conference before the reviewing finished -
     * and is exactly the state the "unreviewed" number on the ranking page
     * exists to warn about.
     */
    private function decisions(Conference $conference, User $owner): void
    {
        $apply = app(ApplyDecision::class);

        /** @var list<Submission> $ranked */
        $ranked = $conference->submissions()
            ->whereNotNull('reference')
            ->where('status', '!=', SubmissionStatus::Withdrawn->value)
            ->orderBy('id')
            ->get()
            // In PHP rather than in SQL: "nulls last" is spelled differently on
            // SQLite and MySQL, there are twelve rows, and PHP's sort is stable
            // so the orderBy above still breaks ties.
            ->sortByDesc(static fn (Submission $submission): float => $submission->score === null ? -1.0 : (float) $submission->score)
            ->values()
            ->all();

        $plan = [
            ...array_fill(0, 4, Decision::AcceptedOral),
            ...array_fill(0, 4, Decision::AcceptedPoster),
            ...array_fill(0, 2, Decision::Waitlisted),
            ...array_fill(0, 2, Decision::Rejected),
        ];

        foreach ($plan as $position => $decision) {
            $submission = $ranked[$position] ?? null;

            if (! $submission instanceof Submission) {
                break;
            }

            // No email: ApplyDecision writes the decision and the history row
            // and leaves `decision_notified_at` null, so "Send decision emails"
            // is still there for the owner to click.
            $apply->handle($submission, $decision, $owner, self::DECISION_NOTES[$decision->value]);
        }
    }

    private function report(
        DemoStage $stage,
        Organization $organization,
        Conference $conference,
        User $owner,
        ?string $generatedPassword,
    ): DemoSeedReport {
        $submissions = $conference->submissionCounts();
        $decisions = $conference->decisionCounts();

        $counts = [
            'Abstracts' => $submissions['total'],
            // `submitted` is "waiting for its first review": ApplyDecision
            // rewrites status to accepted/rejected/waitlisted, so at the
            // `decided` stage both of the next two numbers are legitimately
            // zero and the decision rows below are where the abstracts are.
            'Awaiting review' => $submissions['submitted'],
            'Under review' => $conference->submissions()->where('status', SubmissionStatus::UnderReview->value)->count(),
            'Drafts' => $submissions['draft'],
            'Withdrawn' => $submissions['withdrawn'],
            'Files on the private disk' => (int) $conference->submissions()->withCount('files')->get()->sum('files_count'),
            'Active reviewers' => $conference->activeReviewers()->count(),
            'Reviews submitted' => $this->reviewCount($conference, ReviewStatus::Submitted),
            'Reviews in draft' => $this->reviewCount($conference, ReviewStatus::Draft),
            'Decisions applied' => $decisions['decided'],
        ];

        if ($decisions['decided'] > 0) {
            foreach (Decision::inReportOrder() as $decision) {
                $counts[$decision->getLabel()] = $decisions['by_decision'][$decision->value] ?? 0;
            }
        }

        $counts['Decision letters sent'] = $decisions['notified'];

        return new DemoSeedReport(
            stage: $stage,
            organizationSlug: (string) $organization->slug,
            conferenceName: (string) $conference->name,
            conferenceUrl: $conference->publicUrl(),
            shortLinkUrl: $conference->shortLink()->first()?->url(),
            // url() rather than a Filament panel URL: getUrl() on a tenant panel
            // wants a booted panel and a tenant, and a console command has
            // neither. The two paths are fixed in the panel providers.
            organizerPanelUrl: url('/org/'.$organization->slug),
            reviewerPanelUrl: url('/review'),
            ownerEmail: (string) $owner->email,
            reviewerEmails: self::reviewerEmails(),
            generatedPassword: $generatedPassword,
            counts: $counts,
            resetCommand: 'php artisan cass:demo-reset --confirm',
        );
    }

    private function reviewCount(Conference $conference, ReviewStatus $status): int
    {
        return Review::query()
            ->whereIn('submission_id', $conference->submissions()->select('id'))
            ->where('status', $status->value)
            ->count();
    }
}
