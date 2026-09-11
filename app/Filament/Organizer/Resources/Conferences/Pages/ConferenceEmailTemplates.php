<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Actions\Mail\RenderEmailTemplate;
use App\Actions\Mail\ResetEmailTemplate;
use App\Actions\Mail\SaveEmailTemplate;
use App\Enums\EmailTemplateKey;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Models\Conference;
use App\Models\EmailTemplate;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * All eleven template keys of spec 5.9, with their state, an editor, a live
 * preview and a reset.
 *
 * The table is **array-backed** (fact 10): `Table::records()` takes a closure,
 * Filament keys each array record by `ArrayRecord::getKeyName()` or by the
 * collection key, and the row actions receive `array $record`. That is what
 * makes it possible to list eleven keys when `email_templates` contains none -
 * the database holds overrides, not templates.
 */
class ConferenceEmailTemplates extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ConferenceResource::class;

    protected string $view = 'filament.organizer.pages.email-templates';

    public function mount(int|string $record): void
    {
        // resolveRecord() runs through ConferenceResource::getEloquentQuery(),
        // which carries the panel's tenancy global scope, so another
        // organization's conference is already a 404. The policy check is
        // defence in depth, exactly as on ConferenceShortLink.
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 404);
    }

    public function getTitle(): string
    {
        return 'Email templates';
    }

    public function getSubheading(): ?string
    {
        return 'These are the emails CASS sends about this conference. Leave one alone and it uses the CASS wording; edit it and this conference uses yours.';
    }

    public function getConference(): Conference
    {
        /** @var Conference $conference */
        $conference = $this->getRecord();

        return $conference;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->rows())
            ->columns([
                TextColumn::make('label')->label('Email')->weight('semibold')
                    ->description(fn (array $record): string => $record['subject']),
                TextColumn::make('state')->label('Wording')->badge()
                    ->color(fn (array $record): string => match ($record['state']) {
                        'Customised' => 'success',
                        'Platform-wide' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('sends')->label('Sent when')->wrap(),
            ])
            ->recordActions([
                $this->editAction(),
                $this->resetAction(),
            ])
            ->paginated(false)
            ->emptyStateHeading('No templates');
    }

    /**
     * One array record per key. Keyed by the key itself, so
     * HasRecords::getTableRecords() stamps `__key` with it and every row action
     * receives an argument the enum can be rebuilt from.
     *
     * The value type is the exact shape rather than `array<string, mixed>`:
     * Collection's TValue is invariant, so the wider annotation is a
     * return.type error at Larastan level 6, and the narrow one also tells the
     * column and action closures below what `$record` really holds.
     *
     * @return Collection<string, array{key: string, label: string, subject: string, body: string, state: string, sends: string}>
     */
    private function rows(): Collection
    {
        $render = app(RenderEmailTemplate::class);
        $conference = $this->getConference();
        $overrides = $conference->emailTemplates()->pluck('key')->all();

        return collect(EmailTemplateKey::cases())
            ->mapWithKeys(function (EmailTemplateKey $key) use ($render, $conference, $overrides): array {
                $template = $render->template($key, $conference);

                return [$key->value => [
                    'key' => $key->value,
                    'label' => $key->getLabel(),
                    'subject' => $template->subject,
                    'body' => $template->body,
                    'state' => match (true) {
                        ! $key->isConferenceScoped() => 'Platform-wide',
                        in_array($key->value, $overrides, true) => 'Customised',
                        default => 'Platform default',
                    },
                    'sends' => self::sendsWhen($key),
                ]];
            });
    }

    private function editAction(): Action
    {
        return Action::make('edit')
            ->label('Edit')
            ->icon(Heroicon::OutlinedEnvelopeOpen)
            ->visible(fn (array $record): bool => EmailTemplateKey::from($record['key'])->isConferenceScoped()
                && Gate::allows('create', EmailTemplate::class))
            ->modalHeading(fn (array $record): string => 'Edit: '.$record['label'])
            ->modalWidth('4xl')
            ->fillForm(fn (array $record): array => [
                'subject' => $record['subject'],
                'body' => $record['body'],
            ])
            ->schema(fn (array $record): array => [
                Section::make('Placeholders')
                    ->description('Type these exactly as shown. Anything else is left in the email as you typed it, which is how a typo shows up here instead of in an author\'s inbox.')
                    ->collapsible()
                    ->components([
                        Placeholder::make('legend')
                            ->hiddenLabel()
                            ->content(new HtmlString(collect(EmailTemplateKey::from($record['key'])->placeholders())
                                ->map(fn (string $name): string => '<code>{{'.e($name).'}}</code>')
                                ->implode(' '))),
                    ]),

                TextInput::make('subject')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->helperText('A link cannot go in a subject line.')
                    // Wrapped in a closure that *returns* the rule. Filament
                    // evaluates a Closure rule
                    // (Forms\Components\Concerns\CanBeValidated::getRules() does
                    // `$rule = $this->evaluate($rule)`), so an unwrapped custom
                    // rule is called by the closure evaluator instead of by the
                    // validator - and its first parameter, `string $attribute`,
                    // is unresolvable, so the modal throws
                    // BindingResolutionException the moment it validates. This
                    // is the idiom Filament's own code uses.
                    ->rule(fn (): Closure => $this->placeholderRule($record['key'], subject: true)),

                Textarea::make('body')
                    ->required()
                    ->rows(14)
                    ->maxLength(10000)
                    ->live(onBlur: true)
                    // "shown as text", not "removed": RenderEmailTemplate::renderBody()
                    // escapes `<`, so a tag an organizer types is delivered to
                    // the recipient as visible characters rather than stripped.
                    // Telling them it is removed would have them paste a tag,
                    // see nothing in the preview they expected, and send it.
                    ->helperText('Markdown: **bold**, _italic_, - lists, and [links](https://example.org). Raw HTML is not rendered - it is shown as text.')
                    ->rule(fn (): Closure => $this->placeholderRule($record['key'])),

                Section::make('Preview')
                    // The wording, with sample values. Not the layout: the
                    // preview renders the Markdown on its own, without the mail
                    // template, the organization header or the theme, so
                    // promising "the layout the recipient sees" would be a
                    // promise this section does not keep.
                    ->description('The wording, with sample values filled in. The email itself is sent in your organization\'s branded layout.')
                    ->components([
                        Placeholder::make('preview')
                            ->hiddenLabel()
                            ->content(fn (Get $get): HtmlString => $this->preview(
                                EmailTemplateKey::from($record['key']),
                                (string) $get('subject'),
                                (string) $get('body'),
                            )),
                    ]),
            ])
            ->action(function (array $record, array $data, SaveEmailTemplate $save): void {
                $key = EmailTemplateKey::from($record['key']);

                // The ability has to match what the save actually does.
                // Authorizing `create` for every save made
                // EmailTemplatePolicy::update() unreachable dead code, so any
                // later tightening of it - Plan 4 gives reviewers a role, and
                // spec section 4 may yet narrow who edits wording - would have
                // silently not applied to the one screen that edits wording.
                $existing = $this->getConference()->emailTemplates()->where('key', $key->value)->first();

                $existing instanceof EmailTemplate
                    ? Gate::authorize('update', $existing)
                    : Gate::authorize('create', EmailTemplate::class);

                $save->handle(
                    $this->getConference(),
                    $key,
                    (string) $data['subject'],
                    (string) $data['body'],
                );

                Notification::make()->success()->title('Template saved')
                    ->body('This conference now uses your wording for '.e($record['label']).'.')
                    ->send();
            });
    }

    private function resetAction(): Action
    {
        return Action::make('reset')
            ->label('Reset to default')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Reset to the CASS wording?')
            ->modalDescription('Your version of this email is deleted and the conference goes back to the platform default. This cannot be undone.')
            ->visible(fn (array $record): bool => $record['state'] === 'Customised'
                && Gate::allows('deleteAny', EmailTemplate::class))
            ->action(function (array $record, ResetEmailTemplate $reset): void {
                Gate::authorize('deleteAny', EmailTemplate::class);

                $reset->handle($this->getConference(), EmailTemplateKey::from($record['key']));

                Notification::make()->success()->title('Back to the CASS wording')->send();
            });
    }

    /**
     * A placeholder the key does not declare would render literally in a real
     * email. Reject it here, where the organizer can still see why.
     *
     * `$subject` narrows the list to EmailTemplateKey::subjectPlaceholders(),
     * which drops `status_link` and `review_link`: a rendered subject is stored
     * verbatim in `email_logs.subject`, listed in the admin panel and carried in
     * a clear-text SMTP header, so a bearer credential must not be
     * substitutable into one.
     */
    private function placeholderRule(string $key, bool $subject = false): Closure
    {
        $allowed = $subject
            ? EmailTemplateKey::from($key)->subjectPlaceholders()
            : EmailTemplateKey::from($key)->placeholders();

        return static function (string $attribute, mixed $value, Closure $fail) use ($allowed): void {
            preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', (string) $value, $matches);

            $unknown = array_values(array_unique(array_diff($matches[1], $allowed)));

            if ($unknown !== []) {
                $fail('This email does not have '.implode(', ', array_map(fn (string $n): string => '{{'.$n.'}}', $unknown)).'. Use only the placeholders listed above.');
            }
        };
    }

    /**
     * The preview renders the text that is **on screen**, not the text that is
     * stored - RenderEmailTemplate::handle() reads the database, and a preview
     * of the saved version while the organizer is typing is a preview that
     * lies.
     *
     * The body goes through RenderEmailTemplate::renderBody(), the same method
     * the delivered email uses, rather than through a second copy of its rules:
     * that method's `<` escaping is the only thing making organizer-typed raw
     * HTML inert, and this preview is handed to the panel as an HtmlString. The
     * subject is a header in a real email and plain text here, so it is
     * substituted unescaped and then escaped once, by e(), for display.
     */
    private function preview(EmailTemplateKey $key, string $subject, string $body): HtmlString
    {
        $render = app(RenderEmailTemplate::class);
        $values = $key->sampleValues();

        return new HtmlString(
            '<p style="font-weight:600;margin-bottom:.75rem">'.e($render->fill($subject, $values, escape: false)).'</p>'
            .Markdown::parse($render->renderBody($body, $values))->toHtml()
        );
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToConference')
                ->label('Back to the conference')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(fn (): string => ConferenceResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    private static function sendsWhen(EmailTemplateKey $key): string
    {
        return match ($key) {
            EmailTemplateKey::SubmissionReceived => 'To the corresponding author when an abstract is submitted.',
            EmailTemplateKey::SubmissionDraftSaved => 'To the corresponding author when a draft is saved, and when you resend a status link.',
            EmailTemplateKey::ReviewerInvitation => 'To a reviewer you invite. Not sent yet — reviewing arrives in a later release.',
            EmailTemplateKey::ReviewerReminder => 'To reviewers with outstanding work, 7, 3 and 1 days before the review deadline. Not sent yet.',
            EmailTemplateKey::ReviewerOverdue => 'To reviewers once the review deadline has passed. Not sent yet.',
            EmailTemplateKey::DecisionAcceptedOral,
            EmailTemplateKey::DecisionAcceptedPoster,
            EmailTemplateKey::DecisionWaitlisted,
            EmailTemplateKey::DecisionRejected => 'When you send decision emails. Not sent yet — decisions arrive in a later release.',
            EmailTemplateKey::OrganizationApproved,
            EmailTemplateKey::OrganizationRejected => 'Sent by the platform when an organization is approved or rejected. Not editable per conference.',
        };
    }
}
