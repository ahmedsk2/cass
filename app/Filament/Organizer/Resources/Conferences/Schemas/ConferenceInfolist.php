<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Schemas;

use App\Actions\Conferences\PublishConference;
use App\Enums\ConferenceStatus;
use App\Models\Conference;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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
