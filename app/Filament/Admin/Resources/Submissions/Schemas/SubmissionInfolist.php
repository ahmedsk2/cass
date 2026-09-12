<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Schemas;

use App\Filament\Admin\Resources\Conferences\ConferenceResource;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Models\Submission;
use App\Models\SubmissionFile;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

class SubmissionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.submissions.sections.abstract'))->columns(2)->components([
                TextEntry::make('reference')->fontFamily('mono')->placeholder('-'),
                TextEntry::make('status')->badge(),
                TextEntry::make('title')->columnSpanFull(),
                TextEntry::make('abstract')->columnSpanFull()->prose(),
                TextEntry::make('word_count')->numeric(),
                TextEntry::make('presentation_preference')->badge()->placeholder('-'),
                TextEntry::make('track.name')->placeholder('-'),
            ]),

            Section::make(__('admin.submissions.sections.owner'))->columns(2)->components([
                TextEntry::make('conference.name')
                    ->url(fn (Submission $record): ?string => $record->conference === null
                        ? null
                        : ConferenceResource::getUrl('view', ['record' => $record->conference], panel: 'admin')),
                TextEntry::make('conference.organization.name')
                    ->url(fn (Submission $record): ?string => $record->conference?->organization === null
                        ? null
                        : OrganizationResource::getUrl('view', ['record' => $record->conference->organization], panel: 'admin')),
                TextEntry::make('conference.status')->badge(),
                // The fallback is load-bearing, not decoration: Conference
                // soft-deletes and nothing cascades, so this page still opens
                // for an abstract whose conference is gone - and Carbon's
                // setTimezone('') throws InvalidTimeZoneException, a 500 on the
                // one screen a platform admin has for exactly that mess.
                TextEntry::make('submitted_at')->dateTime('j M Y, H:i')
                    ->timezone(fn (Submission $record): string => (string) ($record->conference?->timezone ?: config('app.timezone')))
                    ->placeholder('-'),
            ]),

            Section::make(__('admin.submissions.sections.authors'))
                ->visible(fn (Submission $record): bool => $record->authors()->exists())
                ->components([
                    RepeatableEntry::make('authors')->hiddenLabel()->columns(4)->schema([
                        TextEntry::make('name'),
                        TextEntry::make('email'),
                        TextEntry::make('affiliation')->placeholder('-'),
                        // boolean() lives on IconEntry, not TextEntry - the
                        // same shape as ConferenceInfolist.php:232 and the
                        // organizer SubmissionInfolist.php:43. TextEntry has no
                        // boolean(), so ->boolean() there hits
                        // Macroable::__call and throws BadMethodCallException:
                        // a 500 on every admin submission view that has an
                        // author.
                        IconEntry::make('is_presenter')
                            ->boolean()
                            ->label(__('admin.submissions.columns.presenter')),
                    ]),
                ]),

            Section::make(__('admin.submissions.sections.files'))
                ->visible(fn (Submission $record): bool => $record->files()->exists())
                ->components([
                    RepeatableEntry::make('files')->hiddenLabel()->columns(3)->schema([
                        // A fresh signed URL per render, minted behind the
                        // policy exactly as the organizer infolist mints one.
                        // No `blind` flag: a platform admin is not blinded, and
                        // temporaryUrl() leaves the parameter out of the
                        // signature entirely when it is false.
                        TextEntry::make('original_name')
                            ->label(__('admin.submissions.columns.file'))
                            ->url(fn (SubmissionFile $record): ?string => Gate::allows('view', $record)
                                ? $record->temporaryUrl()
                                : null)
                            ->openUrlInNewTab(),
                        TextEntry::make('size')->formatStateUsing(fn (int $state): string => number_format($state / 1024).' KB'),
                        TextEntry::make('mime')->label(__('admin.submissions.columns.type')),
                    ]),
                ]),

            Section::make(__('admin.submissions.sections.decisions'))
                ->visible(fn (Submission $record): bool => $record->decisions()->exists())
                ->components([
                    TextEntry::make('decision')->badge()->placeholder('-'),
                    // Same null conference, same fallback, same reason.
                    TextEntry::make('decision_notified_at')->dateTime('j M Y, H:i')
                        ->timezone(fn (Submission $record): string => (string) ($record->conference?->timezone ?: config('app.timezone')))
                        ->placeholder(__('admin.submissions.not_notified')),
                    // The whole history, newest first, with the letter that was
                    // actually sent - which is the thing no organizer screen
                    // shows either and which Plan 5 stored for exactly this.
                    RepeatableEntry::make('decisions')->hiddenLabel()->columnSpanFull()->columns(3)->schema([
                        TextEntry::make('decision')->badge(),
                        TextEntry::make('decided_at')->dateTime('j M Y, H:i'),
                        TextEntry::make('decidedBy.name')->label(__('admin.submissions.columns.decided_by'))
                            ->placeholder(__('admin.submissions.former_member')),
                        TextEntry::make('letter_subject')->columnSpanFull()->placeholder('-'),
                        TextEntry::make('note')->columnSpanFull()->placeholder('-'),
                    ]),
                ]),
        ]);
    }
}
