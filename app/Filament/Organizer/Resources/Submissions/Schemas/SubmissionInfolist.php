<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Submissions\Schemas;

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
            Section::make('Abstract')->columns(3)->components([
                TextEntry::make('reference')->fontFamily('mono')->placeholder('Not submitted'),
                TextEntry::make('status')->badge(),
                TextEntry::make('conference.name')->label('Conference'),
                TextEntry::make('title')->columnSpanFull(),
                // The abstract is plain text (the column is, and the form is a
                // textarea), so this is an escaped entry and never HTML.
                TextEntry::make('abstract')->columnSpanFull()->prose(),
                TextEntry::make('word_count')->suffix(' words'),
                TextEntry::make('track.name')->label('Track')->placeholder('-'),
                TextEntry::make('presentation_preference')->label('Presentation preference')->badge()->placeholder('-'),
            ]),

            Section::make('Authors')->components([
                RepeatableEntry::make('authors')
                    ->hiddenLabel()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('email')->copyable(),
                        TextEntry::make('affiliation')->placeholder('-'),
                        IconEntry::make('is_corresponding')->label('Corresponding')->boolean(),
                    ]),
                TextEntry::make('contact_phone')->label('Contact phone')->placeholder('-'),
            ]),

            Section::make('Extra answers')
                ->visible(fn (Submission $record): bool => filled($record->custom_field_values))
                ->components([
                    TextEntry::make('custom_field_values')
                        ->hiddenLabel()
                        ->listWithLineBreaks()
                        ->bulleted()
                        // Keyed by custom_fields.key; print the label the
                        // organizer wrote, not the derived key.
                        ->state(function (Submission $record): array {
                            $labels = $record->conference->customFields()->pluck('label', 'key');
                            $lines = [];

                            foreach ((array) $record->custom_field_values as $key => $value) {
                                $printable = match (true) {
                                    is_bool($value) => $value ? 'Yes' : 'No',
                                    is_scalar($value) => (string) $value,
                                    default => json_encode($value) ?: '',
                                };

                                $lines[] = ($labels[$key] ?? $key).': '.$printable;
                            }

                            return $lines;
                        }),
                ]),

            Section::make('Files')
                ->visible(fn (Submission $record): bool => $record->files()->exists())
                ->components([
                    RepeatableEntry::make('files')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('original_name')
                                ->label('File')
                                // A fresh 30-minute signed URL per render. The
                                // policy is checked before one is minted, so an
                                // organizer cannot hand out a link to a file
                                // they may not read (the signature itself is
                                // the capability once it exists).
                                ->url(fn (SubmissionFile $record): ?string => Gate::allows('view', $record)
                                    ? $record->temporaryUrl()
                                    : null)
                                ->openUrlInNewTab(),
                            TextEntry::make('size')->formatStateUsing(fn (int $state): string => number_format($state / 1024).' KB'),
                            TextEntry::make('mime')->label('Type'),
                        ]),
                ]),

            Section::make('Activity')->columns(3)->components([
                TextEntry::make('submitted_at')->dateTime('j M Y, H:i')
                    ->timezone(fn (Submission $record): string => $record->conference->timezone)
                    ->placeholder('Not submitted'),
                TextEntry::make('last_edited_at')->label('Last edited')->dateTime('j M Y, H:i')
                    ->timezone(fn (Submission $record): string => $record->conference->timezone)
                    ->placeholder('-'),
                TextEntry::make('withdrawn_at')->label('Withdrawn')->dateTime('j M Y, H:i')
                    ->timezone(fn (Submission $record): string => $record->conference->timezone)
                    ->placeholder('-'),
            ]),
        ]);
    }
}
