<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Schemas;

use App\Actions\Conferences\PublishConference;
use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Submissions\SubmissionResource;
use App\Models\Conference;
use Filament\Actions\Action;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use WeakMap;

class ConferenceInfolist
{
    /**
     * blockers() runs several queries (the organization's status, the review
     * form's questions, the submission window), and the checklist section asks
     * for it twice per render: once to decide whether to show itself and once
     * for the list itself. Keyed by the record object rather than its id so a
     * stale answer cannot outlive the instance it was computed for, and weakly
     * so nothing is held in memory after the render.
     *
     * @var WeakMap<Conference, list<string>>|null
     */
    private static ?WeakMap $blockers = null;

    /** @return list<string> */
    private static function blockers(Conference $record): array
    {
        self::$blockers ??= new WeakMap;

        return self::$blockers[$record] ??= app(PublishConference::class)->blockers($record);
    }

    /**
     * @var WeakMap<Conference, array{total: int, draft: int, submitted: int, withdrawn: int}>|null
     */
    private static ?WeakMap $counts = null;

    /** @return array{total: int, draft: int, submitted: int, withdrawn: int} */
    private static function counts(Conference $record): array
    {
        self::$counts ??= new WeakMap;

        return self::$counts[$record] ??= $record->submissionCounts();
    }

    /**
     * @var WeakMap<Conference, array{expected: int, submitted: int, drafts: int, reviewers: list<array{name: string, submitted: int, expected: int}>}>|null
     */
    private static ?WeakMap $progress = null;

    /** @return array{expected: int, submitted: int, drafts: int, reviewers: list<array{name: string, submitted: int, expected: int}>} */
    private static function progress(Conference $record): array
    {
        self::$progress ??= new WeakMap;

        return self::$progress[$record] ??= $record->reviewProgress();
    }

    /**
     * @var WeakMap<Conference, array{decided: int, undecided: int, notified: int, by_decision: array<string, int>}>|null
     */
    private static ?WeakMap $decisions = null;

    /** @return array{decided: int, undecided: int, notified: int, by_decision: array<string, int>} */
    private static function decisions(Conference $record): array
    {
        self::$decisions ??= new WeakMap;

        return self::$decisions[$record] ??= $record->decisionCounts();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Publishing checklist')
                ->description('Everything below must be true before this conference can go live.')
                // blockers() is written for the publish action, so it also
                // reports "a conference that is open for submissions cannot be
                // opened for submissions". Ask first whether going live is even
                // on the table, or every live and archived conference shows a
                // red go-live checklist it can do nothing about.
                ->visible(fn (Conference $record): bool => $record->status->canTransitionTo(ConferenceStatus::Open)
                    && self::blockers($record) !== [])
                ->components([
                    TextEntry::make('publishing_blockers')
                        ->hiddenLabel()
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->color('danger')
                        ->state(fn (Conference $record): array => self::blockers($record)),
                ]),

            Section::make('Conference')->columns(2)->components([
                TextEntry::make('name'),
                TextEntry::make('status')->badge(),
                TextEntry::make('slug')->label('Public address')
                    ->url(fn (Conference $record): string => $record->publicUrl())
                    ->openUrlInNewTab()
                    ->formatStateUsing(fn (Conference $record): string => $record->publicUrl()),
                TextEntry::make('published_at')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->placeholder('Not published'),
                TextEntry::make('short_description')->columnSpanFull()->placeholder('-'),
            ]),

            // A section rather than a relation manager: the conference page
            // needs four numbers and a way through to the list, and a relation
            // manager would be a second table of the same rows with its own
            // filters, its own authorization surface and its own tests.
            Section::make('Submissions')
                ->headerActions([
                    Action::make('viewSubmissions')
                        ->label('Open the list')
                        ->icon(Heroicon::OutlinedInbox)
                        ->color('gray')
                        ->url(fn (Conference $record): string => SubmissionResource::urlForConference($record)),
                ])
                ->columns(4)
                ->components([
                    TextEntry::make('submissions_total')->label('Total')->badge()
                        ->state(fn (Conference $record): int => self::counts($record)['total']),
                    TextEntry::make('submissions_draft')->label('Drafts')->badge()->color('gray')
                        ->state(fn (Conference $record): int => self::counts($record)['draft']),
                    TextEntry::make('submissions_submitted')->label('Submitted')->badge()->color('info')
                        ->state(fn (Conference $record): int => self::counts($record)['submitted']),
                    TextEntry::make('submissions_withdrawn')->label('Withdrawn')->badge()->color('danger')
                        ->state(fn (Conference $record): int => self::counts($record)['withdrawn']),
                ]),

            Section::make(__('reviewer.progress.heading'))
                // Only once reviewing has started: a checklist of zeroes on a
                // conference still collecting abstracts is noise.
                ->visible(fn (Conference $record): bool => in_array(
                    $record->status,
                    [ConferenceStatus::Reviewing, ConferenceStatus::Decided],
                    true,
                ))
                ->headerActions([
                    Action::make('manageReviewers')
                        ->label(__('reviewer.actions.page_link'))
                        ->icon(Heroicon::OutlinedUserGroup)
                        ->color('gray')
                        ->url(fn (Conference $record): string => ConferenceResource::getUrl('reviewers', ['record' => $record])),
                ])
                ->columns(3)
                ->components([
                    TextEntry::make('reviews_submitted')->label(__('reviewer.progress.submitted'))->badge()->color('success')
                        ->state(fn (Conference $record): string => self::progress($record)['submitted'].' / '.self::progress($record)['expected']),
                    TextEntry::make('reviews_drafts')->label(__('reviewer.progress.drafts'))->badge()->color('warning')
                        ->state(fn (Conference $record): int => self::progress($record)['drafts']),
                    TextEntry::make('reviewers_active')->label(__('reviewer.progress.reviewers'))->badge()
                        ->state(fn (Conference $record): int => count(self::progress($record)['reviewers'])),
                    TextEntry::make('per_reviewer')->label(__('reviewer.progress.per_reviewer'))
                        ->listWithLineBreaks()
                        ->columnSpanFull()
                        ->placeholder(__('reviewer.progress.none'))
                        ->state(fn (Conference $record): array => array_map(
                            fn (array $row): string => $row['name'].' — '.$row['submitted'].' / '.$row['expected'],
                            self::progress($record)['reviewers'],
                        )),
                ]),

            Section::make(__('decisions.conference.heading'))
                ->visible(fn (Conference $record): bool => in_array(
                    $record->status,
                    [ConferenceStatus::Reviewing, ConferenceStatus::Decided, ConferenceStatus::Archived],
                    true,
                ))
                ->headerActions([
                    Action::make('openRanking')
                        ->label(__('decisions.conference.open'))
                        ->icon(Heroicon::OutlinedTrophy)
                        ->color('gray')
                        ->url(fn (Conference $record): string => ConferenceResource::getUrl('ranking', ['record' => $record])),
                ])
                ->columns(3)
                ->components([
                    TextEntry::make('decisions_decided')->label(__('decisions.conference.decided'))->badge()->color('success')
                        ->state(fn (Conference $record): string => self::decisions($record)['decided']
                            .' / '.(self::decisions($record)['decided'] + self::decisions($record)['undecided'])),
                    TextEntry::make('decisions_notified')->label(__('decisions.conference.notified'))->badge()->color('info')
                        ->state(fn (Conference $record): int => self::decisions($record)['notified']),
                    TextEntry::make('decisions_undecided')->label(__('decisions.conference.undecided'))->badge()->color('warning')
                        ->state(fn (Conference $record): int => self::decisions($record)['undecided']),
                    TextEntry::make('decisions_breakdown')->label(__('decisions.conference.breakdown'))
                        ->listWithLineBreaks()
                        ->columnSpanFull()
                        ->placeholder(__('decisions.conference.none'))
                        // No array_values(): Decision::inReportOrder() is
                        // declared `list<self>`, so array_map over it is
                        // already a list and Larastan level 6 rejects the
                        // wrapper as a call with no effect.
                        ->state(fn (Conference $record): array => array_map(
                            fn (Decision $decision): string => $decision->getLabel().' — '
                                .self::decisions($record)['by_decision'][$decision->value],
                            Decision::inReportOrder(),
                        )),
                ]),

            // Every date-time entry names the conference timezone explicitly:
            // Filament otherwise formats in config('app.timezone'), which is
            // UTC, and the organizer would read a deadline three hours early
            // right next to the "Asia/Riyadh" line below it (spec section 10).
            Section::make('Dates')->columns(3)->components([
                TextEntry::make('starts_at')->date('j M Y')->placeholder('Not set'),
                TextEntry::make('ends_at')->date('j M Y')->placeholder('Not set'),
                TextEntry::make('timezone'),
                TextEntry::make('submission_opens_at')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->placeholder('Not set'),
                TextEntry::make('submission_deadline')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->placeholder('Not set'),
                TextEntry::make('review_deadline')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->placeholder('Not set'),
            ]),

            Section::make('Rules')->columns(3)->components([
                TextEntry::make('review_mode')->badge(),
                TextEntry::make('reviewers_per_submission')->label('Reviewers per abstract'),
                IconEntry::make('blind_review')->boolean()->label('Blind review'),
                TextEntry::make('word_limit')->suffix(' words'),
                TextEntry::make('max_files')->label('Files allowed'),
                TextEntry::make('allowed_file_types')->badge(),
                TextEntry::make('presentation_types')->badge()->columnSpanFull(),
                TextEntry::make('terms')->columnSpanFull()->placeholder('-'),
            ]),

            Section::make('Sharing')
                ->visible(fn (Conference $record): bool => $record->shortLink !== null)
                ->columns(2)
                ->components([
                    TextEntry::make('shortLink.code')->label('Short link')
                        ->formatStateUsing(fn (Conference $record): string => $record->shortLink?->url() ?? '-')
                        ->url(fn (Conference $record): ?string => $record->shortLink?->url())
                        ->openUrlInNewTab(),
                    TextEntry::make('shortLink.clicks')->label('Total scans')->badge(),
                ]),
        ]);
    }
}
